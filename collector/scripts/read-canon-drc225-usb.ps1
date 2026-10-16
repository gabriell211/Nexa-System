# Laboratory-only, interactive read of Canon's signed imageFORMULA Utility.
# The Canon GUI/maintenance page is not a documented counter API. Never run
# this from a Windows service or infer a reading for a different USB model.
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)

if (-not [Environment]::UserInteractive) {
    throw 'An interactive Windows desktop is required for the Canon utility.'
}

$matching = @(Get-PnpDevice -PresentOnly -ErrorAction Stop | Where-Object {
    $_.Class -eq 'Image' -and $_.Status -eq 'OK' -and
    $_.FriendlyName -eq 'CANON DR-C225 USB' -and
    $_.InstanceId -match '^USB\\VID_1083&PID_1658(?:&|\\|$)'
})
if ($matching.Count -ne 1) {
    throw 'Exactly one ready Canon DR-C225 USB scanner is required.'
}

$utilityPath = Join-Path ${env:ProgramFiles(x86)} 'Canon Electronics\Shared Files\utility.exe'
if (-not (Test-Path -LiteralPath $utilityPath -PathType Leaf)) {
    throw 'Canon imageFORMULA Utility is not installed.'
}
$signature = Get-AuthenticodeSignature -LiteralPath $utilityPath
if ($signature.Status -ne 'Valid' -or
    $signature.SignerCertificate.Subject -notmatch '(^|,\s*)O=Canon Electronics Inc\.') {
    throw 'Canon imageFORMULA Utility signature is invalid or unexpected.'
}
if ((Get-Item -LiteralPath $utilityPath).VersionInfo.ProductVersion -ne '1.7.11512.4001') {
    throw 'Canon utility version has not been validated for this profile.'
}
if (@(Get-Process -Name utility, devprop -ErrorAction SilentlyContinue).Count -gt 0) {
    throw 'Close existing Canon utility/property windows before the diagnostic read.'
}

Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class NexaCanonWindow {
    [DllImport("user32.dll", CharSet = CharSet.Auto)]
    public static extern IntPtr SendMessage(IntPtr hwnd, uint message, IntPtr wparam, IntPtr lparam);
    [DllImport("user32.dll", CharSet = CharSet.Auto)]
    public static extern bool PostMessage(IntPtr hwnd, uint message, IntPtr wparam, IntPtr lparam);
}
'@

function Get-ChildElement([System.Windows.Automation.AutomationElement] $parent, [scriptblock] $match) {
    $children = $parent.FindAll(
        [System.Windows.Automation.TreeScope]::Descendants,
        [System.Windows.Automation.Condition]::TrueCondition
    )
    foreach ($child in $children) {
        if (& $match $child) { return $child }
    }
    return $null
}

function Wait-For([scriptblock] $query, [int] $seconds = 12) {
    $deadline = [DateTime]::UtcNow.AddSeconds($seconds)
    do {
        $value = & $query
        if ($null -ne $value) { return $value }
        Start-Sleep -Milliseconds 150
    } while ([DateTime]::UtcNow -lt $deadline)
    throw 'Canon utility did not expose the expected window in time.'
}

