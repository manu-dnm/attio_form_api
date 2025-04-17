<?php
include('env.php'); // Asegúrate que $ATTIO_API_KEY está definido aquí

// Habilitar CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// --- Constantes y Configuración ---
define('ATTIO_API_KEY', $ATTIO_API_KEY);
define('ATTIO_API_BASE_URL', "https://api.attio.com/v2");
// !!! AJUSTA ESTO si el slug/ID de tu objeto de ciclos de facturación es diferente !!!
define('BILLING_CYCLES_OBJECT_ID', 'billing_cycles');
define('COMPANIES_OBJECT_ID', 'companies'); // Asumiendo que el objeto es 'companies'

// --- Función reutilizable para llamadas a la API de Attio ---
function makeAttioApiRequest(string $endpoint, string $method = 'GET', ?array $payload = null): array {
    $url = ATTIO_API_BASE_URL . $endpoint;
    $ch = curl_init();
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . ATTIO_API_KEY,
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 20 // Aumentado ligeramente por posibles llamadas múltiples
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
    } elseif ($method !== 'GET') {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
    }

    curl_setopt_array($ch, $options);

    $responseBody = curl_exec($ch);
    $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    $responseData = json_decode($responseBody, true);
    $jsonLastError = json_last_error();

    return [
        'body' => $responseData,
        'raw_body' => $responseBody,
        'status_code' => $httpStatusCode,
        'curl_errno' => $curlErrno,
        'curl_error' => $curlError,
        'json_last_error' => $jsonLastError,
        'json_last_error_msg' => json_last_error_msg()
    ];
}

// --- Manejo de Solicitud HTTP ---
$requestMethod = $_SERVER['REQUEST_METHOD'];

if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($requestMethod !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."]);
    exit();
}

// --- Obtener y Validar Parámetro 'period' ---
$period = $_GET['period'] ?? null; // 'current_month' o 'next_month'

if ($period === null || !in_array($period, ['current_month', 'next_month'])) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Parámetro 'period' es requerido y debe ser 'current_month' o 'next_month'."
    ]);
    exit();
}

// --- Calcular Rango de Fechas según el Periodo ---
$today = new DateTime('now', new DateTimeZone('UTC')); // Usar UTC es buena práctica para APIs
$startDate = null;
$endDate = null;

if ($period === 'current_month') {
    $startDate = (clone $today)->format('Y-m-01');
    $endDate = (clone $today)->format('Y-m-t');
} else { // next_month
    $startDate = (clone $today)->modify('first day of next month')->format('Y-m-01');
    $endDate = (clone $today)->modify('last day of next month')->format('Y-m-t');
}

// --- 1. Obtener Todas las Compañías Activas ---
$companiesPayload = [
    "filter" => [
        "status" => "b09e60c1-7d87-4209-883e-f28cc26743b0", // ID del status "Active"
    ]
    // Podrías añadir paginación aquí si la lista es muy grande,
    // pero complicaría el filtrado posterior.
    // "limit" => 100,
    // "offset" => 0
];

$companiesEndpoint = "/objects/" . COMPANIES_OBJECT_ID . "/records/query";
$apiResult = makeAttioApiRequest($companiesEndpoint, 'POST', $companiesPayload);

// --- Manejo de Errores de la Llamada Principal ---
if ($apiResult['curl_errno'] > 0) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al contactar la API de Attio (Consulta inicial)",
        "error_details" => "cURL Error ({$apiResult['curl_errno']}): {$apiResult['curl_error']}"
    ]);
    exit();
}

if ($apiResult['json_last_error'] !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "message" => "Respuesta JSON inválida de la API de Attio (Consulta inicial)",
        "error_details" => "JSON Error: " . $apiResult['json_last_error_msg'],
        "raw_response" => substr($apiResult['raw_body'], 0, 1000)
    ]);
    exit();
}

if ($apiResult['status_code'] < 200 || $apiResult['status_code'] >= 300) {
     $errorMessage = $apiResult['body']['error']['message'] ??
                    $apiResult['body']['message'] ??
                    $apiResult['body']['errors'][0]['detail'] ??
                    "Error desconocido desde la API de Attio (Consulta inicial)";
    http_response_code($apiResult['status_code']);
    echo json_encode([
        "success" => false,
        "message" => $errorMessage,
        "attio_response" => $apiResult['body'],
        "status_code" => $apiResult['status_code']
    ]);
    exit();
}

$allActiveCompanies = $apiResult['body']['data'] ?? [];
$filteredCompanies = [];

// --- 2. Filtrar Compañías según Billing Cycles y Periodo ---

// *** ADVERTENCIA DE RENDIMIENTO ***
// El siguiente bucle puede realizar MUCHAS llamadas a la API de Attio,
// una por cada ciclo de facturación referenciado.
// Considera alternativas si el rendimiento es crítico.

foreach ($allActiveCompanies as $company) {
    $billingCyclesRefs = $company['values']['billing_cycles'] ?? [];
    $hasBillingCycleInPeriod = false;

    if (!empty($billingCyclesRefs)) {
        foreach ($billingCyclesRefs as $cycleRef) {
            if (!isset($cycleRef['target_record_id'])) continue;

            $billingCycleRecordId = $cycleRef['target_record_id'];
            $billingCycleEndpoint = "/objects/" . BILLING_CYCLES_OBJECT_ID . "/records/{$billingCycleRecordId}";

            // Obtener detalles del ciclo de facturación individual
            $cycleResult = makeAttioApiRequest($billingCycleEndpoint, 'GET');

            // Manejo básico de errores para esta llamada secundaria (puedes mejorarlo)
            if ($cycleResult['status_code'] >= 200 && $cycleResult['status_code'] < 300 && $cycleResult['json_last_error'] === JSON_ERROR_NONE) {
                $cycleData = $cycleResult['body']['data']['values'] ?? [];
                $paymentDueDateValues = $cycleData['payment_due_date'] ?? [];

                if (!empty($paymentDueDateValues)) {
                    // Asumimos que tomamos el primer valor de fecha de pago
                    $paymentDueDate = $paymentDueDateValues[0]['value'] ?? null;

                    // Comparar la fecha si existe
                    if ($paymentDueDate && $paymentDueDate >= $startDate && $paymentDueDate <= $endDate) {
                        $hasBillingCycleInPeriod = true;
                        // Encontramos uno en el periodo, no necesitamos chequear más para esta compañía
                        break;
                    }
                }
            } else {
                 // Opcional: Registrar error al obtener un billing_cycle específico
                 error_log("Error fetching billing cycle {$billingCycleRecordId}: Status {$cycleResult['status_code']}, CurlErr {$cycleResult['curl_errno']}, JsonErr {$cycleResult['json_last_error']}");
            }
        } // fin foreach billingCyclesRefs
    }

    // Incluir la compañía SOLO si NO tiene ningún billing cycle en el periodo especificado
    if (!$hasBillingCycleInPeriod) {
        $filteredCompanies[] = $company;
    }

} // fin foreach allActiveCompanies


// --- Devolver Datos Filtrados Exitosamente ---
http_response_code(200);
echo json_encode([
    "success" => true,
    "filter_period" => $period,
    "filter_start_date" => $startDate,
    "filter_end_date" => $endDate,
    "total_of_companies" => count($filteredCompanies), // Usar la lista filtrada
    "companies_to_billing" => $filteredCompanies
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

?>