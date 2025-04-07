<?php

include('env.php');

// --- CONFIGURACIÓN ---

// ¡¡ NUNCA USES LA CLAVE DIRECTAMENTE EN CÓDIGO DE PRODUCCIÓN !!
// ¡¡ USA VARIABLES DE ENTORNO U OTRO MÉTODO SEGURO !!
$attioApiKey = $ATTIO_API_KEY; // <-- ¡¡ REEMPLAZA Y PROTEGE !!

// Registros a obtener: [slug => record_id]
$recordsToFetch = [
    'companies'      => '50d47ba1-d5dd-499d-b6e7-215440c5f760',
    'deals'          => '5ea307fa-2f0a-40a0-86ec-27d1393b1662',
    'people'         => 'b18152c4-93f9-4c5b-8f6c-ec24a7407f25',
    'users'          => '773263e6-29f0-41d5-90b5-adcf0e8920be',
    'billing_cycles' => '4aadeda1-2398-4c67-be33-1deeedda08ca' // Slug exacto de tu objeto personalizado
];

// --- FIN CONFIGURACIÓN ---

// --- HEADERS ---
// Habilitar CORS (Opcional, útil si llamas desde un navegador en localhost)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Establece el encabezado de contenido para devolver JSON
header('Content-Type: application/json');

// Manejo básico de OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}
// --- FIN HEADERS ---


// --- FUNCIÓN REUTILIZABLE PARA LLAMADAS API ---
// (Similar a la usada en get_all_data.php)
function makeAttioApiCall($objectSlug, $recordId, $apiKey) {
    $attioApiUrl = "https://api.attio.com/v2/objects/" . urlencode($objectSlug) . "/records/" . urlencode($recordId);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $attioApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Timeout
    $apiResponse = curl_exec($ch);
    $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    $result = [
        'status' => $httpStatusCode,
        'data' => null,
        'error' => null,
        'curl_error' => null
    ];

    if ($curlErrno > 0) {
        $result['status'] = 500; // Considerar error cURL como 500
        $result['curl_error'] = "cURL Error ({$curlErrno}): {$curlError}";
        return $result;
    }

    $decodedData = json_decode($apiResponse, true);
    $jsonError = json_last_error();

    // Incluso si hay un error HTTP (>=400), intentar decodificar el cuerpo por si Attio devuelve un error JSON
    if ($jsonError === JSON_ERROR_NONE) {
         $result['data'] = $decodedData; // Guardar datos decodificados (pueden ser datos de éxito o de error de Attio)
    } elseif ($httpStatusCode < 300) {
        // Si el status es OK pero el JSON es inválido, es un problema
        $result['status'] = 502; // Bad Gateway
        $result['error'] = "Respuesta inválida (JSON malformado) recibida de Attio.";
    }
     // Si el status es >= 400 y el JSON es inválido, es menos crítico, podríamos usar el cuerpo crudo.
     // Por ahora, lo dejamos así, el 'data' será null si el JSON falla.

    // Extraer mensaje de error específico de Attio si existe
    if ($httpStatusCode >= 400 && is_array($decodedData)) {
         if (isset($decodedData['error']['message'])) $result['error'] = $decodedData['error']['message'];
         elseif (isset($decodedData['message'])) $result['error'] = $decodedData['message'];
         elseif (isset($decodedData['errors'][0]['detail'])) $result['error'] = $decodedData['errors'][0]['detail'];
         elseif (empty($result['error'])) $result['error'] = "Error de API de Attio (Status {$httpStatusCode})";
    } elseif ($httpStatusCode >= 400 && empty($result['error'])) {
        // Si hubo error HTTP pero no se pudo decodificar JSON de error
         $result['error'] = "Error de API de Attio (Status {$httpStatusCode}). Respuesta no JSON.";
    }


    return $result;
}
// --- FIN FUNCIÓN API ---


// --- LÓGICA PRINCIPAL ---
$allResults = [];

foreach ($recordsToFetch as $slug => $recordId) {
    if (empty($slug) || empty($recordId)) {
        $allResults[$slug ?? 'invalid_slug'] = [
            'status' => 400,
            'data' => null,
            'error' => 'Slug o Record ID inválido/vacío proporcionado en la configuración.',
            'curl_error' => null
        ];
        continue;
    }
    // Llamar a la API para cada registro
    $apiResult = makeAttioApiCall($slug, $recordId, $attioApiKey);
    // Guardar el resultado completo (status, data, error) bajo la clave del slug
    $allResults[$slug] = $apiResult;
}
// --- FIN LÓGICA PRINCIPAL ---


// --- SALIDA FINAL ---
// Establecer el código de respuesta general (ej. 200 OK, incluso si algunas llamadas fallaron,
// ya que el script en sí funcionó; los errores individuales están en el cuerpo)
http_response_code(200);

// Imprimir el JSON combinado
// Current time is Thursday, April 3, 2025 at 6:03:16 PM CST (Zapopan, Jalisco, Mexico)
echo json_encode($allResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

exit(); // Terminar script

?>