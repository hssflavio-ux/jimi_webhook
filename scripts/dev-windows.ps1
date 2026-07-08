# ============================================================
#  Ambiente de desenvolvimento local (Windows) — jimi_webhook
#  Sobe o MySQL portatil + o servidor PHP embutido (router shim).
#
#  Uso:  powershell -ExecutionPolicy Bypass -File scripts\dev-windows.ps1
#  Parar: feche as janelas dos servidores (ou Ctrl+C em cada uma).
#
#  Pre-requisitos (ja instalados nesta maquina):
#    - PHP 8.3     em C:\Users\flavi\php  (no PATH do usuario)
#    - MySQL 8.0   em C:\Users\flavi\mysql (portatil, data dir inicializado)
# ============================================================

$ErrorActionPreference = 'Stop'
$PhpDir   = 'C:\Users\flavi\php'
$MysqlDir = 'C:\Users\flavi\mysql'
$MysqlBin = Join-Path $MysqlDir 'mysql-8.0.37-winx64\bin'
$MyIni    = Join-Path $MysqlDir 'my.ini'
$Proj     = Split-Path -Parent $PSScriptRoot
$Port     = 8000

Write-Host "== jimi_webhook :: ambiente de desenvolvimento ==" -ForegroundColor Cyan

# 1) MySQL — sobe se ainda nao estiver respondendo
$alive = & "$MysqlBin\mysqladmin.exe" -h 127.0.0.1 -P 3306 -u root -p1029384756 ping 2>$null
if ($alive -match 'mysqld is alive') {
    Write-Host "[MySQL]  ja esta rodando na porta 3306." -ForegroundColor Green
} else {
    Write-Host "[MySQL]  iniciando servidor..." -ForegroundColor Yellow
    Start-Process -FilePath "$MysqlBin\mysqld.exe" -ArgumentList "--defaults-file=`"$MyIni`"" -WindowStyle Minimized
    for ($i=0; $i -lt 20; $i++) {
        Start-Sleep -Milliseconds 1000
        $p = & "$MysqlBin\mysqladmin.exe" -h 127.0.0.1 -P 3306 -u root -p1029384756 ping 2>$null
        if ($p -match 'mysqld is alive') { Write-Host "[MySQL]  pronto (porta 3306)." -ForegroundColor Green; break }
    }
}

# 2) Servidor PHP (router shim que reproduz o .htaccess)
Write-Host "[PHP]    servindo em http://127.0.0.1:$Port  (Ctrl+C para parar)" -ForegroundColor Green
Write-Host "         Login: /login   |  Primeiro acesso: /setup" -ForegroundColor DarkGray
& "$PhpDir\php.exe" -S "127.0.0.1:$Port" -t "$Proj" "$Proj\server.php"
