Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Escape-SynthesysXmlText {
    param(
        [AllowNull()]
        [object]$Value
    )

    return [System.Security.SecurityElement]::Escape([string]$Value)
}

function Get-SynthesysWsdlUrlFromServiceUrl {
    param(
        [Parameter(Mandatory)]
        [string]$ServiceUrl
    )

    $trimmed = $ServiceUrl.TrimEnd("/")
    if ($trimmed -match "/CRMWebService$") {
        return ($trimmed -replace "/CRMWebService$", "/crmwebservice?wsdl")
    }

    if ($trimmed -match "\?wsdl$") {
        return $trimmed
    }

    return "$trimmed?wsdl"
}

function Invoke-SynthesysHttpRequest {
    param(
        [Parameter(Mandatory)]
        [string]$Uri,

        [ValidateSet("GET", "POST")]
        [string]$Method = "POST",

        [AllowEmptyString()]
        [string]$Body = "",

        [hashtable]$Headers = @{},

        [string]$ContentType = "text/xml; charset=utf-8"
    )

    $client = [System.Net.Http.HttpClient]::new()
    $request = $null

    try {
        $httpMethod = [System.Net.Http.HttpMethod]::new($Method)
        $request = [System.Net.Http.HttpRequestMessage]::new($httpMethod, $Uri)

        foreach ($key in $Headers.Keys) {
            [void]$request.Headers.TryAddWithoutValidation($key, [string]$Headers[$key])
        }

        if ($Method -eq "POST") {
            $request.Content = [System.Net.Http.StringContent]::new(
                $Body,
                [System.Text.Encoding]::UTF8
            )
            $request.Content.Headers.ContentType =
                [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse($ContentType)
        }

        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        $content = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()

        return [pscustomobject]@{
            StatusCode          = [int]$response.StatusCode
            IsSuccessStatusCode = $response.IsSuccessStatusCode
            Content             = $content
        }
    }
    finally {
        if ($request) {
            $request.Dispose()
        }

        $client.Dispose()
    }
}

function Invoke-SynthesysSoap {
    param(
        [Parameter(Mandatory)]
        [string]$ServiceUrl,

        [Parameter(Mandatory)]
        [string]$Operation,

        [Parameter(Mandatory)]
        [System.Collections.Specialized.OrderedDictionary]$Parameters
    )

    $parameterXml = foreach ($key in $Parameters.Keys) {
        $value = $Parameters[$key]
        if ($null -eq $value) {
            continue
        }

        "      <tem:{0}>{1}</tem:{0}>" -f $key, (Escape-SynthesysXmlText $value)
    }

    $body = @"
<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
  <soapenv:Header/>
  <soapenv:Body>
    <tem:$Operation>
$($parameterXml -join [Environment]::NewLine)
    </tem:$Operation>
  </soapenv:Body>
</soapenv:Envelope>
"@

    $headers = @{
        SOAPAction = "http://tempuri.org/ICRMWebServiceAPI/$Operation"
    }

    $response = Invoke-SynthesysHttpRequest -Uri $ServiceUrl -Method "POST" -Body $body -Headers $headers
    [xml]$soapXml = $response.Content

    $faultNode = $soapXml.SelectNodes("//*") |
        Where-Object { $_.LocalName -eq "Fault" } |
        Select-Object -First 1

    if ($faultNode) {
        $faultText = $null
        $faultStringNode = $faultNode.SelectSingleNode("./faultstring")
        if ($faultStringNode) {
            $faultText = $faultStringNode.InnerText
        }

        if (-not $faultText) {
            $faultText = ($faultNode.InnerText -replace "\s+", " ").Trim()
        }

        throw ("SOAP fault on {0}: {1}" -f $Operation, $faultText)
    }

    $resultNode = $soapXml.SelectNodes("//*") |
        Where-Object { $_.LocalName -like "*Result" } |
        Select-Object -First 1

    if ($resultNode) {
        return $resultNode.InnerText
    }

    return $response.Content
}

function New-SynthesysAuthenticationString {
    param(
        [Parameter(Mandatory)]
        [string]$ServiceUrl,

        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",

        [string]$Username,

        [string]$Password
    )

    switch ($AuthMode) {
        "None" {
            return "<Authentication />"
        }
        "PerCall" {
            if ([string]::IsNullOrWhiteSpace($Username) -or
                [string]::IsNullOrWhiteSpace($Password)) {
                throw "AuthMode=PerCall richiede Username e Password."
            }

            return "<Authentication><Username>$(Escape-SynthesysXmlText $Username)</Username><Password>$(Escape-SynthesysXmlText $Password)</Password></Authentication>"
        }
        "Token" {
            if ([string]::IsNullOrWhiteSpace($Username) -or
                [string]::IsNullOrWhiteSpace($Password)) {
                throw "AuthMode=Token richiede Username e Password."
            }

            return Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "AuthenticateUser" -Parameters ([ordered]@{
                Username = $Username
                Password = $Password
            })
        }
        "Auto" {
            if ([string]::IsNullOrWhiteSpace($Username) -and
                [string]::IsNullOrWhiteSpace($Password)) {
                return "<Authentication />"
            }

            if ([string]::IsNullOrWhiteSpace($Username) -or
                [string]::IsNullOrWhiteSpace($Password)) {
                throw "Con AuthMode=Auto devi passare sia Username che Password, oppure nessuno dei due."
            }

            return "<Authentication><Username>$(Escape-SynthesysXmlText $Username)</Username><Password>$(Escape-SynthesysXmlText $Password)</Password></Authentication>"
        }
    }
}

function ConvertTo-SynthesysPropertyTable {
    param(
        [Parameter(Mandatory)]
        [string[]]$Property
    )

    $table = [ordered]@{}

    foreach ($item in $Property) {
        $separatorIndex = $item.IndexOf("=")
        if ($separatorIndex -lt 1) {
            throw "Ogni valore -Property deve avere il formato Nome=Valore. Valore ricevuto: $item"
        }

        $name = $item.Substring(0, $separatorIndex).Trim()
        $value = $item.Substring($separatorIndex + 1)
        $table[$name] = $value
    }

    return $table
}

function New-SynthesysSearchXml {
    param(
        [Parameter(Mandatory)]
        [System.Collections.IDictionary]$Properties
    )

    $propertyXml = foreach ($key in $Properties.Keys) {
        '  <Property Name="{0}" Value="{1}" />' -f (
            Escape-SynthesysXmlText $key
        ), (
            Escape-SynthesysXmlText $Properties[$key]
        )
    }

    return @"
<Customer>
$($propertyXml -join [Environment]::NewLine)
</Customer>
"@
}

function New-SynthesysCustomersXml {
    param(
        [Parameter(Mandatory)]
        [string]$Prefix,

        [Parameter(Mandatory)]
        [System.Collections.IDictionary]$Properties
    )

    $propertyXml = foreach ($key in $Properties.Keys) {
        '    <Property Name="{0}" Value="{1}" />' -f (
            Escape-SynthesysXmlText $key
        ), (
            Escape-SynthesysXmlText $Properties[$key]
        )
    }

    return @"
<Customers>
  <Customer Prefix="$(Escape-SynthesysXmlText $Prefix)">
$($propertyXml -join [Environment]::NewLine)
  </Customer>
</Customers>
"@
}

function ConvertFrom-SynthesysPrefixesXml {
    param([string]$XmlText)

    if ([string]::IsNullOrWhiteSpace($XmlText)) {
        return @()
    }

    [xml]$xml = $XmlText
    return @($xml.Prefixes.Prefix | ForEach-Object { [string]$_ })
}

function ConvertFrom-SynthesysCustomerDescriptionXml {
    param([string]$XmlText)

    if ([string]::IsNullOrWhiteSpace($XmlText)) {
        return @()
    }

    [xml]$xml = $XmlText
    return @(
        $xml.CustomerDescription.Property | ForEach-Object {
            [pscustomobject]@{
                Id           = $_.GetAttribute("Id")
                Name         = $_.GetAttribute("Name")
                Type         = $_.GetAttribute("Type")
                SqlType      = $_.GetAttribute("SQLType")
                Default      = $_.GetAttribute("Default")
                Maximum      = $_.GetAttribute("Maximum")
                IsCustomerId = $_.GetAttribute("IsCustomerId")
                IsName       = $_.GetAttribute("IsName")
                IsIndexed    = $_.GetAttribute("IsIndexed")
                IsRequired   = $_.GetAttribute("IsRequired")
                IsActive     = $_.GetAttribute("IsActive")
                IsTelephone  = $_.GetAttribute("IsTelephone")
                IsEmail      = $_.GetAttribute("IsEmail")
            }
        }
    )
}

function ConvertFrom-SynthesysCustomerXml {
    param([string]$XmlText)

    if ([string]::IsNullOrWhiteSpace($XmlText)) {
        return @()
    }

    [xml]$xml = $XmlText
    $customers = @()

    foreach ($customer in @($xml.SelectNodes("//Customer"))) {
        $row = [ordered]@{
            Prefix = $customer.GetAttribute("Prefix")
        }

        foreach ($property in @($customer.SelectNodes("./Property"))) {
            $name = $property.GetAttribute("Name")
            if ([string]::IsNullOrWhiteSpace($name)) {
                continue
            }

            $row[$name] = $property.GetAttribute("Value")
        }

        $customers += [pscustomobject]$row
    }

    return $customers
}

function ConvertFrom-SynthesysHistoryXml {
    param([string]$XmlText)

    if ([string]::IsNullOrWhiteSpace($XmlText)) {
        return @()
    }

    [xml]$xml = $XmlText
    $items = @()

    foreach ($item in @($xml.History.HistoryItem)) {
        $getValue = {
            param([string]$Name)

            $node = $item.SelectSingleNode("./$Name")
            if ($node) {
                return [string]$node.InnerText
            }

            return ""
        }

        $items += [pscustomobject]@{
            Type        = $item.Type
            ObjectIndex = & $getValue "object_index"
            Performer   = & $getValue "Performer"
            DateTime    = & $getValue "DateTime"
            Duration    = & $getValue "Duration"
            Direction   = & $getValue "Direction"
            Aborted     = & $getValue "Aborted"
            Result      = & $getValue "Result"
            Text        = & $getValue "Text"
            Detail      = & $getValue "Detail"
            MimeType    = & $getValue "MimeType"
            Account     = & $getValue "Account"
            Campaign    = & $getValue "Campaign"
            List        = & $getValue "List"
        }
    }

    return $items
}

function Get-SynthesysHistoryNodeValue {
    param(
        [Parameter(Mandatory)]
        [System.Xml.XmlNode]$HistoryItem,

        [Parameter(Mandatory)]
        [string]$Name
    )

    $node = $HistoryItem.SelectSingleNode("./$Name")
    if ($node) {
        return [string]$node.InnerText
    }

    return ""
}

function ConvertTo-SynthesysHistoryDateTime {
    param(
        [AllowEmptyString()]
        [string]$Value
    )

    if ([string]::IsNullOrWhiteSpace($Value)) {
        return [datetime]::MinValue
    }

    $parsed = [datetime]::MinValue

    if ([datetime]::TryParse(
            $Value,
            [System.Globalization.CultureInfo]::InvariantCulture,
            [System.Globalization.DateTimeStyles]::AllowWhiteSpaces,
            [ref]$parsed
        )) {
        return $parsed
    }

    if ([datetime]::TryParse(
            $Value,
            [System.Globalization.CultureInfo]::CurrentCulture,
            [System.Globalization.DateTimeStyles]::AllowWhiteSpaces,
            [ref]$parsed
        )) {
        return $parsed
    }

    return [datetime]::MinValue
}

function ConvertTo-SynthesysHistoryXml {
    param(
        [System.Xml.XmlNode[]]$HistoryItems = @()
    )

    $document = [System.Xml.XmlDocument]::new()
    $declaration = $document.CreateXmlDeclaration("1.0", "utf-8", $null)
    [void]$document.AppendChild($declaration)

    $history = $document.CreateElement("History")
    [void]$document.AppendChild($history)

    foreach ($historyItem in $HistoryItems) {
        [void]$history.AppendChild($document.ImportNode($historyItem, $true))
    }

    return $document.OuterXml
}

function Get-SynthesysMimeTypeFromPath {
    param(
        [Parameter(Mandatory)]
        [string]$Path
    )

    switch ([System.IO.Path]::GetExtension($Path).ToLowerInvariant()) {
        ".pdf" { return "application/pdf" }
        ".txt" { return "text/plain" }
        ".xml" { return "application/xml" }
        ".json" { return "application/json" }
        ".csv" { return "text/csv" }
        ".png" { return "image/png" }
        ".jpg" { return "image/jpeg" }
        ".jpeg" { return "image/jpeg" }
        default { return "application/octet-stream" }
    }
}

function Test-SynthesysService {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [string]$WsdlUrl
    )

    if ([string]::IsNullOrWhiteSpace($WsdlUrl)) {
        $WsdlUrl = Get-SynthesysWsdlUrlFromServiceUrl -ServiceUrl $ServiceUrl
    }

    $wsdlResponse = Invoke-SynthesysHttpRequest -Uri $WsdlUrl -Method "GET" -ContentType "text/plain"

    return [pscustomobject]@{
        ServiceUrl     = $ServiceUrl
        WsdlUrl        = $WsdlUrl
        StatusCode     = $wsdlResponse.StatusCode
        Reachable      = $wsdlResponse.StatusCode -ge 200 -and $wsdlResponse.StatusCode -lt 300
        HasDefinitions = $wsdlResponse.Content -match "<wsdl:definitions"
    }
}

