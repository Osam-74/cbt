Param(
    [Parameter(Mandatory = $false)]
    [string]$Version = "2.1.0"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$pluginRoot = Resolve-Path (Join-Path $scriptRoot "..")
$releaseRoot = Join-Path $pluginRoot "release"
$stagingRoot = Join-Path $releaseRoot "staging"
$packageRoot = Join-Path $stagingRoot "educbt-pro"
$zipPath = Join-Path $releaseRoot ("EduCBT-Pro-" + $Version + ".zip")

$excludeNames = @(
    ".vscode",
    "tests",
    "docs",
    "release",
    "tools",
    "phpunit.xml.dist",
    ".phpunit.result.cache",
    "composer.phar",
    "composer-setup.php",
    "composer.lock",
    "_analytics_hardening.log",
    "_exam_route_hardening.log",
    "_full_suite.log",
    "_full_test_run.log",
    "_rest_privacy_scope_test.log",
    "_rest_privacy_scope_test_utf8.log"
)

function Should-Exclude {
    Param(
        [string]$RelativePath
    )

    $normalized = $RelativePath -replace "\\", "/"
    foreach ($name in $excludeNames) {
        if ($normalized -eq $name) {
            return $true
        }

        if ($normalized.StartsWith($name + "/")) {
            return $true
        }
    }

    if ($normalized.EndsWith(".log")) {
        return $true
    }

    return $false
}

Write-Host "Preparing release directories..."
if (Test-Path $stagingRoot) {
    Remove-Item -Path $stagingRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $packageRoot -Force | Out-Null

Write-Host "Copying files into staging..."
$items = Get-ChildItem -Path $pluginRoot -Force
foreach ($item in $items) {
    $relative = [System.IO.Path]::GetRelativePath($pluginRoot, $item.FullName)
    if (Should-Exclude -RelativePath $relative) {
        continue
    }

    $destination = Join-Path $packageRoot $relative
    if ($item.PSIsContainer) {
        New-Item -ItemType Directory -Path $destination -Force | Out-Null
        Copy-Item -Path (Join-Path $item.FullName "*") -Destination $destination -Recurse -Force
    }
    else {
        Copy-Item -Path $item.FullName -Destination $destination -Force
    }
}

Write-Host "Applying nested exclusions..."
Get-ChildItem -Path $packageRoot -Recurse -Force | ForEach-Object {
    $relative = [System.IO.Path]::GetRelativePath($packageRoot, $_.FullName)
    if (Should-Exclude -RelativePath $relative) {
        Remove-Item -Path $_.FullName -Recurse -Force
    }
}

Write-Host "Creating release zip..."
if (Test-Path $zipPath) {
    Remove-Item -Path $zipPath -Force
}
New-Item -ItemType Directory -Path $releaseRoot -Force | Out-Null
Compress-Archive -Path (Join-Path $stagingRoot "educbt-pro") -DestinationPath $zipPath -Force

Write-Host "Release package generated: $zipPath"
