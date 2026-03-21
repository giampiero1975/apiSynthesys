        <?php

        class SynthesysCRM {
        private $url;
        private $prefix;
        private $authString = '<Authentication />';

        public function __construct($url = "http://192.168.10.34/CRMWebService", $prefix = "IDS") {
            $this->setUrl($url);
            $this->setPrefix($prefix);
        }

        // --- GETTER & SETTER (Scalabilità per altri suffissi/URL) ---
        public function setUrl($url) { $this->url = $url; }
        public function getUrl() { return $this->url; }

        public function setPrefix($prefix) { $this->prefix = $prefix; }
        public function getPrefix() { return $this->prefix; }

        /**
         * READ - Corrisponde allo script PS di ricerca
         */
        public function getCustomer($customerId) {
            $payload = $this->prepareEnvelope("GetCustomer", [
                'Prefix' => $this->getPrefix(),
                'CustomerId' => $customerId
            ]);
            $response = $this->execute("http://tempuri.org/ICRMWebServiceAPI/GetCustomer", $payload);
            return $this->parseResponse($response, "GetCustomerResult");
        }

        /**
         * CREATE / UPDATE - Inscatola la logica dello script PS "InsertUpdateCustomer"
         */
        public function saveCustomer(array $data) {
            $prefix = $this->getPrefix();
            if (isset($data['_prefix'])) {
                $prefix = $data['_prefix'];
                unset($data['_prefix']);
            }

            // Traduciamo l'array in XML (come faceva il ciclo foreach in PS)
            $innerXml = '<Customers><Customer Prefix="' . htmlspecialchars($prefix) . '">';
            foreach ($data as $name => $value) {
                $innerXml .= '<Property Name="' . htmlspecialchars($name) . '" Value="' . htmlspecialchars($value) . '" />';
            }
            $innerXml .= '</Customer></Customers>';

            $payload = $this->prepareEnvelope("InsertUpdateCustomer", [
                'Customers' => $innerXml
            ]);

            $response = $this->execute("http://tempuri.org/ICRMWebServiceAPI/InsertUpdateCustomer", $payload);
            return $this->parseResponse($response, "InsertUpdateCustomerResult");
        }

        /**
         * CREATE - Replica l'esatto comportamento di new-customer.ps1 (Antigravity)
         */
        public function insertCustomer(array $data) {
            $prefix = $this->getPrefix();
            if (isset($data['_prefix'])) {
                $prefix = $data['_prefix'];
                unset($data['_prefix']);
            }

            // 1. Costruiamo l'XML interno delle Property
            $innerXml = '<Customers><Customer Prefix="' . htmlspecialchars($prefix) . '">';
            foreach ($data as $name => $value) {
                if ($value !== null && $value !== '') {
                    $innerXml .= '<Property Name="' . htmlspecialchars($name) . '" Value="' . htmlspecialchars($value) . '" />';
                }
            }
            $innerXml .= '</Customer></Customers>';

            // 2. PAYLOAD IDENTICO AD ANTIGRAVITY
            // Nota: l'ordine dei tag e i prefissi tem: sono cruciali
            $payload = '<?xml version="1.0" encoding="utf-8"?>
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
            <soapenv:Header/>
            <soapenv:Body>
                <tem:InsertCustomer>
                <tem:AuthenticationString>&lt;Authentication /&gt;</tem:AuthenticationString>
                <tem:Customers>' . htmlspecialchars($innerXml) . '</tem:Customers>
                </tem:InsertCustomer>
            </soapenv:Body>
            </soapenv:Envelope>';

            $response = $this->execute("http://tempuri.org/ICRMWebServiceAPI/InsertCustomer", $payload);
            return $this->parseResponse($response, "InsertCustomerResult");
        }
        // --- MOTORE INTERNO (Privato) ---

        private function prepareEnvelope($method, $params) {
            $body = "<tem:$method><tem:AuthenticationString>" . htmlspecialchars($this->authString) . "</tem:AuthenticationString>";
            foreach ($params as $key => $val) {
                $body .= "<tem:$key>" . htmlspecialchars($val) . "</tem:$key>";
            }
            $body .= "</tem:$method>";

            return '<?xml version="1.0" encoding="utf-8"?>
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
                <soapenv:Body>' . $body . '</soapenv:Body>
            </soapenv:Envelope>';
        }

        private function execute($action, $payload) {
            $ch = curl_init($this->getUrl());
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: text/xml; charset=utf-8",
                "SOAPAction: $action"
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            return $res;
        }

        private function parseResponse($xml, $tag) {
            $dom = new DOMDocument();
            if(!@$dom->loadXML($xml)) return ["error" => "Invalid XML response", "raw" => $xml];

            // 1. Controllo se è andato in errore (SOAP Fault)
            $faults = $dom->getElementsByTagName('faultstring');
            if ($faults->length > 0) {
                return ["error" => $faults->item(0)->nodeValue];
            }

            // 2. Cerco il tag di risposta atteso
            $nodes = $dom->getElementsByTagName($tag);
            if ($nodes->length > 0) {
                $inner = @simplexml_load_string($nodes->item(0)->nodeValue);
                if ($inner) {
                    $res = [];
                    foreach ($inner->xpath('//Property') as $p) {
                        $res[(string)$p['Name']] = (string)$p['Value'];
                    }
                    return $res;
                }
            }
            
            // 3. Fallback per debug se la struttura non fa match col parsing desiderato
            return ["error" => "Tag $tag not found or empty inner XML", "raw" => $xml];
        }
        }