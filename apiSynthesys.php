    <?php
    require_once 'vendor/autoload.php';
    require_once 'src/SynthesysCRM.php';

    use Firebase\JWT\JWT;
    use Firebase\JWT\Key;

    header("Content-Type: application/json; charset=UTF-8");

    // --- 1. CONFIGURAZIONE ---
    $jwtKey = "LaMiaChiaveSegretaSuperSicura2026!";
    $crm = new SynthesysCRM();
    $headers = getallheaders();

    // --- 2. SICUREZZA (JWT) ---
    // ... (codice per verificare il token X-Synthesys-Token) ...

    // --- 3. LO SMISTAMENTO (ROUTING) "FURBO" ---

    // Recuperiamo il metodo reale
    $metodo = $_SERVER['REQUEST_METHOD'];

    // TRUCCO PER IL PROXY: Se il proxy trasforma POST in GET, 
    // ma noi vediamo che nel "corpo" della richiesta c'è un JSON, 
    // allora forziamo lo smistamento su POST.
    $inputGrezzo = file_get_contents('php://input');
    $datiJson = json_decode($inputGrezzo, true);

    if ($metodo === 'GET' && !empty($datiJson)) {
        $metodo = 'POST'; 
    }

    // Ora lo switch decide cosa fare in base al metodo "pulito"
    switch ($metodo) {
        
        case 'GET':
            // Se è una GET pura, leggiamo l'ID dall'URL e cerchiamo il cliente
            $id = $_GET['id'] ?? null;
            $risultato = $crm->getCustomer($id);
            echo json_encode($risultato ?: ["error" => "Cliente non trovato"]);
            break;

        case 'POST':
        case 'PUT':
            // Se nei dati c'è il "Customer ID", facciamo un UPDATE (saveCustomer = InsertUpdateCustomer)
            // Altrimenti facciamo un nuovo INSERT (insertCustomer = InsertCustomer)
            if (!empty($datiJson['Customer ID'])) {
                $rispostaParsed = $crm->saveCustomer($datiJson);
            } else {
                $rispostaParsed = $crm->insertCustomer($datiJson);
            }
        
        // Restituiamo il risultato formattato a JSON
        echo json_encode([
            "status" => "success",
            "crm_output" => $rispostaParsed 
        ]);
        break;

        default:
            http_response_code(405);
            echo json_encode(["error" => "Metodo non supportato"]);
            break;
    }