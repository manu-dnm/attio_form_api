<?php
// users_by_company.php (v2 - fetches Person details)

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
define('PERSON_OBJECT_SLUG', 'people'); // ¿Es 'people' u otro? ¡VERIFICAR!
define('COMPANY_LINKED_USERS_SLUG', 'users'); // Atributo en Company que linkea a Users

// Atributo en User que linkea a Person
define('USER_LINKED_PERSON_SLUG', 'person'); // ¿Es 'person' u otro? ¡VERIFICAR!

// Atributos en el objeto Person
define('PERSON_NAME_SLUG', 'name');              // ¿Es 'name', 'full_name'? ¡VERIFICAR!
define('PERSON_EMAIL_SLUG', 'email_addresses');  // ¿Es 'email', 'email_addresses', 'primary_email'? ¡VERIFICAR!
define('PERSON_PHONE_SLUG', 'phone_numbers');    // ¿Es 'phone', 'phone_numbers'? ¡VERIFICAR!
define('USER_TYPE_ATTRIBUTE_SLUG', 'type');    // ¿Es 'phone', 'phone_numbers'? ¡VERIFICAR!


// --- FUNCIÓN AUXILIAR cURL (Reutilizada) ---
function makeAttioApiRequest($url, $apiKey, $method = 'GET', $payload = null) {
    $ch = curl_init(); $headers = [ 'Authorization: Bearer ' . $apiKey, 'Accept: application/json', 'Content-Type: application/json'];
    $options = [ CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60 ];
    if (strtoupper($method) === 'POST') { $options[CURLOPT_POST] = true; if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    elseif (strtoupper($method) !== 'GET') { $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method); if ($payload !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($payload); } }
    curl_setopt_array($ch, $options); $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); $errno = curl_errno($ch); curl_close($ch);
    return ['response' => $response, 'http_code' => $httpCode, 'error' => $error, 'errno' => $errno];
}

// --- FUNCIONES AUXILIARES PARA OBTENER VALORES DE ATTIO (Reutilizadas y Corregidas) ---
function getAttributeValue($recordData, $attributeSlug, $expectedType = 'string') {
    if ($recordData === null) { return null; }

    // Determinar fuente de atributos
    $attributesSource = null;
    if (isset($recordData['values']) && is_array($recordData['values'])) { $attributesSource = $recordData['values']; }
    elseif (isset($recordData['attributes']) && is_array($recordData['attributes'])) { $attributesSource = $recordData['attributes']; }
    else { return null; }

    if (!isset($attributesSource[$attributeSlug]) || !is_array($attributesSource[$attributeSlug]) || empty($attributesSource[$attributeSlug])) {
        // Si el tipo esperado es multi-select title, devolver array vacío en lugar de null si no existe
        return ($expectedType === 'select_option_title') ? [] : null;
    }

    $returnValue = null;
    // Tratar 'select_option_title' como potencialmente múltiple
    $isMulti = ($expectedType === 'select_option_title');
    if ($isMulti) { $returnValue = []; } // Inicializar como array vacío

    foreach ($attributesSource[$attributeSlug] as $valueEntry) {
        // Verificar si la entrada es válida y activa
        if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) {
            // Es una entrada activa, extraer valor según el tipo esperado
            $extractedValue = null;
            $actualAttributeType = $valueEntry['attribute_type'] ?? null;

            switch ($expectedType) {
                case 'date':
                     try { $dateValue = $valueEntry['value'] ?? null; $extractedValue = $dateValue ? new DateTime($dateValue, new DateTimeZone('UTC')) : null; } catch (Exception $e) { $extractedValue = null; }
                     break; // Salir del switch
                case 'select_option_id':
                     // Para ID de opción, usualmente solo queremos el primero activo
                     $extractedValue = $valueEntry['option']['id'] ?? null;
                     break; // Salir del switch (asumiendo que no es multi-ID)
                 case 'select_option_title':
                     // Para títulos, SÍ recolectamos todos los activos
                     $extractedValue = $valueEntry['option']['title'] ?? null;
                     // No salimos del switch aquí para que la lógica de abajo lo maneje
                     break;
                case 'number':
                     $extractedValue = ($actualAttributeType === 'currency' && isset($valueEntry['currency_value'])) ? (float)$valueEntry['currency_value'] : (isset($valueEntry['value']) ? (float)$valueEntry['value'] : null);
                     break; // Salir del switch
                 case 'currency_code':
                      $extractedValue = ($actualAttributeType === 'currency' && isset($valueEntry['currency_code'])) ? $valueEntry['currency_code'] : null;
                      break; // Salir del switch
                 case 'email':
                     $extractedValue = ($actualAttributeType === 'email-address') ? ($valueEntry['email_address'] ?? null) : ($valueEntry['email'] ?? null);
                     break; // Salir del switch
                 case 'phone':
                     $extractedValue = ($actualAttributeType === 'phone-number') ? ($valueEntry['phone_number'] ?? null) : ($valueEntry['value'] ?? null);
                     break; // Salir del switch
                case 'record_reference':
                     // Devolver array de IDs. Si se necesita solo uno, manejar fuera de la función.
                     $ids = []; if (isset($valueEntry['target_record_id'])) { $ids[] = $valueEntry['target_record_id']; } elseif (isset($valueEntry['target_records']) && is_array($valueEntry['target_records'])) { foreach($valueEntry['target_records'] as $target) { if (isset($target['target_record_id'])) { $ids[] = $target['target_record_id']; } } }
                     $extractedValue = $ids;
                     break; // Salir del switch
                case 'string': default:
                     $extractedValue = $valueEntry['value'] ?? null;
                     break; // Salir del switch
            } // Fin switch

            // Asignar o añadir el valor extraído si no es null
            if ($extractedValue !== null) {
                if ($isMulti) { // Si esperamos múltiples (solo para select_option_title)
                    $returnValue[] = $extractedValue; // Añadir al array
                    // NO usar break, continuar buscando más títulos activos
                } else { // Si esperamos un solo valor
                    $returnValue = $extractedValue; // Asignar el primer valor activo encontrado
                    break; // Salir del bucle foreach, ya encontramos lo que buscábamos
                }
            }
        } // Fin if (entrada activa)
    } // Fin foreach

    return $returnValue; // Devolver valor único, array de títulos, o null/[]
}

