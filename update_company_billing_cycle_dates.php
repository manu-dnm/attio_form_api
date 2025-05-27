<?php
// update_company_billing_cycle_dates.php

// --- INCLUDES Y CONFIGURACIÓN INICIAL ---
include('env.php'); // Contiene la variable $ATTIO_API_KEY
date_default_timezone_set('UTC'); // Es crucial trabajar consistentemente en UTC

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// --- MANEJO DEL MÉTODO HTTP ---
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    http_response_code(204); // Sin contenido para preflight
    exit();
}
if ($method !== 'GET') {
    http_response_code(405); // Método no permitido
    echo json_encode(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."], JSON_UNESCAPED_UNICODE);
    exit();
}

// --- VALIDACIÓN DE PARÁMETROS ---
if (!isset($_GET['company_id']) || empty(trim($_GET['company_id']))) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "El parámetro 'company_id' es requerido."], JSON_UNESCAPED_UNICODE);
    exit();
}
$companyId = trim($_GET['company_id']);

if (!isset($_GET['payment_day']) || !is_numeric($_GET['payment_day'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "El parámetro 'payment_day' es requerido y debe ser numérico."], JSON_UNESCAPED_UNICODE);
    exit();
}
$paymentDay = (int)$_GET['payment_day'];

if ($paymentDay < 1 || $paymentDay > 31) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "El parámetro 'payment_day' debe estar entre 1 y 31."], JSON_UNESCAPED_UNICODE);
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

// --- DEFINICIÓN DE CONSTANTES (¡ASEGÚRATE QUE ESTOS VALORES SEAN CORRECTOS!) ---
// Slugs de Objetos
define('COMPANY_OBJECT_SLUG', 'companies');
define('BILLING_CYCLE_OBJECT_SLUG', 'billing_cycles');

// Slugs de Atributos de Compañía
define('COMPANY_LINKED_BILLING_CYCLES_SLUG', 'billing_cycles'); // Atributo en Company que linkea a Billing Cycles
define('COMPANY_NAME_SLUG', 'name'); // Para incluir el nombre de la compañía en la respuesta

// Slugs de Atributos de Ciclo de Facturación
define('BILLING_CYCLE_PAYMENT_DUE_DATE_SLUG', 'payment_due_date');
define('BILLING_CYCLE_AMOUNT_SLUG', 'amount_4'); // Ejemplo, ajusta si es necesario
define('BILLING_CYCLE_CURRENCY_SLUG', 'amount_currency'); // Ejemplo, ajusta si es necesario
// Podrías añadir un BILLING_CYCLE_NAME_SLUG si existe y quieres mostrarlo

// --- FUNCIÓN AUXILIAR cURL (Reutilizada) ---
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
        CURLOPT_TIMEOUT => 60 // Timeout extendido a 60 segundos
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

// --- FUNCIÓN AUXILIAR getAttributeValue (Reutilizada y adaptada de scripts anteriores) ---
function getAttributeValue($recordData, $attributeSlug, $expectedType = 'string') {
    if ($recordData === null) { return null; }

    $attributesSource = $recordData['values'] ?? ($recordData['attributes'] ?? null);
    if ($attributesSource === null || !isset($attributesSource[$attributeSlug]) || !is_array($attributesSource[$attributeSlug]) || empty($attributesSource[$attributeSlug])) {
        return null;
    }

    foreach ($attributesSource[$attributeSlug] as $valueEntry) {
        if (is_array($valueEntry) && (!array_key_exists('active_until', $valueEntry) || $valueEntry['active_until'] === null)) {
            $actualAttributeType = $valueEntry['attribute_type'] ?? null;
            switch ($expectedType) {
                case 'date':
                     try { $dateValue = $valueEntry['value'] ?? null; return $dateValue ? new DateTime($dateValue, new DateTimeZone('UTC')) : null; } catch (Exception $e) { return null; }
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
                case 'record_reference': // Devuelve un array de IDs
                     $ids = []; if (isset($valueEntry['target_record_id'])) { $ids[] = $valueEntry['target_record_id']; } elseif (isset($valueEntry['target_records']) && is_array($valueEntry['target_records'])) { foreach($valueEntry['target_records'] as $target) { if (isset($target['target_record_id'])) { $ids[] = $target['target_record_id']; } } } return $ids;
                case 'string': default:
                     return $valueEntry['value'] ?? null;
            }
            return null; // Salir si se procesó la entrada activa
        }
    }
    return null; // No se encontró entrada activa
}

