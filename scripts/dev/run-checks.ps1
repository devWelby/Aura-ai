$ErrorActionPreference = "Stop"

Write-Host "1) PHP syntax lint" -ForegroundColor Cyan
& "$PSScriptRoot\lint-php.ps1" -Root (Resolve-Path "$PSScriptRoot\..\..")
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host "\nChecks completed successfully." -ForegroundColor Green
