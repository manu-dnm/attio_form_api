<?php

// --- CONFIGURACIÓN DE ERRORES ---
ini_set('display_errors', 0); // No mostrar errores PHP al cliente
ini_set('log_errors', 1);     // Registrar errores en el servidor
// ini_set('error_log', '/ruta/absoluta/a/tu/php-error.log'); // Opcional: Especificar archivo
error_reporting(E_ALL);
// --- FIN CONFIGURACIÓN DE ERRORES ---


// --- CONFIGURACIÓN Y DEFINICIONES DE ATTIO ---

// --- ¡¡¡ SEGURIDAD !!! ---
// ¡¡ NUNCA USES LA CLAVE DIRECTAMENTE EN CÓDIGO DE PRODUCCIÓN !!
// ¡¡ USA VARIABLES DE ENTORNO U OTRO ARCHIVO DE CONFIGURACIÓN SEGURO !!
define('ATTIO_API_KEY', 'b201f2d5e696252ff74f9e564683e2c9909f4c2e76cfc8793333274824649056'); // <-- ¡¡ REEMPLAZA Y PROTEGE !!
// --- FIN SEGURIDAD ---

// --- Slugs de Objetos ---
define('SLUG_COMPANIES', 'companies');
define('SLUG_DEALS', 'deals');
define('SLUG_PEOPLE', 'people');
define('SLUG_USERS', 'users');
define('SLUG_BILLING_CYCLES', 'billing_cycles');

// --- IDs/Slugs de Atributos (¡VERIFICA ESTOS!) ---
// Compañía
define('ATTR_COMPANY_DOMAINS', 'domains');
define('ATTR_COMPANY_NAME', 'name');
define('ATTR_COMPANY_TEAM', 'team');
define('ATTR_COMPANY_ASSOC_DEALS', 'associated_deals');
define('ATTR_COMPANY_STATUS', 'cffdf160-2d05-4d09-bdc8-7353fb0dc79d'); // Attribute ID (Confirmado)
define('ATTR_COMPANY_LEGAL_NAME', 'company_legal_name');
define('ATTR_COMPANY_START_DATE', 'dbb9a7ef-9fa3-4abc-9624-02f4ed71ebc6'); // Onboarding Date (Confirmado)
define('ATTR_COMPANY_END_DATE', 'contract_end_date');
define('ATTR_COMPANY_PAYMENT_TERMS', 'payment_terms_2');
define('ATTR_COMPANY_PLAN', 'plan'); // Confirmado

// Deal
define('ATTR_DEAL_VALUE', 'value');
define('ATTR_DEAL_START_DATE', 'contract_start_date');
define('ATTR_DEAL_END_DATE', 'contract_end_date');
define('ATTR_DEAL_ASSOC_COMPANY', 'associated_company');
// define('ATTR_DEAL_ASSOC_PEOPLE', 'associated_people'); // Omitido según solicitud

// People
define('ATTR_PERSON_NAME', 'name');
define('ATTR_PERSON_EMAILS', 'email_addresses');
define('ATTR_PERSON_PHONES', 'phone_numbers');
define('ATTR_PERSON_COMPANY', 'company'); // Relación Persona -> Compañía

// User
define('ATTR_USER_PERSON', 'person');
define('ATTR_USER_EMAIL', 'primary_email_address');
define('ATTR_USER_COMPANY', 'company');
define('ATTR_USER_TYPE_ROLES', 'type'); // Roles (Multi-Select)

// Billing Cycles
define('ATTR_BILLING_NAME', 'name');
define('ATTR_BILLING_USERS', 'users');
define('ATTR_BILLING_STATUS', 'status'); // Asumiendo slug 'status' aquí también
define('ATTR_BILLING_COMPANY', 'company');
define('ATTR_BILLING_DUE_DATE', 'payment_due_date');
define('ATTR_BILLING_DISCOUNT', 'discount');
define('ATTR_BILLING_AMOUNT_GROSS', 'amount_4');
define('ATTR_BILLING_AMOUNT_NET', 'amount');
define('ATTR_BILLING_CURRENCY', 'amount_currency');
// ATTR_BILLING_RELATED_DEAL eliminado según solicitud

