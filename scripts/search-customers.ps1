[CmdletBinding()]
param(
    [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
    [ValidateSet("Auto", "None", "PerCall", "Token")]
    [string]$AuthMode = "Auto",
    [string]$Username,
    [string]$Password,
    [Parameter(Mandatory)]
    [string]$Prefix,
    [Parameter(Mandatory)]
    [string[]]$Criterion,
    [switch]$Wildcard,
    [int]$MaximumResults = 10,
    [switch]$RawXml
)

Import-Module (Join-Path $PSScriptRoot "SynthesysCrmApi.psm1") -Force -DisableNameChecking
$criteria = ConvertTo-SynthesysPropertyTable -Property $Criterion
Search-SynthesysCustomers -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password -Prefix $Prefix -Criteria $criteria -Wildcard:$Wildcard -MaximumResults $MaximumResults -RawXml:$RawXml
