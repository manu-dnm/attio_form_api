<?php
include('env.php');

// Habilitar CORS
header('Access-Control-Allow-Origin: *'); // O especifica tu dominio
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Establece el encabezado de contenido para devolver JSON
header('Content-Type: application/json');

// Verifica el método HTTP
$method = $_SERVER['REQUEST_METHOD'];

// Respuesta para manejar solicitudes OPTIONS (Preflight)
if ($method === 'OPTIONS') {
    http_response_code(204); // Sin contenido
    exit();
}

// Función para devolver una respuesta en formato JSON final
// Esta función ya es compatible con la estructura deseada, ya que solo codifica lo que recibe.
function finalResponse($data, $status = 200) {
    http_response_code($status);
    // Usar JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT para mejor legibilidad
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit();
}

// --- Lógica principal para get_all_data.php ---

// Solo permitir método GET
if ($method !== 'GET') {
    // Los errores ya usan la estructura con 'success': false
    finalResponse(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."], 405);
}

// 1. Obtener el ID del deal del parámetro GET
if (!isset($_GET['deal_id']) || empty(trim($_GET['deal_id']))) {
    // Los errores ya usan la estructura con 'success': false
    finalResponse(["success" => false, "message" => "Parámetro 'deal_id' es requerido."], 400);
}
$dealId = trim($_GET['deal_id']);

// 2. Clave API (¡MANTENER SEGURA!)
// !! SEGURIDAD: NO USES LA API KEY DIRECTAMENTE AQUÍ EN PRODUCCIÓN !!
// !! USA VARIABLES DE ENTORNO O UN ARCHIVO DE CONFIGURACIÓN SEGURO !!
$attioApiKey = $ATTIO_API_KEY; // <-- ¡¡ REEMPLAZA Y PROTEGE !!

// 3. Función reutilizable para llamadas a la API de Attio
function makeAttioApiCall($objectSlug, $recordId, $apiKey) {
    $attioApiUrl = "https://api.attio.com/v2/objects/" . $objectSlug . "/records/" . urlencode($recordId);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $attioApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $apiResponse = curl_exec($ch);
    $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno > 0) {
        return ["status" => 500, "data" => null, "error" => "cURL Error ({$curlErrno}): {$curlError}"];
    }

    $decodedData = json_decode($apiResponse, true);
    $jsonError = json_last_error();

    if ($jsonError !== JSON_ERROR_NONE && $httpStatusCode < 300) {
         return ["status" => 502, "data" => null, "error" => "JSON Decode Error: " . json_last_error_msg()];
    }

    $apiErrorMessage = null;
    if ($httpStatusCode >= 400 && is_array($decodedData)) {
         if (isset($decodedData['error']['message'])) $apiErrorMessage = $decodedData['error']['message'];
         elseif (isset($decodedData['message'])) $apiErrorMessage = $decodedData['message'];
         elseif (isset($decodedData['errors'][0]['detail'])) $apiErrorMessage = $decodedData['errors'][0]['detail'];
    }

    return ["status" => $httpStatusCode, "data" => $decodedData, "error" => $apiErrorMessage];
}

// --- Orquestación de llamadas ---

// 4. Obtener datos del Deal
$dealResult = makeAttioApiCall('deals', $dealId, $attioApiKey);

if ($dealResult['status'] >= 400) {
    $errorMessage = $dealResult['error'] ?? "No se pudo encontrar el Deal con ID: {$dealId}";
    // Los errores ya usan la estructura con 'success': false
    finalResponse(["success" => false, "message" => $errorMessage, "details" => $dealResult['data']], $dealResult['status']);
}
if ($dealResult['data'] === null || !isset($dealResult['data']['data']['values'])) {
     // Los errores ya usan la estructura con 'success': false
     finalResponse(["success" => false, "message" => "Respuesta inválida o incompleta para el Deal ID: {$dealId}"], 502);
}

$dealValues = $dealResult['data']['data']['values'];

// 5. Extraer datos específicos del Deal y IDs asociados
$dealData = [
    "id" => $dealId,
    "name" => $dealValues['name'][0]['value'] ?? null,
    "contract_start_date" => $dealValues['contract_start_date'][0]['value'] ?? null,
    "contract_end_date" => $dealValues['contract_end_date'][0]['value'] ?? null,
    "value" => isset($dealValues['value'][0]['currency_value']) ? (float)$dealValues['value'][0]['currency_value'] : null,
    "currency_type" => $dealValues['currency_type'][0]['option']['title'] ?? null,
    "data_validation" => $dealValues['data_validation'][0]['value'] ?? null,
];

$associatedCompanyId = $dealValues['associated_company'][0]['target_record_id'] ?? null;
$associatedPeopleRefs = $dealValues['associated_people'] ?? [];
$associatedPeopleIds = [];
if (is_array($associatedPeopleRefs)) {
    foreach ($associatedPeopleRefs as $personRef) {
        if (isset($personRef['target_record_id'])) {
            $associatedPeopleIds[] = $personRef['target_record_id'];
        }
    }
}

