<?php
// --- INCLUDES Y CONFIGURACIÓN INICIAL ---
// Se recomienda cargar la clave API desde una ubicación segura fuera del directorio web si es posible.
// Ejemplo: include('/ruta/segura/fuera/del/web/env.php');
include('env.php'); // Contiene la variable $ATTIO_API_KEY

// Establecer zona horaria por defecto para funciones de fecha/hora de PHP
date_default_timezone_set('UTC');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// --- MANEJO DEL MÉTODO HTTP ---
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit();
}
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."], JSON_UNESCAPED_UNICODE);
    exit();
}

// --- CONFIGURACIÓN API ATTIO ---
$attioApiKey = $ATTIO_API_KEY ?? null;
if (empty($attioApiKey)) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error: \$ATTIO_API_KEY no está definida o está vacía en env.php"], JSON_UNESCAPED_UNICODE);
    exit();
}
$attioApiBaseUrl = "https://api.attio.com/v2";

// --- DEFINICIÓN DE CONSTANTES (¡ASEGÚRATE QUE ESTOS VALORES SEAN CORRECTOS PARA TU WORKSPACE!) ---

// Slugs de Objetos
define('COMPANY_OBJECT_SLUG', 'companies');
define('USER_OBJECT_SLUG', 'users');
define('BILLING_CYCLE_OBJECT_SLUG', 'billing_cycles');

// Slugs/IDs de Atributos de Compañía
define('COMPANY_STATUS_ATTRIBUTE_SLUG', 'status');
define('COMPANY_STATUS_ACTIVE_OPTION_ID', 'b09e60c1-7d87-4209-883e-f28cc26743b0');
define('COMPANY_CONTRACT_END_DATE_SLUG', 'contract_end_date');
define('COMPANY_LAST_BILLING_DATE_SLUG', 'last_billing_cycle_date');
define('COMPANY_PAYMENT_TERMS_SLUG', 'payment_terms_2');
define('COMPANY_PAYMENTS_DAY_SLUG', 'payment_day');
define('COMPANY_PLAN_SLUG', 'plan');
define('COMPANY_NAME_SLUG', 'name');
define('COMPANY_LINKED_USERS_SLUG', 'users');
define('COMPANY_LINKED_BILLING_CYCLES_SLUG', 'billing_cycles');

// Slugs/IDs de Atributos de Usuario (¡Verifica estos en tu objeto User!)
define('USER_TYPE_ATTRIBUTE_SLUG', 'type');
define('USER_TYPE_FINANCE_ADMIN_OPTION_ID', '7e8205f5-edb5-42d9-a76a-4da5b6390b65');
define('USER_EMAIL_ATTRIBUTE_SLUG', 'email');

// Slugs/IDs de Atributos de Ciclo de Facturación (¡Verifica estos en tu objeto Billing Cycle!)
define('BILLING_CYCLE_AMOUNT_SLUG', 'amount_4');
define('BILLING_CYCLE_CURRENCY_SLUG', 'amount_currency');
define('BILLING_CYCLE_PAYMENT_DUE_DATE_SLUG', 'payment_due_date'); // Usado para encontrar el más reciente

// --- VALORES ESPERADOS PARA PAYMENT TERMS ---
define('PAYMENT_TERM_MONTHLY', 'Monthly');
define('PAYMENT_TERM_15_DAYS', '15 days');

