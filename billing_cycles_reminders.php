<?php
// billing_cycles_reminders.php (v FINAL con Logs - CORREGIDO para error L.195)

// --- INCLUDES Y CONFIGURACIÓN INICIAL ---
include('env.php');
date_default_timezone_set('UTC');

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

// --- OBTENER MES Y CALCULAR RANGO DE FECHAS ---
$targetMonthStr = $_GET['month'] ?? null; // Formato esperado YYYY-MM
$now = new DateTime('now', new DateTimeZone('UTC'));

if (!empty($targetMonthStr) && preg_match('/^\d{4}-\d{2}$/', $targetMonthStr)) {
    try {
        $startDate = new DateTime($targetMonthStr . '-01 00:00:00', new DateTimeZone('UTC'));
        $endDateExclusive = (clone $startDate)->modify('+1 month');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Formato de mes inválido. Usar YYYY-MM."], JSON_UNESCAPED_UNICODE);
        exit();
    }
} else {
    // Default: Mes SIGUIENTE al actual
    $startDate = (clone $now)->modify('first day of next month')->setTime(0, 0, 0);
    $endDateExclusive = (clone $startDate)->modify('+1 month');
    $targetMonthStr = $startDate->format('Y-m');
}

// Formato YYYY-MM-DD para el filtro de API
$startDateApi = $startDate->format('Y-m-d');
$endDateApiExclusive = $endDateExclusive->format('Y-m-d');

error_log("BillingReminders: Buscando ciclos con due_date >= $startDateApi Y < $endDateApiExclusive (para mes $targetMonthStr)");

// --- CONFIGURACIÓN API ATTIO ---
$attioApiKey = $ATTIO_API_KEY ?? null;
if (empty($attioApiKey)) { http_response_code(500); echo json_encode(["success" => false, "message" => "API Key no configurada."], JSON_UNESCAPED_UNICODE); exit(); }
$attioApiBaseUrl = "https://api.attio.com/v2";

// --- DEFINICIÓN DE CONSTANTES (¡¡¡VERIFICA Y AJUSTA ESTOS SLUGS!!!) ---
define('COMPANY_OBJECT_SLUG', 'companies');
define('USER_OBJECT_SLUG', 'users');
define('BILLING_CYCLE_OBJECT_SLUG', 'billing_cycles');
define('PERSON_OBJECT_SLUG', 'people'); // ¡VERIFICAR!

// Atributos Billing Cycle
define('BILLING_CYCLE_PAYMENT_DUE_DATE_SLUG', 'payment_due_date'); // ¡VERIFICAR!
define('BILLING_CYCLE_NAME_SLUG', 'name');                      // ¡VERIFICAR!
define('BILLING_CYCLE_AMOUNT_TAXES_SLUG', 'amount_taxes');          // ¡VERIFICAR! ¿O usar amount_4?
define('BILLING_CYCLE_AMOUNT_CURRENCY_SLUG', 'amount_currency');     // ¡VERIFICAR! (Select?)
define('BILLING_CYCLE_LINKED_COMPANY_SLUG', 'company');             // ¡VERIFICAR! (Link a Company)
define('BILLING_CYCLE_LINKED_USERS_SLUG', 'users');                 // ¡VERIFICAR! (Link a Users)

// Atributos Company
define('COMPANY_NAME_SLUG', 'name');
define('COMPANY_LEGAL_NAME_SLUG', 'company_legal_name');   // ¡VERIFICAR!

// Atributos User
define('USER_LINKED_PERSON_SLUG', 'person');               // ¡VERIFICAR! (Link a Person)
define('USER_TYPE_ATTRIBUTE_SLUG', 'type');                  // ¡VERIFICAR! (Atributo Type en User)
define('USER_TYPE_FINANCE_ADMIN_TITLE', 'Finance Admin / Payments'); // ¡VERIFICAR Título Exacto!

// Atributos Person (¡VERIFICAR!)
define('PERSON_NAME_SLUG', 'name');                        // Slug para nombre completo en Person
define('PERSON_EMAIL_SLUG', 'email_addresses');            // Slug para email en Person
define('PERSON_PHONE_SLUG', 'phone_numbers');              // Slug para teléfono en Person

