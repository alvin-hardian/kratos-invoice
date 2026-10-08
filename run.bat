@echo off
REM Kratos Invoice - start the built-in PHP server (DocumentRoot = public).
REM First time: composer install ^& copy .env.example .env ^& php bin/migrate.php ^& php bin/create-user.php "Admin" admin@local.id admin123
if not exist vendor\autoload.php (
    echo [run] vendor not found, running composer install...
    composer install
    if errorlevel 1 pause & exit /b 1
)
if not exist .env (
    echo [run] .env not found, copying from .env.example...
    copy .env.example .env >nul
)
set PORT=8099
if not "%~1"=="" set PORT=%~1
echo [run] Starting Kratos Invoice on http://127.0.0.1:%PORT%  (Ctrl+C to stop)
php -S 127.0.0.1:%PORT% -t public
