@echo off
setlocal
title Davao Food Finder Server

echo ===================================================
echo           Starting Davao Food Finder...
echo ===================================================
echo.

set "PHP_CMD=C:\xampp\php\php.exe"
if not exist "%PHP_CMD%" (
    where php >nul 2>nul
    if %ERRORLEVEL% equ 0 (
        set "PHP_CMD=php"
    ) else (
        echo [ERROR] PHP executable not found at C:\xampp\php\php.exe or on PATH.
        echo Please ensure PHP 8.1+ / XAMPP is installed.
        pause
        exit /b 1
    )
)

set "APP_DIR=%~dp0davao_food_finder"
if not exist "%APP_DIR%" (
    set "APP_DIR=%~dp0davao_food_finder"
)

if not exist "%APP_DIR%" (
    echo [ERROR] Could not find the davao_food_finder directory.
    pause
    exit /b 1
)

echo Target directory: %APP_DIR%
echo PHP executable:   %PHP_CMD%
echo.
echo Launching browser at http://127.0.0.1:8080/Main.html ...
start "" "http://127.0.0.1:8080/Main.html"

echo.
echo Starting PHP development server on http://127.0.0.1:8080 ...
echo (Press Ctrl+C to stop the server)
echo.

cd /d "%APP_DIR%"
"%PHP_CMD%" -d extension=gd -S 127.0.0.1:8080 -t .

pause

