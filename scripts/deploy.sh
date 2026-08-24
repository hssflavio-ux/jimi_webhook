#!/usr/bin/env bash
# ============================================================
# JIMI WEBHOOK SYSTEM — Deploy Script v2.0.0
# ============================================================
# Uso:
#   ./scripts/deploy.sh              — deploy normal (backup + pull + migrate)
#   ./scripts/deploy.sh --force      — força redeploy mesmo sem mudanças
#   ./scripts/deploy.sh --skip-migrate — pula migração do banco
#   ./scripts/deploy.sh --skip-backup  — pula backup (NÃO recomendado)
# ============================================================
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="/var/backups/jimi_webhook"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
FORCE=0; SKIP_MIGRATE=0; SKIP_BACKUP=0

for arg in "$@"; do
    case $arg in --force) FORCE=1 ;; --skip-migrate) SKIP_MIGRATE=1 ;; --skip-backup) SKIP_BACKUP=1 ;; esac
done

cd "$APP_DIR" || { echo "ERRO: Diretório $APP_DIR não encontrado"; exit 1; }
echo "=== DEPLOY: $(date) ==="

# ════════════════════════════════════════════════════════════
# FASE 1: PREPARE — Verificações de ambiente
# ════════════════════════════════════════════════════════════
echo ""
echo "=== FASE 1/5: PREPARE — Verificando dependências ==="

# Binários essenciais
check_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "  ✗ FALHA: $1 não encontrado"; exit 1; }; }
check_cmd php; check_cmd mysql; check_cmd git

# PHP version
PHP_VER=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
echo "  PHP $PHP_VER"
if awk "BEGIN {exit !($PHP_VER >= 7.4)}" 2>/dev/null; then
    echo "  ✓ PHP >= 7.4"
else
    echo "  ✗ FALHA: PHP 7.4+ requerido. Instale: sudo apt install php8.1 php8.1-fpm php8.1-mysql"
    exit 1
fi

# Módulos PHP críticos
#
# `zip` entrou na v4.7.2 depois de o homolog rodar MESES sem ele: XLSX é o
# formato padrão de /exportar E do relatório agendado, e sem ZipArchive o
# worker morria com fatal ("Class ZipArchive not found"), deixando o job preso
# em 'processando' e a execução em 'enfileirado' para sempre — sem erro no
# histórico, porque o fatal mata o processo antes de qualquer UPDATE. Esta
# checagem é justamente o que teria pego isso no primeiro deploy.
#
# `openssl` é o que permite ao includes/mailer.php abrir ssl://smtp:465.
#
# -x casa a linha inteira: sem ele, "pdo" casaria com "pdo_mysql" e um php sem
# PDO passaria batido desde que tivesse pdo_sqlite.
echo "  Verificando módulos PHP..."
for mod in pdo pdo_mysql json mbstring zip openssl; do
    php -m 2>/dev/null | grep -qix "$mod" && echo "  ✓ php-$mod" || { echo "  ✗ FALHA: php-$mod — instale com: sudo apt install php$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")-$mod"; exit 1; }
done

# PHP-FPM (necessário para fastcgi_finish_request)
FPM_SERVICE=$(systemctl list-units --type=service 2>/dev/null | grep -oP 'php\d+\.\d+-fpm' | head -1 || true)
if [ -n "$FPM_SERVICE" ]; then
    if systemctl is-active --quiet "$FPM_SERVICE" 2>/dev/null; then
        echo "  ✓ PHP-FPM ($FPM_SERVICE) ativo"
    else
        echo "  ⚠ AVISO: PHP-FPM ($FPM_SERVICE) inativo. Inicie: sudo systemctl start $FPM_SERVICE"
    fi
else
    echo "  ⚠ AVISO: PHP-FPM não detectado. fastcgi_finish_request() não funcionará sem FPM."
    echo "          Instale: sudo apt install php-fpm"
fi

