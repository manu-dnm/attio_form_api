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

$startDateApi = $startDateOfNextMonth->format('Y-m-d');
$endDateApiExclusive = $startDateOfFollowingMonth->format('Y-m-d');
$targetMonthStr = $startDateOfNextMonth->format('Y-m');

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

// !!! --- NUEVAS CONSTANTES - ¡REEMPLAZA ESTOS VALORES! --- !!!
// Asumimos que 'COMPANY_CONTRACT_VALUE_ATTRIBUTE_SLUG' es un atributo de tipo "Currency" en Attio,
// que contiene tanto el valor como el código de moneda.
define('COMPANY_CONTRACT_VALUE_ATTRIBUTE_SLUG', 'contract_value'); // <--- ¡REEMPLAZA CON TU SLUG REAL! Ejemplo: 'contract_value', 'deal_amount', etc.
// Si la moneda es un atributo completamente separado (no parte de un campo "Currency"),
// necesitarías un slug diferente y ajustar la lógica de getAttributeValue.

// --- FUNCIÓN AUXILIAR cURL (Reutilizada) ---
function makeAttioApiRequest($url, $apiKey, $method = 'GET', $payload = null) {
    $ch = curl_init(); $headers = [ 'Authorization: Bearer ' . $apiKey, 'Accept: application/json', 'Content-Type: application/json'];
    $options = [ CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60 ];
    if (strtoupper($method) === 'POST') { $options[CURLOPT_POST] = true; if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    elseif (strtoupper($method) !== 'GET') { $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method); if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    curl_setopt_array($ch, $options); $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); $errno = curl_errno($ch); curl_close($ch);
    return ['response' => $response, 'http_code' => $httpCode, 'error' => $error, 'errno' => $errno];
}

// --- FUNCIÓN AUXILIAR getAttributeValue (EXPANDIDA) ---
function getAttributeValue($recordData, $attributeSlug, $expectedType = 'string') {
    if ($recordData === null) { return null; }
    
    $attributesSource = $recordData['values'] ?? null; // Asume que Compañía usa 'values'

    if ($attributesSource === null || !isset($attributesSource[$attributeSlug]) || !is_array($attributesSource[$attributeSlug]) || empty($attributesSource[$attributeSlug])) {
        return null;
    }

    foreach ($attributesSource[$attributeSlug] as $valueEntry) {
        if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) {
            $actualAttributeType = $valueEntry['attribute_type'] ?? null;
            switch ($expectedType) {
                case 'date':
                    try {
                        $dateValue = $valueEntry['value'] ?? null;
                        return $dateValue ? new DateTime($dateValue, new DateTimeZone('UTC')) : null;
                    } catch (Exception $e) { return null; }
                case 'number':
                    // Si el atributo es de tipo 'currency' en Attio, el valor numérico está en 'currency_value'
                    if ($actualAttributeType === 'currency') {
                        return isset($valueEntry['currency_value']) ? (float)$valueEntry['currency_value'] : null;
                    }
                    // Para otros tipos numéricos, podría estar directamente en 'value'
                    return isset($valueEntry['value']) ? (float)$valueEntry['value'] : null;
                case 'currency_code':
                    // Solo aplica si el atributo es de tipo 'currency' en Attio
                    if ($actualAttributeType === 'currency' && isset($valueEntry['currency_code'])) {
                        return $valueEntry['currency_code'];
                    }
                    return null;
                case 'string':
                default:
                    return $valueEntry['value'] ?? null;
            }
            // Si encontramos una entrada activa y la procesamos, salimos del bucle.
            // (Nota: La estructura original de Attio podría tener múltiples valores para un atributo,
            // pero usualmente solo uno es el "activo" sin active_until.
            // Esta función devuelve el primero que encuentra que cumple la condición).
            return null; 
        }
    }
    return null; // No se encontró entrada activa
}

// --- LÓGICA PRINCIPAL ---
try {
    // --- 1. Construir Payload del Filtro Combinado ---
    $filterPayload = [
        "filter" => [
            '$and' => [
                [COMPANY_STATUS_ATTRIBUTE_SLUG => ['$eq' => COMPANY_STATUS_ACTIVE_OPTION_ID]],
                [COMPANY_CONTRACT_END_DATE_SLUG => ['$gte' => $startDateApi, '$lt' => $endDateApiExclusive]]
            ]
        ],
        "limit" => 1000
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

    // --- 4. Formatear Salida ---
    $outputCompanies = [];
    $foundCompanies = $responseData['data'] ?? [];
    error_log("ActiveExpiringContracts: Encontradas " . count($foundCompanies) . " compañías activas con contrato expirando en $targetMonthStr.");

    // Preparar el formateador de fecha para español
    // Requiere la extensión intl de PHP: sudo apt-get install php-intl (o similar para tu sistema)
    $dateFormatter = null;
    if (class_exists('IntlDateFormatter')) {
        $dateFormatter = new IntlDateFormatter(
            'es_ES', // O 'es_MX' para México, etc.
            IntlDateFormatter::LONG, // Estilo de fecha (sin hora)
            IntlDateFormatter::NONE, // Estilo de hora
            'UTC', // Zona horaria de los datos originales
            IntlDateFormatter::GREGORIAN,
            'dd \'de\' MMMM \'del\' yyyy' // Patrón personalizado
        );
    } else {
        error_log("ActiveExpiringContracts: La extensión PHP intl no está instalada. Las fechas no se formatearán en español.");
    }

    foreach ($foundCompanies as $companyData) {
        $companyId = $companyData['id']['record_id'] ?? null;
        if (!$companyId) continue;

        $contractEndDateObj = getAttributeValue($companyData, COMPANY_CONTRACT_END_DATE_SLUG, 'date');
        $formattedContractEndDate = null;
        if ($contractEndDateObj && $dateFormatter) {
            $formattedContractEndDate = $dateFormatter->format($contractEndDateObj);
        } elseif ($contractEndDateObj) {
            $formattedContractEndDate = $contractEndDateObj->format('Y-m-d'); // Fallback si intl no está
        }

        // Obtener valor y moneda del contrato
        // Asumimos que COMPANY_CONTRACT_VALUE_ATTRIBUTE_SLUG es un campo tipo "Currency" en Attio
        $contractValue = getAttributeValue($companyData, COMPANY_CONTRACT_VALUE_ATTRIBUTE_SLUG, 'number');
        $contractCurrency = getAttributeValue($companyData, COMPANY_CONTRACT_VALUE_ATTRIBUTE_SLUG, 'currency_code');

        $outputCompanies[] = [
            "company_id" => $companyId,
            "name" => getAttributeValue($companyData, COMPANY_NAME_SLUG, 'string'),
            "contract_end_date_iso" => $contractEndDateObj ? $contractEndDateObj->format('Y-m-d') : null,
            "contract_end_date_formatted" => $formattedContractEndDate, // Fecha formateada en español
            "contract_value" => $contractValue,
            "contract_value_currency" => $contractCurrency
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
        // "details" => $e->getMessage() // Descomentar solo para depuración, no en producción
    ], JSON_UNESCAPED_UNICODE);
}

?>