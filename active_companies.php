<?php
include('env.php');

// Habilitar CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Verificar el método HTTP
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."]);
    exit();
}

// --- Configuración API Attio ---
$attioApiKey = $ATTIO_API_KEY;
$attioApiUrl = "https://api.attio.com/v2/objects/companies/records/query";

// Payload vacío (sin filtros, sin paginación)
$payload = [
    "filter" => [
        "status" => "b09e60c1-7d87-4209-883e-f28cc26743b0",
    ]
];

// Realizar solicitud POST con cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $attioApiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $attioApiKey,
        'Accept: application/json',
        'Content-Type: application/json'
    ],
    CURLOPT_TIMEOUT => 15
]);

$apiResponse = curl_exec($ch);
$httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

// Manejo de errores de red
if ($curlErrno > 0) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al contactar la API de Attio",
        "error_details" => "cURL Error ({$curlErrno}): {$curlError}"
    ]);
    exit();
}

// Decodificar respuesta JSON
$attioData = json_decode($apiResponse, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "message" => "Respuesta inválida de la API de Attio",
        "error_details" => "JSON Error: " . json_last_error_msg(),
        "raw_response" => substr($apiResponse, 0, 1000)
    ]);
    exit();
}

// Devolver datos exitosamente
if ($httpStatusCode >= 200 && $httpStatusCode < 300) {
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "total" => count($attioData['data']),
        "active_companies" => $attioData['data'][1]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} else {
    $errorMessage = $attioData['error']['message'] ??
                    $attioData['message'] ??
                    $attioData['errors'][0]['detail'] ??
                    "Error desconocido desde la API de Attio";

    http_response_code($httpStatusCode);
    echo json_encode([
        "success" => false,
        "message" => $errorMessage,
        "attio_response" => $attioData,
        "status_code" => $httpStatusCode
    ]);
}
?>
