# Install NexaCollector as a Windows SCM service without starting it.
# Run from an elevated PowerShell session. Credentials are provisioned separately.
#Requires -RunAsAdministrator
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$DaemonExe
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$serviceName = 'NexaCollector'
$service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($null -ne $service) {
    throw 'NexaCollector is already installed. Stop and review the service before upgrading; this script does not overwrite active installations.'
}
$source = (Resolve-Path -LiteralPath $DaemonExe -ErrorAction Stop).Path
if (-not [IO.Path]::IsPathRooted($source) -or -not [IO.File]::Exists($source)) {
    throw 'Supply an existing absolute path to nexa-collector-daemon.exe.'
}
if ([IO.Path]::GetExtension($source) -ne '.exe') {
    throw 'The daemon source must be a compiled Windows .exe file.'
}

$programData = [Environment]::GetFolderPath('CommonApplicationData')
$root = Join-Path $programData 'NexaCollector'
$data = Join-Path $root 'data'
$credentials = Join-Path $root 'credentials'
$config = Join-Path $root 'config.json'
$installDir = Join-Path $env:ProgramFiles 'NexaCollector'
$exe = Join-Path $installDir 'nexa-collector-daemon.exe'
if ((Test-Path -LiteralPath $root) -or (Test-Path -LiteralPath $exe)) {
    throw 'An existing collector data directory or executable was found; refusing to overwrite files or inherited ACLs. Use a reviewed upgrade procedure.'
}

# Protect the service files and secrets from ordinary users. Use well-known
# SIDs to avoid failures with localized Windows account display names.
function Set-CollectorAcl {
    param(
        [Parameter(Mandatory = $true)][string]$Directory,
        [Parameter(Mandatory = $true)][string]$ServicePermission
    )
    New-Item -ItemType Directory -Path $Directory -Force | Out-Null
    & icacls.exe $Directory /inheritance:r /T | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Failed to remove inherited ACLs for $Directory" }
    & icacls.exe $Directory /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' "*S-1-5-19:(OI)(CI)$ServicePermission" /T | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Failed to set ACLs for $Directory" }
}

Set-CollectorAcl -Directory $root -ServicePermission 'RX'
Set-CollectorAcl -Directory $data -ServicePermission 'M'
Set-CollectorAcl -Directory $credentials -ServicePermission 'R'
if (-not (Test-Path -LiteralPath $config)) {
    $example = Join-Path $PSScriptRoot '..\config.example.json'
    Copy-Item -LiteralPath $example -Destination $config -ErrorAction Stop
}
Set-CollectorAcl -Directory $root -ServicePermission 'RX'
Set-CollectorAcl -Directory $data -ServicePermission 'M'
Set-CollectorAcl -Directory $credentials -ServicePermission 'R'

New-Item -ItemType Directory -Path $installDir -Force | Out-Null
Copy-Item -LiteralPath $source -Destination $exe -ErrorAction Stop

# sc.exe command is explicit about running as LocalService, NEVER LocalSystem.
# No credentials appear in ImagePath or a system-wide environment variable.
$binPath = '"' + $exe + '" --service'
& sc.exe create $serviceName binPath= $binPath start= demand obj= 'NT AUTHORITY\LocalService' DisplayName= 'Nexa Collector' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Failed to register NexaCollector in Windows SCM.' }

# Recovery restarts only when SCM recognizes a process/service failure, with
# bounded delays. The application still needs watchdog/field tests for hangs.
& sc.exe failure $serviceName reset= 86400 actions= 'restart/15000/restart/60000/none/0' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Failed to set service recovery actions.' }
& sc.exe failureflag $serviceName 1 | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Failed to enable failure actions for reported service errors.' }

Write-Host 'NexaCollector registered as a MANUAL Windows service; it was NOT started.'
Write-Host "Config file: $config"
Write-Host "Encrypted queue directory: $data"
Write-Host "Credential directory: $credentials"
Write-Host 'Provision three ACL-protected files (UTF-8, no BOM) as an administrator:'
Write-Host '  NEXA_COLLECTOR_TOKEN       (64 hex characters)'
Write-Host '  NEXA_COLLECTOR_DB_KEY_HEX  (64 hex characters, 32 random bytes)'
Write-Host '  NEXA_SNMP_COMMUNITY       (configured SNMP community)'
Write-Host 'Then configure valid collector UUID/printers and start explicitly:'
Write-Host '  Start-Service -Name NexaCollector'
Write-Host '  Get-Service -Name NexaCollector'
Write-Host 'No secrets are stored in the service registration arguments.'