# Apache modules
echo "  Verificando Apache..."
if command -v apache2ctl >/dev/null 2>&1; then
    apache2ctl -M 2>/dev/null | grep -qi rewrite && echo "  ✓ mod_rewrite" \
        || echo "  ⚠ AVISO: mod_rewrite ausente. Ative: sudo a2enmod rewrite && sudo systemctl reload apache2"
    apache2ctl -M 2>/dev/null | grep -qi headers && echo "  ✓ mod_headers" \
        || echo "  ⚠ AVISO: mod_headers ausente"
    # VirtualHost + AllowOverride
    #
    # 🔴 `apache2ctl -S` NÃO informa o DocumentRoot dos vhosts. A saída traz
    # ServerName, porta e o ARQUIVO de config de cada vhost, mais o
    # "Main DocumentRoot" global (`/var/www/html` no Debian). Procurar
    # $APP_DIR ali dentro falha SEMPRE que a aplicação não é o docroot
    # principal — que é o caso normal. Até 15/08/2026 todo deploy de produção
    # imprimia "Nenhum VirtualHost apontando para /var/www/jimi_webhook" com o
    # vhost perfeitamente configurado; um aviso que aparece sempre é um aviso
    # que se aprende a ignorar, e aí o dia em que ele for verdade passa batido.
    # Agora lemos os arquivos de config que o próprio Apache diz estar usando.
    #
    # ⚠️ Tem de ser `grep -R`/lista explícita de arquivos, NUNCA `grep -r` num
    # diretório: `sites-enabled/` é só symlink para `sites-available/`, e o
    # `-r` minúsculo PULA symlink encontrado na recursão (só o `-R` segue).
    # Com `-r` a varredura volta vazia e o falso aviso ressurge.
    VHOSTS=$(apache2ctl -S 2>/dev/null | grep -oE '\(/[^()]+\.conf:[0-9]+\)' | tr -d '()' | cut -d: -f1 | sort -u || true)
    if [ -z "$VHOSTS" ]; then
        VHOSTS=$(find /etc/apache2/sites-enabled /etc/httpd/conf.d -name '*.conf' 2>/dev/null || true)
    fi

    DOCROOT_HIT=""
    if [ -n "$VHOSTS" ]; then
        DOCROOT_HIT=$(grep -lE "^[[:space:]]*DocumentRoot[[:space:]]+\"?${APP_DIR}/?\"?[[:space:]]*\$" $VHOSTS 2>/dev/null || true)
    fi

    if [ -n "$DOCROOT_HIT" ]; then
        echo "  ✓ VirtualHost com DocumentRoot em $APP_DIR ($(echo "$DOCROOT_HIT" | xargs -n1 basename | tr '\n' ' '))"
        # `AllowOverride All` é o que faz o .htaccess valer — e o .htaccess É o
        # front controller. Sem ele o Apache serve só arquivo existente e TODA
        # rota do dashboard vira 404. Procuramos também no config principal,
        # porque a diretiva costuma morar num <Directory> global; não achar não
        # é prova de ausência, então o tom aqui é de conferência, não de falha.
        # ⚠️ `-q` é obrigatório aqui, e não é estilo: a lista inclui de
        # propósito o config principal das duas distros, e o que não existir
        # faz o GNU grep sair com **2 mesmo tendo casado** em outro arquivo.
        # Com `>/dev/null` no lugar do `-q`, o `if` cai no else e o script
        # nega um `AllowOverride All` que está lá — foi o que aconteceu no
        # primeiro teste desta correção. Só o `-q` sai com 0 no primeiro match
        # "even if an error was detected" (man grep).
        if grep -qE '^[[:space:]]*AllowOverride[[:space:]]+All' $DOCROOT_HIT /etc/apache2/apache2.conf /etc/httpd/conf/httpd.conf 2>/dev/null; then
            echo "  ✓ AllowOverride All presente"
        else
            echo "  ℹ AllowOverride All não localizado na config — se as rotas do dashboard derem 404, é aqui"
        fi
    elif [ -n "$VHOSTS" ]; then
        echo "  ⚠ AVISO: nenhum vhost habilitado com DocumentRoot em $APP_DIR"
        echo "          Conferidos: $(echo "$VHOSTS" | tr '\n' ' ')"
        echo "          O smoke test de /ping na FASE 4 é a prova final — se ele passar, o site está sendo servido."
    else
        echo "  ℹ Config de vhost não localizada (layout fora do padrão Debian/RHEL) — checagem pulada; o /ping da FASE 4 é a prova"
    fi
