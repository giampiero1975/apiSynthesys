<?php
// login.php: Genera il token JWT per gli utenti autorizzati

// 1. Includiamo le librerie scaricate con Composer
require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;

// Permettiamo a chiunque di chiamare questa pagina (CORS) e diciamo che restituiamo JSON
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

// 2. Loader .env → usa putenv/getenv (non dipende da variables_order nel php.ini)
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
        putenv("$name=$value");   // disponibile ovunque via getenv()
    }
}
loadEnv(__DIR__ . '/.env');

$chiaveSegreta = getenv('JWT_SECRET');
$crmUser       = getenv('CRM_USER');
$crmPass       = getenv('CRM_PASS');

// Fail-closed: se la chiave non è caricata, l'API non firma nulla
if (!$chiaveSegreta) {
    http_response_code(500);
    die(json_encode(["errore" => "ERRORE CRITICO: JWT_SECRET non caricato. Verificare percorso del file .env."]));
}

// 3. Riceviamo i dati inviati dal cliente (es. da Postman o da un'altra app)
// Usiamo file_get_contents per leggere i dati inviati in formato JSON
$datiInviati = json_decode(file_get_contents("php://input"));

$utente   = $datiInviati->username ?? '';
$password = $datiInviati->password ?? '';

// 4. Controlliamo le credenziali contro i valori del file .env
if ($utente === $crmUser && $password === $crmPass) {
    
    // Credenziali valide! Creiamo il pass temporaneo (Token)
    $payload = [
        'iss' => 'http://localhost/apiSynthesys', // Chi ha emesso il token
        'iat' => time(),                          // Data e ora di creazione
        'exp' => time() + 36000,                   // Scadenza: 10 ora da adesso (36000 secondi)
        'data' => [
            'id_utente' => 1,
            'ruolo' => 'amministratore'
        ]
    ];

    // Generiamo la stringa criptata con getenv() — stessa sorgente usata per la validazione
    $jwt = JWT::encode($payload, $chiaveSegreta, 'HS256');
    
    // Rispondiamo con successo e consegniamo il token
    http_response_code(200);
    echo json_encode([
        "messaggio" => "Login effettuato con successo.",
        "token"     => $jwt
    ]);

} else {
    // Credenziali errate
    http_response_code(401);
    echo json_encode(["errore" => "Nome utente o password non validi."]);
}
?>