<?php
// debug_soap.php
header("Content-Type: text/plain; charset=UTF-8");

$urlCrm = "http://192.168.10.34/CRMWebService";
$prefix = "IDS";
$idCliente = "IDS_107348";

$xmlPayload = '<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
  <soapenv:Header/>
  <soapenv:Body>
    <tem:GetCustomer>
      <tem:AuthenticationString>&lt;Authentication /&gt;</tem:AuthenticationString>
      <tem:Prefix>' . $prefix . '</tem:Prefix>
      <tem:CustomerId>' . $idCliente . '</tem:CustomerId>
    </tem:GetCustomer>
  </soapenv:Body>
</soapenv:Envelope>';

$ch = curl_init($urlCrm);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: text/xml; charset=utf-8',
    'SOAPAction: http://tempuri.org/ICRMWebServiceAPI/GetCustomer'
]);

$rispostaXml = curl_exec($ch);
curl_close($ch);

echo "--- STEP 1: RICEZIONE XML SOAP ---\n";
// Non stampiamo tutto per brevità, ma verifichiamo se c'è qualcosa
if (!$rispostaXml) { die("Errore: Nessuna risposta dal server."); }

// --- LOGICA DI ESTRAZIONE ---

// 1. Carichiamo l'XML SOAP
$soapXml = simplexml_load_string($rispostaXml);

// 2. Cerchiamo il tag GetCustomerResult (usando local-name per ignorare i namespace)
$nodi = $soapXml->xpath('//*[local-name()="GetCustomerResult"]');

if (!empty($nodi)) {
    echo "--- STEP 2: CONTENUTO DI GETCUSTOMERRESULT ESTRATTO ---\n";
    $stringaInterna = (string)$nodi[0];
    echo $stringaInterna . "\n\n";

    // 3. Carichiamo la stringa interna come XML
    $customerXml = simplexml_load_string($stringaInterna);
    
    if ($customerXml !== false) {
        echo "--- STEP 3: DATI FINALI ESTRATTI ---\n";
        $datiCliente = [];
        
        // Estraiamo le Property
        $properties = $customerXml->xpath('//Property');
        foreach ($properties as $prop) {
            $nome = (string)$prop['Name'];
            $valore = (string)$prop['Value'];
            $datiCliente[$nome] = $valore;
            echo "$nome: $valore\n";
        }
        
        echo "\n--- TEST JSON ---\n";
        echo json_encode($datiCliente, JSON_PRETTY_PRINT);
    } else {
        echo "ERRORE: Impossibile leggere la stringa interna come XML.\n";
    }
} else {
    echo "ERRORE: Tag GetCustomerResult non trovato.\n";
}