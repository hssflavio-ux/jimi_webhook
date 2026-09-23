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
```

⚠️ Esta lista precisa acompanhar `scripts/deploy.sh` (bloco `run_migration`) —
ela ficou parada na v4.9.5 enquanto o deploy já aplicava v4.9.8/v4.9.9, e um
banco novo saía sem a coluna `alarm_types.is_diagnostic`. Sintoma: o
`tests/helpers/diagnostico_guard.test.php` **aborta** com código 2.
