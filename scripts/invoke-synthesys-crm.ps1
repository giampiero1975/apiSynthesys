[CmdletBinding()]
param(
    [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
    [ValidateSet("Auto", "None", "PerCall", "Token")]
    [string]$AuthMode = "Auto",
    [string]$Username,
    [string]$Password,
    [switch]$ListPrefixes,
    [switch]$DescribePrefix,
    [string]$Prefix,
    [string]$CustomerId,
    [string]$SearchProperty,
    [string]$SearchValue,
    [switch]$Wildcard,
    [int]$MaximumResults = 10,
    [switch]$RawXml
)

Import-Module (Join-Path $PSScriptRoot "SynthesysCrmApi.psm1") -Force -DisableNameChecking

if (-not $ListPrefixes -and -not $DescribePrefix -and -not $CustomerId -and -not $SearchProperty -and -not $SearchValue) {
    $ListPrefixes = $true
}

if ($DescribePrefix -and [string]::IsNullOrWhiteSpace($Prefix)) {
    throw "Per DescribePrefix devi passare -Prefix."
}

if ($CustomerId -and [string]::IsNullOrWhiteSpace($Prefix)) {
    throw "Per GetCustomer devi passare -Prefix."
}

if ($SearchProperty -or $SearchValue) {
    if ([string]::IsNullOrWhiteSpace($Prefix)) {
        throw "Per SearchCustomers devi passare -Prefix."
    }

    if ([string]::IsNullOrWhiteSpace($SearchProperty) -or [string]::IsNullOrWhiteSpace($SearchValue)) {
        throw "Per SearchCustomers devi passare sia -SearchProperty sia -SearchValue."
    }
}

if ($ListPrefixes) {
    Get-SynthesysPrefixes -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password -RawXml:$RawXml
    return
}

if ($DescribePrefix) {
    Get-SynthesysPrefixDescription -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password -Prefix $Prefix -RawXml:$RawXml
    return
}

if ($CustomerId) {
    Get-SynthesysCustomer -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password -Prefix $Prefix -CustomerId $CustomerId -RawXml:$RawXml
    return
}

if ($SearchProperty) {
    $criteria = [ordered]@{
        $SearchProperty = $SearchValue
    }

    Search-SynthesysCustomers -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password -Prefix $Prefix -Criteria $criteria -Wildcard:$Wildcard -MaximumResults $MaximumResults -RawXml:$RawXml
}
