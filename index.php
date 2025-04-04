<?php

// Habilitar CORS
header('Access-Control-Allow-Origin: *'); // Permite solicitudes desde cualquier origen (*). Cambia '*' por el dominio específico si lo deseas
header('Access-Control-Allow-Methods: POST, GET, OPTIONS'); // Métodos permitidos
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

// Función para devolver una respuesta en formato JSON
function response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

if ($method == 'GET') {
    response(["success" => true, "message" => "Welcome to the Attio API"]);
} else {
    response(["message" => "Método HTTP no permitido."], 405);
}

?>