// --- FUNCIÓN AUXILIAR cURL ---
function makeAttioApiRequest($url, $apiKey, $method = 'GET', $payload = null) {
    $ch = curl_init(); $headers = [ 'Authorization: Bearer ' . $apiKey, 'Accept: application/json', 'Content-Type: application/json'];
    $options = [ CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60 ];
    if (strtoupper($method) === 'POST') { $options[CURLOPT_POST] = true; if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    elseif (strtoupper($method) !== 'GET') { $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method); if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    curl_setopt_array($ch, $options); $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); $errno = curl_errno($ch); curl_close($ch);
    return ['response' => $response, 'http_code' => $httpCode, 'error' => $error, 'errno' => $errno];
}

// --- FUNCIONES AUXILIARES PARA OBTENER VALORES DE ATTIO (CORREGIDA v3 - con logs internos opcionales) ---
function getAttributeValue($recordData, $attributeSlug, $expectedType = 'string') {
    if ($recordData === null) { return null; }
    $attributesSource = null;
    if (isset($recordData['values']) && is_array($recordData['values'])) { $attributesSource = $recordData['values']; }
    elseif (isset($recordData['attributes']) && is_array($recordData['attributes'])) { $attributesSource = $recordData['attributes']; }
    else { return null; }
    if (!isset($attributesSource[$attributeSlug]) || !is_array($attributesSource[$attributeSlug]) || empty($attributesSource[$attributeSlug])) { return ($expectedType === 'select_option_title') ? [] : null; } // Devuelve array vacío para títulos multi-select no encontrados
    $returnValue = null; $isMulti = ($expectedType === 'select_option_title'); if ($isMulti) { $returnValue = []; }
    foreach ($attributesSource[$attributeSlug] as $key => $valueEntry) {
        if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) {
            // error_log("Debug getAttributeValue: Entrada $key ACTIVA encontrada para $attributeSlug. ExpectedType: $expectedType"); // Log interno opcional
            $extractedValue = null; $actualAttributeType = $valueEntry['attribute_type'] ?? null;
            switch ($expectedType) {
                case 'date': try { $dateValue = $valueEntry['value'] ?? null; $extractedValue = $dateValue ? new DateTime($dateValue, new DateTimeZone('UTC')) : null; } catch (Exception $e) { $extractedValue = null; } break;
                case 'select_option_id': $extractedValue = $valueEntry['option']['id'] ?? null; break;
                case 'select_option_title': $extractedValue = $valueEntry['option']['title'] ?? null; break;
                case 'number': $extractedValue = ($actualAttributeType === 'currency' && isset($valueEntry['currency_value'])) ? (float)$valueEntry['currency_value'] : (isset($valueEntry['value']) ? (float)$valueEntry['value'] : null); break;
                case 'currency_code': $extractedValue = ($actualAttributeType === 'currency' && isset($valueEntry['currency_code'])) ? $valueEntry['currency_code'] : null; break;
                case 'email': $extractedValue = ($actualAttributeType === 'email-address') ? ($valueEntry['email_address'] ?? null) : ($valueEntry['email'] ?? null); break;
                case 'phone': $extractedValue = ($actualAttributeType === 'phone-number') ? ($valueEntry['phone_number'] ?? null) : ($valueEntry['value'] ?? null); break;
                case 'record_reference': $ids = []; if (isset($valueEntry['target_record_id'])) { $ids[] = $valueEntry['target_record_id']; } elseif (isset($valueEntry['target_records']) && is_array($valueEntry['target_records'])) { foreach($valueEntry['target_records'] as $target) { if (isset($target['target_record_id'])) { $ids[] = $target['target_record_id']; } } } $extractedValue = $ids; break;
                case 'string': default:
                     if ($actualAttributeType === 'personal-name') { $extractedValue = $valueEntry['full_name'] ?? $valueEntry['value'] ?? null; }
                     else { $extractedValue = $valueEntry['value'] ?? null; }
                     break;
            }
            if ($extractedValue !== null) { if ($isMulti) { $returnValue[] = $extractedValue; } else { $returnValue = $extractedValue; break; } }
        }
    }
    // if ($returnValue === null && !$isMulti) { error_log("Debug getAttributeValue: No se encontró NINGUNA entrada ACTIVA para $attributeSlug."); } // Log interno opcional
    // elseif ($isMulti && empty($returnValue)) { error_log("Debug getAttributeValue: No se encontró NINGUNA entrada ACTIVA para $attributeSlug (multi-select)."); } // Log interno opcional
    return $returnValue;
}

