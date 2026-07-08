<#
.SYNOPSIS
    Roda a suite Playwright E2E do JIMI Webhook System (Fase M.4).

.DESCRIPTION
    Verifica pré-requisitos (PHP, Node), instala dependências na primeira
    execução e dispara `npx playwright test`. O servidor dev PHP
    (php -S localhost:8000 server.php) é gerenciado pelo próprio Playwright
    (webServer no playwright.config.js) — não é preciso subi-lo manualmente.

.EXAMPLE
    ./scripts/run-tests.ps1
    ./scripts/run-tests.ps1 -BaseUrl http://189.22.240.43
    ./scripts/run-tests.ps1 -- --grep "Login"           # args extras do Playwright

.NOTES
    Specs autenticados exigem: $env:TEST_EMAIL / $env:TEST_PASSWORD
    Multi-tenant:              $env:TEST_EMAIL_B / $env:TEST_PASSWORD_B
    Webhook → ocorrência:      $env:TEST_IMEI / $env:WEBHOOK_TOKEN
    Sem essas variáveis os specs correspondentes são pulados (skip).
#>
param(
    [string]$BaseUrl = "http://localhost:8000",
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$PlaywrightArgs
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Push-Location $root
try {
    # ── Pré-requisitos ──
    if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
        throw "PHP não encontrado no PATH (esperado: C:\Users\flavi\php\php.exe)"
    }
    if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
        throw "Node.js não encontrado no PATH (requerido: Node 18+)"
    }

    # ── Dependências (primeira execução) ──
    if (-not (Test-Path (Join-Path $root 'node_modules/@playwright/test'))) {
        Write-Host "Instalando @playwright/test..." -ForegroundColor Cyan
        npm install
        if ($LASTEXITCODE -ne 0) { throw "npm install falhou" }
    }
    npx playwright install chromium
    if ($LASTEXITCODE -ne 0) { throw "playwright install falhou" }

    # ── Executa a suite ──
    $env:BASE_URL = $BaseUrl
    Write-Host "Rodando Playwright contra $BaseUrl ..." -ForegroundColor Cyan
    npx playwright test @PlaywrightArgs
    $exit = $LASTEXITCODE

    if ($exit -eq 0) {
        Write-Host "Suite verde. Relatório: npx playwright show-report" -ForegroundColor Green
    } else {
        Write-Host "Falhas na suite (exit $exit). Relatório: npx playwright show-report" -ForegroundColor Yellow
    }
    exit $exit
}
finally {
    Pop-Location
}