function Get-SynthesysPrefixes {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [switch]$RawXml
    )

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $result = Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "ListPrefixes" -Parameters ([ordered]@{
        AuthenticationString = $auth
    })

    if ($RawXml) { return $result }
    return ConvertFrom-SynthesysPrefixesXml -XmlText $result
}

function Get-SynthesysPrefixDescription {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [switch]$RawXml
    )

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $result = Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "DescribeCustomer" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Prefix               = $Prefix
    })

    if ($RawXml) { return $result }
    return ConvertFrom-SynthesysCustomerDescriptionXml -XmlText $result
}

function Get-SynthesysHistoryTypes {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [switch]$RawXml
    )

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $result = Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "GetHistoryTypes" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Prefix               = $Prefix
    })

    if ($RawXml) { return $result }

    [xml]$xml = $result
    return @($xml.HistoryTypes.HistoryType | ForEach-Object { [string]$_ })
}

function Get-SynthesysCustomer {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [string]$CustomerId,
        [switch]$RawXml
    )

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $result = Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "GetCustomer" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Prefix               = $Prefix
        CustomerId           = $CustomerId
    })

    if ($RawXml) { return $result }
    return ConvertFrom-SynthesysCustomerXml -XmlText $result
}

function Search-SynthesysCustomers {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [System.Collections.IDictionary]$Criteria,
        [switch]$Wildcard,
        [int]$MaximumResults = 10,
        [switch]$RawXml
    )

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $searchXml = New-SynthesysSearchXml -Properties $Criteria
    $result = Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "SearchCustomers" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Prefix               = $Prefix
        Search               = $searchXml
        DoWildcardSearch     = $Wildcard.ToString().ToLowerInvariant()
        MaximumResults       = $MaximumResults
    })

    if ($RawXml) { return $result }
    return ConvertFrom-SynthesysCustomerXml -XmlText $result
}

