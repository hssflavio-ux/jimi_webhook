# Plano de Implementação — Pendências v4.0.0+

> **Contexto**: Fases 0–L do YUV Parity estão concluídas (80 PHP, 0 erros lint). Este plano cobre os itens **abertos** do [STATUS.md](STATUS.md) §10 "Pendências para Próxima Iteração", organizados em 5 fases incrementais por valor × risco × dependência.
>
> **Aprovado em**: 08/07/2026

---

## Questões Abertas (decidir antes de executar)

1. **Exportação Excel/PDF**: qual lib PHP usar?
   - **(Recomendado)** PhpSpreadsheet para Excel (.xlsx) + DomPDF para PDF — requer Composer
   - Alternativa: gerar Excel via CSV com extensão `.xls` (sem dependência, mas limitado) + PDF via `wkhtmltopdf` (binário no servidor)
   - Alternativa: manter CSV e postergar Excel/PDF real para quando houver Composer configurado

2. **Servidor IoTHub**: o endpoint `http://localhost:10088` está acessível no servidor de produção? Necessário para validar end-to-end (Fase M.2).

3. **Playwright**: instalar via `npx playwright install` no dev Windows? Ou criar scripts de teste manuais (curl + PHP CLI) primeiro?

---

## Matriz de Prioridade × Dependência

| Ordem | Fase | Justificativa | Dependência | Estimativa |
|-------|------|---------------|-------------|------------|
| 1º | **M.3** — PWA Responsive | Zero dependências externas, melhoria imediata de UX mobile | Nenhuma ✅ | ~2h |
| 2º | **M.1** — Excel/PDF | Funcionalidade pedida, depende de decisão Composer | Composer ⚠️ | ~3h |
| 3º | **M.2** — E2E Verify | Requer acesso ao servidor, valida integridade do deploy | Servidor + device ⚠️ | ~2h |
| 4º | **M.4** — Playwright Tests | Cobertura automatizada, depende de M.1-M.3 estarem prontos | Node.js ⚠️ | ~4h |
| 5º | **M.5** — Docs & Cleanup | Documenta tudo, fecha a iteração | Nenhuma ✅ | ~1h |

---

## Fase M.1 — Exportação Excel/PDF (Relatórios)

**Objetivo**: substituir a exportação CSV por Excel (.xlsx) e PDF nos 5 tipos de relatório do `worker.php`.

**Arquivos impactados**: `scripts/worker.php`, `handlers/exportar.php`, `handlers/exportardata.php`

### Passos detalhados

| # | Ação | Arquivo(s) | Detalhes |
|---|------|-----------|----------|
| 1.1 | Instalar PhpSpreadsheet + DomPDF via Composer | `composer.json` [NEW], `composer install` | Cria `vendor/` + autoload. Adicionar `vendor/` ao `.gitignore` |
| 1.2 | Criar helper de exportação | `includes/export_helper.php` [NEW] | Funções `generate_xlsx($headers, $rows, $filename)` e `generate_pdf($html, $filename)` encapsulando PhpSpreadsheet e DomPDF |
| 1.3 | Atualizar `worker.php` | `scripts/worker.php` | Substituir geração CSV por chamada ao helper. Suportar `format` param no job (csv/xlsx/pdf). Manter CSV como fallback |
| 1.4 | Atualizar form de exportação | `handlers/exportar.php` | Adicionar seletor de formato (CSV/Excel/PDF) no form de criação de job |
| 1.5 | Atualizar polling | `handlers/exportardata.php` | Incluir `format` e `mime_type` na resposta JSON para download correto |
| 1.6 | Alterar tabela `jobs` | Migration incremental ou ALTER | Adicionar coluna `format ENUM('csv','xlsx','pdf') DEFAULT 'csv'` |
| 1.7 | Lint + teste manual | — | `php -l` em todos os arquivos modificados + criar job de teste via UI |

**Dependência**: Composer no servidor. Se não houver Composer, postergar para CSV melhorado (headers UTF-8 BOM, separador `;`).

---

## Fase M.2 — Verificação End-to-End (IoTHub + Webhooks)

**Objetivo**: validar o ciclo completo de comandos e recepção de mídia no ambiente de produção.

### Passos detalhados

| # | Ação | Detalhes |
|---|------|----------|
| 2.1 | Verificar IoTHub | `curl http://localhost:10088/api/device/status` no servidor. Documentar se o serviço está UP |
| 2.2 | Teste envio de comando | Enviar comando real via `/sendcommand` (proNo 128 = take photo) para um device online. Verificar `/commandstatus` |
| 2.3 | Teste recepção `pushinstructresponse` | Monitorar `logs/` para confirmar que a resposta do device é processada |
| 2.4 | Teste `pushfileupload` → vídeo | Enviar payload simulado de upload para `/pushfileupload`. Verificar se aparece em `/video/downloads` com status correto e se `link_upload_to_occurrence()` vincula à ocorrência (se houver alarme recente) |
| 2.5 | Teste OTA firmware | Enviar proNo 33027 (firmware update) para device de teste. **REQUER device real** — documentar resultado |
| 2.6 | Criar script de replay | `scripts/test_e2e.sh` [NEW] — sequência de `curl` que simula: pushgps → pushalarm → pushfileupload → verificar ocorrência criada + mídia vinculada |
| 2.7 | Documentar resultados | Atualizar STATUS.md com resultado de cada teste |

**Dependência**: Acesso SSH ao servidor + pelo menos 1 device online.

---

## Fase M.3 — PWA Responsive Improvements

**Objetivo**: melhorar a experiência mobile (off-canvas sidebar, touch targets, meta tags PWA).

**Arquivos impactados**: `web/layout_base.php`, `web/login_template.php`

