$ErrorActionPreference = 'Stop'

$ExpectedVersion = '__EXPECTED_VERSION__'
$ExpectedArtifact = '__EXPECTED_ARTIFACT__'
$ExpectedSha256 = '__EXPECTED_SHA256__'
$ExpectedSize = __EXPECTED_SIZE__
$ServiceName = 'MirvMonAgent'
$LegacyTaskName = 'MirvMon Agent'
$InstallerDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$PackageAgentPath = Join-Path $InstallerDir 'mirvmon-agent.exe'
$PackageConfigPath = Join-Path $InstallerDir 'server-config.json'
$InstallDir = Join-Path $env:ProgramFiles 'MirvMon\Agent'
$StateDir = Join-Path $env:ProgramData 'MirvMon\Agent'
$InstalledAgentPath = Join-Path $InstallDir 'mirvmon-agent.exe'
$ConfigPath = Join-Path $StateDir 'config.json'
$QueuePath = Join-Path $StateDir 'queue.json'
$LegacyQueuePath = Join-Path $StateDir 'queue.txt'
$StageDir = Join-Path ([IO.Path]::GetTempPath()) ('mirvmon-install-' + [Guid]::NewGuid().ToString('N'))
$StageAgentPath = Join-Path $StageDir 'mirvmon-agent.exe'
$StageServerConfigPath = Join-Path $StageDir 'server-config.json'
$PreflightConfigPath = Join-Path $StageDir 'preflight-config.json'
$StageConfigPath = Join-Path $StageDir 'config.json'
$StageQueuePath = Join-Path $StageDir 'queue.json'
$StageCheckConfigPath = Join-Path $StageDir 'check-config.json'
$CommitStarted = $false
$InstallationSucceeded = $false
$CreatedService = $false
$OriginalService = $null
$LegacyTaskExists = $false
$SourceConfigPath = ''
$SourceQueuePath = ''
$HadInstalledAgent = $false
$HadConfig = $false
$HadQueue = $false
$RollbackAgentPath = Join-Path $StageDir 'rollback-agent.exe'
$RollbackConfigPath = Join-Path $StageDir 'rollback-config.json'
$RollbackQueuePath = Join-Path $StageDir 'rollback-queue.json'

function Write-Stage {
    param([string]$Message)
    Write-Host ('[MirvMon] ' + $Message)
}

function Invoke-NativeRequired {
    param(
        [string]$Stage,
        [string]$Program,
        [string[]]$Arguments
    )
    $Output = & $Program $Arguments 2>&1
    $ExitCode = $LASTEXITCODE
    if ($ExitCode -ne 0) {
        $Summary = ($Output -join ' ').Trim()
        if ($Summary.Length -gt 500) {
            $Summary = $Summary.Substring(0, 500)
        }
        if ($Summary.Length -gt 0) {
            throw ($Stage + ': ' + $Program + ' failed with exit code ' + $ExitCode + ': ' + $Summary)
        }
        throw ($Stage + ': ' + $Program + ' failed with exit code ' + $ExitCode)
    }
    return $Output
}

function Invoke-NativeAllowed {
    param(
        [string]$Stage,
        [string]$Program,
        [string[]]$Arguments,
        [int[]]$AllowedExitCodes
    )
    $Output = & $Program $Arguments 2>&1
    $ExitCode = $LASTEXITCODE
    if (-not ($AllowedExitCodes -contains $ExitCode)) {
        throw ($Stage + ': ' + $Program + ' failed with exit code ' + $ExitCode)
    }
    return $Output
}

function Protect-Directory {
    param([string]$Path, [string]$Stage)
    Invoke-NativeRequired $Stage 'icacls.exe' @(
        $Path,
        '/inheritance:r',
        '/grant:r',
        '*S-1-5-18:(OI)(CI)F',
        '*S-1-5-32-544:(OI)(CI)F'
    ) | Out-Null
}

function Protect-File {
    param([string]$Path, [string]$SystemRights, [string]$Stage)
    Invoke-NativeRequired $Stage 'icacls.exe' @(
        $Path,
        '/inheritance:r',
        '/grant:r',
        ('*S-1-5-18:' + $SystemRights),
        '*S-1-5-32-544:F'
    ) | Out-Null
}

