<?php
include('env.php');

// Habilitar CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Verifica el método HTTP
$method = $_SERVER['REQUEST_METHOD'];

// Manejar solicitudes OPTIONS
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// Función para devolver respuesta JSON
function response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit();
}

// --- Lógica principal ---

// Solo permitir método GET
if ($method !== 'GET') {
    response(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."], 405);
}

// Validar parámetro ID
if (!isset($_GET['id']) || empty(trim($_GET['id']))) {
    response(["success" => false, "message" => "Parámetro 'id' de la compañía es requerido."], 400);
}
$companyId = trim($_GET['id']);

// Configurar solicitud a Attio
$attioApiKey = $ATTIO_API_KEY;
$attioApiUrl = "https://api.attio.com/v2/objects/companies/records/" . urlencode($companyId);

// Realizar solicitud cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $attioApiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $attioApiKey,
        'Content-Type: application/json'
    ],
    CURLOPT_TIMEOUT => 15
]);

$apiResponse = curl_exec($ch);
$httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

// Manejar errores de cURL
if ($curlErrno > 0) {
    response([
        "success" => false,
        "message" => "Error al contactar la API de Attio",
        "error_details" => "cURL Error ({$curlErrno}): {$curlError}"
    ], 500);
}

// Decodificar respuesta JSON
$attioData = json_decode($apiResponse, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    response([
        "success" => false,
        "message" => "Respuesta inválida de la API de Attio",
        "error_details" => "JSON Error: " . json_last_error_msg(),
        "raw_response" => substr($apiResponse, 0, 1000) // Muestra parte de la respuesta para depuración
    ], 502);
}

// Devolver toda la respuesta de Attio
if ($httpStatusCode >= 200 && $httpStatusCode < 300) {
    response([
        "success" => true,
        "data" => $attioData // Devuelve todo el objeto de Attio
    ], 200);
} else {
    // Manejar errores de la API de Attio
    $errorMessage = $attioData['error']['message'] ?? 
                  $attioData['message'] ?? 
                  $attioData['errors'][0]['detail'] ?? 
                  "Error desconocido desde la API de Attio";
    
    response([
        "success" => false,
        "message" => $errorMessage,
        "attio_response" => $attioData, // Incluye toda la respuesta de error
        "status_code" => $httpStatusCode
    ], $httpStatusCode);
}
?>