### Passos detalhados

| # | Ação | Arquivo(s) | Detalhes |
|---|------|-----------|----------|
| 3.1 | Manifest PWA | `manifest.json` [NEW] | `name`, `short_name`, `start_url: /`, `display: standalone`, `theme_color: #0052ff`, `background_color: #0a0b0d`, ícones 192px/512px |
| 3.2 | Meta tags PWA | `web/layout_base.php` | `<link rel="manifest">`, `<meta name="theme-color">`, `<meta name="apple-mobile-web-app-capable">`, viewport já existe |
| 3.3 | Ícones PWA | `assets/icons/` [NEW] | Gerar ícones 192×192 e 512×512 do logo JIMI com fundo `#0a0b0d` |
| 3.4 | Sidebar off-canvas refinada | `web/layout_base.php` | Melhorar transição CSS (transform + overlay backdrop), touch swipe para fechar, body scroll lock quando aberta |
| 3.5 | Touch targets | `web/layout_base.php` | Garantir mínimo 44×44px em botões/links da sidebar e header no breakpoint ≤768px |
| 3.6 | Tabelas responsivas | CSS global em layout_base.php | `overflow-x: auto` em containers de tabela + `white-space: nowrap` em colunas IMEI/data |
| 3.7 | Login responsivo | `web/login_template.php` | Ajustar card de login para 100% width em mobile com padding adequado |
| 3.8 | Teste visual | Browser DevTools | Testar em Chrome DevTools (iPhone 12/14, Galaxy S21) cada tela principal |

**Dependência**: Nenhuma (CSS + meta tags).

---

## Fase M.4 — Testes Automatizados (Playwright)

**Objetivo**: criar suite de testes E2E para fluxos críticos — login, ocorrências, webhook replay, CRUD.

**Diretório**: `tests/` [NEW]

### Passos detalhados

| # | Ação | Arquivo(s) | Detalhes |
|---|------|-----------|----------|
| 4.1 | Setup Playwright | `package.json` [NEW], `playwright.config.js` [NEW] | `npx -y playwright install --with-deps chromium`, configurar `baseURL: http://localhost:8000` |
| 4.2 | Fixture de auth | `tests/fixtures/auth.js` [NEW] | Login helper que obtém cookie `jimi_token` e reutiliza em todos os testes |
| 4.3 | Teste: Login | `tests/login.spec.js` [NEW] | Cenários: login correto, senha errada, rate limiting (6ª tentativa bloqueada), redirect para `/` |
| 4.4 | Teste: Navegação sidebar | `tests/navigation.spec.js` [NEW] | Verificar que todas as 22 rotas da sidebar renderizam sem erro 500/404 |
| 4.5 | Teste: CRUD Motoristas | `tests/motoristas.spec.js` [NEW] | Criar → listar → editar → remover motorista |
| 4.6 | Teste: Webhook replay → Ocorrência | `tests/webhook_occurrence.spec.js` [NEW] | `curl` envia `pushalarm` → verifica ocorrência criada via `/ocorrencias/dashboard` |
| 4.7 | Teste: Multi-tenant isolamento | `tests/multitenant.spec.js` [NEW] | Login como Cliente A, verificar que dados de Cliente B não aparecem |
| 4.8 | Teste: Exportação | `tests/export.spec.js` [NEW] | Criar job de relatório → polling até concluído → download funciona |
| 4.9 | CI script | `scripts/run-tests.ps1` [NEW] | Script PowerShell que sobe `php -S localhost:8000 server.php` em background → roda Playwright → mata o servidor |
| 4.10 | Documentar | README.md | Adicionar seção "Testes" com instruções de setup e execução |

**Dependência**: Node.js 18+ no dev Windows. Servidor local PHP funcionando.

---

## Fase M.5 — Documentação e Cleanup

**Objetivo**: atualizar documentação que ficou defasada e fechar dívidas de documentação.

### Passos detalhados

| # | Ação | Arquivo(s) | Detalhes |
|---|------|-----------|----------|
| 5.1 | API_COVERAGE.md | `API_COVERAGE.md` [NEW ou UPDATE] | Mapear cada endpoint AJAX/webhook com método, parâmetros, resposta esperada, auth |
| 5.2 | PRD.md | `docs/PRD.md` [UPDATE] | Atualizar para refletir v4.0.0 (YUV Parity) — módulos, personas, fluxos |
| 5.3 | STATUS.md | `STATUS.md` | Marcar itens concluídos das fases M.x, adicionar nova seção com estado final |
| 5.4 | CHANGELOG.md | `CHANGELOG.md` | Adicionar entries para cada fase M.x concluída |
| 5.5 | MEMORY.md | `.agents/memory/MEMORY.md` | Atualizar com decisões tomadas nesta iteração |

---

## Plano de Verificação

### Lint PHP (cada fase)
```bash
$files = Get-ChildItem -Recurse -Include *.php -Path handlers,includes,config,core,web,scripts
foreach ($f in $files) { & "C:\Users\flavi\php\php.exe" -l $f.FullName }
```

### Playwright (após Fase M.4)
```bash
npx playwright test --reporter=html
```

### E2E webhook replay (Fase M.2)
```bash
bash scripts/test_e2e.sh
```

### Verificações Manuais
- **M.1**: Criar job de exportação via UI → verificar que Excel abre no LibreOffice/Excel e PDF renderiza
- **M.2**: SSH no servidor → executar script de replay → verificar dados no MySQL
- **M.3**: Abrir `localhost:8000` no Chrome DevTools → emular iPhone 14 → navegar por todas as rotas
- **M.4**: `npx playwright test` → todos os specs passam (verde)
- **M.5**: Revisar STATUS.md e CHANGELOG.md por completude
