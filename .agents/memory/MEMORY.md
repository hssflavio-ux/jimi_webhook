# Memory Index

> Auto-load at session start. Max 200 lines.

## User
- [user] Flavian, Windows 11, PowerShell 7+, VS Code/Cursor → user-preferences.md
- [user] Português (PT-BR), respostas concisas, prefere ação a explicação → user-preferences.md
- [user] PHP lint via `C:\Users\flavi\php\php.exe -l` → user-preferences.md

## Project
- [project] jimi_webhook v4.0.0 — YUV Parity, PHP 8.3 puro + MySQL 8.0 → project-conventions.md
- [project] Design system Coinbase: azul #0052ff, sidebar #0a0b0d, CTAs pill 100px, JetBrains Mono números/IMEI → project-conventions.md
- [project] Autenticação token cookie `jimi_token` → MySQL `sessions`, sem `session_start()` → project-conventions.md
- [project] PROJETO_YUV.md é o contrato-mestre; STATUS.md é o diário vivo → project-conventions.md
- [project] Todas as 8 fases concluídas (68 arquivos, 0 erros lint) → project-conventions.md
- [project] Servidor produção: 189.22.240.43 Apache 2.4 + PHP 8.3 FPM → project-conventions.md

## Feedback
- [feedback] Usuário diz "Continue" para avançar fases (não perguntar se quer continuar) → feedback-history.md
- [feedback] Prefere implementação completa por fase com verificação lint ao final → feedback-history.md
- [feedback] Valoriza STATUS.md atualizado como artefato de handoff entre sessões → feedback-history.md

## Reference
- [reference] Migration v4.0.0 adiciona 15 tabelas + altera 4 existentes → tech-decisions.md
- [reference] Motor de ocorrências: pushalarm → occurrence_engine → ocorrências (dedup 10min) → tech-decisions.md
- [reference] CSRF: `includes/csrf.php` com token por sessão, 8 páginas protegidas → tech-decisions.md
- [reference] Workers cron: worker.php (jobs), trip_builder.php (haversine), metrics_rollup.php (stub) → tech-decisions.md
