@echo off
setlocal DisableDelayedExpansion
rem Imagine Portal - somente previsualizacao local com dados ficticios.
rem Nao inicia automaticamente, nao altera DNS, VPS ou acesso a clientes.
cd /d "%~dp0"
if errorlevel 1 (
  echo Nao foi possivel acessar a pasta do pacote.
  exit /b 2
)
if not exist "serve_private_preview.py" (
  echo Arquivo do visualizador ausente. Nao iniciar.
  pause
  exit /b 2
)
if not exist "imagine-original-ui-synthetic.zip" (
  echo Pacote HTML nao encontrado. Nao iniciar.
  pause
  exit /b 2
)
set "PYTHON=python"
where py >nul 2>nul
if not errorlevel 1 set "PYTHON=py -3"
echo.
echo Verificando integridade do Imagine Portal ficticio...
%PYTHON% "serve_private_preview.py" --zip "imagine-original-ui-synthetic.zip" --check
if errorlevel 1 (
  echo Verificacao falhou: nenhum servidor sera aberto.
  pause
  exit /b 2
)
echo.
echo ATENCAO: demonstracao com dados FICTICIOS.
echo Acesso somente no seu computador: http://127.0.0.1:18417/index.html
echo Nao disponibilizar em redes publicas ou para clientes.
echo.
choice /C SN /N /M "Autorizar abertura do servidor LOCAL? [S/N] "
if errorlevel 2 (
  echo Operacao cancelada. Nenhum servidor iniciado.
  exit /b 0
)
if errorlevel 1 (
  echo Iniciando servidor local. Para encerrar, pressione Ctrl+C.
  %PYTHON% "serve_private_preview.py" --zip "imagine-original-ui-synthetic.zip" --serve --port 18417
  exit /b %ERRORLEVEL%
)
exit /b 2