// --- FUNCIÓN AUXILIAR cURL ---
function makeAttioApiRequest($url, $apiKey, $method = 'GET', $payload = null) {
    $ch = curl_init();
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json'
    ];
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60
    ];
    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
    } elseif (strtoupper($method) !== 'GET') {
        $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
        if ($payload !== null) {
             $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    return ['response' => $response, 'http_code' => $httpCode, 'error' => $error, 'errno' => $errno];
}

// --- FUNCIONES AUXILIARES PARA OBTENER VALORES DE ATTIO (CORREGIDA) ---
function getAttributeValue($recordData, $attributeSlug, $expectedType = 'string') {
    if ($recordData === null) { return null; }

    $attributesSource = null;
    if (isset($recordData['values']) && is_array($recordData['values'])) {
        $attributesSource = $recordData['values'];
    } elseif (isset($recordData['attributes']) && is_array($recordData['attributes'])) {
        $attributesSource = $recordData['attributes'];
    } else { return null; }

    if (!isset($attributesSource[$attributeSlug]) || !is_array($attributesSource[$attributeSlug]) || empty($attributesSource[$attributeSlug])) {
        return null;
    }

    foreach ($attributesSource[$attributeSlug] as $valueEntry) {
        // Corregido: Usar array_key_exists
        if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) {
            $actualAttributeType = $valueEntry['attribute_type'] ?? null;
            switch ($expectedType) {
                case 'date':
                     try { $dateValue = $valueEntry['value'] ?? null; if ($dateValue) { return new DateTime($dateValue, new DateTimeZone('UTC')); } else { return null;} } catch (Exception $e) { /* error_log silenciado */ return null; }
                case 'select_option_id':
                     return $valueEntry['option']['id'] ?? null;
                 case 'select_option_title':
                     return $valueEntry['option']['title'] ?? null;
                case 'number':
                     if ($actualAttributeType === 'currency') { return isset($valueEntry['currency_value']) ? (float)$valueEntry['currency_value'] : null; }
                     else { return isset($valueEntry['value']) ? (float)$valueEntry['value'] : null; }
                 case 'currency_code':
                      return ($actualAttributeType === 'currency' && isset($valueEntry['currency_code'])) ? $valueEntry['currency_code'] : null;
                 case 'email':
                     return $valueEntry['email'] ?? null;
                case 'record_reference':
                     $ids = []; if (isset($valueEntry['target_record_id'])) { $ids[] = $valueEntry['target_record_id']; } elseif (isset($valueEntry['target_records']) && is_array($valueEntry['target_records'])) { foreach($valueEntry['target_records'] as $target) { if (isset($target['target_record_id'])) { $ids[] = $target['target_record_id']; } } } return $ids;
                case 'string': default:
                     return $valueEntry['value'] ?? null;
            }
            return null; // Salir si se procesó la entrada activa
        }
    }
    return null; // No se encontró entrada activa
}

// Obtiene IDs de registros vinculados (CORREGIDA)
function getLinkedRecordIds($recordData, $attributeSlug) {
    $sourceKey = 'values'; // Asume Compañía usa 'values' para links
    if ($recordData === null || !isset($recordData[$sourceKey][$attributeSlug]) || !is_array($recordData[$sourceKey][$attributeSlug]) || empty($recordData[$sourceKey][$attributeSlug])) {
        return [];
    }
    $ids = [];
    foreach ($recordData[$sourceKey][$attributeSlug] as $valueEntry) {
        // Corregido: Usar array_key_exists
        if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) {
            if (isset($valueEntry['target_record_id'])) {
                $ids[] = $valueEntry['target_record_id'];
            } elseif (isset($valueEntry['target_records']) && is_array($valueEntry['target_records'])) {
                foreach ($valueEntry['target_records'] as $target) {
                    if (isset($target['target_record_id'])) {
                        $ids[] = $target['target_record_id'];
                    }
                }
            }
        }
    }
    return array_unique($ids);
}


// --- LÓGICA PRINCIPAL ---