else
    echo "  ⚠ AVISO: Apache não detectado (pode ser Nginx — verifique rewrite manualmente)"
fi

# MySQL — a conexão é testada com as credenciais do .env (que é o que a app e
# as migrations usam). NÃO usar `mysql -e "SELECT 1"` sem credenciais: isso
# conecta como o usuário do SO (root/administrador sob sudo), que normalmente
# não tem conta MySQL, e falha SEMPRE — dando um "verifique credenciais no .env"
# enganoso mesmo com o .env perfeito.
echo "  Verificando MySQL..."
if mysql --version >/dev/null 2>&1; then
    echo "  ✓ MySQL disponível"
    if [ -f .env ]; then
        source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS)=' .env | sed 's/^/export /')
        if MYSQL_PWD="${DB_PASS:-}" mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" \
            -u"${DB_USER:-root}" "${DB_NAME:-jimi_tracker}" -e "SELECT 1" >/dev/null 2>/tmp/mysql_check_err.log; then
            echo "  ✓ Conexão MySQL OK (credenciais do .env)"
        else
            echo "  ⚠ AVISO: conexão MySQL com as credenciais do .env falhou:"
            grep -v 'Using a password' /tmp/mysql_check_err.log | sed 's/^/          /'
        fi
        rm -f /tmp/mysql_check_err.log
    else
        echo "  ⚠ AVISO: .env ausente — não foi possível testar a conexão MySQL"
    fi
else
    echo "  ✗ FALHA: mysql CLI não encontrado"
    exit 1
fi

# Git remote — verificar conexão e forçar SSH
echo "  Verificando Git..."
GIT_REMOTE=$(git remote get-url origin 2>/dev/null || echo "")
echo "  Remote: $GIT_REMOTE"

