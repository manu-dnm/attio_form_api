<?php
// users_by_company.php (v3 - finds FIRST Finance Admin User)

// --- INCLUDES Y CONFIGURACIÓN INICIAL ---
include('env.php');
date_default_timezone_set('UTC');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// --- MANEJO DEL MÉTODO HTTP ---
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit(); }
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método HTTP no permitido. Solo se permite GET."], JSON_UNESCAPED_UNICODE);
    exit();
}

// --- OBTENER Y VALIDAR PARÁMETRO ---
$companyId = $_GET['company_id'] ?? null;
if (empty($companyId)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Parámetro company_id es requerido."], JSON_UNESCAPED_UNICODE);
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

// --- DEFINICIÓN DE CONSTANTES (¡¡¡VERIFICA Y AJUSTA ESTOS SLUGS!!!) ---
define('COMPANY_OBJECT_SLUG', 'companies');
define('USER_OBJECT_SLUG', 'users');
define('PERSON_OBJECT_SLUG', 'people');
define('COMPANY_LINKED_USERS_SLUG', 'users');
define('USER_LINKED_PERSON_SLUG', 'person'); // Atributo en User que linkea a Person

// Atributos en User
define('USER_TYPE_ATTRIBUTE_SLUG', 'type');
// ¡¡¡ IMPORTANTE: Título exacto de la opción a buscar !!!
define('USER_TYPE_FINANCE_ADMIN_TITLE', 'Finance Admin / Payments'); // Ajusta si el título es diferente

// Atributos en Person
define('PERSON_NAME_SLUG', 'name');
define('PERSON_EMAIL_SLUG', 'email_addresses');
define('PERSON_PHONE_SLUG', 'phone_numbers');

// --- FUNCIÓN AUXILIAR cURL (Reutilizada) ---
function makeAttioApiRequest($url, $apiKey, $method = 'GET', $payload = null) {
    $ch = curl_init(); $headers = [ 'Authorization: Bearer ' . $apiKey, 'Accept: application/json', 'Content-Type: application/json'];
    $options = [ CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60 ];
    if (strtoupper($method) === 'POST') { $options[CURLOPT_POST] = true; if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    elseif (strtoupper($method) !== 'GET') { $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method); if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    curl_setopt_array($ch, $options); $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); $errno = curl_errno($ch); curl_close($ch);
    return ['response' => $response, 'http_code' => $httpCode, 'error' => $error, 'errno' => $errno];
}

// --- FUNCIONES AUXILIARES PARA OBTENER VALORES DE ATTIO (CON SOPORTE MULTI-SELECT TITLES) ---
function getAttributeValue($recordData, $attributeSlug, $expectedType = 'string') {
    if ($recordData === null) { return null; }
    $attributesSource = null;
    if (isset($recordData['values']) && is_array($recordData['values'])) { $attributesSource = $recordData['values']; }
    elseif (isset($recordData['attributes']) && is_array($recordData['attributes'])) { $attributesSource = $recordData['attributes']; }
    else { return null; }
    if (!isset($attributesSource[$attributeSlug]) || !is_array($attributesSource[$attributeSlug]) || empty($attributesSource[$attributeSlug])) {
        return ($expectedType === 'select_option_title') ? [] : null; // Devolver array vacío para títulos multi-select no encontrados
    }
    $returnValue = null;
    $isMulti = ($expectedType === 'select_option_title');
    if ($isMulti) { $returnValue = []; }
    foreach ($attributesSource[$attributeSlug] as $valueEntry) {
        if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) {
            $extractedValue = null; $actualAttributeType = $valueEntry['attribute_type'] ?? null;
            switch ($expectedType) {
                case 'date': try { $dateValue = $valueEntry['value'] ?? null; $extractedValue = $dateValue ? new DateTime($dateValue, new DateTimeZone('UTC')) : null; } catch (Exception $e) { $extractedValue = null; } break;
                case 'select_option_id': $extractedValue = $valueEntry['option']['id'] ?? null; break;
                case 'select_option_title': $extractedValue = $valueEntry['option']['title'] ?? null; break; // Extraer título individual
                case 'number': $extractedValue = ($actualAttributeType === 'currency' && isset($valueEntry['currency_value'])) ? (float)$valueEntry['currency_value'] : (isset($valueEntry['value']) ? (float)$valueEntry['value'] : null); break;
                case 'currency_code': $extractedValue = ($actualAttributeType === 'currency' && isset($valueEntry['currency_code'])) ? $valueEntry['currency_code'] : null; break;
                case 'email': $extractedValue = ($actualAttributeType === 'email-address') ? ($valueEntry['email_address'] ?? null) : ($valueEntry['email'] ?? null); break;
                case 'phone': $extractedValue = ($actualAttributeType === 'phone-number') ? ($valueEntry['phone_number'] ?? null) : ($valueEntry['value'] ?? null); break;
                case 'record_reference': $ids = []; if (isset($valueEntry['target_record_id'])) { $ids[] = $valueEntry['target_record_id']; } elseif (isset($valueEntry['target_records']) && is_array($valueEntry['target_records'])) { foreach($valueEntry['target_records'] as $target) { if (isset($target['target_record_id'])) { $ids[] = $target['target_record_id']; } } } $extractedValue = $ids; break;
                case 'string': default: $extractedValue = $valueEntry['value'] ?? null; break;
            }
            if ($extractedValue !== null) {
                if ($isMulti) { $returnValue[] = $extractedValue; } // Añadir al array
                else { $returnValue = $extractedValue; break; } // Asignar y salir para valor único
            }
        }
    }
    return $returnValue;
}

