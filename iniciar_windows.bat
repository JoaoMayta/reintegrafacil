@echo off
cd /d "%~dp0"
docker info >nul 2>&1
if errorlevel 1 (
 echo Abra o Docker Desktop antes de continuar.
 pause
 exit /b 1
)
if not exist .env (
 powershell -NoProfile -Command "$db=[guid]::NewGuid().ToString('N'); $root=[guid]::NewGuid().ToString('N'); $admin=[guid]::NewGuid().ToString('N'); @('APP_PORT=8080',('DB_PASSWORD='+$db),('DB_ROOT_PASSWORD='+$root),('ADMIN_PASSWORD='+$admin),'AI_API_KEY=','AI_MODEL=nvidia/nemotron-3.5-super-vl:free') | Set-Content -Encoding ASCII .env"
)
docker compose up --build -d
if errorlevel 1 (
 echo Confira as mensagens do Docker.
 pause
 exit /b 1
)
echo Acesse http://localhost:8080
findstr /b ADMIN_PASSWORD= .env
echo A primeira instalacao pode levar alguns minutos.
pause