# Se o remote for HTTPS, testa SSH e converte se disponível
if [[ "$GIT_REMOTE" == https://github.com/* ]]; then
    echo "  ℹ Remote HTTPS detectado — testando SSH..."
    SSH_REMOTE=$(echo "$GIT_REMOTE" | sed 's|https://github.com/|git@github.com:|')
    SSH_REMOTE="${SSH_REMOTE%/}"
    if [[ "$SSH_REMOTE" != *.git ]]; then SSH_REMOTE="${SSH_REMOTE}.git"; fi

    if git ls-remote "$SSH_REMOTE" HEAD --quiet 2>/dev/null; then
        git remote set-url origin "$SSH_REMOTE"
        echo "  ✓ Remote alterado para: $SSH_REMOTE"
        GIT_REMOTE="$SSH_REMOTE"
    else
        echo "  ⚠ SSH indisponível — mantendo HTTPS"
    fi
fi

if git fetch origin --quiet 2>/dev/null; then
    echo "  ✓ Conexão GitHub OK (SSH)"
else
    echo "  ✗ FALHA: git fetch falhou."
    echo "          Configure SSH Key: ssh-keygen -t ed25519 -C 'deploy' -f ~/.ssh/github_deploy"
    echo "          Adicione chave pública em: https://github.com/settings/keys"
    echo "          Teste: ssh -T git@github.com"
    exit 1
fi

# Grupo do servidor web — o que sustenta a leitura do .env pelo PHP.
#
# 🔴 SILENCIOSO SE FALTAR, E DERRUBA O SITE. O .env é 640 com grupo www-data,
# e todo `sed -i` (inclusive o que ESTE script faz na FASE 3a) recria o
# arquivo. Se quem roda o deploy não pertence ao www-data:
#   • o `chgrp www-data .env` da FASE 3a falha com "Operation not permitted";
#   • o `chmod 2755` da FASE 3c tem o setgid DESCARTADO PELO KERNEL sem erro
#     (POSIX: sem CAP_FSETID e fora do grupo, o bit é limpo em silêncio);
# e o .env fica ilegível para o Apache. Sintoma: `/ping` responde
# `"version":"desconhecida"` e os webhooks devolvem 500, sem nada no log da
# aplicação — ela não chega a conectar no banco. Ocorreu em 13 e 14/08/2026.
echo "  Verificando grupo do servidor web..."
if id -nG 2>/dev/null | tr ' ' '\n' | grep -qx www-data; then
    echo "  ✓ $(whoami) pertence ao grupo www-data"
else
    echo "  ⚠ AVISO: $(whoami) NÃO pertence ao grupo www-data."
    echo "          O .env pode ficar ilegível para o PHP no próximo 'sed -i'."
    echo "          Corrija com: sudo usermod -aG www-data $(whoami) && sudo chmod 2755 $APP_DIR"
    echo "          (é preciso reabrir a sessão para o grupo valer)"
fi

# Disco
DISK_USAGE=$(df -h "$APP_DIR" | tail -1 | awk '{print $5}' | tr -d '%')
echo "  Disco: ${DISK_USAGE}% usado"
if [ "$DISK_USAGE" -gt 90 ]; then
    echo "  ⚠ AVISO: Disco quase cheio (${DISK_USAGE}%). Libere espaço."
fi

# ════════════════════════════════════════════════════════════
# FASE 2: BACKUP — Salvar estado atual
# ════════════════════════════════════════════════════════════
echo ""
if [ "$SKIP_BACKUP" -eq 0 ]; then
    echo "=== FASE 2/5: BACKUP — Salvando estado atual ==="
    mkdir -p "$BACKUP_DIR"

    # Backup do .env (contém credenciais — crítico)
    if [ -f .env ]; then
        cp .env "$BACKUP_DIR/env_$TIMESTAMP.bak"
        echo "  ✓ .env → backup/env_$TIMESTAMP.bak"
    fi

    # Backup do banco de dados. O erro do mysqldump é capturado e exibido — não
    # é sempre credencial: uma VIEW quebrada (ex.: definer/tabela inexistente)
    # aborta o dump com erro 1356 e o backup sai incompleto. Silenciar o stderr
    # e culpar as credenciais mascarava a causa real.
    if [ -f .env ]; then
        source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS)=' .env | sed 's/^/export /')
        if MYSQL_PWD="${DB_PASS:-}" mysqldump -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
            --single-transaction --routines --triggers \
            "${DB_NAME:-jimi_tracker}" > "$BACKUP_DIR/db_$TIMESTAMP.sql" 2>/tmp/dump_err.log; then
            echo "  ✓ Banco → backup/db_$TIMESTAMP.sql ($(du -h "$BACKUP_DIR/db_$TIMESTAMP.sql" | cut -f1))"
        else
            echo "  ⚠ AVISO: mysqldump falhou (backup NÃO gerado). Erro:"
            grep -v 'Using a password' /tmp/dump_err.log | head -3 | sed 's/^/          /'
        fi
        rm -f /tmp/dump_err.log
    fi

    # Limpar backups antigos (manter últimos 10)
    ls -1t "$BACKUP_DIR"/db_*.sql 2>/dev/null | tail -n +11 | xargs -r rm -f
    ls -1t "$BACKUP_DIR"/env_*.bak 2>/dev/null | tail -n +11 | xargs -r rm -f
else
    echo "=== FASE 2/5: BACKUP — PULADO (--skip-backup) ==="
fi

# ════════════════════════════════════════════════════════════
# FASE 3: DEPLOY — Atualizar código
# ════════════════════════════════════════════════════════════
echo ""
echo "=== FASE 3/5: DEPLOY — Atualizando código ==="

# Garantir que estamos no branch main
git checkout main --quiet 2>/dev/null || true

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)

if [ "$LOCAL" = "$REMOTE" ] && [ "$FORCE" -eq 0 ]; then
    echo "  ✓ Já estamos no commit mais recente ($(git rev-parse --short HEAD))"
    echo "  Use --force para redeploy mesmo sem mudanças."