function Authenticate-SynthesysCustomer {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [System.Collections.IDictionary]$Criteria
    )

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $searchXml = New-SynthesysSearchXml -Properties $Criteria
    return Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "AuthenticateCustomer" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Prefix               = $Prefix
        Search               = $searchXml
    })
}

function New-SynthesysCustomer {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [System.Collections.IDictionary]$Properties,
        [switch]$RawXml
    )

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $customersXml = New-SynthesysCustomersXml -Prefix $Prefix -Properties $Properties
    $result = Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "InsertCustomer" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Customers            = $customersXml
    })

    if ($RawXml) { return $result }
    return ConvertFrom-SynthesysCustomerXml -XmlText $result
}

function Invoke-SynthesysInsertUpdateCustomer {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [System.Collections.IDictionary]$Properties,
        [string]$CustomerId,
        [switch]$RawXml
    )

    $hasCustomerId = -not [string]::IsNullOrWhiteSpace($CustomerId)

    if ($hasCustomerId -and $Properties.Contains("Customer ID")) {
        throw "Passa CustomerId come parametro oppure dentro -Property, non entrambi."
    }

    $payload = [ordered]@{}

    if ($hasCustomerId) {
        $payload["Customer ID"] = $CustomerId
    }

    foreach ($key in $Properties.Keys) {
        $payload[$key] = $Properties[$key]
    }

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $customersXml = New-SynthesysCustomersXml -Prefix $Prefix -Properties $payload
    $result = Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "InsertUpdateCustomer" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Customers            = $customersXml
    })

    if ($RawXml) { return $result }
    return ConvertFrom-SynthesysCustomerXml -XmlText $result
}

