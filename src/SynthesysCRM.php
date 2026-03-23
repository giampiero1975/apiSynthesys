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
         * READ - Legge un singolo cliente per ID.
         * $prefix opzionale: se null usa $this->getPrefix().
         */
        public function getCustomer($customerId, $prefix = null) {
            $prefix = $prefix ?? $this->getPrefix();
            $payload = $this->prepareEnvelope("GetCustomer", [
                'Prefix'     => $prefix,
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

            // Pulizia: rimuove tutte le chiavi di servizio (_action, ecc.) prima di costruire l'XML
            $data = $this->stripServiceKeys($data);

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

            // Pulizia: rimuove tutte le chiavi di servizio (_action, ecc.) prima di costruire l'XML
            $data = $this->stripServiceKeys($data);

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
        /**
         * DISCOVERY - Elenca tutti i prefissi disponibili nel CRM.
         * Equivalente a Get-SynthesysPrefixes nel modulo PSM1.
         * Restituisce un array piatto di stringhe: ['IDS', 'ABC', ...]
         */
        public function listPrefixes() {
            $payload = $this->prepareEnvelope("ListPrefixes", []);
            $response = $this->execute("http://tempuri.org/ICRMWebServiceAPI/ListPrefixes", $payload);

            $dom = new DOMDocument();
            if (!@$dom->loadXML($response)) {
                return ["error" => "Invalid XML response", "raw" => $response];
            }

            $faults = $dom->getElementsByTagName('faultstring');
            if ($faults->length > 0) {
                return ["error" => $faults->item(0)->nodeValue];
            }

            $nodes = $dom->getElementsByTagName('ListPrefixesResult');
            if ($nodes->length === 0) {
                return ["error" => "ListPrefixesResult not found", "raw" => $response];
            }

            // Inner XML: <Prefixes><Prefix>IDS</Prefix><Prefix>ABC</Prefix></Prefixes>
            $inner = @simplexml_load_string($nodes->item(0)->nodeValue);
            if (!$inner) {
                return ["error" => "Cannot parse inner ListPrefixes XML", "raw" => $nodes->item(0)->nodeValue];
            }

            $prefixes = [];
            foreach ($inner->Prefix as $p) {
                $prefixes[] = (string)$p;
            }
            return $prefixes;
        }

        /**
         * DISCOVERY - Descrive i campi di un prefisso CRM.
         * Equivalente a Get-SynthesysPrefixDescription nel modulo PSM1.
         * Restituisce un array di associativi con gli attributi di ogni <Property>.
         */
        public function describeCustomer($prefix = null) {
            $prefix = $prefix ?? $this->getPrefix();

            $payload = $this->prepareEnvelope("DescribeCustomer", [
                'Prefix' => $prefix
            ]);
            $response = $this->execute("http://tempuri.org/ICRMWebServiceAPI/DescribeCustomer", $payload);

            $dom = new DOMDocument();
            if (!@$dom->loadXML($response)) {
                return ["error" => "Invalid XML response", "raw" => $response];
            }

            $faults = $dom->getElementsByTagName('faultstring');
            if ($faults->length > 0) {
                return ["error" => $faults->item(0)->nodeValue];
            }

            $nodes = $dom->getElementsByTagName('DescribeCustomerResult');
            if ($nodes->length === 0) {
                return ["error" => "DescribeCustomerResult not found", "raw" => $response];
            }

            // Inner XML: <CustomerDescription><Property Id="..." Name="..." Type="..." .../></CustomerDescription>
            $inner = @simplexml_load_string($nodes->item(0)->nodeValue);
            if (!$inner) {
                return ["error" => "Cannot parse inner DescribeCustomer XML", "raw" => $nodes->item(0)->nodeValue];
            }

            $properties = [];
            foreach ($inner->Property as $p) {
                $properties[] = [
                    'Id'           => (string)$p['Id'],
                    'Name'         => (string)$p['Name'],
                    'Type'         => (string)$p['Type'],
                    'SqlType'      => (string)$p['SQLType'],
                    'Default'      => (string)$p['Default'],
                    'Maximum'      => (string)$p['Maximum'],
                    'IsCustomerId' => (string)$p['IsCustomerId'],
                    'IsName'       => (string)$p['IsName'],
                    'IsIndexed'    => (string)$p['IsIndexed'],
                    'IsRequired'   => (string)$p['IsRequired'],
                    'IsActive'     => (string)$p['IsActive'],
                    'IsTelephone'  => (string)$p['IsTelephone'],
                    'IsEmail'      => (string)$p['IsEmail'],
                ];
            }
            return $properties;
        }

        /**
         * HISTORY - Recupera la cronologia di un cliente.
         * Equivalente a Get-SynthesysCustomerHistory nel modulo PSM1.
         * Restituisce un array di array associativi, uno per ogni HistoryItem.
         */
        public function getCustomerHistory($customerId, $prefix = null) {
            $prefix = $prefix ?? $this->getPrefix();

            $payload = $this->prepareEnvelope("GetCustomerHistory", [
                'Prefix'     => $prefix,
                'CustomerId' => $customerId,
            ]);

            $response = $this->execute("http://tempuri.org/ICRMWebServiceAPI/GetCustomerHistory", $payload);
            return $this->parseHistoryResponse($response);
        }

        /**
         * Parsing della cronologia: cicla su tutti i <HistoryItem>.
         * Nel XML di Noetica, Type è un attributo del nodo <HistoryItem>,
         * mentre tutti gli altri campi sono nodi figli (letti tramite nodeValue).
         * Struttura: <History><HistoryItem Type="Note"><DateTime>...</DateTime>...</HistoryItem></History>
         */
        private function parseHistoryResponse($xml) {
            $dom = new DOMDocument();
            if (!@$dom->loadXML($xml)) {
                return ["error" => "Invalid XML response", "raw" => $xml];
            }

            $faults = $dom->getElementsByTagName('faultstring');
            if ($faults->length > 0) {
                return ["error" => $faults->item(0)->nodeValue];
            }

            $nodes = $dom->getElementsByTagName('GetCustomerHistoryResult');
            if ($nodes->length === 0) {
                return ["error" => "GetCustomerHistoryResult not found", "raw" => $xml];
            }

            $inner = @simplexml_load_string($nodes->item(0)->nodeValue);
            if ($inner === false) {
                return ["error" => "Cannot parse inner GetCustomerHistory XML", "raw" => $nodes->item(0)->nodeValue];
            }

            // Helper: legge il testo di un nodo figlio, stringa vuota se assente
            $getVal = function($item, $name) {
                $child = $item->xpath("./$name");
                return !empty($child) ? (string)$child[0] : '';
            };

            $items = [];
            foreach ($inner->xpath('.//HistoryItem') as $item) {
                $items[] = [
                    'Type'        => (string)$item['Type'],
                    'ObjectIndex' => $getVal($item, 'object_index'),
                    'Performer'   => $getVal($item, 'Performer'),
                    'DateTime'    => $getVal($item, 'DateTime'),
                    'Duration'    => $getVal($item, 'Duration'),
                    'Direction'   => $getVal($item, 'Direction'),
                    'Aborted'     => $getVal($item, 'Aborted'),
                    'Result'      => $getVal($item, 'Result'),
                    'Text'        => $getVal($item, 'Text'),
                    'Detail'      => $getVal($item, 'Detail'),
                    'MimeType'    => $getVal($item, 'MimeType'),
                    'Account'     => $getVal($item, 'Account'),
                    'Campaign'    => $getVal($item, 'Campaign'),
                    'List'        => $getVal($item, 'List'),
                ];
            }
            return $items;
        }

        /**
         * HISTORY V4 - Registra un esito strutturato nella cronologia del cliente.
         * Equivalente a Add-SynthesysCustomerHistoryV4 nel modulo PSM1.
         *
         * Parametri SOAP reali (PSM1): Prefix, CustomerId, AgentName, EventId,
         *   EventTimeStamp, EventText, EventData.
         * NB: Duration, Direction, Aborted, Account, Campaign, List NON sono parametri
         *   nativi di AddCustomerHistoryV4 — vengono serializzati in EventData come JSON
         *   in modo da non perderli e poterli rileggere dalla history.
         *
         * Chiavi attese nell'array $data:
         *   id            (string, obbl.)  - Customer ID (es. IDS_107348)
         *   _prefix       (string, opt.)   - Prefisso CRM
         *   agent         (string, obbl.)  - Nome operatore
         *   event_id      (int,    obbl.)  - ID esito SOAP
         *   timestamp     (string, opt.)   - ISO8601, default = ora corrente
         *   event_text    (string, opt.)   - Nota testuale principale
         *   event_data    (string, opt.)   - Dati aggiuntivi liberi (XML/JSON)
         *   duration      (int,    opt.)   - Durata in secondi
         *   direction     (int,    opt.)   - 0=Outbound, 1=Inbound
         *   aborted       (bool,   opt.)   - Chiamata interrotta
         *   result        (string, opt.)   - Alias di event_text se event_text assente
         *   account       (string, opt.)   - Account di riferimento
         *   campaign      (string, opt.)   - Campagna
         *   list          (string, opt.)   - Lista
         */
        public function addCustomerHistoryV4(array $data) {
            $prefix = $data['_prefix'] ?? $this->getPrefix();

            $customerId = $data['id']         ?? null;
            $agentName  = $data['agent']       ?? null;
            $eventId    = isset($data['event_id']) ? intval($data['event_id']) : null;

            if (!$customerId || !$agentName || $eventId === null) {
                return ["error" => "I campi 'id', 'agent' e 'event_id' sono obbligatori per add_history_v4"];
            }

            // Timestamp: usa quello passato oppure ora corrente in formato ISO8601
            $timestamp = !empty($data['timestamp'])
                ? $data['timestamp']
                : date('Y-m-d\TH:i:s');

            // EventText: usa event_text oppure result come alias
            $eventText = $data['event_text'] ?? $data['result'] ?? '';

            // EventData: serializza i campi extra che la V4 non espone come parametri SOAP
            $extra = [];
            foreach (['duration','direction','aborted','account','campaign','list'] as $field) {
                if (isset($data[$field]) && $data[$field] !== '') {
                    $extra[$field] = $data[$field];
                }
            }
            // Mantieni eventData passato manualmente, oppure costruiscilo dagli extra
            $eventData = $data['event_data'] ?? (!empty($extra) ? json_encode($extra) : '');

            $payload = $this->prepareEnvelope("AddCustomerHistoryV4", [
                'Prefix'         => $prefix,
                'CustomerId'     => $customerId,
                'AgentName'      => $agentName,
                'EventId'        => (string)$eventId,
                'EventTimeStamp' => $timestamp,
                'EventText'      => $eventText,
                'EventData'      => $eventData,
            ]);

            $response = $this->execute("http://tempuri.org/ICRMWebServiceAPI/AddCustomerHistoryV4", $payload);

            $dom = new DOMDocument();
            if (!@$dom->loadXML($response)) {
                return ["error" => "Invalid XML response", "raw" => $response];
            }
            $faults = $dom->getElementsByTagName('faultstring');
            if ($faults->length > 0) {
                return ["error" => $faults->item(0)->nodeValue];
            }
            $nodes = $dom->getElementsByTagName('AddCustomerHistoryV4Result');
            if ($nodes->length > 0) {
                return ["success" => true, "result" => $nodes->item(0)->nodeValue];
            }
            return ["error" => "AddCustomerHistoryV4Result not found", "raw" => $response];
        }

        /**
         * HISTORY TYPES - Restituisce gli EventId disponibili per un prefisso.
         * Equivalente a Get-SynthesysHistoryTypes nel modulo PSM1.
         * XML risposta: <HistoryTypes><HistoryType>...</HistoryType></HistoryTypes>
         * Restituisce un array piatto di stringhe (i nomi/ID degli eventi).
         */
        public function getHistoryTypes($prefix = null) {
            $prefix = $prefix ?? $this->getPrefix();

            $payload = $this->prepareEnvelope("GetHistoryTypes", [
                'Prefix' => $prefix,
            ]);

            $response = $this->execute("http://tempuri.org/ICRMWebServiceAPI/GetHistoryTypes", $payload);

            $dom = new DOMDocument();
            if (!@$dom->loadXML($response)) {
                return ["error" => "Invalid XML response", "raw" => $response];
            }
            $faults = $dom->getElementsByTagName('faultstring');
            if ($faults->length > 0) {
                return ["error" => $faults->item(0)->nodeValue];
            }
            $nodes = $dom->getElementsByTagName('GetHistoryTypesResult');
            if ($nodes->length === 0) {
                return ["error" => "GetHistoryTypesResult not found", "raw" => $response];
            }
            $inner = @simplexml_load_string($nodes->item(0)->nodeValue);
            if ($inner === false) {
                return ["error" => "Cannot parse GetHistoryTypes XML", "raw" => $nodes->item(0)->nodeValue];
            }
            $types = [];
            foreach ($inner->HistoryType as $t) {
                $types[] = (string)$t;
            }
            return $types;
        }

        /**
        /**
         * NOTE - Aggiunge una nota testuale alla cronologia di un cliente.
         * Equivalente a Add-SynthesysCustomerNote nel modulo PSM1.
         * Parametri SOAP: Prefix, CustomerId, Note.
         */
        public function addCustomerNote($customerId, $nota, $prefix = null) {
            $prefix = $prefix ?? $this->getPrefix();

            if (empty($customerId)) {
                return ["error" => "Il campo 'id' è obbligatorio per add_note"];
            }
            if (empty($nota)) {
                return ["error" => "Il campo 'nota' è obbligatorio per add_note"];
            }

            $payload = $this->prepareEnvelope("AddCustomerNote", [
                'Prefix'     => $prefix,
                'CustomerId' => $customerId,
                'Note'       => $nota,
            ]);

            $response = $this->execute("http://tempuri.org/ICRMWebServiceAPI/AddCustomerNote", $payload);

            // AddCustomerNote restituisce un booleano (true/false) nell'AddCustomerNoteResult
            $dom = new DOMDocument();
            if (!@$dom->loadXML($response)) {
                return ["error" => "Invalid XML response", "raw" => $response];
            }
            $faults = $dom->getElementsByTagName('faultstring');
            if ($faults->length > 0) {
                return ["error" => $faults->item(0)->nodeValue];
            }
            $nodes = $dom->getElementsByTagName('AddCustomerNoteResult');
            if ($nodes->length > 0) {
                $ok = strtolower(trim($nodes->item(0)->nodeValue)) === 'true';
                return ["success" => $ok];
            }
            return ["error" => "AddCustomerNoteResult not found", "raw" => $response];
        }

        /**
         * Equivalente a Search-SynthesysCustomers nel modulo PSM1.
         *
         * Chiavi speciali nell'array $criteria (vengono estratte prima della chiamata):
         *   _prefix     (obbligatorio) - Prefisso CRM (es. 'IDS')
         *   _wildcard   (opzionale, bool, default false) - Ricerca con wildcard
         *   _maxResults (opzionale, int,  default 50)   - Limite risultati
         *
         * Criteri di ricerca reali: es. ['Cognome' => 'Rossi', 'Nome' => 'Mario']
         */
        public function searchCustomers(array $criteria) {

            // 1. Validazione bloccante: _prefix obbligatorio
            if (empty($criteria['_prefix'])) {
                return ["error" => "Il campo _prefix è obbligatorio per la ricerca"];
            }

            // 2. Estrazione chiavi speciali
            $prefix     = $criteria['_prefix'];   unset($criteria['_prefix']);
            $wildcard   = isset($criteria['_wildcard'])   ? (bool)$criteria['_wildcard']   : false;
            unset($criteria['_wildcard']);
            $maxResults = isset($criteria['_maxResults']) ? (int)$criteria['_maxResults']  : 50;
            unset($criteria['_maxResults']);

            // Pulizia difensiva: rimuove qualsiasi chiave _* residua (es. _action) prima del foreach
            $criteria = $this->stripServiceKeys($criteria);

            // 3. Validazione: almeno un criterio di ricerca reale
            if (empty($criteria)) {
                return ["error" => "Inserire almeno un criterio di ricerca"];
            }

            // 4. Costruzione XML del frammento di ricerca
            // htmlspecialchars() viene applicato SOLO ai singoli valori/chiavi nel ciclo.
            // prepareEnvelope() eseguirà UN SOLO htmlspecialchars() sull'intero blocco,
            // producendo il corretto singolo escape richiesto da Noetica (es. &lt;Customer&gt;).
            // NON applicare un secondo htmlspecialchars() sull'intera stringa: causerebbe
            // doppio escape (&amp;lt;) che fa crashare il parser XML .NET di Noetica.
            $searchXmlInner = '<Customer>';
            foreach ($criteria as $name => $value) {
                $searchXmlInner .= '<Property Name="' . htmlspecialchars($name) . '" Value="' . htmlspecialchars($value) . '" />';
            }
            $searchXmlInner .= '</Customer>';

            // 5. Chiamata SOAP — passiamo $searchXmlInner grezzo, prepareEnvelope lo escapa una volta sola
            $payload = $this->prepareEnvelope("SearchCustomers", [
                'Prefix'           => $prefix,
                'Search'           => $searchXmlInner,
                'DoWildcardSearch' => $wildcard ? 'true' : 'false',
                'MaximumResults'   => (string)$maxResults,
            ]);

            $response = $this->execute("http://tempuri.org/ICRMWebServiceAPI/SearchCustomers", $payload);
            $parsed   = $this->parseSearchResponse($response);

            // Debug: se il parser restituisce un errore, accodiamo il payload SOAP per ispezione
            if (isset($parsed['error'])) {
                $parsed['debug_payload'] = $payload;
            }

            return $parsed;
        }


        // --- MOTORE INTERNO (Privato) ---

        /**
         * Parsing multiplo: estrae tutti i <Customer> dal SearchCustomersResult.
         * A differenza di parseResponse (che estrae un singolo record via Property/Name+Value),
         * questo metodo cicla su tutti i nodi <Customer> e raccoglie un array multi-record.
         */
        private function parseSearchResponse($xml) {
            $dom = new DOMDocument();
            if (!@$dom->loadXML($xml)) {
                return ["error" => "Invalid XML response", "raw" => $xml];
            }

            $faults = $dom->getElementsByTagName('faultstring');
            if ($faults->length > 0) {
                return ["error" => $faults->item(0)->nodeValue];
            }

            $nodes = $dom->getElementsByTagName('SearchCustomersResult');
            if ($nodes->length === 0) {
                return ["error" => "SearchCustomersResult not found", "raw" => $xml];
            }

            // <Customers /> è XML valido: simplexml_load_string restituisce un oggetto vuoto, non false.
            // Usiamo !== false per distinguere il fallimento del parsing da un risultato legittimamente vuoto.
            $inner = @simplexml_load_string($nodes->item(0)->nodeValue);
            if ($inner !== false) {
                $customers = [];
                // .//Customer (relativo) invece di //Customer (assoluto) per sicurezza con namespace
                foreach ($inner->xpath('.//Customer') as $custNode) {
                    $row = ['Prefix' => (string)$custNode['Prefix']];
                    foreach ($custNode->Property as $prop) {
                        $row[(string)$prop['Name']] = (string)$prop['Value'];
                    }
                    $customers[] = $row;
                }
                return $customers; // [] se zero risultati, array popolato altrimenti
            }

            // Solo qui siamo in errore reale di parsing
            return ["error" => "Cannot parse inner SearchCustomers XML", "raw" => $nodes->item(0)->nodeValue];
        }




        /**
         * Helper: rimuove tutte le chiavi che iniziano con '_' dall'array dati.
         * Le chiavi di servizio (_action, _prefix, _wildcard, _maxResults, ecc.) non devono
         * mai raggiungere il payload XML inviato al CRM Noetica.
         */
        private function stripServiceKeys(array $data): array {
            foreach (array_keys($data) as $key) {
                // Compatibile con PHP 7.4: controlla se la chiave inizia con '_'
                if (is_string($key) && strpos($key, '_') === 0) {
                    unset($data[$key]);
                }
            }
            return $data;
        }

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