---
name: db-setup
description: Fresh MySQL database install and full migration order for jimi_tracker. Use when setting up a new local dev database or a separate copy for testing a migration.
---

# Fresh database install

Desde a v4.7.3 nenhum `.sql` embute `USE`/`CREATE DATABASE` — o banco vem da
linha de comando, então dá para instalar (ou montar cópia de teste) com
qualquer nome, trocando `jimi_tracker` em todas as linhas abaixo.

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS jimi_tracker DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p jimi_tracker < mysql/jimi_tracker.sql
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v3.1.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.0.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.1.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.2.1.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.3.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.4.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.4.1.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.5.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.6.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.7.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.8.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.8.1.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.8.3.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.8.4.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.8.5.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.8.6.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.8.7.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.8.9.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.4.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.5.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.8.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.9.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.10.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.11.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.12.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.14.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.15.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.17.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.24.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.25.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.31.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.32.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.9.37.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.10.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.10.1.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.10.3.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.10.4.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.11.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.12.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.13.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.13.21.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.14.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.15.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.16.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.16.1.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.17.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.17.5.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.17.8.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.17.12.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.17.13.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.17.23.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.17.24.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.18.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.18.2.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.18.4.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.19.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.19.1.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.19.2.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.20.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.21.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.21.5.sql
```

Lista extraída de `scripts/deploy.sh` (bloco `run_migration`, fonte de verdade real) e validada
ponta-a-ponta em 22/09/2026: 61 migrações, `2.0.0` -> `4.21.5`, banco novo criado do zero e todas
aplicadas sem erro. Antes disso ela ficava parada na v4.9.14 enquanto o projeto ja estava na
v4.23.0 -- mesma classe de defeito ja registrada aqui uma vez (parou na v4.9.5 enquanto o deploy
aplicava v4.9.8/v4.9.9, banco novo saia sem `alarm_types.is_diagnostic`,
`tests/helpers/diagnostico_guard.test.php` abortava com codigo 2). Sempre que atualizar, conferir
contra `scripts/deploy.sh`, nao copiar de memoria.

`mysql/jimi_tracker.sql` so carrega em MySQL 8.4+ se os comentarios dentro das stored procedures
nao tiverem emoji -- MySQL rejeita com `ERROR 4089: Definition of stored routine contains an
invalid utf8mb3 character string` (o dicionario interno de rotinas e limitado a utf8mb3,
independente do charset da conexao ou da tabela). Corrigido em 22/09/2026 removendo os emojis de
dentro dos blocos `DELIMITER //...DELIMITER ;` do arquivo (emoji em comentario FORA de rotina nao
tem esse problema). Se uma migracao futura recriar uma stored procedure com emoji dentro do
corpo, o mesmo erro volta.