try {
    // --- PASO 1: Obtener Compañías Activas ---
    $companiesUrl = "{$attioApiBaseUrl}/objects/" . COMPANY_OBJECT_SLUG . "/records/query";
    $companiesPayload = [
        "filter" => [ COMPANY_STATUS_ATTRIBUTE_SLUG => ['$eq' => COMPANY_STATUS_ACTIVE_OPTION_ID] ],
        "limit" => 1000
    ];
    // error_log("PASO 1: Solicitando compañías activas..."); // Log eliminado
    $companiesResult = makeAttioApiRequest($companiesUrl, $attioApiKey, 'POST', $companiesPayload);

    if ($companiesResult['errno'] > 0) { throw new Exception("Error cURL (Compañías): " . $companiesResult['error'], 500); }
    $companiesData = json_decode($companiesResult['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE) { throw new Exception("Error JSON (Compañías): " . json_last_error_msg(), 502); }
    if ($companiesResult['http_code'] < 200 || $companiesResult['http_code'] >= 300) { throw new Exception("Error API Attio (Compañías): Código {$companiesResult['http_code']} | " . $companiesResult['response'], $companiesResult['http_code']); }

    $allActiveCompanies = $companiesData['data'] ?? [];
    // error_log("PASO 1: Obtenidas " . count($allActiveCompanies) . " compañías activas."); // Log eliminado
    if (empty($allActiveCompanies)) {
        echo json_encode(["success" => true, "billing_cycles" => []]);
        exit();
    }

    // --- PASO 2: Filtrar Compañías Elegibles y Recolectar IDs ---
    // error_log("PASO 2: Iniciando filtrado de compañías elegibles y recolección de IDs..."); // Log eliminado
    $eligibleCompanies = [];
    $requiredUserIds = [];
    $requiredBillingCycleIds = [];

    $now = new DateTime('now', new DateTimeZone('UTC'));
    $startOfCurrentMonth = new DateTime($now->format('Y-m-01 00:00:00'), new DateTimeZone('UTC'));
    $startOfNextMonth = (clone $startOfCurrentMonth)->modify('+1 month');

    foreach ($allActiveCompanies as $company) {
         $companyId = $company['id']['record_id'] ?? null;
         if (!$companyId) { continue; }
         // $companyNameLog = getAttributeValue($company, COMPANY_NAME_SLUG, 'string') ?? $companyId; // Log eliminado
         // error_log("--- Evaluando Compañía: $companyNameLog ($companyId) ---"); // Log eliminado

        $contractEndDate = getAttributeValue($company, COMPANY_CONTRACT_END_DATE_SLUG, 'date');
        // error_log("Compañía $companyNameLog: Contract End Date = " . ($contractEndDate ? $contractEndDate->format('Y-m-d') : 'NULL')); // Log eliminado
        if ($contractEndDate !== null) { /* error_log("DESCARTADA..."); */ continue; }

        $lastBillingDate = getAttributeValue($company, COMPANY_LAST_BILLING_DATE_SLUG, 'date');
         // error_log("Compañía $companyNameLog: Last Billing Date = ... | Start of Next Month = ..."); // Log eliminado
        if ($lastBillingDate !== null && $lastBillingDate >= $startOfNextMonth) { /* error_log("DESCARTADA..."); */ continue; }

        // $rawPaymentTermsData = $company['values'][COMPANY_PAYMENT_TERMS_SLUG] ?? null; // Log eliminado
        // error_log("Compañía $companyNameLog: Raw data for 'payment_terms_2': ..."); // Log eliminado
        $paymentTerms = getAttributeValue($company, COMPANY_PAYMENT_TERMS_SLUG, 'select_option_title');
        // $paymentTermsLog = $paymentTerms ?? 'NULL'; // Log eliminado
        // error_log("Compañía $companyNameLog: Payment Terms = '$paymentTermsLog' | Esperado: ..."); // Log eliminado
        if ($paymentTerms !== PAYMENT_TERM_MONTHLY && $paymentTerms !== PAYMENT_TERM_15_DAYS) { /* error_log("DESCARTADA..."); */ continue; }

        // error_log("Compañía $companyNameLog: ELEGIBLE (Pasa filtros iniciales)"); // Log eliminado
        $eligibleCompanies[$companyId] = $company;

        // error_log("Compañía $companyNameLog: Recolectando IDs vinculados..."); // Log eliminado
        $linkedUserIds = getLinkedRecordIds($company, COMPANY_LINKED_USERS_SLUG);
        $requiredUserIds = array_merge($requiredUserIds, $linkedUserIds);
        // error_log("Compañía $companyNameLog: IDs de Usuario vinculados: ..."); // Log eliminado

        $linkedBillingCycleIds = getLinkedRecordIds($company, COMPANY_LINKED_BILLING_CYCLES_SLUG);
        $requiredBillingCycleIds = array_merge($requiredBillingCycleIds, $linkedBillingCycleIds);
        // error_log("Compañía $companyNameLog: IDs de Billing Cycle vinculados: ..."); // Log eliminado

    } // Fin foreach ($allActiveCompanies...)

    $eligibleCompaniesCount = count($eligibleCompanies);
    error_log("PASO 2: Finalizado. Número total de compañías ELEGIBLES: $eligibleCompaniesCount"); // Log MANTENIDO

    $uniqueUserIds = array_values(array_unique($requiredUserIds));
    $uniqueBillingCycleIds = array_values(array_unique($requiredBillingCycleIds));
    $uniqueUserIdsCount = count($uniqueUserIds);
    $uniqueBillingCycleIdsCount = count($uniqueBillingCycleIds);
    error_log("IDs únicos recolectados: $uniqueUserIdsCount Usuarios, $uniqueBillingCycleIdsCount Ciclos de Facturación."); // Log MANTENIDO


    // --- PASO 3: Obtener Datos de Usuarios Requeridos ---
    $usersDataMap = [];
    if (!empty($uniqueUserIds)) {
        error_log("PASO 3: Solicitando datos para $uniqueUserIdsCount usuarios..."); // Log MANTENIDO
        $usersUrl = "{$attioApiBaseUrl}/objects/" . USER_OBJECT_SLUG . "/records/query";
        $usersPayload = ["filter" => ["record_id" => ['$in' => $uniqueUserIds]]];
        $usersResult = makeAttioApiRequest($usersUrl, $attioApiKey, 'POST', $usersPayload);

        if ($usersResult['errno'] === 0 && $usersResult['http_code'] >= 200 && $usersResult['http_code'] < 300) {
            // error_log("PASO 3: API Users OK..."); // Log eliminado
            $usersData = json_decode($usersResult['response'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($usersData['data'])) {
                 $returnedUserCount = count($usersData['data']);
                 // error_log("PASO 3: JSON Users OK. API devolvió $returnedUserCount ..."); // Log eliminado
                foreach ($usersData['data'] as $record) {
                    $userId = $record['id']['record_id'] ?? null; // Asume estructura User
                    if ($userId) { $usersDataMap[$userId] = $record; }
                    // else { error_log("PASO 3: ADVERTENCIA: Usuario sin ID..."); } // Log eliminado
                }
                 error_log("PASO 3: Mapa de usuarios llenado con " . count($usersDataMap) . " registros."); // Log MANTENIDO
            } else {
                 error_log("PASO 3: !!! ERROR JSON/Data (Users): " . json_last_error_msg()); // Log de error MANTENIDO
                 $usersDataMap = [];
            }
        } else {
             error_log("PASO 3: !!! ERROR API/cURL (Users): Código {$usersResult['http_code']} | Error: {$usersResult['error']}"); // Log de error MANTENIDO
             $usersDataMap = [];
        }
    } else {
         error_log("PASO 3: Saltado. No había IDs únicos de Usuario requeridos."); // Log MANTENIDO
    }


    // --- PASO 4: Obtener Datos de Ciclos de Facturación Requeridos (CORREGIDO) ---
    $billingCyclesDataMap = [];
    $countIdsToRequest = count($uniqueBillingCycleIds);
    // error_log("PRE-PASO 4: Comprobando \$uniqueBillingCycleIds. Cantidad: $countIdsToRequest"); // Log eliminado

    if (!empty($uniqueBillingCycleIds)) {
        $idsToRequestJson = json_encode($uniqueBillingCycleIds);
        error_log("PASO 4: Iniciando. Se solicitarán datos para $countIdsToRequest IDs de Billing Cycles."); // Log MANTENIDO (resumido)
        $billingCyclesUrl = "{$attioApiBaseUrl}/objects/" . BILLING_CYCLE_OBJECT_SLUG . "/records/query";
        $billingCyclesPayload = ["filter" => ["record_id" => ['$in' => $uniqueBillingCycleIds]]];
        // error_log("PASO 4: Llamando a Attio API..."); // Log eliminado
        $billingCyclesResult = makeAttioApiRequest($billingCyclesUrl, $attioApiKey, 'POST', $billingCyclesPayload);
        // error_log("PASO 4: Respuesta API recibida. HTTP Code: ..."); // Log eliminado

        if ($billingCyclesResult['errno'] === 0 && $billingCyclesResult['http_code'] >= 200 && $billingCyclesResult['http_code'] < 300) {
             // error_log("PASO 4: La llamada API fue exitosa..."); // Log eliminado
            $billingCyclesData = json_decode($billingCyclesResult['response'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($billingCyclesData['data'])) {
                 $returnedRecordsCount = count($billingCyclesData['data']);
                 // error_log("PASO 4: JSON decodificado OK. Número de registros devueltos: $returnedRecordsCount..."); // Log eliminado
                 $addedToMapCount = 0;
                 foreach ($billingCyclesData['data'] as $record) {
                    $recordId = null;
                    // Intentar extraer el ID como string
                    if (isset($record['id'])) {
                        if (is_string($record['id'])) {
                            $recordId = $record['id'];
                        } elseif (is_array($record['id']) && isset($record['id']['record_id']) && is_string($record['id']['record_id'])) {
                            // Extraer de la estructura tipo Company si es necesario
                            $recordId = $record['id']['record_id'];
                        }
                    }

                    // Verificar si obtuvimos un ID string válido
                    if ($recordId !== null) { // is_string() ya está implícito si no es null aquí
                        // Añadir al mapa
                        $billingCyclesDataMap[$recordId] = $record;
                        $addedToMapCount++;
                    } else {
                        // --- ¡ESTE LOG ES EL IMPORTANTE AHORA! ---
                        $idInfo = 'NO EXISTE o tipo inválido';
                        if (isset($record['id'])) {
                             $idInfo = "Tipo: " . gettype($record['id']) . ", Valor: " . json_encode($record['id']);
                        }
                        error_log("PASO 4: !!! ADVERTENCIA: No se pudo extraer un ID de registro válido (string) del registro de BC. Clave 'id' $idInfo. Registro completo: " . json_encode($record));
                        // ------------------------------------------
                    }
                } // ----- FIN DEL BUCLE A REEMPLAZAR -----
                 error_log("PASO 4: Mapa de ciclos de facturación llenado con $addedToMapCount registros."); // Log MANTENIDO
            } else {
                 error_log("PASO 4: !!! ERROR JSON/Data (Billing Cycles): " . json_last_error_msg()); // Log de error MANTENIDO
                 $billingCyclesDataMap = [];
            }
        } else {
             error_log("PASO 4: !!! ERROR API/cURL (Billing Cycles): Código {$billingCyclesResult['http_code']} | Error: {$billingCyclesResult['error']}"); // Log de error MANTENIDO
            $billingCyclesDataMap = [];
        }
    } else {
         error_log("PASO 4: Saltado. No había IDs únicos de Billing Cycle requeridos."); // Log MANTENIDO
    }
    $finalMapCount = count($billingCyclesDataMap);
    error_log("PASO 4: Finalizado. Tamaño final de \$billingCyclesDataMap: $finalMapCount"); // Log MANTENIDO


    // --- PASO 5: Generar los Objetos de Ciclos de Facturación para el Siguiente Mes (CORREGIDO) ---
    $billingCyclesToCreate = [];
    $nextMonth = (clone $now)->modify('+1 month');
    $year = $nextMonth->format('Y');
    $month = $nextMonth->format('m');
    $endOfNextMonthDay = $nextMonth->format('t');

    error_log("PASO 5: Iniciando generación de ciclos para $eligibleCompaniesCount compañías elegibles."); // Log MANTENIDO

    foreach ($eligibleCompanies as $companyId => $companyData) {
        // $companyNameLog = getAttributeValue($company, COMPANY_NAME_SLUG, 'string') ?? $companyId; // Log eliminado
        // error_log("--- Generando ciclo(s) para Compañía Elegible: $companyNameLog ($companyId) ---"); // Log eliminado

        $mostRecentBillingCycle = null;
        $latestDueDate = null;
        $financeAdminEmail = null;

        // Bucle INTERNO para encontrar el ciclo con la ÚLTIMA FECHA DE VENCIMIENTO
        $linkedBillingCycleIds = getLinkedRecordIds($companyData, COMPANY_LINKED_BILLING_CYCLES_SLUG);
        // $linkedIdsString = implode(', ', $linkedBillingCycleIds); // Log eliminado
        // error_log("Compañía $companyNameLog: Iniciando búsqueda de ciclo reciente por payment_due_date..."); // Log eliminado

        if (!empty($linkedBillingCycleIds)) {
            foreach ($linkedBillingCycleIds as $bcId) {
                // error_log("Compañía $companyNameLog: ---> Evaluando BC ID '$bcId'"); // Log eliminado
                if (isset($billingCyclesDataMap[$bcId])) {
                    $cycleData = $billingCyclesDataMap[$bcId];
                    // error_log("Compañía $companyNameLog:     Datos encontrados... Buscando payment_due_date..."); // Log eliminado

                    // Obtener payment_due_date usando la función corregida
                    $dueDate = getAttributeValue($cycleData, BILLING_CYCLE_PAYMENT_DUE_DATE_SLUG, 'date');

                    if ($dueDate instanceof DateTime) {
                         // $dueDateStr = $dueDate->format('Y-m-d'); // Log eliminado
                         // error_log("Compañía $companyNameLog:     Found payment_due_date '$dueDateStr'..."); // Log eliminado
                        if ($mostRecentBillingCycle === null || $dueDate > $latestDueDate) {
                            // error_log("Compañía $companyNameLog:     >>>> BC ID '$bcId' es más reciente. Actualizando. <<<<"); // Log eliminado
                            $latestDueDate = $dueDate;
                            $mostRecentBillingCycle = $cycleData;
                        } // else { error_log("Compañía $companyNameLog:     BC ID '$bcId' NO es más reciente..."); } // Log eliminado
                    } // else { error_log("Compañía $companyNameLog:     !!! ADVERTENCIA: BC ID '$bcId' no tiene payment_due_date válido."); } // Log eliminado
                } // else { error_log("Compañía $companyNameLog: !!! ADVERTENCIA: Datos para BC ID '$bcId' no encontrados..."); } // Log eliminado
            } // Fin del bucle INTERNO foreach
        } // else { error_log("Compañía $companyNameLog: No hay IDs de ciclos vinculados..."); } // Log eliminado

        // $finalDecision = ($mostRecentBillingCycle === null) ? 'NULL' : 'ASIGNADO (ID: ...)'; // Log eliminado
        // error_log("Compañía $companyNameLog: Búsqueda de ciclo reciente finalizada. \$mostRecentBillingCycle es $finalDecision"); // Log eliminado

        // Comprobación después de buscar el ciclo más reciente por fecha de vencimiento
        if ($mostRecentBillingCycle === null) {
            // error_log("Compañía $companyNameLog: No se encontró ciclo reciente válido. Saltando."); // Log eliminado
            continue;
        }
        // $recentCycleId = $mostRecentBillingCycle['id'] ?? '??'; // Log eliminado
        // error_log("Compañía $companyNameLog: Ciclo reciente válido encontrado (ID: $recentCycleId). Obteniendo datos..."); // Log eliminado

        // Obtener datos del ciclo más reciente encontrado
        $amount = getAttributeValue($mostRecentBillingCycle, BILLING_CYCLE_AMOUNT_SLUG, 'number');
        $currency = getAttributeValue($mostRecentBillingCycle, BILLING_CYCLE_CURRENCY_SLUG, 'select_option_title');
        // error_log("Compañía $companyNameLog: Monto del ciclo reciente = '$amount', Moneda = '$currency'"); // Log eliminado
        if ($amount === null || $currency === null) {
            // error_log("Compañía $companyNameLog: Falta monto o moneda. Saltando."); // Log eliminado
            continue;
        }

        // Buscar admin de finanzas
        $linkedUserIds = getLinkedRecordIds($companyData, COMPANY_LINKED_USERS_SLUG);
        foreach ($linkedUserIds as $userId) {
            if (isset($usersDataMap[$userId])) {
                $userData = $usersDataMap[$userId];
                $userTypeId = getAttributeValue($userData, USER_TYPE_ATTRIBUTE_SLUG, 'select_option_id');
                if ($userTypeId === USER_TYPE_FINANCE_ADMIN_OPTION_ID) {
                    $financeAdminEmail = getAttributeValue($userData, USER_EMAIL_ATTRIBUTE_SLUG, 'email');
                    break;
                }
            }
        }
        // $financeAdminEmailLog = $financeAdminEmail ?? 'No encontrado'; // Log eliminado
        // error_log("Compañía $companyNameLog: Email Admin Finanzas = '$financeAdminEmailLog'..."); // Log eliminado

        // Obtener otros datos de la compañía
        $companyName = getAttributeValue($companyData, COMPANY_NAME_SLUG, 'string');
        $companyPlan = getAttributeValue($companyData, COMPANY_PLAN_SLUG, 'select_option_title');
        $paymentTerms = getAttributeValue($companyData, COMPANY_PAYMENT_TERMS_SLUG, 'select_option_title');
        $paymentDayNum = getAttributeValue($companyData, COMPANY_PAYMENTS_DAY_SLUG, 'number');

        // Calcular impuestos
        $amountTaxes = 0;
        if (strtoupper($currency) === 'MXN') { $amountTaxes = round($amount * 0.16, 2); }
        // error_log("Compañía $companyNameLog: Cálculo Impuestos..."); // Log eliminado

        // Crear el objeto(s) del ciclo de facturación para la respuesta JSON FINAL
        $baseBillingCycleOutput = [
            "amount" => $amount,
            "amount_currency" => $currency,
            "amount_taxes" => $amountTaxes,
            "company" => [ "company_id" => $companyId, "name" => $companyName ],
            "user" => $financeAdminEmail
        ];

        // $billingCyclesCreatedCount = 0; // Log eliminado

        if ($paymentTerms === PAYMENT_TERM_MONTHLY) {
            $dueDateDay = $paymentDayNum ?: 1;
            $dueDateDay = min((int)$dueDateDay, (int)$endOfNextMonthDay);
            $dueDate = sprintf('%s-%s-%02d', $year, $month, $dueDateDay);

            $billingCycleOutput = $baseBillingCycleOutput;
            $billingCycleOutput["payment_due_date"] = $dueDate;
            $billingCycleOutput["billing_cycle_name"] = trim("$companyName | $companyPlan");
            $billingCyclesToCreate[] = $billingCycleOutput;
            // $billingCyclesCreatedCount = 1; // Log eliminado

        } elseif ($paymentTerms === PAYMENT_TERM_15_DAYS) {
            // Quincena 1
            $dueDateQ1 = sprintf('%s-%s-15', $year, $month);
            $billingCycleQ1 = $baseBillingCycleOutput;
            $billingCycleQ1["payment_due_date"] = $dueDateQ1;
            $billingCycleQ1["billing_cycle_name"] = trim("$companyName | $companyPlan | Quincena 1");
            $billingCyclesToCreate[] = $billingCycleQ1;

            // Quincena 2
            $dueDateQ2 = sprintf('%s-%s-%02d', $year, $month, $endOfNextMonthDay);
            $billingCycleQ2 = $baseBillingCycleOutput;
            $billingCycleQ2["payment_due_date"] = $dueDateQ2;
            $billingCycleQ2["billing_cycle_name"] = trim("$companyName | $companyPlan | Quincena 2");
            $billingCyclesToCreate[] = $billingCycleQ2;
            // $billingCyclesCreatedCount = 2; // Log eliminado
        }
         // error_log("Compañía $companyNameLog: Creados $billingCyclesCreatedCount objeto(s)..."); // Log eliminado

    } // Fin del bucle principal foreach ($eligibleCompanies...)

    $finalGeneratedCount = count($billingCyclesToCreate);
    error_log("PASO 5: Finalizado. Número total de ciclos de facturación generados para la respuesta: $finalGeneratedCount"); // Log MANTENIDO

    // --- RESPUESTA FINAL ---
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "billing_cycles" => $billingCyclesToCreate
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    $errorCode = $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($errorCode);
    error_log("Error general en script: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine()); // Log de error MANTENIDO
    echo json_encode([
        "success" => false,
        "message" => "Ocurrió un error interno procesando la solicitud.",
        // "details" => $e->getMessage() // Mantener comentado en producción
    ], JSON_UNESCAPED_UNICODE);
}

?>