// Función getLinkedRecordIds sin cambios, asume que Company->Users está en 'values'
function getLinkedRecordIds($recordData, $attributeSlug) {
    $sourceKey = 'values';
    if ($recordData === null || !isset($recordData[$sourceKey][$attributeSlug]) || !is_array($recordData[$sourceKey][$attributeSlug]) || empty($recordData[$sourceKey][$attributeSlug])) { return []; }
    $ids = [];
    foreach ($recordData[$sourceKey][$attributeSlug] as $valueEntry) {
        if (is_array($valueEntry) && array_key_exists('active_until', $valueEntry) && $valueEntry['active_until'] === null) {
            if (isset($valueEntry['target_record_id'])) { $ids[] = $valueEntry['target_record_id']; }
            elseif (isset($valueEntry['target_records']) && is_array($valueEntry['target_records'])) { foreach ($valueEntry['target_records'] as $target) { if (isset($target['target_record_id'])) { $ids[] = $target['target_record_id']; } } }
        }
    }
    return array_unique($ids);
}


// --- LÓGICA PRINCIPAL ---

try {
    // --- 1. Obtener el registro de la compañía específica ---
    $companyUrl = "{$attioApiBaseUrl}/objects/" . COMPANY_OBJECT_SLUG . "/records/{$companyId}";
    error_log("UsersByCompany: Solicitando compañía ID: $companyId");
    $companyResult = makeAttioApiRequest($companyUrl, $attioApiKey, 'GET');

    if ($companyResult['http_code'] === 404) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Compañía con ID '$companyId' no encontrada."], JSON_UNESCAPED_UNICODE);
        exit();
    }
    if ($companyResult['errno'] > 0) { throw new Exception("Error cURL (Compañía): " . $companyResult['error'], 500); }
    $companyResponseData = json_decode($companyResult['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE) { throw new Exception("Error JSON (Compañía): " . json_last_error_msg(), 502); }
    if ($companyResult['http_code'] < 200 || $companyResult['http_code'] >= 300) { throw new Exception("Error API Attio (Compañía): Código {$companyResult['http_code']} | " . $companyResult['response'], $companyResult['http_code']); }

    $companyData = $companyResponseData['data'] ?? null;
    if ($companyData === null) { throw new Exception("Respuesta API para compañía $companyId no contenía datos válidos.", 500); }

    // --- 2. Extraer IDs de usuarios vinculados ---
    error_log("UsersByCompany: Extrayendo IDs de usuarios de compañía $companyId");
    $linkedUserIds = getLinkedRecordIds($companyData, COMPANY_LINKED_USERS_SLUG);
    $linkedUserIdsCount = count($linkedUserIds);
    error_log("UsersByCompany: $linkedUserIdsCount IDs de usuarios vinculados encontrados.");

    $usersDataMap = [];      // Para guardar datos de User { user_id => userData }
    $personsDataMap = [];     // Para guardar datos de Person { person_id => personData }
    $userToPersonMap = [];    // Para mapear { user_id => person_id }
    $requiredPersonIds = []; // Para la llamada API de Person
    $outputUsers = [];       // Array final para la respuesta

    // --- 3. Obtener detalles de los usuarios vinculados ---
    if (!empty($linkedUserIds)) {
        error_log("UsersByCompany: Solicitando detalles para $linkedUserIdsCount usuarios...");
        $usersUrl = "{$attioApiBaseUrl}/objects/" . USER_OBJECT_SLUG . "/records/query";
        $usersPayload = ["filter" => ["record_id" => ['$in' => $linkedUserIds]]];
        $usersResult = makeAttioApiRequest($usersUrl, $attioApiKey, 'POST', $usersPayload);

        if ($usersResult['errno'] === 0 && $usersResult['http_code'] >= 200 && $usersResult['http_code'] < 300) {
            $usersData = json_decode($usersResult['response'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($usersData['data'])) {
                $foundUsersData = $usersData['data'];
                error_log("UsersByCompany: API devolvió datos para " . count($foundUsersData) . " usuarios.");

                // --- 4. Extraer IDs de Personas vinculadas ---
                foreach ($foundUsersData as $userData) {
                    // ¡¡¡ VERIFICAR ESTRUCTURA ID USER !!! Asumiendo id.record_id
                    $userId = $userData['id']['record_id'] ?? null;
                    if (!$userId) continue; // Saltar si no hay ID de usuario

                    $usersDataMap[$userId] = $userData; // Guardar datos del usuario

                     // Extraer ID(s) de persona vinculada desde el User
                     // Usamos getAttributeValue porque puede estar en 'values' o 'attributes' y devuelve array
                     // ¡¡¡ Asumiendo que User usa 'values' o 'attributes' !!!
                    $personIdArray = getAttributeValue($userData, USER_LINKED_PERSON_SLUG, 'record_reference');

                    if (!empty($personIdArray) && isset($personIdArray[0])) {
                         $personId = $personIdArray[0]; // Tomar el primer ID de persona vinculado
                         $userToPersonMap[$userId] = $personId; // Mapear User -> Person
                         $requiredPersonIds[] = $personId; // Añadir a la lista para buscar detalles
                         error_log("UsersByCompany: User '$userId' vinculado a Person '$personId'.");
                    } else {
                         error_log("UsersByCompany: User '$userId' no tiene un Person vinculado (o no se pudo extraer).");
                    }
                }
            } else { error_log("UsersByCompany: Error JSON/Data al obtener usuarios."); }
        } else { error_log("UsersByCompany: Error API/cURL al obtener usuarios: Código {$usersResult['http_code']}"); }
    } // Fin if (!empty($linkedUserIds))

    // --- 5. Obtener detalles de las Personas requeridas ---
    $uniquePersonIds = array_values(array_unique($requiredPersonIds));
    $uniquePersonIdsCount = count($uniquePersonIds);
    if (!empty($uniquePersonIds)) {
        error_log("UsersByCompany: Solicitando detalles para $uniquePersonIdsCount personas...");
        $personsUrl = "{$attioApiBaseUrl}/objects/" . PERSON_OBJECT_SLUG . "/records/query";
        $personsPayload = ["filter" => ["record_id" => ['$in' => $uniquePersonIds]]];
        $personsResult = makeAttioApiRequest($personsUrl, $attioApiKey, 'POST', $personsPayload);

        if ($personsResult['errno'] === 0 && $personsResult['http_code'] >= 200 && $personsResult['http_code'] < 300) {
            $personsApiResponse = json_decode($personsResult['response'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($personsApiResponse['data'])) {
                $foundPersonsData = $personsApiResponse['data'];
                error_log("UsersByCompany: API devolvió datos para " . count($foundPersonsData) . " personas. Llenando mapa...");
                $addedToMapCount = 0;
                foreach($foundPersonsData as $personData) {
                    // --- CORRECCIÓN: Extraer ID string de Persona ---
                    $personId = null;
                    if (isset($personData['id'])) {
                        if (is_string($personData['id'])) {
                            // Caso 1: ID es string
                            $personId = $personData['id'];
                        } elseif (is_array($personData['id']) && isset($personData['id']['record_id']) && is_string($personData['id']['record_id'])) {
                            // Caso 2: ID es objeto/array con record_id
                            $personId = $personData['id']['record_id'];
                        }
                        // Podríamos añadir más elseif aquí si sospechamos otras estructuras
                    }
                    // --------------------------------------------

                    // Verificar si obtuvimos un ID string válido
                    if ($personId !== null) { // is_string() está implícito
                        // error_log("UsersByCompany DEBUG: Añadiendo Persona al mapa con clave: '$personId'"); // Log opcional
                        $personsDataMap[$personId] = $personData; // Línea ~206
                        $addedToMapCount++;
                    } else {
                         // --- ¡ESTE LOG ES ESENCIAL AHORA! ---
                         $idInfo = 'NO EXISTE o tipo inválido';
                         if (isset($personData['id'])) {
                             $idInfo = "Tipo: " . gettype($personData['id']) . ", Valor: " . json_encode($personData['id']);
                         }
                         error_log("UsersByCompany: !!! ADVERTENCIA: No se pudo extraer un ID de registro válido (string) del registro de Person. Clave 'id' $idInfo. Registro completo: " . json_encode($personData));
                         // ------------------------------------
                    }
                } // ----- FIN DEL BUCLE A REEMPLAZAR -----
                 error_log("UsersByCompany: Mapa de personas llenado con " . count($personsDataMap) . " registros.");
            } else { error_log("UsersByCompany: Error JSON/Data al obtener personas."); }
        } else { error_log("UsersByCompany: Error API/cURL al obtener personas: Código {$personsResult['http_code']}"); }
    } else {
         error_log("UsersByCompany: No se requieren IDs de Persona.");
    }

    // --- 6. Formatear la Salida Final ---
    error_log("UsersByCompany: Formateando salida...");
    // Iterar sobre los USUARIOS que encontramos originalmente ($usersDataMap)
    foreach ($usersDataMap as $userId => $userData) {
        $personId = $userToPersonMap[$userId] ?? null; // Obtener el ID de persona mapeado
        // ---- AÑADIR LOGS AQUÍ ----
        error_log("UsersByCompany: Procesando Person ID $personId para User ID $userId.");
        error_log("UsersByCompany: Raw Person Data: " . json_encode($personData));

        $personName = getAttributeValue($personData, PERSON_NAME_SLUG, 'string');
        error_log("UsersByCompany: Resultado getAttributeValue(Name): " . var_export($personName, true));

        $personEmail = getAttributeValue($personData, PERSON_EMAIL_SLUG, 'email');
        error_log("UsersByCompany: Resultado getAttributeValue(Email): " . var_export($personEmail, true));

        $personPhone = getAttributeValue($personData, PERSON_PHONE_SLUG, 'phone');
        error_log("UsersByCompany: Resultado getAttributeValue(Phone): " . var_export($personPhone, true));
        // ---------------------------


        // Si tenemos un ID de persona Y encontramos sus datos en el mapa de personas
        if ($personId && isset($personsDataMap[$personId])) {
            $personData = $personsDataMap[$personId];
            // Extraer detalles de la persona
            // ¡¡¡ VERIFICAR SLUGS Y TIPOS ESPERADOS !!!
            $personName = getAttributeValue($personData, PERSON_NAME_SLUG, 'string');
            $personEmail = getAttributeValue($personData, PERSON_EMAIL_SLUG, 'email'); // Ajusta 'email' si el atributo es texto simple
            $personPhone = getAttributeValue($personData, PERSON_PHONE_SLUG, 'phone'); // Ajusta 'phone' si el atributo es texto simple
        }

        $userType = getAttributeValue($userData, USER_TYPE_ATTRIBUTE_SLUG, 'select_option_title');

        // Añadir al array de salida final
        $outputUsers[] = [
            "user_id" => $userId, // ID del registro User
            "name" => $personName,    // Nombre desde Person (o null)
            "email" => $personEmail,   // Email desde Person (o null)
            "phone" => $personPhone,    // Teléfono desde Person (o null)
            "user_type" => $userType // <-- Campo añadido
        ];
    }

    // --- 7. Respuesta Final ---
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "company_id" => $companyId,
        "users" => $outputUsers
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    $errorCode = $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($errorCode);
    error_log("Error en users_by_company.php: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
    echo json_encode([
        "success" => false,
        "message" => "Ocurrió un error interno procesando la solicitud.",
        "details" => $e->getMessage() // Opcional
    ], JSON_UNESCAPED_UNICODE);
}

?>