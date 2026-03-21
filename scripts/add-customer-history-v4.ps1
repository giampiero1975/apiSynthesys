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
    [Parameter(Mandatory)]
    [string]$AgentName,
    [Parameter(Mandatory)]
    [string]$EventId,
    [datetime]$EventTimeStamp = (Get-Date),
    [string]$EventText = "",
    [string]$EventData = ""
)

Import-Module (Join-Path $PSScriptRoot "SynthesysCrmApi.psm1") -Force -DisableNameChecking
Add-SynthesysCustomerHistoryV4 -ServiceUrl $ServiceUrl -AuthMode $AuthMode -Username $Username -Password $Password -Prefix $Prefix -CustomerId $CustomerId -AgentName $AgentName -EventId $EventId -EventTimeStamp $EventTimeStamp -EventText $EventText -EventData $EventData
