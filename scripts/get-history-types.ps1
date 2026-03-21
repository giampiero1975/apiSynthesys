[CmdletBinding()]
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

Import-Module (Join-Path $PSScriptRoot "SynthesysCrmApi.psm1") -Force -DisableNameChecking
Get-SynthesysHistoryTypes -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password -Prefix $Prefix -RawXml:$RawXml