function Set-SynthesysCustomer {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [string]$CustomerId,
        [Parameter(Mandatory)]
        [System.Collections.IDictionary]$Properties,
        [switch]$RawXml
    )

    $payload = [ordered]@{
        "Customer ID" = $CustomerId
    }

    foreach ($key in $Properties.Keys) {
        $payload[$key] = $Properties[$key]
    }

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $customersXml = New-SynthesysCustomersXml -Prefix $Prefix -Properties $payload
    $result = Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "UpdateCustomer" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Customers            = $customersXml
    })

    if ($RawXml) { return $result }
    return ConvertFrom-SynthesysCustomerXml -XmlText $result
}

function Get-SynthesysCustomerHistory {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [string]$CustomerId,
        [switch]$RawXml
    )

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $result = Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "GetCustomerHistory" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Prefix               = $Prefix
        CustomerId           = $CustomerId
    })

    if ($RawXml) { return $result }
    return ConvertFrom-SynthesysHistoryXml -XmlText $result
}

function Get-SynthesysCustomerHistorySorted {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [int]$PageNumber = 1,
        [int]$PageSize = 50,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [string]$CustomerId,
        [Parameter(Mandatory)]
        [string]$SortBy,
        [bool]$SortAsc = $false,
        [switch]$RawXml
    )

    if ($PageNumber -lt 1) {
        throw "PageNumber deve essere maggiore o uguale a 1."
    }

    if ($PageSize -lt 1) {
        throw "PageSize deve essere maggiore o uguale a 1."
    }

    if ($SortBy.Equals("DateTime", [System.StringComparison]::OrdinalIgnoreCase)) {
        $historyXml = Get-SynthesysCustomerHistory -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password -Prefix $Prefix -CustomerId $CustomerId -RawXml
        [xml]$historyDocument = $historyXml

        $sortedHistoryItems = @($historyDocument.History.HistoryItem | Sort-Object -Property @(
                @{
                    Expression = {
                        ConvertTo-SynthesysHistoryDateTime -Value (Get-SynthesysHistoryNodeValue -HistoryItem $_ -Name "DateTime")
                    }
                },
                @{
                    Expression = {
                        $objectIndex = 0
                        [void][int]::TryParse(
                            (Get-SynthesysHistoryNodeValue -HistoryItem $_ -Name "object_index"),
                            [ref]$objectIndex
                        )
                        $objectIndex
                    }
                }
            ) -Descending:(!$SortAsc))

        $skip = ($PageNumber - 1) * $PageSize
        $pagedHistoryXml = ConvertTo-SynthesysHistoryXml -HistoryItems @($sortedHistoryItems | Select-Object -Skip $skip -First $PageSize)

        if ($RawXml) { return $pagedHistoryXml }
        return ConvertFrom-SynthesysHistoryXml -XmlText $pagedHistoryXml
    }

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $result = Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "GetCustomerHistorySorted" -Parameters ([ordered]@{
        AuthenticationString = $auth
        pageNumber           = $PageNumber
        pageSize             = $PageSize
        entityPrefix         = $Prefix
        customerID           = $CustomerId
        sortBy               = $SortBy
        sortAsc              = $SortAsc.ToString().ToLowerInvariant()
    })

    if ($RawXml) { return $result }
    return ConvertFrom-SynthesysHistoryXml -XmlText $result
}