$utility = $null
$properties = $null
try {
    # This is an interactive diagnostic; opening the vendor property sheet can
    # briefly show its window. No scan, reset, Apply or OK command is sent.
    $utility = Start-Process -FilePath $utilityPath -PassThru
    $main = Wait-For {
        $utility.Refresh()
        if ($utility.MainWindowHandle -ne [IntPtr]::Zero) {
            [System.Windows.Automation.AutomationElement]::FromHandle($utility.MainWindowHandle)
        }
    }
    $list = Get-ChildElement $main { param($x) $x.Current.ClassName -eq 'SysListView32' }
    $button = Get-ChildElement $main { param($x) $x.Current.AutomationId -eq '128' -and $x.Current.ClassName -eq 'Button' }
    if ($null -eq $list -or $null -eq $button) {
        throw 'Canon utility controls do not match the validated version.'
    }
    $count = [NexaCanonWindow]::SendMessage(
        [IntPtr]$list.Current.NativeWindowHandle, 0x1004, [IntPtr]::Zero, [IntPtr]::Zero
    ).ToInt32()
    if ($count -ne 1) {
        throw 'Canon utility must list exactly one scanner to prevent misattribution.'
    }
    $point = [IntPtr]((12 -shl 16) -bor 50)
    [void][NexaCanonWindow]::PostMessage([IntPtr]$list.Current.NativeWindowHandle, 0x201, [IntPtr]1, $point)
    [void][NexaCanonWindow]::PostMessage([IntPtr]$list.Current.NativeWindowHandle, 0x202, [IntPtr]::Zero, $point)
    $selected = Wait-For { if ($button.Current.IsEnabled) { $true } }
    [void][NexaCanonWindow]::SendMessage([IntPtr]$button.Current.NativeWindowHandle, 0xF5, [IntPtr]::Zero, [IntPtr]::Zero)

    $childProcess = Wait-For {
        Get-CimInstance Win32_Process -Filter "ParentProcessId = $($utility.Id)" |
            Where-Object { $_.Name -eq 'devprop.exe' } | Select-Object -First 1
    }
    if (-not $childProcess.ExecutablePath) {
        throw 'Canon property process path is unavailable.'
    }
    $childSignature = Get-AuthenticodeSignature -LiteralPath $childProcess.ExecutablePath
    if ($childSignature.Status -ne 'Valid' -or
        $childSignature.SignerCertificate.Subject -notmatch '(^|,\s*)O=Canon Electronics Inc\.') {
        throw 'Canon property process signature is invalid or unexpected.'
    }
    $properties = Wait-For {
        $windows = [System.Windows.Automation.AutomationElement]::RootElement.FindAll(
            [System.Windows.Automation.TreeScope]::Children,
            [System.Windows.Automation.Condition]::TrueCondition
        )
        foreach ($window in $windows) {
            if ($window.Current.ProcessId -eq $childProcess.ProcessId -and
                $window.Current.Name -match 'DR-C225') { return $window }
        }
        return $null
    }
    $total = Get-ChildElement $properties {
        param($x) $x.Current.AutomationId -eq '1004' -and $x.Current.ClassName -eq 'Static'
    }
    $rollers = Get-ChildElement $properties {
        param($x) $x.Current.AutomationId -eq '1005' -and $x.Current.ClassName -eq 'Static'
    }
    $totalLabel = Get-ChildElement $properties {
        param($x) $x.Current.ClassName -eq 'Static' -and
            $x.Current.Name -match '^(Digitaliza..o total|Total Scanning)\s*:'
    }
    $rollerLabel = Get-ChildElement $properties {
        param($x) $x.Current.ClassName -eq 'Static' -and
            $x.Current.Name -match '^(Rolos atuais|Current Rollers)\s*:'
    }
    if ($null -eq $total -or $null -eq $rollers -or
        $null -eq $totalLabel -or $null -eq $rollerLabel -or
        [Math]::Abs($total.Current.BoundingRectangle.Top - $totalLabel.Current.BoundingRectangle.Top) -gt 3 -or
        [Math]::Abs($rollers.Current.BoundingRectangle.Top - $rollerLabel.Current.BoundingRectangle.Top) -gt 3 -or
        $total.Current.Name -notmatch '^[0-9]{1,20}$' -or
        $rollers.Current.Name -notmatch '^[0-9]{1,20}$') {
        throw 'Canon maintenance counters are absent or malformed.'
    }
    $scanCount = [UInt64]::Parse($total.Current.Name, [Globalization.CultureInfo]::InvariantCulture)
    $rollerCount = [UInt64]::Parse($rollers.Current.Name, [Globalization.CultureInfo]::InvariantCulture)
    [PSCustomObject]@{
        profile = 'canon-dr-c225-usb-utility-v1'
        model_observed = 'DR-C225'
        meter_scan_total = $scanCount
        roller_count_resettable = $rollerCount
        collected_at = [DateTime]::UtcNow.ToString('o')
        source = 'canon-imageformula-utility-maintenance-ui'
    } | ConvertTo-Json -Compress -Depth 3
}
finally {
    if ($null -ne $properties) {
        $cancel = Get-ChildElement $properties {
            param($x) $x.Current.AutomationId -eq '2' -and $x.Current.ClassName -eq 'Button'
        }
        if ($null -ne $cancel) {
            [void][NexaCanonWindow]::SendMessage([IntPtr]$cancel.Current.NativeWindowHandle, 0xF5, [IntPtr]::Zero, [IntPtr]::Zero)
        }
    }
    if ($null -ne $utility) {
        $utility.Refresh()
        if (-not $utility.HasExited) {
            [void][NexaCanonWindow]::PostMessage($utility.MainWindowHandle, 0x0010, [IntPtr]::Zero, [IntPtr]::Zero)
        }
    }
}