// Función para obtener IDs vinculados, revisa 'values' o 'attributes'
function getLinkedRecordIdsFromAttributesOrValues($recordData, $attributeSlug) {
    $attributesSource = null;
    if (isset($recordData['values']) && is_array($recordData['values'])) { $attributesSource = $recordData['values']; }
    elseif (isset($recordData['attributes']) && is_array($recordData['attributes'])) { $attributesSource = $recordData['attributes']; }
    else { return []; }
    if ($recordData === null || !isset($attributesSource[$attributeSlug]) || !is_array($attributesSource[$attributeSlug]) || empty($attributesSource[$attributeSlug])) { return []; }
    $ids = [];
    foreach ($attributesSource[$attributeSlug] as $valueEntry) {
        if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) {
            if (isset($valueEntry['target_record_id'])) { $ids[] = $valueEntry['target_record_id']; }
            elseif (isset($valueEntry['target_records']) && is_array($valueEntry['target_records'])) { foreach ($valueEntry['target_records'] as $target) { if (isset($target['target_record_id'])) { $ids[] = $target['target_record_id']; } } }
        }
    } return array_unique($ids);
}

// --- LÓGICA PRINCIPAL ---
try {
    // --- PASO 1: Fetch Billing Cycles for the target month ---
    $billingCyclesUrl = "{$attioApiBaseUrl}/objects/" . BILLING_CYCLE_OBJECT_SLUG . "/records/query";
    $filterPayload = [
        "filter" => [ BILLING_CYCLE_PAYMENT_DUE_DATE_SLUG => ['$gte' => $startDateApi, '$lt' => $endDateApiExclusive ] ],
        "limit" => 1000
    ];
    error_log("BillingReminders: Solicitando ciclos con payload: " . json_encode($filterPayload));
    $bcResult = makeAttioApiRequest($billingCyclesUrl, $attioApiKey, 'POST', $filterPayload);

    if ($bcResult['errno'] > 0) { throw new Exception("Error cURL (BillingCycles): " . $bcResult['error'], 500); }
    $bcData = json_decode($bcResult['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE) { throw new Exception("Error JSON (BillingCycles): " . json_last_error_msg(), 502); }
    if ($bcResult['http_code'] < 200 || $bcResult['http_code'] >= 300) { throw new Exception("Error API Attio (BillingCycles): Código {$bcResult['http_code']} | " . $bcResult['response'], $bcResult['http_code']); }

    $foundBillingCycles = $bcData['data'] ?? [];
    $foundBCCount = count($foundBillingCycles);
    error_log("BillingReminders: Encontrados $foundBCCount ciclos de facturación para el mes $targetMonthStr.");
    if (empty($foundBillingCycles)) { echo json_encode(["success" => true, "reminders" => []]); exit(); }

    // --- PASO 2: Collect Linked IDs & Basic BC Details (CORREGIDO ID EXTRACTION) ---
    error_log("BillingReminders: Recolectando IDs y detalles básicos desde $foundBCCount ciclos...");
    $requiredCompanyIds = [];
    $requiredUserIds = [];
    $billingCycleDetails = [];

    foreach ($foundBillingCycles as $bcData) {
        // --- ROBUST BILLING CYCLE ID EXTRACTION ---
        $billingCycleId = null;
        if (isset($bcData['id'])) {
            if (is_string($bcData['id'])) { $billingCycleId = $bcData['id']; }
            elseif (is_array($bcData['id']) && array_key_exists('record_id', $bcData['id'])) {
                 if (isset($bcData['id']['record_id']) && is_string($bcData['id']['record_id'])) { $billingCycleId = $bcData['id']['record_id']; }
            }
        }
        // --- END ROBUST BILLING CYCLE ID EXTRACTION ---

        if (!$billingCycleId) {
            error_log("PASO 2: !!! ADVERTENCIA: Se omitió un ciclo de facturación porque no se pudo extraer un ID de registro válido (string). Registro: " . json_encode($bcData));
            continue;
        }
        error_log("PASO 2: Procesando Billing Cycle ID: $billingCycleId"); // Log MANTENIDO

        $bcDueDate = getAttributeValue($bcData, BILLING_CYCLE_PAYMENT_DUE_DATE_SLUG, 'date');
        if (!$bcDueDate) {
            error_log("PASO 2: Ciclo $billingCycleId omitido por no tener payment_due_date válida."); // Log MANTENIDO
            continue;
        }

        // ¡¡¡ Ajustar la función llamada si BC usa 'values' en lugar de 'attributes' para sus links !!!
        $companyIdArray = getLinkedRecordIdsFromAttributesOrValues($bcData, BILLING_CYCLE_LINKED_COMPANY_SLUG);
        $linkedUserIdsArray = getLinkedRecordIdsFromAttributesOrValues($bcData, BILLING_CYCLE_LINKED_USERS_SLUG);
        error_log("PASO 2: BC ID $billingCycleId - Linked Company IDs: [" . implode(',', $companyIdArray) . "]"); // Log MANTENIDO
        error_log("PASO 2: BC ID $billingCycleId - Linked User IDs: [" . implode(',', $linkedUserIdsArray) . "]"); // Log MANTENIDO


        $companyId = $companyIdArray[0] ?? null;
        if ($companyId) { $requiredCompanyIds[] = $companyId; }
        $requiredUserIds = array_merge($requiredUserIds, $linkedUserIdsArray);

        // Guardar detalles usando el ID string como clave
        $billingCycleDetails[$billingCycleId] = [
            'name' => getAttributeValue($bcData, BILLING_CYCLE_NAME_SLUG, 'string'),
            'amount_taxes' => getAttributeValue($bcData, BILLING_CYCLE_AMOUNT_TAXES_SLUG, 'number'), // O calcula si es necesario
            'amount_currency' => getAttributeValue($bcData, BILLING_CYCLE_AMOUNT_CURRENCY_SLUG, 'select_option_title'),
            'due_date_obj' => $bcDueDate,
            'company_id' => $companyId,
            'user_ids' => $linkedUserIdsArray
        ];
    } // Fin foreach ($foundBillingCycles...)

    $uniqueCompanyIds = array_values(array_unique($requiredCompanyIds));
    $uniqueUserIds = array_values(array_unique($requiredUserIds));
    error_log("BillingReminders: IDs únicos a buscar: " . count($uniqueCompanyIds) . " Compañías, " . count($uniqueUserIds) . " Usuarios."); // Log MANTENIDO

    // --- 3. Fetch Linked Company Details ---
    $companiesDataMap = [];
    if(!empty($uniqueCompanyIds)) {
        error_log("BillingReminders: PASO 3: Solicitando datos para " . count($uniqueCompanyIds) . " compañías..."); // Log MANTENIDO
        $companiesUrl = "{$attioApiBaseUrl}/objects/" . COMPANY_OBJECT_SLUG . "/records/query";
        $companiesPayload = ["filter" => ["record_id" => ['$in' => $uniqueCompanyIds]]];
        $companiesResult = makeAttioApiRequest($companiesUrl, $attioApiKey, 'POST', $companiesPayload);
        if ($companiesResult['errno'] === 0 && $companiesResult['http_code'] >= 200 && $companiesResult['http_code'] < 300) {
            $companiesData = json_decode($companiesResult['response'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($companiesData['data'])) {
                 $addedToMapCount = 0; // Corregido: Inicializar contador
                foreach ($companiesData['data'] as $record) {
                    // --- ROBUST COMPANY ID EXTRACTION ---
                    $cId = null;
                    if (isset($record['id'])) {
                        if (is_array($record['id']) && array_key_exists('record_id', $record['id'])) {
                            if (isset($record['id']['record_id']) && is_string($record['id']['record_id'])) { $cId = $record['id']['record_id']; }
                        } elseif (is_string($record['id'])) { $cId = $record['id']; }
                    }
                    // --- END ROBUST COMPANY ID EXTRACTION ---
                    if ($cId !== null) {
                         // error_log("DEBUG STEP 3 MAP: Añadiendo Compañía al mapa con clave: '$cId'"); // Log eliminado
                        $companiesDataMap[$cId] = $record;
                        $addedToMapCount++;
                    } else { error_log("PASO 3: !!! ADVERTENCIA: No se pudo extraer un ID de registro válido (string) del registro de Company."); } // Log MANTENIDO
                }
                error_log("BillingReminders: PASO 3: Mapa de compañías llenado con $addedToMapCount registros."); // Log MANTENIDO
            } else { error_log("BillingReminders: PASO 3: !!! ERROR JSON/Data (Companies)"); } // Log MANTENIDO
        } else { error_log("BillingReminders: PASO 3: !!! ERROR API/cURL (Companies): Código {$companiesResult['http_code']}"); } // Log MANTENIDO
    } else {
         error_log("BillingReminders: PASO 3: Saltado (No se requieren Compañías)."); // Log MANTENIDO
    }

    // --- 4. Fetch Linked User Details ---
    $usersDataMap = [];
    if (!empty($uniqueUserIds)) {
        error_log("BillingReminders: PASO 4: Solicitando datos para " . count($uniqueUserIds) . " usuarios..."); // Log MANTENIDO
        $usersUrl = "{$attioApiBaseUrl}/objects/" . USER_OBJECT_SLUG . "/records/query";
        $usersPayload = ["filter" => ["record_id" => ['$in' => $uniqueUserIds]]];
        $usersResult = makeAttioApiRequest($usersUrl, $attioApiKey, 'POST', $usersPayload);
        if ($usersResult['errno'] === 0 && $usersResult['http_code'] >= 200 && $usersResult['http_code'] < 300) {
            $usersData = json_decode($usersResult['response'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($usersData['data'])) {
                 $addedToMapCount = 0;
                foreach ($usersData['data'] as $record) {
                    // Asume User usa id.record_id - ¡AJUSTAR SI ES NECESARIO!
                     $uId = $record['id']['record_id'] ?? null;
                     if ($uId) { $usersDataMap[$uId] = $record; $addedToMapCount++; }
                     else { error_log("PASO 4: !!! ADVERTENCIA: Registro de User sin ['id']['record_id']."); } // Log MANTENIDO
                }
                error_log("BillingReminders: PASO 4: Mapa de usuarios llenado con $addedToMapCount registros."); // Log MANTENIDO
            } else { error_log("BillingReminders: PASO 4: !!! ERROR JSON/Data (Users)"); } // Log MANTENIDO
        } else { error_log("BillingReminders: PASO 4: !!! ERROR API/cURL (Users): Código {$usersResult['http_code']}"); } // Log MANTENIDO
    } else {
         error_log("BillingReminders: PASO 4: Saltado (No se requieren Usuarios)."); // Log MANTENIDO
    }

    // --- 5. Extract Linked Person IDs from Users ---
    $requiredPersonIds = [];
    $userToPersonIdMap = [];
    error_log("BillingReminders: PASO 5: Extrayendo IDs de personas desde " . count($usersDataMap) . " usuarios..."); // Log MANTENIDO
    foreach($usersDataMap as $userId => $userData) {
        // Asume User usa 'values' para el link a Person
        $personIdArray = getAttributeValue($userData, USER_LINKED_PERSON_SLUG, 'record_reference');
        if (!empty($personIdArray) && isset($personIdArray[0])) {
            $personId = $personIdArray[0];
            $userToPersonIdMap[$userId] = $personId;
            $requiredPersonIds[] = $personId;
             error_log("BillingReminders: PASO 5: User '$userId' mapeado a Person '$personId'."); // Log MANTENIDO
        } else {
             error_log("BillingReminders: PASO 5: User '$userId' no tiene Person vinculado o no se pudo extraer."); // Log MANTENIDO
        }
    }
    $uniquePersonIds = array_values(array_unique($requiredPersonIds));
    error_log("BillingReminders: PASO 5: IDs únicos de Personas a buscar: " . count($uniquePersonIds)); // Log MANTENIDO

    // --- 6. Fetch Linked Person Details ---
    $personsDataMap = [];
    if (!empty($uniquePersonIds)) {
        error_log("BillingReminders: PASO 6: Solicitando datos para " . count($uniquePersonIds) . " personas..."); // Log MANTENIDO
        $personsUrl = "{$attioApiBaseUrl}/objects/" . PERSON_OBJECT_SLUG . "/records/query";
        $personsPayload = ["filter" => ["record_id" => ['$in' => $uniquePersonIds]]];
        $personsResult = makeAttioApiRequest($personsUrl, $attioApiKey, 'POST', $personsPayload);
        if ($personsResult['errno'] === 0 && $personsResult['http_code'] >= 200 && $personsResult['http_code'] < 300) {
            $personsApiResponse = json_decode($personsResult['response'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($personsApiResponse['data'])) {
                 $addedToMapCount = 0;
                foreach($personsApiResponse['data'] as $personData) {
                    // Asume Person usa 'id' string - ¡AJUSTAR SI ES NECESARIO!
                    $pId = $personData['id'] ?? null;
                    if ($pId && is_string($pId)) { $personsDataMap[$pId] = $personData; $addedToMapCount++;}
                    else { error_log("BillingReminders: PASO 6: !!! ADVERTENCIA: Registro de Person sin ID string válido.");} // Log MANTENIDO
                }
                 error_log("BillingReminders: PASO 6: Mapa de personas llenado con $addedToMapCount registros."); // Log MANTENIDO
            } else { error_log("BillingReminders: PASO 6: !!! ERROR JSON/Data al obtener personas."); } // Log MANTENIDO
        } else { error_log("BillingReminders: PASO 6: !!! ERROR API/cURL al obtener personas: Código {$personsResult['http_code']}"); } // Log MANTENIDO
    } else {
         error_log("BillingReminders: PASO 6: Saltado (No se requieren Personas)."); // Log MANTENIDO
    }

    // --- 7. Format Final Output ---
    error_log("BillingReminders: PASO 7: Formateando salida para $foundBCCount ciclos encontrados..."); // Log MANTENIDO
    $outputData = [];

    // Configurar formateador de fecha en español (si está disponible Intl)
    $dateFormatter = null;
    if (class_exists('IntlDateFormatter')) {
        try {
             $pattern = 'dd \'de\' MMMM \'del\' yyyy'; // Formato deseado
             $dateFormatter = new IntlDateFormatter('es_MX', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, $pattern)
                          ?: new IntlDateFormatter('es', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, $pattern);
             if ($dateFormatter->getErrorCode() !== 0) { error_log("BillingReminders: Error al crear IntlDateFormatter: " . $dateFormatter->getErrorMessage()); $dateFormatter = null; }
        } catch (Exception $e) { error_log("BillingReminders: Excepción al crear IntlDateFormatter: " . $e->getMessage()); $dateFormatter = null; }
    } else { error_log("BillingReminders: Extensión Intl de PHP no encontrada. Fechas como YYYY-MM-DD."); }

    // Iterar sobre los detalles de ciclos guardados previamente
    foreach ($billingCycleDetails as $billingCycleId => $bcDetails) {
        error_log("BillingReminders: -- Procesando Ciclo ID: $billingCycleId --"); // Log MANTENIDO

        $bcDueDateObj = $bcDetails['due_date_obj'];

        $reminderDateBefore = (clone $bcDueDateObj)->modify('-5 days')->format('Y-m-d');
        $reminderDateAfter = (clone $bcDueDateObj)->modify('+5 days')->format('Y-m-d');

        $formattedDueDate = $bcDueDateObj->format('Y-m-d'); // Fallback
        $rawDueDate = $bcDueDateObj->format('Y-m-d');
        if ($dateFormatter) {
            $formattedDateOrFalse = $dateFormatter->format($bcDueDateObj);
            if ($formattedDateOrFalse !== false) { $formattedDueDate = $formattedDateOrFalse; }
            // else { error_log("BillingReminders: Falló formateo localizado para fecha..."); } // Log opcional
        }
        error_log("BillingReminders: BC ID $billingCycleId - DueDate: {$formattedDueDate}, Reminder Before: $reminderDateBefore, After: $reminderDateAfter"); // Log MANTENIDO

        // Datos de la Compañía
        $companyName = null; $companyLegalName = null;
        $companyId = $bcDetails['company_id'];
        if ($companyId && isset($companiesDataMap[$companyId])) {
            $companyData = $companiesDataMap[$companyId];
            $companyName = getAttributeValue($companyData, COMPANY_NAME_SLUG, 'string');
            $companyLegalName = getAttributeValue($companyData, COMPANY_LEGAL_NAME_SLUG, 'string');
            error_log("BillingReminders: BC ID $billingCycleId - Company Found: ID=$companyId, Name=$companyName, LegalName=$companyLegalName"); // Log MANTENIDO
        } else {
            error_log("BillingReminders: BC ID $billingCycleId - Company Data NOT FOUND for ID: " . ($companyId ?? 'N/A')); // Log MANTENIDO
        }

        // Datos del Usuario (Primer Finance Admin encontrado) y Persona
        $financeAdminUserOutput = [ "name" => null, "email" => null, "phone" => null ];
        $foundFinanceAdmin = false; // Flag
        error_log("BillingReminders: BC ID $billingCycleId - Buscando Finance Admin entre usuarios: [" . implode(', ', $bcDetails['user_ids']) . "]"); // Log MANTENIDO
        foreach ($bcDetails['user_ids'] as $userId) {
            if (isset($usersDataMap[$userId])) {
                 $userData = $usersDataMap[$userId];
                 $userTypesArray = getAttributeValue($userData, USER_TYPE_ATTRIBUTE_SLUG, 'select_option_title');
                 error_log("BillingReminders:   - Checking User ID $userId. Types: " . json_encode($userTypesArray)); // Log MANTENIDO
                 if (is_array($userTypesArray) && in_array(USER_TYPE_FINANCE_ADMIN_TITLE, $userTypesArray)) {
                    error_log("BillingReminders:     --> Encontrado Finance Admin: User ID $userId"); // Log MANTENIDO
                    $foundFinanceAdmin = true;
                    $personId = $userToPersonIdMap[$userId] ?? null;
                    if ($personId && isset($personsDataMap[$personId])) {
                        $personData = $personsDataMap[$personId];
                        error_log("BillingReminders:       Fetching details from Person ID $personId"); // Log MANTENIDO
                        $financeAdminUserOutput['name'] = getAttributeValue($personData, PERSON_NAME_SLUG, 'string');
                        $financeAdminUserOutput['email'] = getAttributeValue($personData, PERSON_EMAIL_SLUG, 'email');
                        $financeAdminUserOutput['phone'] = getAttributeValue($personData, PERSON_PHONE_SLUG, 'phone');
                         error_log("BillingReminders:       Person Details: Name=" .($financeAdminUserOutput['name']??'null').", Email=".($financeAdminUserOutput['email']??'null').", Phone=".($financeAdminUserOutput['phone']??'null')); // Log MANTENIDO
                    } else {
                         error_log("BillingReminders:       Finance Admin User $userId encontrado, pero no se encontró Person ID '$personId' o sus datos."); // Log MANTENIDO
                    }
                    break; // Salir al encontrar el primero
                 }
            } else {
                 error_log("BillingReminders:   - !!! ADVERTENCIA: Datos para User ID '$userId' no estaban en el mapa \$usersDataMap."); // Log MANTENIDO
            }
        }
        if (!$foundFinanceAdmin) { error_log("BillingReminders: BC ID $billingCycleId - No se encontró Finance Admin entre los usuarios vinculados."); } // Log MANTENIDO

        // Construir el objeto de salida final
        $outputItem = [
            "billing_cycle" => [
                "name" => $bcDetails['name'],
                "amount_taxes" => $bcDetails['amount_taxes'],
                "amount_currency" => $bcDetails['amount_currency'],
                "payment_due_date" => $formattedDueDate
            ],
            "user" => $financeAdminUserOutput,
            "company" => [
                "name" => $companyName,
                "company_legal_name" => $companyLegalName,
                "company_id" => $companyId
            ],
            "reminders_date" => [
                "before" => $reminderDateBefore."T10:00:00",
                "day" => $rawDueDate."T10:00:00",
                "after" => $reminderDateAfter."T10:00:00"
            ]
        ];
        $outputData[] = $outputItem;
    } // Fin foreach ($billingCycleDetails...)

    $finalGeneratedCount = count($outputData);
    error_log("BillingReminders: Formateo finalizado. Total de recordatorios generados: $finalGeneratedCount"); // Log MANTENIDO

    // --- 8. Respuesta Final ---
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "filter_month" => $targetMonthStr,
        "total_of_reminders" => count($outputData),
        "reminders" => $outputData
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    $errorCode = $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($errorCode);
    error_log("Error en billing_cycles_reminders.php: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine()); // Log MANTENIDO
    echo json_encode([
        "success" => false,
        "message" => "Ocurrió un error interno procesando la solicitud.",
        "details" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

?>