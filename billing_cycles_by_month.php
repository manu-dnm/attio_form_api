<?php
// get_billing_cycles_by_due_date.php (v3 - Mimics CURL example filter)

// --- INCLUDES Y CONFIGURACIÓN INICIAL ---
include('env.php'); // Contiene $ATTIO_API_KEY
date_default_timezone_set('UTC'); // Trabajar en UTC

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// --- MANEJO DEL MÉTODO HTTP ---
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit(); }
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."], JSON_UNESCAPED_UNICODE);
    exit();
}

// --- OBTENER MES Y CALCULAR RANGO DE FECHAS (Estilo CURL) ---
$targetMonthStr = $_GET['month'] ?? null; // Formato esperado YYYY-MM
$now = new DateTime('now', new DateTimeZone('UTC'));

if (!empty($targetMonthStr) && preg_match('/^\d{4}-\d{2}$/', $targetMonthStr)) {
    // Usar el mes proporcionado
    try {
        $startDate = new DateTime($targetMonthStr . '-01 00:00:00', new DateTimeZone('UTC'));
        // Calcular ÚLTIMO DÍA del mes target a las 23:59:59
        $endDate = (clone $startDate)->modify('last day of this month')->setTime(23, 59, 59);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Formato de mes inválido. Usar YYYY-MM."], JSON_UNESCAPED_UNICODE);
        exit();
    }
} else {
    // Default: Mes SIGUIENTE al actual
    $startDate = (clone $now)->modify('first day of next month')->setTime(0, 0, 0);
    // Calcular ÚLTIMO DÍA del mes siguiente a las 23:59:59
    $endDate = (clone $startDate)->modify('last day of this month')->setTime(23, 59, 59);
    $targetMonthStr = $startDate->format('Y-m');
}

// Formato YYYY-MM-DDTHH:MM:SS (como en el ejemplo curl)
$startDateApi = $startDate->format('Y-m-d\TH:i:s');
$endDateApi = $endDate->format('Y-m-d\TH:i:s');

error_log("GetBillingCycles: Buscando ciclos con due_date >= $startDateApi Y <= $endDateApi (para mes $targetMonthStr)");

// --- CONFIGURACIÓN API ATTIO ---
$attioApiKey = $ATTIO_API_KEY ?? null;
if (empty($attioApiKey)) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error: \$ATTIO_API_KEY no definida."], JSON_UNESCAPED_UNICODE);
    exit();
}
$attioApiBaseUrl = "https://api.attio.com/v2";

// --- CONSTANTES NECESARIAS ---
define('BILLING_CYCLE_OBJECT_SLUG', 'billing_cycles');
// Confirmado por el usuario que el tipo es 'Date' y el slug es 'payment_due_date'
define('BILLING_CYCLE_PAYMENT_DUE_DATE_SLUG', 'payment_due_date');

// --- FUNCIÓN AUXILIAR cURL (Reutilizada) ---
function makeAttioApiRequest($url, $apiKey, $method = 'GET', $payload = null) {
    $ch = curl_init(); $headers = [ 'Authorization: Bearer ' . $apiKey, 'Accept: application/json', 'Content-Type: application/json'];
    $options = [ CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60 ];
    if (strtoupper($method) === 'POST') { $options[CURLOPT_POST] = true; if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    elseif (strtoupper($method) !== 'GET') { $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method); if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    curl_setopt_array($ch, $options); $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); $errno = curl_errno($ch); curl_close($ch);
    return ['response' => $response, 'http_code' => $httpCode, 'error' => $error, 'errno' => $errno];
}

// --- LÓGICA PRINCIPAL ---
try {
    // --- Construir Payload del Filtro (usando $gte y $lte, formato con T) ---
    // $filterPayload = [
    //     "filter" => [
    //         BILLING_CYCLE_PAYMENT_DUE_DATE_SLUG => [
    //             '$gte' => $startDateApi, // "YYYY-MM-DDTHH:MM:SS"
    //             '$lte' => $endDateApi    // "YYYY-MM-DDT23:59:59"
    //         ]
    //     ],
    //     "limit" => 1000
    // ];

    $filterPayload = [
        "filter" => [
            BILLING_CYCLE_PAYMENT_DUE_DATE_SLUG => [
                '$gte' => '2025-05-01', // Usar solo igualdad con formato YYYY-MM-DD
                '$lte' => '2025-05-31', // Usar solo igualdad con formato YYYY-MM-DD
            ]
        ],
        "limit" => 1000 // Límite pequeño para la prueba
    ];

    // --- Hacer la llamada API ---
    $billingCyclesUrl = "{$attioApiBaseUrl}/objects/" . BILLING_CYCLE_OBJECT_SLUG . "/records/query";
    error_log("GetBillingCycles: Llamando API: POST $billingCyclesUrl con payload: " . json_encode($filterPayload)); // Loguear payload exacto
    $bcResult = makeAttioApiRequest($billingCyclesUrl, $attioApiKey, 'POST', $filterPayload);

    // --- Manejar Respuesta ---
    if ($bcResult['errno'] > 0) {
        throw new Exception("Error cURL (BillingCycles): " . $bcResult['error'], 500);
    }

    $billingCyclesResponse = json_decode($bcResult['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Si la respuesta no es JSON válido (podría ser un error HTML de Attio?)
        error_log("GetBillingCycles: Respuesta API no es JSON válido. Código: {$bcResult['http_code']}. Respuesta: {$bcResult['response']}");
        throw new Exception("Respuesta inesperada de la API (no JSON). Código: {$bcResult['http_code']}", 502);
    }

    // Comprobar si Attio devolvió un error estructurado dentro del JSON
    if ($bcResult['http_code'] < 200 || $bcResult['http_code'] >= 300) {
        http_response_code($bcResult['http_code']);
        echo json_encode(["success" => false, "message" => "Error de API Attio", "attio_error" => $billingCyclesResponse], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // Éxito
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "filter_month" => $targetMonthStr,
        "filter_start_date" => $startDateApi,
        "filter_end_date_inclusive" => $endDateApi, // Cambiado nombre para claridad
        "total_of_billings" => count($billingCyclesResponse['data']),
        "data" => $billingCyclesResponse['data'] ?? []
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);


} catch (Exception $e) {
    $errorCode = $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($errorCode);
    error_log("Error en get_billing_cycles_by_due_date.php: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
    echo json_encode([
        "success" => false,
        "message" => "Ocurrió un error interno procesando la solicitud.",
        "details" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

?>