function Get-FileSha256 {
    param([string]$Path)
    $Stream = [IO.File]::OpenRead($Path)
    try {
        $Hasher = New-Object Security.Cryptography.SHA256Managed
        try {
            $Hash = $Hasher.ComputeHash($Stream)
        } finally {
            $Hasher.Dispose()
        }
    } finally {
        $Stream.Dispose()
    }
    $Builder = New-Object Text.StringBuilder
    foreach ($Byte in $Hash) {
        [void] $Builder.Append($Byte.ToString('x2'))
    }
    return $Builder.ToString()
}

function Get-AgentService {
    $Services = @(Get-WmiObject -Class Win32_Service -Filter "Name='MirvMonAgent'" -ErrorAction Stop)
    if ($Services.Count -eq 0) {
        return $null
    }
    return $Services[0]
}

function Wait-ServiceState {
    param([string]$ExpectedState, [int]$Attempts)
    for ($Attempt = 0; $Attempt -lt $Attempts; $Attempt++) {
        $Service = Get-AgentService
        if (($Service -ne $null) -and ($Service.State -eq $ExpectedState)) {
            return $true
        }
        Start-Sleep -Seconds 1
    }
    return $false
}

function Wait-ServiceRunning {
    if (-not (Wait-ServiceState 'Running' 20)) {
        throw 'start-and-verify: MirvMonAgent did not reach Running state.'
    }
}

function Test-LegacyTask {
    $Output = & schtasks.exe /Query /TN $LegacyTaskName 2>&1
    $ExitCode = $LASTEXITCODE
    if ($ExitCode -eq 0) {
        return $true
    }
    if ($ExitCode -eq 1) {
        return $false
    }
    throw ('detect-old-task: schtasks.exe failed with exit code ' + $ExitCode)
}

function Get-ScStartMode {
    param([string]$WmiStartMode)
    if ($WmiStartMode -eq 'Auto') { return 'auto' }
    if ($WmiStartMode -eq 'Manual') { return 'demand' }
    if ($WmiStartMode -eq 'Disabled') { return 'disabled' }
    return 'demand'
}

function Restore-File {
    param([bool]$Existed, [string]$RollbackPath, [string]$Destination)
    if ($Existed) {
        Copy-Item -LiteralPath $RollbackPath -Destination $Destination -Force
    } elseif (Test-Path -LiteralPath $Destination) {
        Remove-Item -LiteralPath $Destination -Force
    }
}

function Rollback-Installation {
    Write-Stage 'Rolling back the previous agent.'
    try {
        $CurrentService = Get-AgentService
        if (($CurrentService -ne $null) -and ($CurrentService.State -ne 'Stopped')) {
            Invoke-NativeAllowed 'rollback-stop-service' 'sc.exe' @('stop', $ServiceName) @(0, 1062) | Out-Null
            [void] (Wait-ServiceState 'Stopped' 20)
        }

        Restore-File $HadInstalledAgent $RollbackAgentPath $InstalledAgentPath
        Restore-File $HadConfig $RollbackConfigPath $ConfigPath
        Restore-File $HadQueue $RollbackQueuePath $QueuePath

        if ($OriginalService -ne $null) {
            $OriginalStartMode = Get-ScStartMode $OriginalService.StartMode
            Invoke-NativeRequired 'rollback-configure-service' 'sc.exe' @(
                'config',
                $ServiceName,
                'binPath=',
                [string] $OriginalService.PathName,
                'start=',
                $OriginalStartMode
            ) | Out-Null
            if ([string] $OriginalService.Description -ne '') {
                Invoke-NativeRequired 'rollback-service-description' 'sc.exe' @(
                    'description',
                    $ServiceName,
                    [string] $OriginalService.Description
                ) | Out-Null
            }
            if ($OriginalService.State -eq 'Running') {
                Invoke-NativeRequired 'rollback-start-service' 'sc.exe' @('start', $ServiceName) | Out-Null
            }
        } elseif ($CreatedService) {
            Invoke-NativeRequired 'rollback-delete-service' 'sc.exe' @('delete', $ServiceName) | Out-Null
        }

        if ($LegacyTaskExists) {
            Invoke-NativeRequired 'rollback-start-old-task' 'schtasks.exe' @('/Run', '/TN', $LegacyTaskName) | Out-Null
        }
    } catch {
        Write-Host ('[MirvMon] Rollback error: ' + $_.Exception.Message) -ForegroundColor Red
    }
}

