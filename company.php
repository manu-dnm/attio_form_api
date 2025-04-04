<?php

// Habilitar CORS (copiado de tu index.php)
header('Access-Control-Allow-Origin: *'); // Permite solicitudes desde cualquier origen (*). Cambia '*' por el dominio específico si lo deseas
header('Access-Control-Allow-Methods: GET, OPTIONS'); // Métodos permitidos (solo GET y OPTIONS para este endpoint)
header('Access-Control-Allow-Headers: Content-Type, Authorization'); // Cabeceras permitidas

// Establece el encabezado de contenido para devolver JSON
header('Content-Type: application/json');

// Verifica el método HTTP
$method = $_SERVER['REQUEST_METHOD'];

// Respuesta para manejar solicitudes OPTIONS (Preflight request de CORS)
if ($method === 'OPTIONS') {
    // Solo se responde a las solicitudes OPTIONS sin ejecutar más lógica
    http_response_code(204); // Sin contenido
    exit();
}

// Función para devolver una respuesta en formato JSON (copiada de tu index.php)
function response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

// --- Lógica específica para company.php ---

// Solo permitir método GET
if ($method !== 'GET') {
    response(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."], 405);
}

// 1. Obtener el ID de la compañía del parámetro GET
if (!isset($_GET['id']) || empty(trim($_GET['id']))) {
    response(["success" => false, "message" => "Parámetro 'id' de la compañía es requerido."], 400);
}
$companyId = trim($_GET['id']);

// 2. Preparar la solicitud a la API de Attio
$attioApiKey = 'b201f2d5e696252ff74f9e564683e2c9909f4c2e76cfc8793333274824649056'; // <-- ¡REEMPLAZA ESTO CON TU API KEY REAL!
// Asegúrate que el objeto es 'companies' y el endpoint es el correcto
$attioApiUrl = "https://api.attio.com/v2/objects/companies/records/" . urlencode($companyId);

// 3. Realizar la solicitud a Attio usando cURL
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $attioApiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Devolver la respuesta como string
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $attioApiKey,
    'Content-Type: application/json' // Aunque es GET, es buena práctica incluirlo si la API lo espera
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Timeout de 10 segundos

$apiResponse = curl_exec($ch);
$httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);

curl_close($ch);

// 4. Procesar la respuesta de Attio
if ($curlErrno > 0) {
    // Error durante la ejecución de cURL
    response([
        "success" => false,
        "message" => "Error al contactar la API de Attio.",
        "details" => "cURL Error ({$curlErrno}): {$curlError}"
    ], 500); // Internal Server Error o Bad Gateway (502) podrían ser apropiados
}

// Decodificar la respuesta JSON de Attio
$attioData = json_decode($apiResponse, true); // true para obtener un array asociativo
$jsonError = json_last_error();

if ($jsonError !== JSON_ERROR_NONE) {
    // El JSON recibido de Attio no es válido
     response([
        "success" => false,
        "message" => "Respuesta inválida recibida de la API de Attio.",
        "details" => "Error de decodificación JSON: " . json_last_error_msg(),
        "raw_response" => (strlen($apiResponse) < 500) ? $apiResponse : substr($apiResponse, 0, 500) . '...' // Muestra parte de la respuesta si no es muy larga
    ], 502); // Bad Gateway
}


// Verificar el código de estado HTTP de la respuesta de Attio
if ($httpStatusCode >= 200 && $httpStatusCode < 300) {
    // Éxito (generalmente 200 OK)

    // Extraer los datos requeridos
    // NOTA: Ajusta los nombres de los campos ('name', 'company_legal_name', 'domains')
    // y la estructura ('data', 'values') según la respuesta real de la API de Attio V2.
    // Attio V2 a menudo devuelve valores como arrays, incluso si solo hay uno.
    $companyName = $attioData['data']['values']['name'][0]['value'] ?? null; // Accede al primer elemento
    $legalName = $attioData['data']['values']['company_legal_name'][0] ?? null; // Accede al primer elemento
    $domains = $attioData['data']['values']['domains'][0]['domain'] ?? []; // Puede ser un array vacío

    if ($companyName === null && $legalName === null && empty($domains)) {
         response([
            "success" => false,
            "message" => "No se encontraron los datos esperados (name, company_legal_name, domains) en la respuesta de Attio para el ID: " . $companyId,
            "attio_response_structure" => $attioData // Devuelve la estructura para depuración
        ], 404); // O 200 con mensaje si prefieres
    }

    // Devolver la respuesta exitosa
    response([
        "success" => true,
        "data" => [
            "id" => $companyId, // Puedes incluir el ID solicitado
            "name" => $companyName,
            "company_legal_name" => $legalName,
            "domains" => $domains
        ]
    ], 200); // OK

} else {
    // Hubo un error en la solicitud a la API de Attio (e.g., 404 Not Found, 401 Unauthorized, 5xx)
    $errorMessage = "Error desde la API de Attio.";
    if (isset($attioData['error']['message'])) {
        $errorMessage = $attioData['error']['message']; // Intenta obtener un mensaje de error específico de Attio
    } elseif (isset($attioData['message'])) {
         $errorMessage = $attioData['message'];
    } elseif(isset($attioData['errors'][0]['detail'])) {
        $errorMessage = $attioData['errors'][0]['detail'];
    }


    response([
        "success" => false,
        "message" => $errorMessage,
        "attio_status_code" => $httpStatusCode,
        "attio_response" => $attioData // Incluir la respuesta completa de error de Attio puede ser útil para depurar
    ], $httpStatusCode); // Usa el mismo código de estado que devolvió Attio
}

?>