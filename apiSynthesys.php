<?php
require_once 'vendor/autoload.php';
require_once 'src/SynthesysCRM.php';

header("Content-Type: application/json; charset=UTF-8");

// =========================================================================
// STEP 1: CONFIGURAZIONE — Carica .env con putenv/getenv
// =========================================================================
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($name, $value) = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value, "\"' \r\n");
            putenv("$name=$value");
        }
    }
}
loadEnv(__DIR__ . '/.env');

$jwtKey = getenv('JWT_SECRET');
if (!$jwtKey) {
    http_response_code(500);
    die(json_encode(["error" => "ERRORE CRITICO: JWT_SECRET non caricato. Verificare percorso file .env"]));
}

// =========================================================================
// STEP 2: LETTURA INPUT e PROXY TRICK
// Deve avvenire PRIMA del check JWT perché determina il metodo "reale".
// =========================================================================
$inputGrezzo = file_get_contents('php://input');
$datiJson    = json_decode($inputGrezzo, true);

// Metodo "grezzo" dal server
$metodo = $_SERVER['REQUEST_METHOD'];

// Proxy trick: se il proxy trasforma POST in GET ma il body contiene JSON,
// trattiamo la richiesta come POST.
if ($metodo === 'GET' && !empty($datiJson)) {
    $metodo = 'POST';
}

// =========================================================================
// STEP 3: VALIDAZIONE JWT
// Usa $metodo (già corretto dal proxy trick), non $_SERVER['REQUEST_METHOD'].
// La GET pura (senza body) è l'unico endpoint pubblico (health-check).
// =========================================================================
$headers = getallheaders();

if ($metodo !== 'GET') {
    // Priorità 1: header personalizzato X-Synthesys-Token
    $token = $_SERVER['HTTP_X_SYNTHESYS_TOKEN'] ?? null;

    // Priorità 2: classico Authorization: Bearer
    if (!$token) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }

    if (!$token) {
        http_response_code(401);
        die(json_encode([
            "status"  => "error",
            "message" => "Token mancante. Usa 'X-Synthesys-Token: <token>' oppure 'Authorization: Bearer <token>'."
        ]));
    }

    try {
        // Decode strict: qualsiasi anomalia (firma errata, scadenza, formato) → 401 + die()
        $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($jwtKey, 'HS256'));
    } catch (\Exception $e) {
        http_response_code(401);
        die(json_encode([
            "status"  => "error",
            "message" => "Token non valido o scaduto."
        ]));
    }
}

// =========================================================================
// STEP 4: ROUTING
// Si arriva qui SOLO se il token è valido (o se è una GET health-check).
// =========================================================================
$crm = new SynthesysCRM();

switch ($metodo) {

    case 'GET':
        // Unico endpoint pubblico: health-check senza dati
        http_response_code(200);
        echo json_encode([
            "status"  => "ok",
            "message" => "API Synthesys Active. Usa POST con _action."
        ]);
        break;

    case 'POST':
    case 'PUT':
        $postAction = $datiJson['_action'] ?? null;

        if ($postAction === 'list_prefixes') {
            $rispostaParsed = $crm->listPrefixes();

        } elseif ($postAction === 'describe_customer') {
            $pref = $datiJson['_prefix'] ?? null;
            $rispostaParsed = $crm->describeCustomer($pref);

        } elseif ($postAction === 'get_customer') {
            $id   = $datiJson['id']      ?? null;
            $pref = $datiJson['_prefix'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(["error" => "Il campo 'id' è obbligatorio per get_customer"]);
                break;
            }
            $rispostaParsed = $crm->getCustomer($id, $pref);

        } elseif ($postAction === 'get_history') {
            $id   = $datiJson['id']      ?? null;
            $pref = $datiJson['_prefix'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(["error" => "Il campo 'id' è obbligatorio per get_history"]);
                break;
            }
            $rispostaParsed = $crm->getCustomerHistory($id, $pref);

        } elseif ($postAction === 'get_history_types') {
            $pref = $datiJson['_prefix'] ?? null;
            $rispostaParsed = $crm->getHistoryTypes($pref);

        } elseif ($postAction === 'add_history_v4') {
            unset($datiJson['_action']);
            $rispostaParsed = $crm->addCustomerHistoryV4($datiJson);

        } elseif ($postAction === 'add_note') {
            $id   = $datiJson['id']      ?? null;
            $nota = $datiJson['nota']    ?? null;
            $pref = $datiJson['_prefix'] ?? null;
            if (!$id || !$nota) {
                http_response_code(400);
                echo json_encode(["error" => "I campi 'id' e 'nota' sono obbligatori per add_note"]);
                break;
            }
            $rispostaParsed = $crm->addCustomerNote($id, $nota, $pref);

        } elseif ($postAction === 'search') {
            unset($datiJson['_action']);
            $rispostaParsed = $crm->searchCustomers($datiJson);

        } elseif (!empty($datiJson['Customer ID'])) {
            $rispostaParsed = $crm->saveCustomer($datiJson);

        } else {
            $rispostaParsed = $crm->insertCustomer($datiJson);
        }

        echo json_encode([
            "status"     => "success",
            "crm_output" => $rispostaParsed
        ]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["error" => "Metodo non supportato"]);
        break;
}