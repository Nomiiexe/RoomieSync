@echo off
title RoomieSync Server
cd /d "%~dp0"

set "PHP_BIN=C:\xampp\php\php.exe"

where php >nul 2>nul
if %ERRORLEVEL% equ 0 (
    set "PHP_BIN=php"
) else (
    if not exist "%PHP_BIN%" (
        echo [ERROR] PHP executable was not found at %PHP_BIN% or in PATH.
        echo Please ensure XAMPP is installed at C:\xampp or add PHP to your environment PATH.
        pause
        exit /b 1
    )
)

echo ========================================================
echo               RoomieSync Local Server
echo ========================================================
echo Server URL: http://127.0.0.1:8000
echo Default Login: Prince / Password123!
echo (See ACCOUNTS.txt for more test accounts)
echo.
echo Make sure MySQL is running in XAMPP!
echo Press Ctrl+C in this window to stop the server.
echo ========================================================
echo.

start http://127.0.0.1:8000

"%PHP_BIN%" -S 127.0.0.1:8000
pause