// --- FUNCIÓN AUXILIAR getLinkedRecordIds (Reutilizada) ---
function getLinkedRecordIds($recordData, $attributeSlug) {
    // Intenta obtener los IDs de 'values' primero (común en respuestas de query)
    // y luego de 'attributes' (común en respuestas de GET directo a un record)
    $source = $recordData['values'] ?? ($recordData['attributes'] ?? null);

    if ($source === null || !isset($source[$attributeSlug]) || !is_array($source[$attributeSlug]) || empty($source[$attributeSlug])) {
        return [];
    }
    $ids = [];
    foreach ($source[$attributeSlug] as $valueEntry) {
        if (is_array($valueEntry) && (!array_key_exists('active_until', $valueEntry) || $valueEntry['active_until'] === null)) {
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
    return array_values(array_unique($ids));
}


// --- LÓGICA PRINCIPAL ---
try {
    // 1. Obtener la Compañía
    $companyUrl = "{$attioApiBaseUrl}/objects/" . COMPANY_OBJECT_SLUG . "/records/{$companyId}";
    error_log("Solicitando compañía: GET {$companyUrl}");
    $companyResult = makeAttioApiRequest($companyUrl, $attioApiKey, 'GET');

    if ($companyResult['errno'] > 0) {
        throw new Exception("Error cURL al obtener compañía: " . $companyResult['error'], 500);
    }
    if ($companyResult['http_code'] === 404) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Compañía con ID '{$companyId}' no encontrada."], JSON_UNESCAPED_UNICODE);
        exit();
    }
    if ($companyResult['http_code'] < 200 || $companyResult['http_code'] >= 300) {
        throw new Exception("Error API Attio al obtener compañía: Código {$companyResult['http_code']} | " . $companyResult['response'], $companyResult['http_code']);
    }
    
    $companyAttioData = json_decode($companyResult['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Error JSON al decodificar respuesta de compañía: " . json_last_error_msg(), 502);
    }
    $companyData = $companyAttioData['data'] ?? null;
    if ($companyData === null) {
         throw new Exception("Respuesta de API para compañía no contiene 'data'.", 502);
    }
    $companyName = getAttributeValue($companyData, COMPANY_NAME_SLUG, 'string') ?? 'Nombre no disponible';

    // 2. Obtener IDs de los Billing Cycles vinculados
    $linkedBillingCycleIds = getLinkedRecordIds($companyData, COMPANY_LINKED_BILLING_CYCLES_SLUG);

    if (empty($linkedBillingCycleIds)) {
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "company_id" => $companyId,
            "company_name" => $companyName,
            "message" => "La compañía no tiene ciclos de facturación vinculados.",
            "billing_cycles" => []
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit();
    }
    error_log("Compañía '{$companyName}' ({$companyId}) tiene " . count($linkedBillingCycleIds) . " ciclos de facturación vinculados.");

    // 3. Obtener datos de todos los Billing Cycles vinculados (en lotes si es necesario, aunque para una compañía suele ser manejable)
    $billingCyclesDataMap = [];
    $chunkSize = 50; // Tamaño del lote para la consulta de ciclos
    $idChunks = array_chunk($linkedBillingCycleIds, $chunkSize);

    foreach ($idChunks as $chunkIndex => $idChunk) {
        $billingCyclesQueryUrl = "{$attioApiBaseUrl}/objects/" . BILLING_CYCLE_OBJECT_SLUG . "/records/query";
        $billingCyclesPayload = ["filter" => ["record_id" => ['$in' => $idChunk]], "limit" => count($idChunk)];
        
        error_log("Solicitando lote " . ($chunkIndex + 1) . " de ciclos de facturación. IDs: " . json_encode($idChunk));
        $billingCyclesResult = makeAttioApiRequest($billingCyclesQueryUrl, $attioApiKey, 'POST', $billingCyclesPayload);

        if ($billingCyclesResult['errno'] > 0) {
            throw new Exception("Error cURL (Lote Ciclos Facturación): " . $billingCyclesResult['error'], 500);
        }
        if ($billingCyclesResult['http_code'] < 200 || $billingCyclesResult['http_code'] >= 300) {
            // Podrías decidir continuar con otros lotes o fallar aquí. Por ahora, fallamos.
            throw new Exception("Error API Attio (Lote Ciclos Facturación): Código {$billingCyclesResult['http_code']} | " . $billingCyclesResult['response'], $billingCyclesResult['http_code']);
        }
        
        $billingCyclesResponseData = json_decode($billingCyclesResult['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Error JSON (Lote Ciclos Facturación): " . json_last_error_msg(), 502);
        }

        $cyclesInChunk = $billingCyclesResponseData['data'] ?? [];
        foreach ($cyclesInChunk as $cycleRecord) {
            $cycleId = $cycleRecord['id']['record_id'] ?? ($cycleRecord['id'] ?? null); // Adaptar según estructura de ID
            if ($cycleId) {
                $billingCyclesDataMap[$cycleId] = $cycleRecord;
            }
        }
    }
    error_log("Se obtuvieron datos para " . count($billingCyclesDataMap) . " ciclos de facturación.");

    // 4. Filtrar Billing Cycles y calcular nueva fecha de vencimiento
    $outputBillingCycles = [];
    $firstDayOfCurrentMonth = new DateTime('first day of this month', new DateTimeZone('UTC'));
    $firstDayOfCurrentMonth->setTime(0, 0, 0); // Asegurar que es el inicio del día

    foreach ($billingCyclesDataMap as $cycleId => $cycleData) {
        $originalDueDateObj = getAttributeValue($cycleData, BILLING_CYCLE_PAYMENT_DUE_DATE_SLUG, 'date');

        if ($originalDueDateObj instanceof DateTime && $originalDueDateObj >= $firstDayOfCurrentMonth) {
            // El ciclo es del mes actual o futuro, procesarlo
            $originalYear = (int)$originalDueDateObj->format('Y');
            $originalMonth = (int)$originalDueDateObj->format('m');

            // Crear la nueva fecha de vencimiento
            // DateTime se encarga de ajustar el día si $paymentDay es mayor que los días del mes
            // Por ejemplo, si $paymentDay = 31 y $originalMonth es Febrero, se ajustará al 28 o 29.
            $newDueDateObj = new DateTime('now', new DateTimeZone('UTC')); // Crear objeto base
            $newDueDateObj->setDate($originalYear, $originalMonth, $paymentDay);
            $newDueDateObj->setTime(0,0,0); // Mantener al inicio del día

            $outputBillingCycles[] = [
                "billing_cycle_id" => $cycleId,
                // Podrías añadir más atributos del ciclo aquí si los necesitas
                // "name" => getAttributeValue($cycleData, 'nombre_del_ciclo_slug', 'string'),
                "amount" => getAttributeValue($cycleData, BILLING_CYCLE_AMOUNT_SLUG, 'number'),
                "currency" => getAttributeValue($cycleData, BILLING_CYCLE_CURRENCY_SLUG, 'select_option_title'), // O 'currency_code' si es un campo currency
                "original_payment_due_date" => $originalDueDateObj->format('Y-m-d'),
                "new_payment_due_date" => $newDueDateObj->format('Y-m-d')
            ];
        }
    }
    error_log("Se procesaron " . count($outputBillingCycles) . " ciclos de facturación que cumplen el criterio de fecha.");

    // 5. Respuesta Final
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "company_id" => $companyId,
        "company_name" => $companyName,
        "requested_payment_day" => $paymentDay,
        "billing_cycles" => $outputBillingCycles
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    $errorCode = $e->getCode();
    if (!is_int($errorCode) || $errorCode < 400 || $errorCode > 599) {
        $errorCode = 500; // Código de error por defecto si no es un HTTP status code válido
    }
    http_response_code($errorCode);
    error_log("Error en script: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . " (Código: {$e->getCode()})");
    echo json_encode([
        "success" => false,
        "message" => "Ocurrió un error interno procesando la solicitud.",
        "details" => $e->getMessage() // Opcional: útil para depuración, considera quitarlo en producción
    ], JSON_UNESCAPED_UNICODE);
}

?>
