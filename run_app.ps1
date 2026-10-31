# Davao Food Finder Server Launcher
Write-Host "===================================================" -ForegroundColor Green
Write-Host "          Starting Davao Food Finder..." -ForegroundColor Green
Write-Host "===================================================" -ForegroundColor Green
Write-Host ""

$phpPath = "C:\xampp\php\php.exe"
if (-not (Test-Path $phpPath)) {
    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCmd) {
        $phpPath = $phpCmd.Source
    } else {
        Write-Error "PHP executable not found at C:\xampp\php\php.exe or on PATH."
        exit 1
    }
}

$appDir = Join-Path $PSScriptRoot "davao_food_finder"
if (-not (Test-Path $appDir)) {
    $appDir = Join-Path $PSScriptRoot "davao_food_finder"
}

if (-not (Test-Path $appDir)) {
    Write-Error "Could not find davao_food_finder directory."
    exit 1
}

Write-Host "App Directory: $appDir" -ForegroundColor Gray
Write-Host "PHP Path:      $phpPath" -ForegroundColor Gray
Write-Host ""
Write-Host "Opening http://127.0.0.1:8080/Main.html in browser..." -ForegroundColor Cyan
Start-Process "http://127.0.0.1:8080/Main.html"

Write-Host "Starting server on http://127.0.0.1:8080 (Press Ctrl+C to stop)..." -ForegroundColor Yellow
Set-Location $appDir
& $phpPath -d extension=gd -S 127.0.0.1:8080 -t .

