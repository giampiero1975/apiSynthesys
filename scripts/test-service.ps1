[CmdletBinding()]
param(
    [string]$ServiceUrl = "http://192.168.10.34/CRMWebService",
    [string]$WsdlUrl
)

Import-Module (Join-Path $PSScriptRoot "SynthesysCrmApi.psm1") -Force -DisableNameChecking
Test-SynthesysService -ServiceUrl $ServiceUrl -WsdlUrl $WsdlUrl
