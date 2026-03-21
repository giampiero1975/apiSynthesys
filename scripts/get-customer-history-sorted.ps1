[CmdletBinding()]
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

Import-Module (Join-Path $PSScriptRoot "SynthesysCrmApi.psm1") -Force -DisableNameChecking
Get-SynthesysCustomerHistorySorted -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password -PageNumber $PageNumber -PageSize $PageSize -Prefix $Prefix -CustomerId $CustomerId -SortBy $SortBy -SortAsc:$SortAsc -RawXml:$RawXml