else
    echo "  Atualizando: $(git rev-parse --short HEAD) → $(git rev-parse --short origin/main)"

    # Listar arquivos alterados antes do pull
    CHANGED=$(git diff --name-only HEAD origin/main 2>/dev/null | head -20 || true)
    if [ -n "$CHANGED" ]; then
        echo "  Arquivos que serão atualizados:"
        echo "$CHANGED" | while read -r f; do echo "    - $f"; done
    fi

    # Impressão do próprio script ANTES do pull. O bash lê o arquivo em
    # disco conforme executa: se o pull trocar este script no meio da
    # execução, o interpretador continua a partir do offset antigo dentro
    # do arquivo novo — e blocos inteiros somem silenciosamente.
    # Foi exatamente o que aconteceu no deploy da v4.4.1 (28/07/2026): as
    # migrações v4.4.0 e v4.4.1, recém-adicionadas neste arquivo, nunca
    # rodaram e o deploy terminou com "sucesso". Daí a re-execução abaixo.
    SELF_SHA_BEFORE=$(sha256sum "$0" 2>/dev/null | cut -d' ' -f1 || echo "")

    git pull origin main 2>&1
    echo "  ✓ Código atualizado para $(git rev-parse --short HEAD)"

    SELF_SHA_AFTER=$(sha256sum "$0" 2>/dev/null | cut -d' ' -f1 || echo "")
    if [ -n "$SELF_SHA_BEFORE" ] && [ "$SELF_SHA_BEFORE" != "$SELF_SHA_AFTER" ]; then
        if [ "${DEPLOY_REEXEC:-0}" -eq 1 ]; then
            echo "  ⚠ deploy.sh mudou de novo após a re-execução — seguindo assim mesmo."
        else
            echo ""
            echo "  ⚠ O próprio deploy.sh foi atualizado por este pull."
            echo "    Re-executando a versão nova para não pular etapas..."
            echo ""
            export DEPLOY_REEXEC=1
            exec "$0" "$@"
        fi
    fi
fi

# ─── 3a. Verificar/Criar .env ────────────────────────────────
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        echo ""
        echo "  ⚠ ATENÇÃO: .env criado a partir de .env.example"
        echo "  Edite as credenciais ANTES de continuar:"
        echo "    nano $APP_DIR/.env"
        echo "  Depois execute: ./scripts/deploy.sh --force --skip-backup"
        exit 0
    fi
fi

# Verificar SYSTEM_VERSION
if [ -f .env ] && [ -f .env.example ]; then
    REPO_VERSION=$(grep 'SYSTEM_VERSION=' .env.example | cut -d= -f2)
    LOCAL_VERSION=$(grep 'SYSTEM_VERSION=' .env | cut -d= -f2 || echo "")
    if [ "$LOCAL_VERSION" != "$REPO_VERSION" ] && [ -n "$LOCAL_VERSION" ]; then
        echo "  ℹ Versão do sistema no .env: $LOCAL_VERSION → $REPO_VERSION (repositório)"
        echo "    Atualizando SYSTEM_VERSION no .env..."
        sed -i "s/SYSTEM_VERSION=.*/SYSTEM_VERSION=$REPO_VERSION/" .env

        # 🔴 `sed -i` NÃO edita no lugar: escreve um arquivo novo e o renomeia
        # por cima. O novo nasce com o grupo PRIMÁRIO de quem roda o deploy, e
        # como o .env é 640, o www-data perde a leitura na hora. A aplicação
        # passa a rodar com os DEFAULTS DO CÓDIGO — inclusive a senha de banco
        # do homolog, que em produção não abre.
        #
        # Sintoma: `/ping` responde `"version":"desconhecida"` e os webhooks
        # devolvem 500, sem NADA no log da aplicação (ela não chega a conectar).
        # Aconteceu duas vezes em 13–14/08/2026.
        #
        # O setgid do diretório (abaixo, FASE 3c) resolve o caso geral, mas
        # aqui a restauração é explícita de propósito: a primeira ocorrência
        # foi mitigada só com setgid, e o `chmod 755` desta mesma FASE 3c o
        # apagou no deploy seguinte — a mitigação sozinha se desarmou.
        chgrp www-data .env 2>/dev/null || true
        chmod 640 .env 2>/dev/null || true
        echo "    ✓ .env: $(stat -c '%U:%G %a' .env 2>/dev/null || echo '?')"
    fi
fi