function Commit-Installation {
    $script:OriginalService = Get-AgentService
    $script:LegacyTaskExists = Test-LegacyTask
    $script:HadInstalledAgent = Test-Path -LiteralPath $InstalledAgentPath
    $script:HadConfig = Test-Path -LiteralPath $ConfigPath
    $script:HadQueue = Test-Path -LiteralPath $QueuePath

    New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
    New-Item -ItemType Directory -Force -Path $StateDir | Out-Null
    Protect-Directory $InstallDir 'acl-install-directory'
    Protect-Directory $StateDir 'acl-state-directory'

    if ($HadInstalledAgent) { Copy-Item -LiteralPath $InstalledAgentPath -Destination $RollbackAgentPath -Force }
    if ($HadConfig) { Copy-Item -LiteralPath $ConfigPath -Destination $RollbackConfigPath -Force }
    if ($HadQueue) { Copy-Item -LiteralPath $QueuePath -Destination $RollbackQueuePath -Force }

    $Timestamp = Get-Date -Format 'yyyyMMddHHmmss'
    if ($HadInstalledAgent) { Copy-Item -LiteralPath $InstalledAgentPath -Destination ($InstalledAgentPath + '.legacy-' + $Timestamp) -Force }
    if ($HadConfig) { Copy-Item -LiteralPath $ConfigPath -Destination ($ConfigPath + '.legacy-' + $Timestamp) -Force }
    if ($SourceQueuePath -ne '') { Copy-Item -LiteralPath $SourceQueuePath -Destination ($SourceQueuePath + '.legacy-' + $Timestamp) -Force }

    $script:CommitStarted = $true
    if (($OriginalService -ne $null) -and ($OriginalService.State -ne 'Stopped')) {
        Invoke-NativeRequired 'stop-old-service' 'sc.exe' @('stop', $ServiceName) | Out-Null
        if (-not (Wait-ServiceState 'Stopped' 20)) {
            throw 'stop-old-service: MirvMonAgent did not stop.'
        }
    }
    if ($LegacyTaskExists) {
        Invoke-NativeAllowed 'stop-old-task' 'schtasks.exe' @('/End', '/TN', $LegacyTaskName) @(0, 1) | Out-Null
    }

    Copy-Item -LiteralPath $StageAgentPath -Destination $InstalledAgentPath -Force
    Copy-Item -LiteralPath $StageConfigPath -Destination $ConfigPath -Force
    Copy-Item -LiteralPath $StageQueuePath -Destination $QueuePath -Force
    Protect-File $InstalledAgentPath 'RX' 'acl-agent-binary'
    Protect-File $ConfigPath 'F' 'acl-config'
    Protect-File $QueuePath 'F' 'acl-queue'

    $ServiceCommand = '"' + $InstalledAgentPath + '" run --config "' + $ConfigPath + '"'
    if ($OriginalService -eq $null) {
        Invoke-NativeRequired 'create-service' 'sc.exe' @(
            'create',
            $ServiceName,
            'binPath=',
            $ServiceCommand,
            'start=',
            'auto',
            'obj=',
            'LocalSystem'
        ) | Out-Null
        $script:CreatedService = $true
    } else {
        Invoke-NativeRequired 'configure-service' 'sc.exe' @(
            'config',
            $ServiceName,
            'binPath=',
            $ServiceCommand,
            'start=',
            'auto'
        ) | Out-Null
    }
    Invoke-NativeRequired 'service-description' 'sc.exe' @(
        'description',
        $ServiceName,
        'MirvMon outbound monitoring agent'
    ) | Out-Null

    Invoke-NativeRequired 'start-service' 'sc.exe' @('start', $ServiceName) | Out-Null
    Wait-ServiceRunning

    if ($LegacyTaskExists) {
        Invoke-NativeRequired 'delete-old-task' 'schtasks.exe' @('/Delete', '/TN', $LegacyTaskName, '/F') | Out-Null
    }
    $script:InstallationSucceeded = $true
}

