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
$attioApiKey = $ATTIO_API_KEY ?? null; // Para leer datos
if (empty($attioApiKey)) { http_response_code(500); echo json_encode(["success" => false, "message" => "API Key de lectura no configurada en env.php."], JSON_UNESCAPED_UNICODE); exit(); }

// !!! NUEVA API KEY PARA CREACIÓN DE DEALS - ¡RECOMENDACIÓN: Mover a env.php! !!!
define('DEAL_CREATION_ATTIO_API_KEY', '8e9293b857c0e9bfd3994e53c77bf68a51b89dcc87ed1bef87128019b50fa1e3');
$dealCreationApiKey = DEAL_CREATION_ATTIO_API_KEY; 

$attioApiBaseUrl = "https://api.attio.com/v2";

// --- DEFINICIÓN DE CONSTANTES RELEVANTES ---
// Compañías
define('COMPANY_OBJECT_SLUG', 'companies');
define('COMPANY_STATUS_ATTRIBUTE_SLUG', 'status');
define('COMPANY_STATUS_ACTIVE_OPTION_ID', 'b09e60c1-7d87-4209-883e-f28cc26743b0');
define('COMPANY_CONTRACT_END_DATE_SLUG', 'contract_end_date');
define('COMPANY_NAME_SLUG', 'name');
define('COMPANY_CONTRACT_VALUE_ATTRIBUTE_SLUG', 'contract_value'); // Slug del atributo de valor de contrato en Compañías
define('COMPANY_LINKED_PEOPLE_ATTRIBUTE_SLUG', 'team'); // Slug del atributo en Compañías que linkea a Personas/Usuarios

// Deals
define('DEAL_OBJECT_SLUG', 'deals');
define('DEAL_NAME_ATTRIBUTE_SLUG', 'name'); 
define('DEAL_STAGE_ATTRIBUTE_SLUG', 'stage'); 
define('DEAL_OWNER_ATTRIBUTE_SLUG', 'owner'); 
define('DEAL_VALUE_ATTRIBUTE_SLUG', 'value'); 
define('DEAL_CURRENCY_TYPE_ATTRIBUTE_SLUG', 'currency_type'); 
define('DEAL_ASSOCIATED_COMPANY_SLUG', 'associated_company'); 
define('DEAL_ASSOCIATED_PEOPLE_SLUG', 'associated_people'); 

// Valores estáticos para la creación de Deals (IDs) - Estos deben ser correctos y funcionales
define('DEAL_STAGE_ID_RENEWAL', '7a81178c-af2a-434d-9ff5-4303e99b4bf1');
define('DEAL_OWNER_ID_STATIC', '006198d4-5a9d-4624-bf6f-c137a54250a5');


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
    $attributesSource = $recordData['values'] ?? ($recordData['attributes'] ?? null);
    if ($attributesSource === null || !isset($attributesSource[$attributeSlug]) || !is_array($attributesSource[$attributeSlug]) || empty($attributesSource[$attributeSlug])) {
        return null;
    }
    foreach ($attributesSource[$attributeSlug] as $valueEntry) {
        if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) {
            $actualAttributeType = $valueEntry['attribute_type'] ?? null;
            switch ($expectedType) {
                case 'date':
                    try { $dateValue = $valueEntry['value'] ?? null; return $dateValue ? new DateTime($dateValue, new DateTimeZone('UTC')) : null; } catch (Exception $e) { return null; }
                case 'number': // Devuelve float
                    if ($actualAttributeType === 'currency') { return isset($valueEntry['currency_value']) ? (float)$valueEntry['currency_value'] : null; }
                    return isset($valueEntry['value']) ? (float)$valueEntry['value'] : null;
                case 'currency_code': // Devuelve string
                    if ($actualAttributeType === 'currency' && isset($valueEntry['currency_code'])) { return $valueEntry['currency_code']; }
                    return null;
                case 'string': default: return $valueEntry['value'] ?? null;
            }
            return null; 
        }
    }
    return null;
}

// --- FUNCIÓN AUXILIAR getLinkedRecordIds (AÑADIDA) ---
function getLinkedRecordIds($recordData, $attributeSlug) {
    $attributesSource = $recordData['values'] ?? ($recordData['attributes'] ?? null);
    if ($attributesSource === null || !isset($attributesSource[$attributeSlug]) || !is_array($attributesSource[$attributeSlug]) || empty($attributesSource[$attributeSlug])) {
        return []; 
    }
    $ids = [];
    foreach ($attributesSource[$attributeSlug] as $valueEntry) {
        if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) {
            if (isset($valueEntry['target_record_id']) && is_string($valueEntry['target_record_id'])) {
                $ids[] = $valueEntry['target_record_id'];
            }
            elseif (isset($valueEntry['target_records']) && is_array($valueEntry['target_records'])) {
                foreach ($valueEntry['target_records'] as $target) {
                    if (isset($target['target_record_id']) && is_string($target['target_record_id'])) {
                        $ids[] = $target['target_record_id'];
                    }
                }
            }
        }
    }
    return array_values(array_unique($ids)); 
}


