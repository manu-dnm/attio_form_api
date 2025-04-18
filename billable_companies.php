<?php
// --- INCLUDES Y CONFIGURACIÓN INICIAL ---
include('env.php'); // Contiene $ATTIO_API_KEY

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// --- MANEJO DEL MÉTODO HTTP ---
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit(); }
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."]);
    exit();
}

// --- CONFIGURACIÓN API ATTIO ---
$attioApiKey = $ATTIO_API_KEY;
$attioApiBaseUrl = "https://api.attio.com/v2";

// --- !!! NECESARIO: Reemplaza con los IDs o Slugs reales de tus objetos en Attio !!! ---
define('USER_OBJECT_ID', 'users');
define('BILLING_CYCLE_OBJECT_ID', 'billing_cycles');
define('PAYMENTS_USER_TYPE_VALUE', 'Finance Admin / Payments');

// --- FUNCIÓN AUXILIAR cURL (CORREGIDA) ---
// Parámetros requeridos ($url, $apiKey) van PRIMERO
function makeAttioApiRequest($url, $apiKey, $method = 'GET', $payload = null) {
    $ch = curl_init();
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 45
    ];
    if (strtoupper($method) === 'POST') { // Comparación insensible a mayúsculas
        $options[CURLOPT_POST] = true;
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
    } // Puedes añadir lógica para otros métodos (PUT, DELETE) si es necesario
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    return ['response' => $response, 'http_code' => $httpCode, 'error' => $error, 'errno' => $errno];
}

// --- PASO 1: Obtener Compañías Activas ---
$companiesUrl = "{$attioApiBaseUrl}/objects/companies/records/query";
$companiesPayload = [
    "filter" => ["status" => "b09e60c1-7d87-4209-883e-f28cc26743b0"] // ID "Active"
    // "limit" => 1000
];
// Llamada a función CORREGIDA: $apiKey va segundo
$companiesResult = makeAttioApiRequest($companiesUrl, $attioApiKey, 'POST', $companiesPayload);

// --- MANEJO DE ERRORES (Compañías) ---
if ($companiesResult['errno'] > 0) { http_response_code(500); echo json_encode(["success" => false, "message" => "Error cURL (Compañías)", "details" => $companiesResult['error']]); exit(); }
$companiesData = json_decode($companiesResult['response'], true);
if (json_last_error() !== JSON_ERROR_NONE) { http_response_code(502); echo json_encode(["success" => false, "message" => "Error JSON (Compañías)", "details" => json_last_error_msg()]); exit(); }
if ($companiesResult['http_code'] < 200 || $companiesResult['http_code'] >= 300) { http_response_code($companiesResult['http_code']); echo json_encode(["success" => false, "message" => "Error API (Compañías)", "details" => $companiesData]); exit(); }

// --- PASO 2: Filtrar Compañías por Fecha y Recolectar IDs ---
$allActiveCompanies = $companiesData['data'] ?? [];
$billableCompanies = [];
$allBillingCycleIds = [];
$allUserIds = [];

try {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $today = new DateTime($now->format('Y-m-d'), new DateTimeZone('UTC'));
} catch (Exception $e) { http_response_code(500); echo json_encode(["success" => false, "message" => "Error fecha", "details" => $e->getMessage()]); exit(); }

function getCurrentDateValue($company, $attributeSlug): ?DateTime {
    if (!isset($company['values'][$attributeSlug]) || empty($company['values'][$attributeSlug])) { return null; } $dateStr = null; foreach ($company['values'][$attributeSlug] as $valueEntry) { if ($valueEntry['active_until'] === null) { $dateStr = $valueEntry['value'] ?? null; break; } } if ($dateStr === null) { return null; } try { return new DateTime($dateStr, new DateTimeZone('UTC')); } catch (Exception $e) { return null; }
}

foreach ($allActiveCompanies as $company) {
    $contractEndDate = getCurrentDateValue($company, 'contract_end_date');
    $lastBillingDate = getCurrentDateValue($company, 'last_billing_cycle_date');
    $contractConditionMet = ($contractEndDate === null || $contractEndDate < $today);
    $billingConditionMet = ($lastBillingDate === null || $lastBillingDate <= $today);

    if ($contractConditionMet && $billingConditionMet) {
        $billableCompanies[] = $company;
        if (isset($company['values']['billing_cycles']) && is_array($company['values']['billing_cycles'])) { foreach ($company['values']['billing_cycles'] as $billingRef) { if ($billingRef['active_until'] === null && isset($billingRef['target_record_id'])) { $allBillingCycleIds[] = $billingRef['target_record_id']; } } }
        if (isset($company['values']['users']) && is_array($company['values']['users'])) { foreach ($company['values']['users'] as $userRef) { if ($userRef['active_until'] === null && isset($userRef['target_record_id'])) { $allUserIds[] = $userRef['target_record_id']; } } }
    }
}
$uniqueBillingCycleIds = array_values(array_unique($allBillingCycleIds));
$uniqueUserIds = array_values(array_unique($allUserIds));

