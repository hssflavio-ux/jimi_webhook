# Plano de Implementação — v4.0.0 "YUV Parity"

> **Este plano foi substituído pelo blueprint-mestre [`PROJETO_YUV.md`](./PROJETO_YUV.md).**
> O `PROJETO_YUV.md` contém o detalhamento completo: visão, rotas-alvo, design system, modelo de dados, specs das 22 telas, motor de ocorrências, roadmap por fases e critérios de aceite.
>
> Este arquivo mantém apenas o **resumo executivo do roadmap** e o **plano de verificação**. Para qualquer implementação, siga o `PROJETO_YUV.md`.

## Objetivo

Transformar o `jimi_webhook` em uma **cópia fiel da plataforma YUV** (`app.yuv.com.br`): rastreamento multi-tenant com telemetria de vídeo e **gestão de ocorrências DMS** (alarme de câmera → ocorrência → tratativa → risco, com regras por cliente). O gateway de webhooks é preservado; dashboard e design são reconstruídos.

Fonte visual de origem: [`analise_yuv/analise_yuv.html`](./analise_yuv/analise_yuv.html).

## Roadmap por fases (resumo)

| Fase | Entregas | Depende de |
|---|---|---|
| **0 — Fundação** | Migração `v4.0.0.sql` (tabelas/índices); `router.php` com subrotas; novo `layout_base.php` (design YUV, sidebar-sanfona, header On/Off); componentes base (crud_grid, filtros, cards KPI, barra de risco) | — |
| **1 — Motor de Ocorrências** | `includes/occurrence_engine.php`; integração em `pushalarm.php`; `occurrence_configs`/params; `/config-ocorrencias` | Fase 0 |
| **2 — Módulo DMS** | `/ocorrencias/dashboard` + `/ocorrenciasdata`; tela de tratativa; `/relatorios/ocorrencias`; `/relatorios/alarmes` | Fase 1 |
| **3 — Vídeo** | `/video/aovivo`, `/video/playback`, `/video/downloads`; fila de download via `pushfileupload` | Fase 0 |
| **4 — Equipamentos** | `/equipamentos` (grade+form); OTA firmware; importação em lote; periféricos/streaming | Fase 0 |
| **5 — Relatórios + Exportação** | `/relatorios/posicoes`, `/deslocamento`, `/desatualizados`; `trip_builder`; geocode cache; `/exportar` + `worker.php` | Fase 1 |
| **6 — Cadastros de apoio** | `/chips`; `/motoristas` (+FaceID); `/grupos-permissao`; evoluir `/clientes` e `/usuarios` | Fase 0 |
| **7 — Visão executiva** | `/` Resumo enriquecido; `/bi`; `metrics_rollup` | Fases 1–6 |

## Segurança (fechar na origem, por fase)

Ao reescrever cada handler, incorporar as pendências de `STATUS.md` §10: CSRF em todos os POST (R11), prepared statements (R04/R12), índice `request_logs` (R07), cookie `Secure` (R18), limpeza de `sessions`/`request_logs` (R19). Remover rotas mortas (R08) e `pushcmd` (R09) na Fase 0.

## Plano de verificação

Sem suíte automatizada (convenção do projeto). Por fase:

1. **Lint**: `find handlers includes config core -name "*.php" -exec php -l {} \;`
2. **Webhook replay**: `curl` com payloads oficiais (`pushalarm`, `pushgps`, `pushhb`, `pushfileupload`) → verificar criação de ocorrências, posições, heartbeats e downloads.
3. **Browser** (Playwright): logar, percorrer cada rota nova, validar layout/dinâmica/escopo por cliente, auto-refresh, CRUD e filtros.
4. **Multi-tenant**: repetir fluxos como Cliente A e Cliente B (isolamento); testar impersonação.
5. **Carga**: relatórios de Alarmes/Posições com volume real (paginação/índices).