function Add-SynthesysCustomerHistoryV4 {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [string]$CustomerId,
        [Parameter(Mandatory)]
        [string]$AgentName,
        [Parameter(Mandatory)]
        [string]$EventId,
        [datetime]$EventTimeStamp = (Get-Date),
        [string]$EventText = "",
        [string]$EventData = ""
    )

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    return Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "AddCustomerHistoryV4" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Prefix               = $Prefix
        CustomerId           = $CustomerId
        AgentName            = $AgentName
        EventId              = $EventId
        EventTimeStamp       = $EventTimeStamp.ToString("o")
        EventText            = $EventText
        EventData            = $EventData
    })
}

function Add-SynthesysCustomerNote {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [string]$CustomerId,
        [Parameter(Mandatory)]
        [string]$Note
    )

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    return Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "AddCustomerNote" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Prefix               = $Prefix
        CustomerId           = $CustomerId
        Note                 = $Note
    })
}

function Add-SynthesysCustomerDocument {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [string]$CustomerId,
        [Parameter(Mandatory)]
        [string]$FilePath,
        [string]$FileName,
        [string]$MimeType
    )

    if (-not (Test-Path -LiteralPath $FilePath)) {
        throw "File non trovato: $FilePath"
    }

    if ([string]::IsNullOrWhiteSpace($FileName)) {
        $FileName = [System.IO.Path]::GetFileName($FilePath)
    }

    if ([string]::IsNullOrWhiteSpace($MimeType)) {
        $MimeType = Get-SynthesysMimeTypeFromPath -Path $FilePath
    }

    $resolvedPath = (Resolve-Path -LiteralPath $FilePath).Path
    $bytes = [System.IO.File]::ReadAllBytes($resolvedPath)
    $base64 = [System.Convert]::ToBase64String($bytes)
    $documentXml = "<Document><FileName>$(Escape-SynthesysXmlText $FileName)</FileName><MimeType>$(Escape-SynthesysXmlText $MimeType)</MimeType><Base64Data>$base64</Base64Data></Document>"

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    return Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "AddCustomerDocument" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Prefix               = $Prefix
        CustomerId           = $CustomerId
        DocumentDetails      = $documentXml
    })
}

