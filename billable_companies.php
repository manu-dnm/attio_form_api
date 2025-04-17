<?php
// --- INCLUDES Y CONFIGURACIÓN INICIAL ---
include('env.php'); // Asegúrate de que este archivo contenga tu $ATTIO_API_KEY

// Habilitar CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// --- MANEJO DEL MÉTODO HTTP ---
$method = $_SERVER['REQUEST_METHOD'];

// Respuesta para preflight OPTIONS
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// Permitir solo GET
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."]);
    exit();
}

// --- CONFIGURACIÓN API ATTIO ---
$attioApiKey = $ATTIO_API_KEY;
$attioApiUrl = "https://api.attio.com/v2/objects/companies/records/query";

// Payload: Obtener TODAS las compañías con status "Active"
// El filtrado de fechas se hará después en PHP.
$payload = [
    "filter" => [
        // ID para el estado "Active" (Asegúrate que este ID es correcto para tu workspace)
        "status" => "b09e60c1-7d87-4209-883e-f28cc26743b0",
    ]
    // Podríamos añadir paginación aquí si esperamos > 100 resultados,
    // pero por ahora obtendremos el lote por defecto (usualmente 100).
    // Si necesitas más, habría que implementar lógica de paginación.
];

// --- SOLICITUD A LA API ATTIO (cURL) ---
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $attioApiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $attioApiKey,
        'Accept: application/json',
        'Content-Type: application/json'
    ],
    CURLOPT_TIMEOUT => 30 // Aumentado ligeramente por si la respuesta es grande
]);

$apiResponse = curl_exec($ch);
$httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

// --- MANEJO DE ERRORES (Red, JSON) ---
if ($curlErrno > 0) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al contactar la API de Attio",
        "error_details" => "cURL Error ({$curlErrno}): {$curlError}"
    ]);
    exit();
}

$attioData = json_decode($apiResponse, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "message" => "Respuesta inválida de la API de Attio",
        "error_details" => "JSON Error: " . json_last_error_msg(),
        "raw_response" => substr($apiResponse, 0, 1000) // Muestra parte de la respuesta cruda
    ]);
    exit();
}

// --- MANEJO DE RESPUESTA DE ATTIO ---
if ($httpStatusCode >= 200 && $httpStatusCode < 300) {
    // --- LÓGICA DE FILTRADO ADICIONAL ---
    $allActiveCompanies = $attioData['data'] ?? [];
    $billableCompanies = [];

    // Obtener fecha actual (solo la parte de la fecha, en UTC para comparar con Attio)
    // Usamos UTC porque las fechas de Attio parecen estar en UTC ('Z' al final)
    // Considera tu zona horaria si la lógica de negocio lo requiere específicamente.
    try {
         $now = new DateTime('now', new DateTimeZone('UTC'));
         $today = new DateTime($now->format('Y-m-d'), new DateTimeZone('UTC')); // Medianoche UTC de hoy
    } catch (Exception $e) {
        // Fallback muy improbable, pero seguro.
         http_response_code(500);
         echo json_encode([
            "success" => false,
            "message" => "Error al inicializar la fecha actual.",
            "error_details" => $e->getMessage()
         ]);
         exit();
    }


    // Función auxiliar para obtener el valor actual de un atributo de tipo fecha/timestamp
    function getCurrentDateValue($company, $attributeSlug): ?DateTime {
        if (!isset($company['values'][$attributeSlug]) || empty($company['values'][$attributeSlug])) {
            return null; // Atributo no existe o está vacío
        }
        $dateStr = null;
        foreach ($company['values'][$attributeSlug] as $valueEntry) {
            if ($valueEntry['active_until'] === null) {
                 // Asume que el valor es una cadena de fecha (Y-m-d) o timestamp (ISO 8601)
                $dateStr = $valueEntry['value'] ?? null;
                break;
            }
        }
        if ($dateStr === null) {
            return null; // No hay valor activo
        }
        try {
            // Intentar parsear la fecha/timestamp. Attio usa UTC ('Z').
            return new DateTime($dateStr, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            // Loggear error si es necesario, pero devolver null si el formato es inválido
            // error_log("Formato de fecha inválido para {$attributeSlug}: {$dateStr} en registro {$company['id']['record_id']}");
            return null;
        }
    }

    // Iterar sobre las compañías activas y aplicar filtros
    foreach ($allActiveCompanies as $company) {
        $contractEndDate = getCurrentDateValue($company, 'contract_end_date');
        $lastBillingDate = getCurrentDateValue($company, 'last_billing_cycle_date');

        // Condición 1: contract_end_date debe estar en el pasado (< $today) o no existir (null)
        $contractConditionMet = ($contractEndDate === null || $contractEndDate < $today);

        // Condición 2: last_billing_cycle_date debe estar en el mes en curso o pasado (<= $today) o no existir (null)
        $billingConditionMet = ($lastBillingDate === null || $lastBillingDate <= $today);

        // Si ambas condiciones se cumplen, añadir la compañía a la lista final
        if ($contractConditionMet && $billingConditionMet) {
            $billableCompanies[] = $company;
        }
    }

    // --- RESPUESTA EXITOSA (CON DATOS FILTRADOS) ---
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "total" => count($billableCompanies), // El total de compañías que cumplen AMBOS filtros
        "billable_companies" => $billableCompanies // La lista filtrada
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} else {
    // --- MANEJO DE ERRORES DESDE LA API DE ATTIO ---
    $errorMessage = $attioData['error']['message'] ??
                    $attioData['message'] ??
                    $attioData['errors'][0]['detail'] ??
                    "Error desconocido desde la API de Attio";

    http_response_code($httpStatusCode);
    echo json_encode([
        "success" => false,
        "message" => $errorMessage,
        "attio_response" => $attioData, // Incluye la respuesta completa de Attio para depuración
        "status_code" => $httpStatusCode
    ]);
}

?>