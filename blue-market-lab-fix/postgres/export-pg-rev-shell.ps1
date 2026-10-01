param(
    [string]$Tag = "blue-market-pg-ext-builder",
    [string]$OutputPath = ".\\postgres\\artifacts\\pg_rev_shell.so"
)

$ErrorActionPreference = "Stop"

$postgresDir = Split-Path -Parent $PSCommandPath
$projectRoot = Split-Path -Parent $postgresDir

if ([System.IO.Path]::IsPathRooted($OutputPath)) {
    $outputFull = $OutputPath
} else {
    $outputFull = Join-Path $projectRoot $OutputPath
}

$outputDir = Split-Path -Parent $outputFull
if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

Push-Location $projectRoot
try {
    docker build --target extension-builder -t $Tag .\postgres
    if ($LASTEXITCODE -ne 0) {
        throw "docker build failed for target extension-builder"
    }

    $containerId = (docker create $Tag).Trim()
    if (-not $containerId) {
        throw "docker create did not return a container id"
    }

    try {
        docker cp "${containerId}:/tmp/pg_rev_shell.so" $outputFull
        if ($LASTEXITCODE -ne 0) {
            throw "docker cp failed while exporting pg_rev_shell.so"
        }
    } finally {
        docker rm -f $containerId | Out-Null
    }

    Write-Output "Exported pg_rev_shell.so to $outputFull"
} finally {
    Pop-Location
}