// --- Option IDs (¡¡ ASEGÚRATE DE COMPLETAR ESTOS MAPS CON TODOS TUS IDs !!) ---
define('OPTION_ID_COMPANY_STATUS_ACTIVE', 'b09e60c1-7d87-4209-883e-f28cc26743b0');
define('MAP_PAYMENT_PERIOD_TO_OPTION_ID', [
    '0'   => '530c027e-a59e-4010-b310-b425b0b8db74', // Un solo pago
    '0.5' => 'f47af600-05f2-4a08-b973-f5099f6c35ab', // Quincenal
    '1'   => '98629679-7cf0-4a17-86ad-c86591d94d4c', // Mensual
    '2'   => '65bf6169-af63-41c5-a954-41369e848eb1', // Bimestral
    '3'   => 'adda67d6-1fd8-4c71-989e-039e93c248df', // Trimestral
    '4'   => '79b0ba8d-faa3-4770-a500-33c7ee45e5c8', // Cuatrimestral
    '6'   => 'bc7c0c12-4ae8-4bfb-a8ee-d8c1a59da354', // Semestral
    '12'  => 'b934a12d-4e9e-40b4-bc5b-e2c12a95ee24'  // Anual
]);
define('MAP_PLAN_TO_OPTION_ID', [
    'essential' => '6963a930-bda1-4014-b20c-5a970051cb81',
    'pro'       => '6c9e6dbd-c07d-4ca8-9c55-0d0dc8194fb4',
    'business'  => 'fee1bfe7-047a-42e3-a9d8-583a303fe513'
]);
define('OPTION_ID_USER_ROLE_OWNER', '04c94fe3-5959-4650-9b52-a4f47d0bec39');
define('OPTION_ID_USER_ROLE_FINANCE', '7e8205f5-edb5-42d9-a76a-4da5b6390b65');
define('OPTION_ID_USER_ROLE_POC', '03e48b3e-c162-45e7-868d-ca68556fa916');
define('OPTION_ID_BILLING_STATUS_NEW', 'b5706c40-0eb8-4cd5-94d2-1800487603c2');
define('MAP_CURRENCY_TO_OPTION_ID', [
    'MXN' => '617ee091-1a25-4455-a18e-2e35e488eae6',
    'USD' => '9bd3c21d-a67e-46fa-9a33-b32a05af8254',
    'CAD' => '1a784617-eacf-4dcf-b052-1487ab30735f'
]);

// --- FIN CONFIGURACIÓN ---

// --- HEADERS Y CHEQUEOS INICIALES ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
date_default_timezone_set('America/Mexico_City'); // Importante para cálculos de fecha

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit(); }
if ($method !== 'POST') { finalResponse(['success' => false, 'message' => 'Método no permitido.'], 405); }

$inputJSON = file_get_contents('php://input');
$inputData = json_decode($inputJSON, true);

// Validar JSON básico y deal_id (ahora requerido según lo confirmado)
if (json_last_error() !== JSON_ERROR_NONE || !is_array($inputData) || empty($inputData['deal_id'])) {
    finalResponse(['success' => false, 'message' => 'Cuerpo de la petición inválido o falta deal_id.', 'details' => json_last_error_msg()], 400);
}
// --- FIN HEADERS Y CHEQUEOS INICIALES ---


