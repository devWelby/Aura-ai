$ErrorActionPreference = "Stop"

Write-Host "1) Node syntax check" -ForegroundColor Cyan
Push-Location (Resolve-Path "$PSScriptRoot\..\..")
npm run check
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host "2) HTTP automated tests" -ForegroundColor Cyan
npm test
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

if ($env:RUN_FULL -eq "1") {
    Write-Host "3) Functional smoke test (RUN_FULL=1)" -ForegroundColor Cyan
    node "$PSScriptRoot\functional-test.js"
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
else {
    Write-Host "3) Functional smoke test skipped (set RUN_FULL=1 to run)" -ForegroundColor Yellow
}

Pop-Location

Write-Host "\nChecks completed successfully." -ForegroundColor Green
