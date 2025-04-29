<?php
// active_companies_expiring_contracts.php

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

// --- CALCULAR RANGO DE FECHAS PARA EL MES SIGUIENTE ---
$now = new DateTime('now', new DateTimeZone('UTC'));
$startDateOfNextMonth = (clone $now)->modify('first day of next month')->setTime(0, 0, 0);
$startDateOfFollowingMonth = (clone $startDateOfNextMonth)->modify('+1 month');

// Formato YYYY-MM-DD para el filtro (confirmado que funciona para tipo Date)
$startDateApi = $startDateOfNextMonth->format('Y-m-d');
$endDateApiExclusive = $startDateOfFollowingMonth->format('Y-m-d');
$targetMonthStr = $startDateOfNextMonth->format('Y-m'); // Para informar en la respuesta

error_log("ActiveExpiringContracts: Buscando compañías ACTIVAS con contract_end_date >= $startDateApi Y < $endDateApiExclusive (para mes $targetMonthStr)");

// --- CONFIGURACIÓN API ATTIO ---
$attioApiKey = $ATTIO_API_KEY ?? null;
if (empty($attioApiKey)) { http_response_code(500); echo json_encode(["success" => false, "message" => "API Key no configurada."], JSON_UNESCAPED_UNICODE); exit(); }
$attioApiBaseUrl = "https://api.attio.com/v2";

// --- DEFINICIÓN DE CONSTANTES RELEVANTES ---
define('COMPANY_OBJECT_SLUG', 'companies');
define('COMPANY_STATUS_ATTRIBUTE_SLUG', 'status');
define('COMPANY_STATUS_ACTIVE_OPTION_ID', 'b09e60c1-7d87-4209-883e-f28cc26743b0'); // ¡Verifica que este ID siga siendo el de "Active"!
define('COMPANY_CONTRACT_END_DATE_SLUG', 'contract_end_date');
define('COMPANY_NAME_SLUG', 'name');

// --- FUNCIÓN AUXILIAR cURL (Reutilizada) ---
function makeAttioApiRequest($url, $apiKey, $method = 'GET', $payload = null) {
    $ch = curl_init(); $headers = [ 'Authorization: Bearer ' . $apiKey, 'Accept: application/json', 'Content-Type: application/json'];
    $options = [ CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60 ];
    if (strtoupper($method) === 'POST') { $options[CURLOPT_POST] = true; if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    elseif (strtoupper($method) !== 'GET') { $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method); if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    curl_setopt_array($ch, $options); $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); $errno = curl_errno($ch); curl_close($ch);
    return ['response' => $response, 'http_code' => $httpCode, 'error' => $error, 'errno' => $errno];
}

// --- FUNCIÓN AUXILIAR getAttributeValue (Reutilizada - versión simplificada necesaria aquí) ---
// (Se incluye una versión simplificada porque solo necesitamos 'string' y 'date' aquí)
function getAttributeValue($recordData, $attributeSlug, $expectedType = 'string') {
    if ($recordData === null) { return null; }
    // Asume que Compañía usa 'values'
    $attributesSource = $recordData['values'] ?? null;
    if ($attributesSource === null || !isset($attributesSource[$attributeSlug]) || !is_array($attributesSource[$attributeSlug]) || empty($attributesSource[$attributeSlug])) { return null; }
    foreach ($attributesSource[$attributeSlug] as $valueEntry) {
        if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) {
             switch ($expectedType) {
                 case 'date': try { $dateValue = $valueEntry['value'] ?? null; return $dateValue ? new DateTime($dateValue, new DateTimeZone('UTC')) : null; } catch (Exception $e) { return null; }
                 case 'string': default: return $valueEntry['value'] ?? null;
             }
             return null; // Salir si se procesó la entrada activa
        }
    } return null; // No se encontró entrada activa
}

// --- LÓGICA PRINCIPAL ---
try {
    // --- 1. Construir Payload del Filtro Combinado ---
    $filterPayload = [
        "filter" => [
            '$and' => [ // Operador lógico AND para combinar condiciones
                [ // Condición 1: Status es Active
                    COMPANY_STATUS_ATTRIBUTE_SLUG => [
                        '$eq' => COMPANY_STATUS_ACTIVE_OPTION_ID
                    ]
                ],
                [ // Condición 2: Contract End Date está en el rango del mes siguiente
                    COMPANY_CONTRACT_END_DATE_SLUG => [
                        '$gte' => $startDateApi,       // Mayor o igual que YYYY-MM-DD (inicio mes sig.)
                        '$lt' => $endDateApiExclusive  // Menor que YYYY-MM-DD (inicio mes subsig.)
                    ]
                ]
            ]
        ],
        "limit" => 1000 // Poner un límite razonable
    ];

    // --- 2. Hacer la llamada API ---
    $companiesUrl = "{$attioApiBaseUrl}/objects/" . COMPANY_OBJECT_SLUG . "/records/query";
    error_log("ActiveExpiringContracts: Llamando API: POST $companiesUrl con payload: " . json_encode($filterPayload));
    $result = makeAttioApiRequest($companiesUrl, $attioApiKey, 'POST', $filterPayload);

    // --- 3. Manejar Respuesta ---
    if ($result['errno'] > 0) {
        throw new Exception("Error cURL (ActiveExpiringContracts): " . $result['error'], 500);
    }

    $responseData = json_decode($result['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("ActiveExpiringContracts: Respuesta API no es JSON válido. Código: {$result['http_code']}. Respuesta: {$result['response']}");
        throw new Exception("Respuesta inesperada de la API (no JSON). Código: {$result['http_code']}", 502);
    }

    if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
        http_response_code($result['http_code']);
        echo json_encode(["success" => false, "message" => "Error de API Attio", "attio_error" => $responseData], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --- 4. Formatear Salida (Simplificada) ---
    $outputCompanies = [];
    $foundCompanies = $responseData['data'] ?? [];
    error_log("ActiveExpiringContracts: Encontradas " . count($foundCompanies) . " compañías activas con contrato expirando en $targetMonthStr.");

    foreach ($foundCompanies as $companyData) {
        $companyId = $companyData['id']['record_id'] ?? null; // Asume estructura ID Compañía
        if (!$companyId) continue;

        $contractEndDateObj = getAttributeValue($companyData, COMPANY_CONTRACT_END_DATE_SLUG, 'date');

        $outputCompanies[] = [
            "company_id" => $companyId,
            "name" => getAttributeValue($companyData, COMPANY_NAME_SLUG, 'string'),
            "contract_end_date" => $contractEndDateObj ? $contractEndDateObj->format('Y-m-d') : null // Devolver fecha simple
            // Puedes añadir más campos aquí si los necesitas
        ];
    }

    // --- 5. Respuesta Final ---
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "target_month" => $targetMonthStr,
        "total_of_companies" => count($outputCompanies),
        "expiring_companies" => $outputCompanies
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);


} catch (Exception $e) {
    $errorCode = $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($errorCode);
    error_log("Error en active_companies_expiring_contracts.php: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
    echo json_encode([
        "success" => false,
        "message" => "Ocurrió un error interno procesando la solicitud.",
        "details" => $e->getMessage() // Opcional para depuración
    ], JSON_UNESCAPED_UNICODE);
}

?>