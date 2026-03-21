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
    [string]$CustomerId,
    [int]$DocumentId,
    [switch]$Latest,
    [string]$OutputPath
)

Import-Module (Join-Path $PSScriptRoot "SynthesysCrmApi.psm1") -Force -DisableNameChecking

if ($Latest -and $PSBoundParameters.ContainsKey("DocumentId")) {
    throw "Passa -DocumentId oppure -Latest, non entrambi."
}

$parameters = @{
    ServiceUrl = $ServiceUrl
    AuthMode   = $AuthMode
    Username   = $Username
    Password   = $Password
    Prefix     = $Prefix
    CustomerId = $CustomerId
    OutputPath = $OutputPath
}

if ($PSBoundParameters.ContainsKey("DocumentId")) {
    $parameters.DocumentId = $DocumentId
}

Save-SynthesysCustomerDocument @parameters
