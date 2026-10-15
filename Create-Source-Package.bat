@echo off
setlocal

cd /d "%~dp0"

echo.
echo Unclad Collection Source Packager
echo ---------------------------------
echo.

set "PROJECT_DIR=%CD%"

set /p "ZIP_NAME=Enter ZIP name without .zip: "

if "%ZIP_NAME%"=="" (
    echo.
    echo A ZIP name is required.
    pause
    exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Pack-UncladSource.ps1" ^
    -ProjectPath "%PROJECT_DIR%" ^
    -ZipName "%ZIP_NAME%"

if errorlevel 1 (
    echo.
    echo Packaging failed.
    pause
    exit /b 1
)

echo.
echo Packaging complete.
pause