// Inicializar datos de compañía y personas
$companyData = null;
$peopleData = [];

// 6. Obtener datos de la Compañía (si existe ID)
if ($associatedCompanyId) {
    $companyResult = makeAttioApiCall('companies', $associatedCompanyId, $attioApiKey);
    if ($companyResult['status'] >= 200 && $companyResult['status'] < 300 && isset($companyResult['data']['data']['values'])) {
        $companyValues = $companyResult['data']['data']['values'];

        $companyName = $companyValues['name'][0]['value'] ?? null;
        $companyLegalName = null;
        if (isset($companyValues['company_legal_name'][0])) {
             if(isset($companyValues['company_legal_name'][0]['value']) && is_scalar($companyValues['company_legal_name'][0]['value'])) {
                 $companyLegalName = $companyValues['company_legal_name'][0]['value'];
             } elseif (is_scalar($companyValues['company_legal_name'][0])) {
                 $companyLegalName = $companyValues['company_legal_name'][0];
             }
        }
        $firstDomain = $companyValues['domains'][0]['domain'] ?? null;

        // Agregar los nuevos atributos solicitados
        $customAttribute = $companyValues['caaa1e2cfcdf6a01f5c03c60c0cd793dfeebd280_1737039659'][0]['value'] ?? null;
        $contractEndDate = $companyValues['contract_end_date'][0]['value'] ?? null;
        $contractValue = isset($companyValues['contract_value'][0]['currency_value']) ? 
            (float)$companyValues['contract_value'][0]['currency_value'] : null;
        $contractValueCurrency = $companyValues['contract_value_currency'][0]['option']['title'] ?? null;
        
        // Nuevos atributos agregados
        $plan = $companyValues['plan'][0]['option']['title'] ?? null; // Asumiendo que es un campo de opción
        $paymentTerms2;
        if ( $companyValues[0] ) {
            $paymentTerms2 = $companyValues['payment_terms_2'][0]['option']['title'] ?? null;
        } // Asumiendo que es un campo de texto )

        $companyData = [
            "id" => $associatedCompanyId,
            "name" => $companyName,
            "company_legal_name" => $companyLegalName,
            "domain" => $firstDomain,
            // Campos existentes
            "contract_start_date" => $customAttribute,
            "contract_end_date" => $contractEndDate,
            "contract_value" => $contractValue,
            "contract_value_currency" => $contractValueCurrency,
            // Nuevos campos agregados
            "plan" => $plan,
            "payment_terms" => $paymentTerms2
        ];
    }
}

// 7. Obtener datos de las Personas (si existen IDs)
if (!empty($associatedPeopleIds)) {
    foreach ($associatedPeopleIds as $personId) {
        $personResult = makeAttioApiCall('people', $personId, $attioApiKey);
        if ($personResult['status'] >= 200 && $personResult['status'] < 300 && isset($personResult['data']['data']['values'])) {
            $personValues = $personResult['data']['data']['values'];

            $fullName = $personValues['name'][0]['full_name'] ?? null;
            $email = $personValues['email_addresses'][0]['email_address'] ?? null;
            $phoneNumber = null;
            if (!empty($personValues['phone_numbers'])
                && isset($personValues['phone_numbers'][0]['phone_number'])
                && is_scalar($personValues['phone_numbers'][0]['phone_number']))
            {
                $phoneNumber = $personValues['phone_numbers'][0]['phone_number'];
            }
            elseif ($phoneNumber === null
                    && !empty($personValues['second_phone'])
                    && isset($personValues['second_phone'][0]['phone_number'])
                    && is_scalar($personValues['second_phone'][0]['phone_number']))
            {
                 $phoneNumber = $personValues['second_phone'][0]['phone_number'];
            }

            $peopleData[] = [
                "id" => $personId,
                "full_name" => $fullName,
                "email" => $email,
                "phone_number" => $phoneNumber
            ];
        }
    }
}

// 8. Ensamblar y devolver la respuesta final

// --- INICIO: CAMBIO EN LA RESPUESTA FINAL ---
// Ensamblar los datos específicos como antes
$assembledData = [
    "company" => $companyData,
    "deal" => $dealData,
    "people" => $peopleData
];

// Envolver los datos ensamblados en la estructura estándar de éxito
$finalOutput = [
    "success" => true,
    "data" => $assembledData
];
// --- FIN: CAMBIO EN LA RESPUESTA FINAL ---


// Obteniendo la fecha/hora actual en México Central
date_default_timezone_set('America/Mexico_City');
// Current time is Thursday, April 3, 2025 at 12:04:50 AM CST
// Location is Zapopan, Jalisco, Mexico
$currentDateTime = date('Y-m-d H:i:s T');

// Puedes añadir la info de fecha/hora a la respuesta si es útil:
// $finalOutput['generated_at'] = $currentDateTime;
// $finalOutput['generated_from'] = "Zapopan, Jalisco, Mexico";

// Llamar a finalResponse con el nuevo $finalOutput que ya incluye success: true y data: {...}
finalResponse($finalOutput, 200); // OK

?>