# ─── 3b. Migração do banco de dados ──────────────────────────
#
# Gate por comparação SEMÂNTICA de versão (`sort -V`): uma migração só roda
# quando a versão do banco é MENOR que a dela.
#
# O gate anterior comparava com `!=` bloco a bloco, então um banco em 4.4.1
# satisfazia "!= 4.2.1" e a cadeia inteira era reaplicada a cada publicação:
# 4.4.1 → aplica v4.2.1 → banco vira 4.2.1 → aplica v4.3.0 → 4.3.0 → … De
# fato só não quebrava porque as migrações são idempotentes; em troca, as
# mensagens "versão atual" mentiam, o banco era temporariamente rebaixado no
# meio do deploy e o custo crescia a cada release nova.

# Verdadeiro se $1 < $2 na ordem semântica de versões.
version_lt() {
    [ "$1" = "$2" ] && return 1
    [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" = "$1" ]
}

# Versão registrada em system_info; "0" quando a tabela ainda não existe.
db_version() {
    local v
    v=$(MYSQL_PWD="${DB_PASS:-}" mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" \
        -u"${DB_USER:-root}" -N -e \
        "SELECT COALESCE(version,'0') FROM ${DB_NAME:-jimi_tracker}.system_info WHERE id=1 LIMIT 1" \
        2>/dev/null) || v=""
    echo "${v:-0}"
}

# run_migration <versão-alvo> <arquivo> <descrição>
run_migration() {
    local target="$1" file="$2" desc="$3" current errlog
    [ -f "$file" ] || return 0

    current=$(db_version)
    if ! version_lt "$current" "$target"; then
        echo "  ✓ Banco em $current — migração v$target desnecessária"
        return 0
    fi

    echo "  Aplicando $(basename "$file") ($desc; banco em $current)..."
    errlog="/tmp/migrate_err_v$(echo "$target" | tr -d '.').log"
    if MYSQL_PWD="${DB_PASS:-}" mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" \
        -u"${DB_USER:-root}" "${DB_NAME:-jimi_tracker}" < "$file" 2>"$errlog"; then
        echo "  ✓ Migração v$target aplicada — banco agora em $(db_version)"
    else
        echo "  ⚠ AVISO: erro na migração v$target. Veja $errlog"
        cat "$errlog" 2>/dev/null || true
    fi
    return 0
}

if [ "$SKIP_MIGRATE" -eq 0 ] && [ -f .env ]; then
    echo "  Verificando migrações pendentes..."
    source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS)=' .env | sed 's/^/export /')

    echo "  Versão atual do banco: $(db_version)"

    # Ordem cronológica — o gate decide sozinho quais estão pendentes.
    run_migration "2.0.0" "mysql/migration_v2.0.0.sql" "base v2"
    run_migration "3.1.0" "mysql/migration_v3.1.0.sql" "multi-tenant"
    run_migration "4.0.0" "mysql/migration_v4.0.0.sql" "YUV Parity"
    run_migration "4.1.0" "mysql/migration_v4.1.0.sql" "Excel/PDF + fix seed DMS"
    run_migration "4.2.1" "mysql/migration_v4.2.1.sql" "câmeras por modelo"
    run_migration "4.3.0" "mysql/migration_v4.3.0.sql" "índice de período em trips"
    run_migration "4.4.0" "mysql/migration_v4.4.0.sql" "motor de notificações"
    run_migration "4.4.1" "mysql/migration_v4.4.1.sql" "credenciais SMTP"
    run_migration "4.5.0" "mysql/migration_v4.5.0.sql" "geocercas"
    run_migration "4.6.0" "mysql/migration_v4.6.0.sql" "relatórios operacionais"
    run_migration "4.7.0" "mysql/migration_v4.7.0.sql" "relatórios agendados + modelos"
    run_migration "4.8.0" "mysql/migration_v4.8.0.sql" "motorista na posição (gps_data)"
    run_migration "4.8.1" "mysql/migration_v4.8.1.sql" "alarm_types só com alarmes oficiais"
    run_migration "4.8.3" "mysql/migration_v4.8.3.sql" "nomes DMS/ADAS conforme a doc oficial"
    run_migration "4.8.4" "mysql/migration_v4.8.4.sql" "decisão sobre os códigos JIMI ambíguos"
    run_migration "4.8.5" "mysql/migration_v4.8.5.sql" "Ajuda liberada a grupo restrito"
    run_migration "4.8.6" "mysql/migration_v4.8.6.sql" "religa o motor de ocorrências (nomes da v4.8.3)"
    run_migration "4.8.7" "mysql/migration_v4.8.7.sql" "decisões de produto no motor de ocorrências"
    run_migration "4.8.9" "mysql/migration_v4.8.9.sql" "rastreabilidade de comandos (request_id/server_flag_id)"
    run_migration "4.9.0" "mysql/migration_v4.9.0.sql" "alertTypes JT/T que faltavam no catálogo"
    run_migration "4.9.4" "mysql/migration_v4.9.4.sql" "remetente de e-mail: nome antigo → bycamera"
    run_migration "4.9.5" "mysql/migration_v4.9.5.sql" "categoria unificada em pt-BR + remap das regras"
    run_migration "4.9.8" "mysql/migration_v4.9.8.sql" "anexo do alarme vira media_files (vídeo da ocorrência)"
    run_migration "4.9.9" "mysql/migration_v4.9.9.sql" "evento de diagnóstico separado de alarme"
    run_migration "4.9.10" "mysql/migration_v4.9.10.sql" "capotamento (JT/T 1047) + condução brusca JIMI 144/145/146"
    run_migration "4.9.11" "mysql/migration_v4.9.11.sql" "destrunca command_content + /config na matriz de permissão"
    run_migration "4.9.12" "mysql/migration_v4.9.12.sql" "parametros das cameras JT/T (catalogo + 3 tabelas)"
    run_migration "4.9.14" "mysql/migration_v4.9.14.sql" "perfis de parametros por modelo + escrita 33027"
    run_migration "4.9.15" "mysql/migration_v4.9.15.sql" "16 parametros nomeados pela norma JT/T 808"
    run_migration "4.9.17" "mysql/migration_v4.9.17.sql" "validade de 30 min na lista de gravacoes"
    run_migration "4.9.24" "mysql/migration_v4.9.24.sql" "catalogo completo do JT/T 808 (86 IDs) + tipo composite"
    run_migration "4.9.25" "mysql/migration_v4.9.25.sql" "APN (16/17/18) sai da escrita: valor do 33028 e falso"
    run_migration "4.9.31" "mysql/migration_v4.9.31.sql" "reenvio de video de alarme religa ao alarme certo"
    run_migration "4.9.32" "mysql/migration_v4.9.32.sql" "firmware lido do device + cadastro de URLs de atualizacao"
    run_migration "4.9.37" "mysql/migration_v4.9.37.sql" "download vira estado: pendente / pronto / ja baixado"
    run_migration "4.10.0" "mysql/migration_v4.10.0.sql" "devices.vehicle_type p/ icone do mapa (Tabler Icons)"
    run_migration "4.10.1" "mysql/migration_v4.10.1.sql" "manutencao preventiva + lembrete de documento do motorista"
    run_migration "4.10.3" "mysql/migration_v4.10.3.sql" "dashboard_layouts p/ painel widgetizado (/painel)"
fi

# ─── 3c. Permissões ──────────────────────────────────────────
echo "  Configurando permissões..."

# Diretórios PHP — leitura
#
# ⚠️ 2755, NÃO 755: o `2` é o setgid, e é ele que faz todo arquivo novo criado
# aqui herdar o grupo do diretório (www-data) em vez do grupo primário de quem
# escreveu. Sem isso, qualquer `sed -i` no .env — inclusive o desta mesma FASE
# 3a — deixa o arquivo ilegível para o Apache e derruba o site em silêncio.
#
# Foi exatamente o que aconteceu em 14/08/2026: o setgid tinha sido posto à mão
# na véspera para conter o problema, e este `chmod 755` o removeu, reabrindo o
# defeito no deploy seguinte. Mitigação que o próprio deploy desarma não é
# mitigação.
chmod 2755 "$APP_DIR"
find config core handlers includes web -type d -exec chmod 755 {} \; 2>/dev/null || true
find config core handlers includes web -type f -exec chmod 644 {} \; 2>/dev/null || true

# .htaccess
[ -f .htaccess ] && chmod 644 .htaccess

# Logs — escrita pelo Apache/PHP
if [ ! -d logs ]; then
    mkdir -p logs
fi
# 2777 e não 777: o setgid faz o arquivo de log criado pelo CRON nascer no
# grupo www-data, e não no grupo primário do usuário do deploy. Sem ele, o
# Apache só consegue escrever porque o Logger usa 0666 — cinto e suspensório,
# porque este par (cron × www-data no mesmo arquivo) já falhou uma vez.
chmod 2777 logs
# Storage — reports e media
if [ ! -d storage/reports ]; then
    mkdir -p storage/reports
fi
if [ ! -d storage/media ]; then
    mkdir -p storage/media
fi
# 2777 pelo mesmo motivo de logs/: relatório gerado pelo cron precisa ficar
# legível para o Apache servi-lo em /download.
chmod 2777 storage storage/reports storage/media 2>/dev/null || true
# Manter logs existentes com permissão de escrita
find logs -type f -exec chmod 666 {} \; 2>/dev/null || true
echo "  ✓ Permissões configuradas"

# ════════════════════════════════════════════════════════════
# FASE 4: VERIFY — Testes pós-deploy
# ════════════════════════════════════════════════════════════
echo ""
echo "=== FASE 4/5: VERIFY — Testando ==="

# Sintaxe PHP em todos os arquivos.
# `scripts` entrou na varredura na v4.5.0: os workers de cron (worker.php,
# trip_builder.php, geofence_worker.php…) ficam lá e estavam FORA do lint do
# deploy — um erro de sintaxe num worker passaria batido e só apareceria na
# primeira execução do cron, em silêncio, dentro de logs/<worker>.log.
echo "  Verificando sintaxe PHP..."
ERRORS=0
for f in $(find handlers config core includes scripts -name "*.php" -type f); do
    if ! php -l "$f" >/dev/null 2>&1; then
        echo "  ✗ Erro sintaxe: $f"
        php -l "$f" 2>&1 | head -2
        ERRORS=$((ERRORS + 1))
    fi
done
[ "$ERRORS" -eq 0 ] && echo "  ✓ Todos os arquivos PHP com sintaxe OK" \
    || echo "  ⚠ $ERRORS arquivo(s) com erro de sintaxe"

# Teste /ping
echo "  Testando /ping..."
PING_URL="http://localhost/ping"
if command -v curl >/dev/null 2>&1; then
    HTTP_CODE=$(curl -s -o /tmp/ping_resp.txt -w "%{http_code}" "$PING_URL" --connect-timeout 5 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        PING_BODY=$(cat /tmp/ping_resp.txt)
        echo "  ✓ /ping HTTP 200: $PING_BODY"
    else
        echo "  ⚠ /ping HTTP $HTTP_CODE — verifique se o Apache está servindo $APP_DIR"
    fi
    rm -f /tmp/ping_resp.txt
fi

# Logs graváveis
if [ -d logs ] && [ -w logs ]; then
    # Teste de escrita
    echo "test" > logs/.deploy_test 2>/dev/null && rm -f logs/.deploy_test \
        && echo "  ✓ logs/ gravável" || echo "  ⚠ logs/ não está gravável"
fi

# ════════════════════════════════════════════════════════════
# FASE 5: CONFIRM — Resumo final
# ════════════════════════════════════════════════════════════
echo ""
echo "============================================================"
echo "  DEPLOY CONCLUÍDO — $(date)"
echo "============================================================"
echo "  Projeto:    $APP_DIR"
echo "  Commit:     $(git rev-parse --short HEAD)"
echo "  Branch:     $(git rev-parse --abbrev-ref HEAD)"
echo "  PHP:        $PHP_VER"
echo "  Backup:     $BACKUP_DIR/${TIMESTAMP}_*"
echo ""
echo "  Próximos passos:"
echo "  1. Monitore por ~15 minutos: tail -f logs/webhook_$(date +%Y-%m-%d).log"
echo "  2. Acesse o painel: http://<ip>/dashboard"
echo "  3. Em caso de problema: ./scripts/rollback.sh $TIMESTAMP"
echo "============================================================"
