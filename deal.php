<?php

// Habilitar CORS
header('Access-Control-Allow-Origin: *'); // O especifica tu dominio
header('Access-Control-Allow-Methods: GET, OPTIONS'); // Solo GET y OPTIONS
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

// Función para devolver una respuesta en formato JSON
function response($data, $status = 200) {
    http_response_code($status);
    // Usar JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES para mejor legibilidad si hay caracteres especiales o URLs
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// --- Lógica específica para deal.php ---

// Solo permitir método GET
if ($method !== 'GET') {
    response(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."], 405);
}

// 1. Obtener el ID del deal del parámetro GET
if (!isset($_GET['id']) || empty(trim($_GET['id']))) {
    response(["success" => false, "message" => "Parámetro 'id' del deal es requerido."], 400);
}
$dealId = trim($_GET['id']);
$attioApiKey = 'b201f2d5e696252ff74f9e564683e2c9909f4c2e76cfc8793333274824649056'; // <-- ¡¡ REEMPLAZA ESTO CON TU API KEY !! ¡¡ Y MANTÉNLA SEGURA !!

$objectSlug = 'deals';
$attioApiUrl = "https://api.attio.com/v2/objects/" . $objectSlug . "/records/" . urlencode($dealId);

// https://api.attio.com/v2/objects/deals/records/5ea307fa-2f0a-40a0-86ec-27d1393b1662

// 3. Realizar la solicitud a Attio usando cURL
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $attioApiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $attioApiKey,
    'Content-Type: application/json'
]);
// Aumentar timeout si las respuestas de Attio son lentas
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$apiResponse = curl_exec($ch);
$httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);

curl_close($ch);

// 4. Procesar la respuesta de Attio

// Error de cURL
if ($curlErrno > 0) {
    response([
        "success" => false,
        "message" => "Error al contactar la API de Attio.",
        "details" => "cURL Error ({$curlErrno}): {$curlError}"
    ], 500); // Internal Server Error o Bad Gateway (502)
}

// Decodificar JSON
$attioData = json_decode($apiResponse, true);
$jsonError = json_last_error();

// Error de JSON
if ($jsonError !== JSON_ERROR_NONE) {
     response([
        "success" => false,
        "message" => "Respuesta inválida (JSON malformado) recibida de la API de Attio.",
        "details" => "Error de decodificación JSON: " . json_last_error_msg(),
        "raw_response" => (strlen($apiResponse) < 500) ? $apiResponse : substr($apiResponse, 0, 500) . '...'
    ], 502); // Bad Gateway
}

// Verificar código de estado HTTP de Attio
if ($httpStatusCode >= 200 && $httpStatusCode < 300) {
    // Éxito (200 OK)

    // Extraer todos los atributos (values) del deal
    $dealAttributes = null;
    if (isset($attioData['data']['values']) && is_array($attioData['data']['values'])) {
        $dealAttributes = $attioData['data']['values'];

        // Opcional: Podrías aquí procesar $dealAttributes para simplificar
        // estructuras como hiciste con company.php (extraer 'value', etc.)
        // si ya sabes qué campos quieres y cómo vienen.
        // Por ahora, devolvemos todos los 'values' tal cual.

    } else {
        // La estructura esperada 'data.values' no vino, aunque el status fue 2xx
         response([
            "success" => false,
            "message" => "Respuesta exitosa de Attio, pero la estructura de datos ('data.values') no se encontró.",
            "attio_status_code" => $httpStatusCode,
            "attio_response" => $attioData
        ], 502); // Bad Gateway - respuesta inesperada del upstream
    }

    // Devolver la respuesta exitosa
    response([
        "success" => true,
        "data" => [
            "id" => $dealId, // Incluir el ID solicitado
            "attributes" => $dealAttributes // Devolver todos los atributos encontrados
        ]
    ], 200); // OK

} else {
    // Error desde la API de Attio (4xx, 5xx)
    $errorMessage = "Error desde la API de Attio.";
     // Intentar extraer un mensaje de error más específico de la respuesta de Attio
     if (isset($attioData['error']['message'])) {
        $errorMessage = $attioData['error']['message'];
    } elseif (isset($attioData['message'])) {
         $errorMessage = $attioData['message'];
    } elseif (isset($attioData['errors'][0]['detail'])) {
        $errorMessage = $attioData['errors'][0]['detail'];
    } elseif (!empty($apiResponse) && is_string($apiResponse)) {
         // Si no hay JSON de error estructurado, usar la respuesta cruda (si es texto)
         $errorMessage = $apiResponse;
    }

    response([
        "success" => false,
        "message" => $errorMessage,
        "attio_status_code" => $httpStatusCode,
        // Incluir 'attio_response' solo si no es el mismo $errorMessage y es útil
        "attio_response" => (is_array($attioData) && $errorMessage != $apiResponse) ? $attioData : null
    ], $httpStatusCode); // Usa el mismo código de estado que devolvió Attio
}

?>