// Función getLinkedRecordIds sin cambios
function getLinkedRecordIds($recordData, $attributeSlug) {
    $sourceKey = 'values'; if ($recordData === null || !isset($recordData[$sourceKey][$attributeSlug]) || !is_array($recordData[$sourceKey][$attributeSlug]) || empty($recordData[$sourceKey][$attributeSlug])) { return []; } $ids = [];
    foreach ($recordData[$sourceKey][$attributeSlug] as $valueEntry) { if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) { if (isset($valueEntry['target_record_id'])) { $ids[] = $valueEntry['target_record_id']; } elseif (isset($valueEntry['target_records']) && is_array($valueEntry['target_records'])) { foreach ($valueEntry['target_records'] as $target) { if (isset($target['target_record_id'])) { $ids[] = $target['target_record_id']; } } } } }
    return array_unique($ids);
}

// --- LÓGICA PRINCIPAL ---
try {
    // --- 1. Obtener Compañía ---
    $companyUrl = "{$attioApiBaseUrl}/objects/" . COMPANY_OBJECT_SLUG . "/records/{$companyId}";
    error_log("FindFinanceAdmin: Solicitando compañía ID: $companyId");
    $companyResult = makeAttioApiRequest($companyUrl, $attioApiKey, 'GET');
    // (Manejo de errores para compañía igual que antes...)
     if ($companyResult['http_code'] === 404) { http_response_code(404); echo json_encode(["success" => false, "message" => "Compañía con ID '$companyId' no encontrada."], JSON_UNESCAPED_UNICODE); exit(); }
     if ($companyResult['errno'] > 0) { throw new Exception("Error cURL (Compañía): " . $companyResult['error'], 500); }
     $companyResponseData = json_decode($companyResult['response'], true);
     if (json_last_error() !== JSON_ERROR_NONE) { throw new Exception("Error JSON (Compañía): " . json_last_error_msg(), 502); }
     if ($companyResult['http_code'] < 200 || $companyResult['http_code'] >= 300) { throw new Exception("Error API Attio (Compañía): Código {$companyResult['http_code']} | " . $companyResult['response'], $companyResult['http_code']); }
     $companyData = $companyResponseData['data'] ?? null;
     if ($companyData === null) { throw new Exception("Respuesta API para compañía $companyId no contenía datos válidos.", 500); }

    // --- 2. Extraer IDs de usuarios vinculados ---
    error_log("FindFinanceAdmin: Extrayendo IDs de usuarios de compañía $companyId");
    $linkedUserIds = getLinkedRecordIds($companyData, COMPANY_LINKED_USERS_SLUG);
    $linkedUserIdsCount = count($linkedUserIds);
    error_log("FindFinanceAdmin: $linkedUserIdsCount IDs de usuarios vinculados encontrados.");

    $targetUserId = null;
    $targetPersonId = null;
    $targetUserTypeTitles = []; // Para guardar los tipos del usuario encontrado
    $usersDataMap = []; // Todavía necesitamos obtener todos los usuarios para buscar

    // --- 3. Obtener detalles de TODOS los usuarios vinculados (para poder buscar el tipo) ---
    if (!empty($linkedUserIds)) {
        error_log("FindFinanceAdmin: Solicitando detalles para $linkedUserIdsCount usuarios...");
        $usersUrl = "{$attioApiBaseUrl}/objects/" . USER_OBJECT_SLUG . "/records/query";
        $usersPayload = ["filter" => ["record_id" => ['$in' => $linkedUserIds]]];
        $usersResult = makeAttioApiRequest($usersUrl, $attioApiKey, 'POST', $usersPayload);

        if ($usersResult['errno'] === 0 && $usersResult['http_code'] >= 200 && $usersResult['http_code'] < 300) {
            $usersData = json_decode($usersResult['response'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($usersData['data'])) {
                $foundUsersData = $usersData['data'];
                error_log("FindFinanceAdmin: API devolvió datos para " . count($foundUsersData) . " usuarios. Buscando Finance Admin...");

                // --- 4. Buscar el PRIMER Usuario "Finance Admin / Payments" ---
                foreach ($foundUsersData as $userData) {
                    $currentUserId = $userData['id']['record_id'] ?? null; // Asume estructura User ID
                    if (!$currentUserId) continue;

                    // Obtener los títulos de tipo (devuelve array)
                    $userTypesArray = getAttributeValue($userData, USER_TYPE_ATTRIBUTE_SLUG, 'select_option_title');

                    // Verificar si el tipo buscado está en el array
                    if (is_array($userTypesArray) && in_array(USER_TYPE_FINANCE_ADMIN_TITLE, $userTypesArray)) {
                        error_log("FindFinanceAdmin: ¡Encontrado! User ID '$currentUserId' es Finance Admin.");
                        $targetUserId = $currentUserId; // Guardar el ID del usuario encontrado
                        $targetUserTypeTitles = $userTypesArray; // Guardar sus tipos

                        // Extraer el ID de la persona vinculada a ESTE usuario
                        $personIdArray = getAttributeValue($userData, USER_LINKED_PERSON_SLUG, 'record_reference');
                        if (!empty($personIdArray) && isset($personIdArray[0])) {
                            $targetPersonId = $personIdArray[0];
                            error_log("FindFinanceAdmin: User '$targetUserId' vinculado a Person '$targetPersonId'.");
                        } else {
                             error_log("FindFinanceAdmin: User '$targetUserId' (Finance Admin) no tiene un Person vinculado.");
                        }
                        break; // Salir del bucle foreach, ya encontramos el primero
                    }
                } // Fin foreach $foundUsersData
                if ($targetUserId === null) {
                     error_log("FindFinanceAdmin: No se encontró ningún usuario con el tipo '" . USER_TYPE_FINANCE_ADMIN_TITLE . "'.");
                }

            } else { error_log("FindFinanceAdmin: Error JSON/Data al obtener usuarios."); }
        } else { error_log("FindFinanceAdmin: Error API/cURL al obtener usuarios: Código {$usersResult['http_code']}"); }
    } // Fin if (!empty($linkedUserIds))

    // --- 5. Obtener detalles de la Persona vinculada (si se encontró) ---
    $personData = null; // Inicializar
    if ($targetPersonId !== null) {
        error_log("FindFinanceAdmin: Solicitando detalles para la Persona ID: $targetPersonId");
        $personUrl = "{$attioApiBaseUrl}/objects/" . PERSON_OBJECT_SLUG . "/records/{$targetPersonId}"; // GET directo
        $personResult = makeAttioApiRequest($personUrl, $attioApiKey, 'GET');

        if ($personResult['http_code'] === 404) {
             error_log("FindFinanceAdmin: Persona con ID '$targetPersonId' no encontrada (quizás eliminada?).");
             // $personData permanece null
        } elseif ($personResult['errno'] > 0 || $personResult['http_code'] < 200 || $personResult['http_code'] >= 300) {
             error_log("FindFinanceAdmin: Error API/cURL al obtener persona '$targetPersonId': Código {$personResult['http_code']}");
             // $personData permanece null
        } else {
            $personApiResponse = json_decode($personResult['response'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($personApiResponse['data'])) {
                $personData = $personApiResponse['data']; // Guardar datos de la persona
                error_log("FindFinanceAdmin: Datos de la Persona '$targetPersonId' obtenidos.");
            } else {
                error_log("FindFinanceAdmin: Error JSON/Data al obtener persona '$targetPersonId'.");
                 // $personData permanece null
            }
        }
    } else {
        error_log("FindFinanceAdmin: No se encontró un Person ID vinculado al Finance Admin user, no se solicitarán detalles de persona.");
    }

    // --- 6. Formatear la Salida Final (solo un usuario o null) ---
    $outputUser = null; // Cambiado de $outputUsers a $outputUser
    if ($targetUserId !== null) {
        error_log("FindFinanceAdmin: Formateando salida para User ID $targetUserId");
        $personName = null;
        $personEmail = null;
        $personPhone = null;

        // Extraer detalles de la persona si obtuvimos sus datos
        if ($personData !== null) {
            $personName = getAttributeValue($personData, PERSON_NAME_SLUG, 'string');
            $personEmail = getAttributeValue($personData, PERSON_EMAIL_SLUG, 'email');
            $personPhone = getAttributeValue($personData, PERSON_PHONE_SLUG, 'phone');
            error_log("FindFinanceAdmin: Detalles extraídos de Person: Name=".($personName ?? 'null').", Email=".($personEmail ?? 'null').", Phone=".($personPhone ?? 'null'));
        } else {
             error_log("FindFinanceAdmin: No se pudieron obtener/extraer detalles de la persona asociada.");
        }

        $outputUser = [ // Asignar al objeto simple, no al array
            "user_id" => $targetUserId,
            "name" => $personName,
            "email" => $personEmail,
            "phone" => $personPhone,
            "user_type" => $targetUserTypeTitles // Array de tipos del usuario encontrado
        ];
    }

    // --- 7. Respuesta Final ---
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "company_id" => $companyId,
        "user" => $outputUser // Devolver el objeto usuario encontrado (o null si no se encontró)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // ... (Bloque catch sin cambios) ...
     $errorCode = $e->getCode() >= 400 ? $e->getCode() : 500;
     http_response_code($errorCode);
     error_log("Error en users_by_company.php (FindFinanceAdmin): " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
     echo json_encode([
         "success" => false,
         "message" => "Ocurrió un error interno procesando la solicitud.",
         "details" => $e->getMessage() // Opcional
     ], JSON_UNESCAPED_UNICODE);
}

?>