try {
    Write-Stage 'Checking prerequisites.'
    $Identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $Principal = New-Object Security.Principal.WindowsPrincipal($Identity)
    if (-not $Principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'prerequisites: run install.bat as Administrator.'
    }
    $IsX64 = ($env:PROCESSOR_ARCHITECTURE -eq 'AMD64') -or ($env:PROCESSOR_ARCHITEW6432 -eq 'AMD64')
    if (-not $IsX64) {
        throw 'prerequisites: only x64 Windows is supported.'
    }
    if (-not (Test-Path -LiteralPath $PackageAgentPath -PathType Leaf)) {
        throw 'prerequisites: bundled mirvmon-agent.exe is missing.'
    }
    if (-not (Test-Path -LiteralPath $PackageConfigPath -PathType Leaf)) {
        throw 'prerequisites: bundled server-config.json is missing.'
    }

    New-Item -ItemType Directory -Path $StageDir | Out-Null
    Protect-Directory $StageDir 'acl-staging-directory'
    Copy-Item -LiteralPath $PackageAgentPath -Destination $StageAgentPath
    Copy-Item -LiteralPath $PackageConfigPath -Destination $StageServerConfigPath

    Write-Stage 'Validating the bundled native agent.'
    if ((Get-Item -LiteralPath $StageAgentPath).Length -ne $ExpectedSize) {
        throw 'validate-binary: bundled mirvmon-agent.exe size does not match the release manifest.'
    }
    if ((Get-FileSha256 $StageAgentPath) -ne $ExpectedSha256) {
        throw 'validate-binary: bundled mirvmon-agent.exe checksum does not match the release manifest.'
    }
    $VersionOutput = @(Invoke-NativeRequired 'version' $StageAgentPath @('version'))
    $VersionParts = (($VersionOutput -join ' ').Trim() -split '\s+')
    if (($VersionParts.Count -ne 4) -or
        ($VersionParts[0] -ne $ExpectedVersion) -or
        ($VersionParts[2] -ne 'windows/amd64') -or
        ($VersionParts[3] -ne $ExpectedArtifact)) {
        throw 'version: bundled mirvmon-agent.exe has unexpected release identity.'
    }

    $ServerConfigText = [IO.File]::ReadAllText($StageServerConfigPath)
    $FinalQueueJson = '%PROGRAMDATA%\\MirvMon\\Agent\\queue.json'
    $AbsoluteQueueJson = $QueuePath.Replace('\', '\\')
    $PreflightQueueJson = $StageQueuePath.Replace('\', '\\')
    $PreflightConfigText = $ServerConfigText.Replace($FinalQueueJson, $PreflightQueueJson)
    if ($PreflightConfigText -eq $ServerConfigText) {
        throw 'check-server-config: queue path placeholder was not found.'
    }
    Set-Content -LiteralPath $PreflightConfigPath -Value $PreflightConfigText -Encoding ASCII
    Invoke-NativeRequired 'check-server-config' $StageAgentPath @('check', '--config', $PreflightConfigPath) | Out-Null

    if (Test-Path -LiteralPath $ConfigPath -PathType Leaf) {
        $SourceConfigPath = $ConfigPath
    }
    if (Test-Path -LiteralPath $QueuePath -PathType Leaf) {
        $SourceQueuePath = $QueuePath
    } elseif (Test-Path -LiteralPath $LegacyQueuePath -PathType Leaf) {
        $SourceQueuePath = $LegacyQueuePath
    }

    Write-Stage 'Migrating existing configuration and queue.'
    $MigrateArguments = @(
        'migrate',
        '--server-config', $StageServerConfigPath,
        '--output-config', $StageConfigPath,
        '--output-queue', $StageQueuePath
    )
    if ($SourceConfigPath -ne '') {
        $MigrateArguments += @('--source-config', $SourceConfigPath)
    }
    if ($SourceQueuePath -ne '') {
        $MigrateArguments += @('--source-queue', $SourceQueuePath)
    }
    Invoke-NativeRequired 'migrate-state' $StageAgentPath $MigrateArguments | Out-Null

    $MigratedConfigText = [IO.File]::ReadAllText($StageConfigPath)
    $StageCheckConfigText = $MigratedConfigText.Replace($AbsoluteQueueJson, $PreflightQueueJson)
    if ($StageCheckConfigText -eq $MigratedConfigText) {
        throw 'check-migrated-config: queue path placeholder was not found.'
    }
    Set-Content -LiteralPath $StageCheckConfigPath -Value $StageCheckConfigText -Encoding ASCII
    Invoke-NativeRequired 'check-migrated-config' $StageAgentPath @('check', '--config', $StageCheckConfigPath) | Out-Null

    Write-Stage 'Installing the validated native agent.'
    Commit-Installation
    Write-Stage 'MirvMon agent installed and MirvMonAgent is running.'
    exit 0
} catch {
    $Failure = $_.Exception.Message
    if ($CommitStarted -and (-not $InstallationSucceeded)) {
        Rollback-Installation
    }
    Write-Host ('[MirvMon] Installation failed: ' + $Failure) -ForegroundColor Red
    exit 1
} finally {
    if (Test-Path -LiteralPath $StageDir) {
        Remove-Item -LiteralPath $StageDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}
