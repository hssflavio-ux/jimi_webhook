---
type: project
created: 2026-07-06
updated: 2026-07-12
---

# Project Conventions — jimi_webhook v4.0.0

## Identity
- **Produto**: Plataforma de rastreamento com telemetria de vídeo e gestão de ocorrências DMS
- **Cópia fiel do**: YUV (`app.yuv.com.br`)
- **Nome**: Jimi Webhook System v4.0.0 "YUV Parity"
- **Servidor produção**: `http://189.22.240.43` (Apache 2.4 + PHP 8.3 FPM + MySQL)

## Deploy (homolog 189.22.240.43)
- **Repo canônico**: `hssflavio-ux/jimi_webhook` — o repo `Flaviohses/jimi_webhook` é legado e NÃO recebe os pushes do dev (causou homolog desatualizado até 12/07/2026)
- **Servidor puxa de**: `git@github.com:hssflavio-ux/jimi_webhook.git` com deploy key dedicada `/root/.ssh/github_hssflavio` (read-only, via `core.sshCommand` do repo em `/var/www/jimi_webhook`)
- **Executar deploy**: `ssh administrador@189.22.240.43` (chave da máquina dev instalada) → `sudo ./scripts/deploy.sh` (sudo exige senha; a chave GitHub fica no root)
- **Usuário E2E no homolog**: `e2e@teste.local` / `E2e-Playwright-2026` (admin, customer 1) — para Playwright com `BASE_URL=http://189.22.240.43`

## Tech Stack (imutável)
- **PHP 8.3 puro** — sem Laravel, sem Symfony, sem build step, sem npm/webpack
- **MySQL 8.0** com prepared statements obrigatórios (nunca string interpolation)
- **Front controller**: `handlers/router.php` (subrotas de 2 segmentos)
- **CSS inline** em `layout_base.php` — sem arquivos CSS separados
- **JS vanilla** inline — sem React, Vue, jQuery, ou bundlers
- **CDN externo**: Leaflet (mapas), Chart.js (gráficos), flv.js (streaming)

## Design System (Coinbase)
- **Primária**: `#0052ff` (Coinbase Blue) — uso escasso (CTAs, links, foco)
- **Sidebar**: `#0a0b0d` (dark near-black) com `#16181c` hover
- **Canvas**: `#ffffff` (branco)
- **Tipografia**: Inter 400/500/600/700 para texto, JetBrains Mono para números/IMEI/códigos
- **CTAs**: pill 100px border-radius
- **Profundidade**: sombra única `0 4px 12px rgba(0,0,0,.04)` só em hover; hairline `#dee1e6`
- **Display headings**: peso 400 (voz editorial calma, não 700)

## Convenções de Código
- **Nomes de handlers**: snake_case em PT-BR (`ocorrencias_dashboard.php`, `video_aovivo.php`)
- **Rotas**: kebab-case em PT-BR (`/config-ocorrencias`, `/grupos-permissao`)
- **Auth**: `require_login()` bloqueia dashboard; `require_admin()` bloqueia admin; `csrf_verify()` em POST
- **Layout**: `require_once web/layout_base.php` → conteúdo → `require_once web/layout_base_close.php`
- **$page_title** + **$current_route** definidos antes do layout_base
- **$extra_head** para CSS/JS específico da página
- **Multi-tenant**: toda query escopada por `customer_id` da sessão (exceto admin/revendedor)
- **Prepared statements**: sempre com named placeholders (`:imei`, `:cid`)

## Artefatos de Documentação (ordem de leitura)
1. `PROJETO_YUV.md` — contrato-mestre do escopo (22 telas, modelo de dados, roadmap)
2. `STATUS.md` — diário vivo do desenvolvimento (fases, bugs, pendências)
3. `DESIGN.md` / `DESIGN-coinbase.md` — design system tokens
4. `AGENTS.md` — guia para AI agents (rotas, DB, gotchas)
5. `analise_yuv/analise_yuv.html` — fonte visual de verdade

## Status Atual (06/07/2026)
- **Fases 0–F concluídas** — 68 arquivos, 0 erros de lint
- **Migração v4.0.0** pronta (15 tabelas novas + 4 alteradas + índices + seeds)
- **Motor de ocorrências** integrado em pushalarm.php
- **CSRF** em 8 páginas de formulário
- **Cookie** Secure/HttpOnly/SameSite=Lax
- Pendente: teste end-to-end no servidor de produção