// --- LÓGICA PRINCIPAL ---
try {
    // --- 1. Construir Payload del Filtro Combinado para obtener Compañías ---
    $filterPayload = [
        "filter" => [
            '$and' => [
                [COMPANY_STATUS_ATTRIBUTE_SLUG => ['$eq' => COMPANY_STATUS_ACTIVE_OPTION_ID]],
                [COMPANY_CONTRACT_END_DATE_SLUG => ['$gte' => $startDateApi, '$lt' => $endDateApiExclusive]]
            ]
        ],
        "limit" => 1000 
    ];

    // --- 2. Hacer la llamada API para obtener Compañías ---
    $companiesUrl = "{$attioApiBaseUrl}/objects/" . COMPANY_OBJECT_SLUG . "/records/query";
    error_log("ActiveExpiringContracts: Llamando API para obtener compañías: POST $companiesUrl con payload: " . json_encode($filterPayload));
    $companiesResult = makeAttioApiRequest($companiesUrl, $attioApiKey, 'POST', $filterPayload); 

    // --- 3. Manejar Respuesta de Compañías ---
    if ($companiesResult['errno'] > 0) { throw new Exception("Error cURL (Obteniendo Compañías): " . $companiesResult['error'], 500); }
    $companiesResponseData = json_decode($companiesResult['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("ActiveExpiringContracts: Respuesta API de Compañías no es JSON válido. Código: {$companiesResult['http_code']}. Respuesta: {$companiesResult['response']}");
        throw new Exception("Respuesta inesperada de API Compañías (no JSON). Código: {$companiesResult['http_code']}", 502);
    }
    if ($companiesResult['http_code'] < 200 || $companiesResult['http_code'] >= 300) {
        http_response_code($companiesResult['http_code']);
        echo json_encode(["success" => false, "message" => "Error de API Attio (Obteniendo Compañías)", "attio_error" => $companiesResponseData], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    // --- 4. Formatear Salida y Crear Deals ---
    $outputCompanies = [];
    $foundCompanies = $companiesResponseData['data'] ?? [];
    error_log("ActiveExpiringContracts: Encontradas " . count($foundCompanies) . " compañías activas con contrato expirando en $targetMonthStr.");

    $dateFormatter = null;
    if (class_exists('IntlDateFormatter')) {
        $dateFormatter = new IntlDateFormatter('es_ES', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, 'dd \'de\' MMMM \'del\' finalList');
    } else {
        error_log("ActiveExpiringContracts: La extensión PHP intl no está instalada. Las fechas no se formatearán en español.");
    }

    $dealsApiUrl = "{$attioApiBaseUrl}/objects/" . DEAL_OBJECT_SLUG . "/records";

    foreach ($foundCompanies as $companyData) {
        $companyId = $companyData['id']['record_id'] ?? null;
        if (!$companyId) continue;

        $companyName = getAttributeValue($companyData, COMPANY_NAME_SLUG, 'string') ?? 'N/A';
        $contractEndDateObj = getAttributeValue($companyData, COMPANY_CONTRACT_END_DATE_SLUG, 'date');
        $formattedContractEndDate = null;
        if ($contractEndDateObj && $dateFormatter) { $formattedContractEndDate = $dateFormatter->format($contractEndDateObj); } 
        elseif ($contractEndDateObj) { $formattedContractEndDate = $contractEndDateObj->format('Y-m-d'); }

        $contractValueFloat = getAttributeValue($companyData, COMPANY_CONTRACT_VALUE_ATTRIBUTE_SLUG, 'number'); // Sigue siendo float aquí
        $contractCurrency = getAttributeValue($companyData, COMPANY_CONTRACT_VALUE_ATTRIBUTE_SLUG, 'currency_code');
        $linkedPeopleIds = getLinkedRecordIds($companyData, COMPANY_LINKED_PEOPLE_ATTRIBUTE_SLUG);

        $createdDealInfo = null; 

        // --- Crear el Deal ---
        // Validar que tenemos los datos necesarios antes de intentar crear el deal
        if ($companyName !== 'N/A' && $contractValueFloat !== null && $contractCurrency !== null) {
            $dealPayloadValues = [
                DEAL_NAME_ATTRIBUTE_SLUG => "{$companyName} RENEWAL",
                DEAL_STAGE_ATTRIBUTE_SLUG => DEAL_STAGE_ID_RENEWAL,
                DEAL_OWNER_ATTRIBUTE_SLUG => DEAL_OWNER_ID_STATIC,
                // !!! CORRECCIÓN IMPORTANTE: Enviar 'value' como STRING !!!
                DEAL_VALUE_ATTRIBUTE_SLUG => (string)$contractValueFloat, 
                DEAL_ASSOCIATED_COMPANY_SLUG => [$companyId] 
            ];
            
            if (!empty($linkedPeopleIds)) {
                $dealPayloadValues[DEAL_ASSOCIATED_PEOPLE_SLUG] = $linkedPeopleIds; 
            }

            if ($contractCurrency !== null) {
                 $dealPayloadValues[DEAL_CURRENCY_TYPE_ATTRIBUTE_SLUG] = $contractCurrency;
            }

            $dealPayload = ["data" => ["values" => $dealPayloadValues]];
            
            error_log("ActiveExpiringContracts: Creando Deal para {$companyName}. Payload: " . json_encode($dealPayload));
            $dealResult = makeAttioApiRequest($dealsApiUrl, $dealCreationApiKey, 'POST', $dealPayload); 

            if ($dealResult['errno'] > 0) {
                error_log("ActiveExpiringContracts: Error cURL creando Deal para {$companyName}: " . $dealResult['error']);
                $createdDealInfo = ["error" => "Error cURL: " . $dealResult['error']];
            } else {
                $dealResponseData = json_decode($dealResult['response'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if ($dealResult['http_code'] >= 200 && $dealResult['http_code'] < 300) {
                        $createdDealId = $dealResponseData['data']['id']['record_id'] ?? null;
                        if ($createdDealId) {
                            $createdDealInfo = ["deal_id" => $createdDealId, "status" => "creado"];
                            error_log("ActiveExpiringContracts: Deal creado para {$companyName} con ID: {$createdDealId}");
                        } else {
                            error_log("ActiveExpiringContracts: Deal creado para {$companyName} pero no se pudo extraer ID. Respuesta: " . $dealResult['response']);
                            $createdDealInfo = ["error" => "Deal creado, ID no encontrado en respuesta", "response_code" => $dealResult['http_code']];
                        }
                    } else {
                        error_log("ActiveExpiringContracts: Error API creando Deal para {$companyName}. Código: {$dealResult['http_code']}. Respuesta: " . $dealResult['response']);
                        $createdDealInfo = ["error" => "Error API", "response_code" => $dealResult['http_code'], "details" => $dealResponseData];
                    }
                } else {
                    error_log("ActiveExpiringContracts: Respuesta de creación de Deal no es JSON válido para {$companyName}. Código: {$dealResult['http_code']}. Respuesta: {$dealResult['response']}");
                    $createdDealInfo = ["error" => "Respuesta API no JSON", "response_code" => $dealResult['http_code']];
                }
            }
        } else {
            error_log("ActiveExpiringContracts: Saltando creación de Deal para {$companyName} (ID: {$companyId}) por falta de datos (nombre, valor o moneda del contrato).");
            $createdDealInfo = ["status" => "omitido", "reason" => "Faltan datos de contrato (nombre, valor o moneda)"];
        }
        // --- Fin Crear el Deal ---

        $outputCompanies[] = [
            "company_id" => $companyId,
            "name" => $companyName,
            "contract_end_date_iso" => $contractEndDateObj ? $contractEndDateObj->format('Y-m-d') : null,
            "contract_end_date_formatted" => $formattedContractEndDate, 
            "contract_value" => $contractValueFloat, // Devolvemos el float en nuestra API
            "contract_value_currency" => $contractCurrency,
            "linked_people_ids" => $linkedPeopleIds,
            "deal_creation_info" => $createdDealInfo 
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
    $errorCode = $e->getCode();
    if (!is_int($errorCode) || $errorCode < 400 || $errorCode > 599) {
        $errorCode = 500; 
    }
    http_response_code($errorCode);
    error_log("Error en active_companies_expiring_contracts.php: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . " (Código: {$e->getCode()})");
    echo json_encode([
        "success" => false,
        "message" => "Ocurrió un error interno procesando la solicitud.",
    ], JSON_UNESCAPED_UNICODE);
}

?>