function Get-SynthesysCustomerDocument {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [string]$CustomerId,
        [Parameter(Mandatory)]
        [int]$DocumentId,
        [switch]$RawXml
    )

    $auth = New-SynthesysAuthenticationString -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password
    $result = Invoke-SynthesysSoap -ServiceUrl $ServiceUrl -Operation "GetCustomerDocument" -Parameters ([ordered]@{
        AuthenticationString = $auth
        Prefix               = $Prefix
        CustomerId           = $CustomerId
        DocumentId           = $DocumentId
    })

    if ($RawXml) { return $result }

    [xml]$docXml = $result
    $base64Data = [string]$docXml.Document.Base64Data
    $bytes = [System.Convert]::FromBase64String($base64Data)

    return [pscustomobject]@{
        FileName   = [string]$docXml.Document.FileName
        MimeType   = [string]$docXml.Document.MimeType
        Base64Data = $base64Data
        Bytes      = $bytes
    }
}

function Save-SynthesysCustomerDocument {
    param(
        [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
        [ValidateSet("Auto", "None", "PerCall", "Token")]
        [string]$AuthMode = "Auto",
        [string]$Username,
        [string]$Password,
        [Parameter(Mandatory)]
        [string]$Prefix,
        [Parameter(Mandatory)]
        [string]$CustomerId,
        [int]$DocumentId,
        [string]$OutputPath
    )

    if (-not $PSBoundParameters.ContainsKey("DocumentId")) {
        $history = Get-SynthesysCustomerHistory -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password -Prefix $Prefix -CustomerId $CustomerId
        $latestDocument = $history |
            Where-Object { $_.Type -eq "Document" } |
            Sort-Object ObjectIndex -Descending |
            Select-Object -First 1

        if (-not $latestDocument) {
            throw "Nessun documento trovato nella history di $CustomerId."
        }

        $DocumentId = [int]$latestDocument.ObjectIndex
    }

    $document = Get-SynthesysCustomerDocument -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password -Prefix $Prefix -CustomerId $CustomerId -DocumentId $DocumentId

    if ([string]::IsNullOrWhiteSpace($OutputPath)) {
        $OutputPath = Join-Path (Get-Location) ("retrieved_" + $document.FileName)
    }

    [System.IO.File]::WriteAllBytes($OutputPath, $document.Bytes)

    return [pscustomobject]@{
        OutputPath = $OutputPath
        DocumentId = $DocumentId
        FileName   = $document.FileName
        MimeType   = $document.MimeType
        Length     = $document.Bytes.Length
    }
}

Export-ModuleMember -Function @(
    "Add-SynthesysCustomerHistoryV4",
    "Add-SynthesysCustomerDocument",
    "Add-SynthesysCustomerNote",
    "Authenticate-SynthesysCustomer",
    "ConvertFrom-SynthesysCustomerDescriptionXml",
    "ConvertFrom-SynthesysCustomerXml",
    "ConvertFrom-SynthesysHistoryXml",
    "ConvertFrom-SynthesysPrefixesXml",
    "ConvertTo-SynthesysPropertyTable",
    "Get-SynthesysCustomer",
    "Get-SynthesysCustomerDocument",
    "Get-SynthesysCustomerHistory",
    "Get-SynthesysCustomerHistorySorted",
    "Get-SynthesysHistoryTypes",
    "Get-SynthesysMimeTypeFromPath",
    "Get-SynthesysPrefixDescription",
    "Get-SynthesysPrefixes",
    "Get-SynthesysWsdlUrlFromServiceUrl",
    "Invoke-SynthesysInsertUpdateCustomer",
    "Invoke-SynthesysSoap",
    "New-SynthesysAuthenticationString",
    "New-SynthesysCustomer",
    "Save-SynthesysCustomerDocument",
    "Search-SynthesysCustomers",
    "Set-SynthesysCustomer",
    "Test-SynthesysService"
)
