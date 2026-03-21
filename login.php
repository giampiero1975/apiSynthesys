<?php
// login.php: Genera il token JWT per gli utenti autorizzati

// 1. Includiamo le librerie scaricate con Composer
require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;

// Permettiamo a chiunque di chiamare questa pagina (CORS) e diciamo che restituiamo JSON
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

// 2. La tua chiave segreta (Non condividerla mai!)
$chiaveSegreta = "LaMiaChiaveSegretaSuperSicura2026!";

// 3. Riceviamo i dati inviati dal cliente (es. da Postman o da un'altra app)
// Usiamo file_get_contents per leggere i dati inviati in formato JSON
$datiInviati = json_decode(file_get_contents("php://input"));

$utente = $datiInviati->username ?? '';
$password = $datiInviati->password ?? '';

// 4. Controlliamo le credenziali (In futuro qui potrai interrogare un tuo database)
if ($utente === 'admin' && $password === 'password123') {
    
    // Credenziali valide! Creiamo il pass temporaneo (Token)
    $payload = [
        'iss' => 'http://localhost/apiSynthesys', // Chi ha emesso il token
        'iat' => time(),                          // Data e ora di creazione
        'exp' => time() + 3600,                   // Scadenza: 1 ora da adesso (3600 secondi)
        'data' => [
            'id_utente' => 1,
            'ruolo' => 'amministratore'
        ]
    ];

    // Generiamo la stringa criptata
    $jwt = JWT::encode($payload, $chiaveSegreta, 'HS256');
    
    // Rispondiamo con successo e consegniamo il token
    http_response_code(200);
    echo json_encode([
        "messaggio" => "Login effettuato con successo.",
        "token" => $jwt
    ]);

} else {
    // Credenziali errate
    http_response_code(401);
    echo json_encode(["errore" => "Nome utente o password non validi."]);
}
?>