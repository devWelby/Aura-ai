param(
    [string]$Root = "."
)

$ErrorActionPreference = "Stop"

Write-Host "Running PHP syntax check..." -ForegroundColor Cyan
$files = Get-ChildItem -Path $Root -Recurse -File -Filter *.php | Where-Object { $_.FullName -notmatch '\\vendor\\' }

$failed = @()
foreach ($file in $files) {
    $result = & php -l $file.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        $failed += [PSCustomObject]@{ File = $file.FullName; Output = ($result -join "`n") }
    }
}

if ($failed.Count -gt 0) {
    Write-Host "\nSyntax errors found:" -ForegroundColor Red
    foreach ($item in $failed) {
        Write-Host "- $($item.File)" -ForegroundColor Yellow
        Write-Host $item.Output -ForegroundColor DarkYellow
    }
    exit 1
}

Write-Host "All PHP files are syntactically valid." -ForegroundColor Green
exit 0
