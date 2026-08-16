param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string] $Version
)

$packageRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$iconsPath = Join-Path $packageRoot 'resources\icons'
$temporaryRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('atom-lucide-' + [guid]::NewGuid().ToString('N'))

New-Item -ItemType Directory -Path $temporaryRoot | Out-Null

try {
    npm pack "lucide-static@$Version" --pack-destination $temporaryRoot --silent
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to download lucide-static $Version."
    }

    $archive = Get-ChildItem -LiteralPath $temporaryRoot -File -Filter '*.tgz' | Select-Object -First 1
    if ($null -eq $archive) {
        throw 'The lucide-static archive was not downloaded.'
    }

    tar -xf $archive.FullName -C $temporaryRoot
    $upstreamRoot = Join-Path $temporaryRoot 'package'
    $upstreamIcons = Join-Path $upstreamRoot 'icons'

    if (-not (Test-Path -LiteralPath (Join-Path $upstreamIcons 'search.svg'))) {
        throw 'The downloaded package does not contain the expected icon catalogue.'
    }

    $resolvedIconsPath = [System.IO.Path]::GetFullPath($iconsPath)
    if (-not $resolvedIconsPath.StartsWith($packageRoot + '\', [System.StringComparison]::OrdinalIgnoreCase)) {
        throw 'The icon destination is outside the Lucide package.'
    }

    if (Test-Path -LiteralPath $resolvedIconsPath) {
        Get-ChildItem -LiteralPath $resolvedIconsPath -File -Filter '*.svg' | Remove-Item -Force
    } else {
        New-Item -ItemType Directory -Path $resolvedIconsPath | Out-Null
    }

    Copy-Item -Path (Join-Path $upstreamIcons '*.svg') -Destination $resolvedIconsPath
    Copy-Item -LiteralPath (Join-Path $upstreamRoot 'LICENSE') -Destination (Join-Path $packageRoot 'LICENSE-LUCIDE') -Force

    $count = (Get-ChildItem -LiteralPath $resolvedIconsPath -File -Filter '*.svg').Count
    Write-Output "Imported $count icons from lucide-static $Version."
    Write-Output 'Update UPSTREAM.md and readme.md to record the imported version.'
} finally {
    if (Test-Path -LiteralPath $temporaryRoot) {
        Remove-Item -LiteralPath $temporaryRoot -Recurse -Force
    }
}