// --- FUNCIONES HELPER ---
function finalResponse($data, $status = 200) {
    // Asegurarse que no haya salida previa
    if (!headers_sent()) {
        http_response_code($status);
    }
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

function makeAttioApiCall($method, $endpoint, $apiKey, $payloadValues = null) {
    $url = "https://api.attio.com" . $endpoint;
    $ch = curl_init();
    $headers = ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'];
    $jsonData = null;

    // Envolver payloadValues en data.values para POST/PATCH
    if ($payloadValues !== null && ($method === 'POST' || $method === 'PATCH')) {
        if (!empty($payloadValues)) {
            // Construir el payload final
            $finalPayload = ['data' => ['values' => $payloadValues]];
            $jsonData = json_encode($finalPayload);
            if ($jsonData === false) {
                 return ['status' => 500, 'data' => null, 'error_message' => 'Error interno al codificar payload JSON: ' . json_last_error_msg(), 'curl_error' => null];
            }
            // Log para depurar payload enviado (opcional, quitar en prod)
             error_log("DEBUG Attio API Request: {$method} {$endpoint} Payload: " . $jsonData);
        } else {
              // Si es PATCH y no hay valores, no hacer la llamada (o devolver éxito vacío)
              if ($method === 'PATCH') {
                   return ['status' => 200, 'data' => ['message'=>'Payload vacío, no se realizó la llamada PATCH.'], 'error_message' => null, 'curl_error' => null];
              }
              $jsonData = '{}'; // Cuerpo vacío para POST si no hay valores
        }
    } elseif (($method === 'POST' || $method === 'PATCH') && $payloadValues === null) {
         $jsonData = '{}'; // Permitir cuerpo vacío explícito
    } elseif ($method === 'GET') {
        // No hacer nada especial para GET (ya se maneja sin payload)
    } elseif ($method === 'POST' && strpos($endpoint, '/query') !== false && $payloadValues !== null) {
         // Caso especial para Query (payload no va dentro de data/values)
         $jsonData = json_encode($payloadValues);
         if ($jsonData === false) {
             return ['status' => 500, 'data' => null, 'error_message' => 'Error interno al codificar payload JSON para Query.', 'curl_error' => null];
         }
         error_log("DEBUG Attio API Query Request: {$method} {$endpoint} Payload: " . $jsonData);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Aumentar timeout aún más
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($jsonData !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    }

    $apiResponse = curl_exec($ch);
    $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    $result = ['status' => $httpStatusCode, 'data' => null, 'error_message' => null, 'curl_error' => null];
    if ($curlErrno > 0) { $result['status'] = 500; $result['curl_error'] = "cURL Error ({$curlErrno}): {$curlError}"; return $result; }

    $decodedData = json_decode($apiResponse, true);
    $jsonError = json_last_error();

    if ($jsonError === JSON_ERROR_NONE) { $result['data'] = $decodedData; }
    elseif ($httpStatusCode >= 200 && $httpStatusCode < 300) { $result['status'] = 502; $result['error_message'] = "Respuesta inválida (JSON malformado) de Attio con status OK."; }

    if ($httpStatusCode >= 400) {
        $errMsg = null;
        if (is_array($decodedData) && isset($decodedData['error']['message'])) $errMsg = $decodedData['error']['message'];
        elseif (is_array($decodedData) && isset($decodedData['message'])) $errMsg = $decodedData['message'];
        elseif (is_array($decodedData) && isset($decodedData['errors'][0]['detail'])) $errMsg = $decodedData['errors'][0]['detail'];
        $result['error_message'] = $errMsg ?? "Error API Attio (Status {$httpStatusCode}). Cuerpo: " . substr($apiResponse, 0, 1000); // Más cuerpo
        // Loggear el error de Attio también en el servidor
        error_log("Attio API Error: {$method} {$endpoint} -> Status: {$httpStatusCode} | Message: {$result['error_message']} | Response Body: " . $apiResponse);
    }
    return $result;
}

// -- Formateadores --
function formatValuePayload($attributeId, $value) { if ($value === null || $value === '') return []; return [$attributeId => [['value' => $value]]]; }
function formatDatePayload($attributeId, $dateString) { if (empty($dateString) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) return []; return [$attributeId => [['value' => $dateString]]]; }
function formatSelectOptionPayload($attributeId, $optionId) { if (empty($optionId)) return []; return [$attributeId => [['option' => ['id' => ['option_id' => $optionId]]]]]; }
function formatMultiSelectOptionPayload($attributeId, $optionIds) { if (!is_array($optionIds) || empty($optionIds)) return []; $p = []; foreach($optionIds as $id) { if (!empty($id)) $p[] = ['option' => ['id' => ['option_id' => $id]]]; } return empty($p) ? [] : [$attributeId => $p]; }
function formatRelationPayload($attributeId, $targetRecordId, $targetObjectSlug) { if (empty($targetRecordId)) return []; return [$attributeId => [['target_object' => $targetObjectSlug, 'target_record_id' => $targetRecordId]]]; }
function formatMultiRelationPayload($attributeId, $targetRecordIds, $targetObjectSlug) { if (!is_array($targetRecordIds) || empty($targetRecordIds)) return []; $p = []; foreach($targetRecordIds as $id) { if (!empty($id)) $p[] = ['target_object' => $targetObjectSlug, 'target_record_id' => $id]; } return empty($p) ? [] : [$attributeId => $p]; }
function formatCurrencyPayload($attributeId, $currencyCode, $value) { if ($value === null || empty($currencyCode)) return []; $numVal = filter_var($value, FILTER_VALIDATE_FLOAT); if ($numVal === false) return []; return [$attributeId => [['currency_code' => strtoupper($currencyCode), 'currency_value' => $numVal]]]; }
function formatDomainPayload($attributeId, $domainValue) { if (empty($domainValue)) return []; return [$attributeId => [['domain' => $domainValue]]]; }
function formatEmailPayload($attributeId, $emailValue) { if (empty($emailValue)) return []; return [$attributeId => [['email_address' => $emailValue]]]; }
function formatPhonePayload($attributeId, $phoneValue) { if (empty($phoneValue)) return []; return [$attributeId => [['phone_number' => $phoneValue]]]; }
function formatPersonNamePayload($attributeId, $fullName) { if (empty(trim($fullName))) return []; return [$attributeId => [['full_name' => trim($fullName)]]]; }
function formatStatusPayload($attributeId, $statusOptionId) { if (empty($statusOptionId)) return []; return [$attributeId => [['status' => ['id' => ['status_id' => $statusOptionId]]]]]; }
// --- FIN FUNCIONES HELPER ---


// --- LÓGICA PRINCIPAL DE PROCESAMIENTO ---
try {
    // Extraer datos principales
    $dealId = $inputData['deal_id'];
    $companyId = $inputData['company_id'] ?? null;
    $companyPayloadValues = [];
    $dealPayloadValues = [];
    $financeUserRecordId = null;
    $personRecordIdsCreatedOrFound = [];
    $companyNameForBilling = $inputData['company_name'] ?? 'Cliente'; // Usar nombre real si está disponible

    // --- 1. Procesar Compañía ---
    $plan = $inputData['plan'] ?? null;
    $contractStartDate = $inputData['contract_start_date'] ?? null;
    $contractEndDate = $inputData['contract_end_date'] ?? null;
    $paymentPeriod = $inputData['payment_period'] ?? null;

    // Construir payload base de compañía
    // Usar array_merge para combinar los resultados de los formateadores
    $companyPayloadValues = array_merge(
        formatValuePayload(ATTR_COMPANY_NAME, $inputData['company_name'] ?? null),
        formatDomainPayload(ATTR_COMPANY_DOMAINS, $inputData['company_domain'] ?? null),
        formatValuePayload(ATTR_COMPANY_LEGAL_NAME, $inputData['company_legal_name'] ?? null),
        formatDatePayload(ATTR_COMPANY_START_DATE, $contractStartDate),
        formatDatePayload(ATTR_COMPANY_END_DATE, $contractEndDate),
        // Usando formato Select según la última prueba para Company Status
        formatSelectOptionPayload(ATTR_COMPANY_STATUS, OPTION_ID_COMPANY_STATUS_ACTIVE),
        ($plan && isset(MAP_PLAN_TO_OPTION_ID[strtolower($plan)])) ? formatSelectOptionPayload(ATTR_COMPANY_PLAN, MAP_PLAN_TO_OPTION_ID[strtolower($plan)]) : [],
        isset(MAP_PAYMENT_PERIOD_TO_OPTION_ID[$paymentPeriod]) ? formatSelectOptionPayload(ATTR_COMPANY_PAYMENT_TERMS, MAP_PAYMENT_PERIOD_TO_OPTION_ID[$paymentPeriod]) : []
    );

    // Crear o Actualizar Compañía
    $isNewCompany = empty($companyId);
    $companyApiMethod = $isNewCompany ? 'POST' : 'PATCH';
    $companyApiEndpoint = '/v2/objects/' . SLUG_COMPANIES . '/records';
    if (!$isNewCompany) {
        $companyApiEndpoint .= '/' . $companyId;
    }

    $companyResult = makeAttioApiCall($companyApiMethod, $companyApiEndpoint, ATTIO_API_KEY, $companyPayloadValues);

    if ($companyResult['status'] >= 400) {
        $action = $isNewCompany ? 'crear' : "actualizar ({$companyId})";
        // Incluir payload en el error puede ser útil para depurar
        throw new Exception("Error al {$action} la compañía: " . ($companyResult['error_message'] ?? 'Respuesta inesperada.'), $companyResult['status']);
    }

    if ($isNewCompany) {
        $companyId = $companyResult['data']['data']['id']['record_id'] ?? null;
        if (empty($companyId)) { throw new Exception("No se pudo obtener el ID de la nueva compañía creada.", 500); }
    }
    // Siempre asegurar asociación Deal -> Company (Añadir al payload del deal)
    $dealPayloadValues = array_merge($dealPayloadValues, formatRelationPayload(ATTR_DEAL_ASSOC_COMPANY, $companyId, SLUG_COMPANIES));


    // --- 2. Actualizar Deal ---
    $dealValueInput = $inputData['dealValue'] ?? null;
    $paymentCurrencyInput = $inputData['payment_currency'] ?? null;
    // Añadir otros campos al payload del deal
    $dealPayloadValues = array_merge($dealPayloadValues,
        formatCurrencyPayload(ATTR_DEAL_VALUE, $paymentCurrencyInput, $dealValueInput),
        formatDatePayload(ATTR_DEAL_START_DATE, $contractStartDate),
        formatDatePayload(ATTR_DEAL_END_DATE, $contractEndDate)
    );

    if (!empty($dealPayloadValues)) {
        $updateDealResult = makeAttioApiCall('PATCH', '/v2/objects/' . SLUG_DEALS . '/records/' . $dealId, ATTIO_API_KEY, $dealPayloadValues);
        if ($updateDealResult['status'] >= 400) {
            throw new Exception("Error al actualizar Deal {$dealId}: " . ($updateDealResult['error_message'] ?? 'Respuesta inesperada.'), $updateDealResult['status']);
        }
    }

    // --- 3. Procesar Personas y Usuarios ---
    if (isset($inputData['people']) && is_array($inputData['people'])) {
        foreach ($inputData['people'] as $index => $personInput) {
            $personEmail = trim($personInput['email'] ?? ''); // Trim email
            if (empty($personEmail)) { error_log("WARN: Persona #".($index+1)." sin email. Saltando."); continue; }

            $personRecordId = $personInput['id'] ?? null;
            $userRecordId = null;
            $personPayloadForUpdate = []; // Payload solo para actualizar persona

            // A. Buscar/Crear/Actualizar Persona
            if (!empty($personRecordId)) {
                // Actualizar Persona existente (nombre, teléfono, compañía)
                $personPayloadForUpdate += formatPersonNamePayload(ATTR_PERSON_NAME, $personInput['full_name'] ?? null);
                $personPayloadForUpdate += formatPhonePayload(ATTR_PERSON_PHONES, $personInput['phone_number'] ?? null);
                $personPayloadForUpdate += formatRelationPayload(ATTR_PERSON_COMPANY, $companyId, SLUG_COMPANIES);
                if(!empty($personPayloadForUpdate)){
                     $updatePersonResult = makeAttioApiCall('PATCH', '/v2/objects/'.SLUG_PEOPLE.'/records/'.$personRecordId, ATTIO_API_KEY, $personPayloadForUpdate);
                     if ($updatePersonResult['status'] >= 400) { throw new Exception("Error al actualizar persona {$personRecordId}: " . $updatePersonResult['error_message'], $updatePersonResult['status']);}
                }
                $personRecordIdsCreatedOrFound[] = $personRecordId;
            } else {
                // Buscar Persona por email
                $findPersonPayload = ['filter' => ['and' => [['attribute' => ATTR_PERSON_EMAILS, 'condition' => 'eq', 'value' => $personEmail]]], 'limit' => 1]; // Limitar a 1
                $findPersonResult = makeAttioApiCall('POST', '/v2/objects/' . SLUG_PEOPLE . '/records/query', ATTIO_API_KEY, $findPersonPayload); // Pasar payload directo
                if ($findPersonResult['status'] >= 400) { throw new Exception("Error al buscar persona por email {$personEmail}: " . $findPersonResult['error_message'], $findPersonResult['status']); }

                if (!empty($findPersonResult['data']['data'])) {
                    // Encontrada por email
                    $personRecordId = $findPersonResult['data']['data'][0]['id']['record_id'];
                    $personPayloadForUpdate += formatPersonNamePayload(ATTR_PERSON_NAME, $personInput['full_name'] ?? null);
                    $personPayloadForUpdate += formatPhonePayload(ATTR_PERSON_PHONES, $personInput['phone_number'] ?? null);
                    $personPayloadForUpdate += formatRelationPayload(ATTR_PERSON_COMPANY, $companyId, SLUG_COMPANIES);
                    if(!empty($personPayloadForUpdate)){
                        $updatePersonResult = makeAttioApiCall('PATCH', '/v2/objects/'.SLUG_PEOPLE.'/records/'.$personRecordId, ATTIO_API_KEY, $personPayloadForUpdate);
                        if ($updatePersonResult['status'] >= 400) { throw new Exception("Error al actualizar persona {$personRecordId} (encontrada por email): " . $updatePersonResult['error_message'], $updatePersonResult['status']);}
                    }
                     $personRecordIdsCreatedOrFound[] = $personRecordId;
                } else {
                    // No encontrada, Crear Persona
                    $personPayloadForCreate = [];
                    $personPayloadForCreate += formatPersonNamePayload(ATTR_PERSON_NAME, $personInput['full_name'] ?? null);
                    $personPayloadForCreate += formatEmailPayload(ATTR_PERSON_EMAILS, $personEmail);
                    $personPayloadForCreate += formatPhonePayload(ATTR_PERSON_PHONES, $personInput['phone_number'] ?? null);
                    $personPayloadForCreate += formatRelationPayload(ATTR_PERSON_COMPANY, $companyId, SLUG_COMPANIES);
                    if(!empty($personPayloadForCreate)){
                         $createPersonResult = makeAttioApiCall('POST', '/v2/objects/'.SLUG_PEOPLE.'/records', ATTIO_API_KEY, $personPayloadForCreate);
                         if ($createPersonResult['status'] >= 400 || !isset($createPersonResult['data']['data']['id']['record_id'])) { throw new Exception("Error al crear persona para {$personEmail}: " . ($createPersonResult['error_message'] ?? 'Respuesta inesperada.'), $createPersonResult['status']);}
                         $personRecordId = $createPersonResult['data']['data']['id']['record_id'];
                         $personRecordIdsCreatedOrFound[] = $personRecordId;
                    } else { error_log("WARN: Datos insuficientes para crear persona para {$personEmail}. Saltando."); continue; }
                }
            }

            if (empty($personRecordId)) { error_log("WARN: No se pudo obtener ID de Persona para {$personEmail}. Saltando Usuario."); continue; }

            // B. Buscar/Crear/Actualizar Usuario
            $findUserPayload = ['filter' => ['and' => [['attribute' => ATTR_USER_EMAIL, 'condition' => 'eq', 'value' => $personEmail]]], 'limit' => 1];
            $findUserResult = makeAttioApiCall('POST', '/v2/objects/' . SLUG_USERS . '/records/query', ATTIO_API_KEY, $findUserPayload); // Pasar payload directo
            if ($findUserResult['status'] >= 400) { throw new Exception("Error al buscar usuario por email {$personEmail}: " . $findUserResult['error_message'], $findUserResult['status']); }

            // Preparar payload común para crear/actualizar usuario
            $userPayloadValues = [];
            $userPayloadValues += formatRelationPayload(ATTR_USER_PERSON, $personRecordId, SLUG_PEOPLE);
            $userPayloadValues += formatRelationPayload(ATTR_USER_COMPANY, $companyId, SLUG_COMPANIES);
            $roleOptionIds = [];
            if ($personInput['roles']['owner'] ?? false) $roleOptionIds[] = OPTION_ID_USER_ROLE_OWNER;
            if ($personInput['roles']['finance'] ?? false) $roleOptionIds[] = OPTION_ID_USER_ROLE_FINANCE;
            if ($personInput['roles']['poc'] ?? false) $roleOptionIds[] = OPTION_ID_USER_ROLE_POC;
            if (!empty($roleOptionIds)) { // Solo añadir roles si hay alguno
                 $userPayloadValues += formatMultiSelectOptionPayload(ATTR_USER_TYPE_ROLES, $roleOptionIds);
            }

             if (!empty($findUserResult['data']['data'])) {
                 // Usuario encontrado
                 $userRecordId = $findUserResult['data']['data'][0]['id']['record_id'];
                 if (!empty($userPayloadValues)){
                     $updateUserResult = makeAttioApiCall('PATCH', '/v2/objects/'.SLUG_USERS.'/records/'.$userRecordId, ATTIO_API_KEY, $userPayloadValues);
                     if ($updateUserResult['status'] >= 400) { throw new Exception("Error al actualizar usuario {$userRecordId}: " . $updateUserResult['error_message'], $updateUserResult['status']);}
                 }
             } else {
                 // Usuario no encontrado, Crear
                  $userPayloadValues += formatEmailPayload(ATTR_USER_EMAIL, $personEmail); // Añadir email para creación
                  if (!empty($userPayloadValues['email_addresses'])) { // Asegurar que al menos tenemos email
                     $createUserResult = makeAttioApiCall('POST', '/v2/objects/'.SLUG_USERS.'/records', ATTIO_API_KEY, $userPayloadValues);
                     if ($createUserResult['status'] >= 400 || !isset($createUserResult['data']['data']['id']['record_id'])) { throw new Exception("Error al crear usuario para {$personEmail}: " . ($createUserResult['error_message'] ?? 'Respuesta inesperada.'), $createUserResult['status']);}
                     $userRecordId = $createUserResult['data']['data']['id']['record_id'];
                  } else { error_log("WARN: Datos insuficientes (falta email?) para crear usuario para {$personEmail}."); /* Continuar? */ }
             }

            // Guardar ID del usuario de finanzas si aplica y se obtuvo un ID
             if (($personInput['roles']['finance'] ?? false) && !empty($userRecordId)) {
                 $financeUserRecordId = $userRecordId;
             }

        } // End foreach people
    } // End if isset people


    // --- 4. Actualizar 'team' en la Compañía ---
    $uniquePersonIds = array_unique($personRecordIdsCreatedOrFound);
    if (!empty($uniquePersonIds) && !empty($companyId)) {
         $companyTeamPayload = formatMultiRelationPayload(ATTR_COMPANY_TEAM, $uniquePersonIds, SLUG_PEOPLE);
         $updateCompanyTeamResult = makeAttioApiCall('PATCH', '/v2/objects/'.SLUG_COMPANIES.'/records/'.$companyId, ATTIO_API_KEY, $companyTeamPayload);
         if ($updateCompanyTeamResult['status'] >= 400) {
             error_log("WARN: Error al actualizar team compañía {$companyId}: " . ($updateCompanyTeamResult['error_message'] ?? 'Respuesta inesperada.'));
         }
    }

    // --- 5. Crear Registros de Billing Cycles ---
    if (isset($inputData['payments']) && is_array($inputData['payments']) && ($inputData['payment_period'] ?? '0') !== '0') {
        $paymentIndex = 0;
        $billingCurrencyInput = $inputData['payment_currency'] ?? 'MXN';
        $billingStartDateInput = $inputData['contract_start_date'] ?? null;
        $billingPeriodInput = $inputData['payment_period'] ?? null;

        foreach ($inputData['payments'] as $paymentInput) {
            $paymentIndex++;
            $grossAmount = $paymentInput['amount'] ?? null;
            $discountPercent = $paymentInput['discount'] ?? 0;

            if (!is_numeric($grossAmount) || $grossAmount <= 0 || !is_numeric($discountPercent) || $discountPercent < 0 || $discountPercent > 100) {
                error_log("WARN: Datos de pago inválidos para pago #{$paymentIndex}. Saltando."); continue;
            }

            // Calcular fecha de vencimiento
            $paymentDueDate = null;
            if($billingStartDateInput && $billingPeriodInput !== '' && is_numeric($billingPeriodInput)){
                 try {
                     $startDateObj = new DateTime($billingStartDateInput);
                     if ($billingPeriodInput == 0.5) {
                          $daysToAdd = max(0, round($paymentIndex * 15.2) - 15); // Ajuste ligero para quincena promedio
                          $startDateObj->modify("+{$daysToAdd} days");
                     } elseif ($billingPeriodInput > 0) {
                          $monthsToAdd = ($paymentIndex -1) * (float)$billingPeriodInput;
                          if ($monthsToAdd >= 0) {
                               $startDateObj->modify("+{$monthsToAdd} months");
                               // Podríamos ajustar el día aquí si queremos mantenerlo, pero modify a veces lo hace bien.
                               // Si no, usar la lógica de setDate como antes.
                          }
                     }
                     $paymentDueDate = $startDateObj->format('Y-m-d');
                 } catch (Exception $e) { error_log("WARN: Error calculando fecha vencimiento pago #{$paymentIndex}: " . $e->getMessage() . ". Saltando."); continue; }
            } else { error_log("WARN: No se puede calcular fecha vencimiento pago #{$paymentIndex}. Saltando."); continue; }

            // Calcular monto neto
            $netAmount = $grossAmount * (1 - ($discountPercent / 100));
            // Construir nombre
            $billingCycleName = "Pago {$paymentIndex} de {$companyNameForBilling}";

            // Construir payload
            $billingPayloadValues = [];
            $billingPayloadValues = array_merge(
                formatValuePayload(ATTR_BILLING_NAME, $billingCycleName),
                formatRelationPayload(ATTR_BILLING_USERS, $financeUserRecordId, SLUG_USERS),
                formatStatusPayload(ATTR_BILLING_STATUS, OPTION_ID_BILLING_STATUS_NEW), // Usar formato Status
                formatRelationPayload(ATTR_BILLING_COMPANY, $companyId, SLUG_COMPANIES),
                formatDatePayload(ATTR_BILLING_DUE_DATE, $paymentDueDate),
                formatValuePayload(ATTR_BILLING_DISCOUNT, $discountPercent),
                formatCurrencyPayload(ATTR_BILLING_AMOUNT_GROSS, $billingCurrencyInput, $grossAmount),
                formatCurrencyPayload(ATTR_BILLING_AMOUNT_NET, $billingCurrencyInput, $netAmount),
                ($billingCurrencyInput && isset(MAP_CURRENCY_TO_OPTION_ID[strtoupper($billingCurrencyInput)])) ? formatSelectOptionPayload(ATTR_BILLING_CURRENCY, MAP_CURRENCY_TO_OPTION_ID[strtoupper($billingCurrencyInput)]) : []
                // Relación con Deal eliminada
            );

            // Crear registro
             if (!empty($billingPayloadValues)) {
                 $createBillingResult = makeAttioApiCall('POST', '/v2/objects/' . SLUG_BILLING_CYCLES . '/records', ATTIO_API_KEY, $billingPayloadValues);
                 if ($createBillingResult['status'] >= 400) { throw new Exception("Error al crear Billing Cycle #{$paymentIndex}: " . ($createBillingResult['error_message'] ?? 'Respuesta inesperada.'), $createBillingResult['status']); }
             } else {
                  error_log("WARN: Payload vacío para Billing Cycle #{$paymentIndex}. Saltando creación.");
             }

        } // End foreach payments
    } // End if payments exist

    // --- 6. Respuesta Final de Éxito ---
    finalResponse([ 'success' => true, 'message' => 'Proceso completado exitosamente.', 'company_id' => $companyId ], 200);

} catch (Exception $e) {
    // Capturar errores de lógica o API
    $statusCode = $e->getCode() >= 400 ? $e->getCode() : 500;
    error_log("Error en save_in_attio.php (Exception): Linea " . $e->getLine() . " - " . $e->getMessage());
     finalResponse([ 'success' => false, 'message' => 'Error durante el procesamiento: ' . $e->getMessage() ], $statusCode);
} catch (Throwable $t) {
    // Capturar otros errores fatales de PHP
     http_response_code(500);
     error_log("Error Fatal/Throwable en save_in_attio.php: " . $t->getMessage() . " en " . $t->getFile() . ":" . $t->getLine());
     echo json_encode(['success' => false, 'message' => 'Ocurrió un error interno inesperado en el servidor.']);
     exit();
}

// Current time: Friday, April 4, 2025 at 1:05:40 AM CST (Zapopan, Jalisco, Mexico)
?>