// --- PASO 3: Obtener Datos de Billing Cycles ---
$billingCyclesDataMap = [];
if (!empty($uniqueBillingCycleIds)) {
    $billingCyclesUrl = "{$attioApiBaseUrl}/objects/" . BILLING_CYCLE_OBJECT_ID . "/records/query";
    $billingCyclesPayload = ["filter" => ["record_id" => ['$in' => $uniqueBillingCycleIds]]];
    // Llamada a función CORREGIDA: $apiKey va segundo
    $billingCyclesResult = makeAttioApiRequest($billingCyclesUrl, $attioApiKey, 'POST', $billingCyclesPayload);
    if ($billingCyclesResult['errno'] === 0 && $billingCyclesResult['http_code'] >= 200 && $billingCyclesResult['http_code'] < 300) {
        $billingCyclesData = json_decode($billingCyclesResult['response'], true);
        if (json_last_error() === JSON_ERROR_NONE && isset($billingCyclesData['data'])) { foreach ($billingCyclesData['data'] as $record) { if (isset($record['id']['record_id'])) { $billingCyclesDataMap[$record['id']['record_id']] = $record; } } } else { error_log("Error JSON/Data (Billing Cycles): " . json_last_error_msg()); }
    } else { error_log("Error API (Billing Cycles): " . ($billingCyclesResult['error'] ?? $billingCyclesResult['response'])); }
}

// --- PASO 4: Obtener Datos de Users ---
$usersDataMap = [];
if (!empty($uniqueUserIds)) {
    $usersUrl = "{$attioApiBaseUrl}/objects/" . USER_OBJECT_ID . "/records/query";
    $usersPayload = ["filter" => ["record_id" => ['$in' => $uniqueUserIds]]];
    // Llamada a función CORREGIDA: $apiKey va segundo
    $usersResult = makeAttioApiRequest($usersUrl, $attioApiKey, 'POST', $usersPayload);
     if ($usersResult['errno'] === 0 && $usersResult['http_code'] >= 200 && $usersResult['http_code'] < 300) {
        $usersData = json_decode($usersResult['response'], true);
        if (json_last_error() === JSON_ERROR_NONE && isset($usersData['data'])) { foreach ($usersData['data'] as $record) { if (isset($record['id']['record_id'])) { $usersDataMap[$record['id']['record_id']] = $record; } } } else { error_log("Error JSON/Data (Users): " . json_last_error_msg()); }
    } else { error_log("Error API (Users): " . ($usersResult['error'] ?? $usersResult['response'])); }
}

// --- PASO 5: Añadir Atributos Adicionales (last_billing_cycle, payments_user) ---
$finalCompaniesOutput = [];

function getCurrentSelectOptionTitle($recordData, $attributeSlug): ?string {
    if (!isset($recordData['values'][$attributeSlug]) || empty($recordData['values'][$attributeSlug])) { return null; } foreach ($recordData['values'][$attributeSlug] as $valueEntry) { if ($valueEntry['active_until'] === null && isset($valueEntry['option']['title']) && $valueEntry['attribute_type'] === 'select') { return $valueEntry['option']['title']; } } return null;
}

foreach ($billableCompanies as $company) {
    // --- Añadir last_billing_cycle ---
    $latestBillingCycle = null; $latestBillingCycleDate = null; $companyBillingCycleIds = [];
    if (isset($company['values']['billing_cycles']) && is_array($company['values']['billing_cycles'])) { foreach ($company['values']['billing_cycles'] as $ref) { if ($ref['active_until'] === null && isset($ref['target_record_id'])) { $companyBillingCycleIds[] = $ref['target_record_id']; } } }
    foreach ($companyBillingCycleIds as $id) { if (isset($billingCyclesDataMap[$id])) { $cycleData = $billingCyclesDataMap[$id]; try { $cycleDate = new DateTime($cycleData['created_at'], new DateTimeZone('UTC')); if ($latestBillingCycle === null || $cycleDate > $latestBillingCycleDate) { $latestBillingCycleDate = $cycleDate; $latestBillingCycle = $cycleData; } } catch (Exception $e) { error_log("Fecha invalida BC {$id}: {$cycleData['created_at']}"); } } }
    $company['last_billing_cycle'] = $latestBillingCycle;

    // --- Añadir payments_user ---
    $foundPaymentsUser = null; $companyUserIds = [];
     if (isset($company['values']['users']) && is_array($company['values']['users'])) { foreach ($company['values']['users'] as $ref) { if ($ref['active_until'] === null && isset($ref['target_record_id'])) { $companyUserIds[] = $ref['target_record_id']; } } }
    foreach ($companyUserIds as $id) { if (isset($usersDataMap[$id])) { $userData = $usersDataMap[$id]; $userType = getCurrentSelectOptionTitle($userData, 'type'); if ($userType === PAYMENTS_USER_TYPE_VALUE) { $foundPaymentsUser = $userData; break; } } }
     $company['payments_user'] = $foundPaymentsUser;

    $finalCompaniesOutput[] = $company;
}

// --- RESPUESTA FINAL ---
http_response_code(200);
echo json_encode([
    "success" => true,
    "total" => count($finalCompaniesOutput),
    "billable_companies" => $finalCompaniesOutput
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

?>