# Rastreadores fora das telas de câmera + Alertas Dirigibilidade — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** separar os eventos de condução (câmera ou rastreador) numa tela "Alertas Dirigibilidade" sem vídeo, renomear a atual para "Alertas Videomonitoramento", tirar o vídeo das ocorrências de condução e tirar os rastreadores das telas exclusivas de câmera.

**Architecture:** classificação por código numa coluna nova `alarm_types.is_driving` (migração v4.21.0), lida por quatro helpers puros em `includes/functions.php` com guarda para a janela entre os dois deploys. `rel_alarmes.php` passa a servir as duas telas por um modo (`$ALARM_REPORT_MODE`), com `rel_dirigibilidade.php` como handler fino. Ocorrências, pedido de vídeo, backfill, Downloads, Mapa de Risco e agendamentos consomem os mesmos helpers.

**Tech Stack:** PHP 8.3 puro, MySQL 8, Playwright (só `tests/`), testes-helper em PHP CLI sem banco.

**Spec:** `docs/superpowers/specs/2026-09-14-rastreadores-dirigibilidade-design.md`

## Global Constraints

- Versão: **4.21.0**. Migração `mysql/migration_v4.21.0.sql`, listada em `scripts/deploy.sh`.
- Rótulos exatos: **"Alertas Videomonitoramento"** (rota `/relatorios/alarmes`, mantida) e **"Alertas Dirigibilidade"** (rota `/relatorios/dirigibilidade`).
- Classificação **por protocolo + código**, nunca por nome nem categoria.
- "Sem câmera" = `COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) = 0` (regra da v4.16.0).
- Sem MySQL local: verificação por `php -l`, `tests/helpers/*.test.php` e specs Playwright (que pulam sem `TEST_EMAIL`/`TEST_PASSWORD`).
- Comentários em PT-BR, PHPDoc com `@param`/`@returns`. Keep a Changelog.
- Commit ao fim de cada tarefa; push ao fim do plano.

## Mapa de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `mysql/migration_v4.21.0.sql` (novo) | coluna `is_driving` + marcação por código + conferências |
| `scripts/deploy.sh` | `run_migration "4.21.0"` |
| `includes/functions.php` | `alarm_types_has_driving_flag()`, `alarm_driving_expr()`, `device_has_camera_sql()`, `occurrence_no_video_sql()`, `is_driving_alarm()` |
| `handlers/rel_alarmes.php` | as duas telas, por modo |
| `handlers/rel_dirigibilidade.php` (novo) | handler fino do modo `driving` |
| `handlers/router.php`, `web/layout_base.php` | rota, permissão, menu |
| `handlers/ocorrenciasdata.php`, `handlers/ocorrencias_dashboard.php` | vídeo inibido em ocorrência de condução/sem câmera |
| `includes/alarm_video_request.php`, `includes/occurrence_engine.php`, `scripts/video_upload_backfill.php` | nenhum pedido de vídeo para condução/sem câmera |
| `handlers/video_downloads.php`, `handlers/mapa_risco.php`, `scripts/risk_builder.php` | rastreador fora das telas de câmera e da exposição |
| `includes/schedule.php`, `scripts/worker.php`, `handlers/exportar.php` | agendamento/export nos dois recortes |
| `tests/helpers/driving_alarms.test.php` (novo), `tests/dirigibilidade.spec.js` (novo), `tests/navigation.spec.js` | verificação |
| `CHANGELOG.md`, `STATUS.md`, `CLAUDE.md`, `handlers/wiki.php`, `.env.example` | documentação |

---

### Task 1: Migração `is_driving` + teste de classificação

**Files:**
- Create: `mysql/migration_v4.21.0.sql`
- Modify: `scripts/deploy.sh` (após a linha `run_migration "4.20.0" …`)
- Test: `tests/helpers/driving_alarms.test.php` (novo)

**Interfaces:**
- Produces: coluna `alarm_types.is_driving TINYINT(1) NOT NULL DEFAULT 0`; o arquivo de teste com `confere()` e o marcador `// ── novas seções entram acima desta linha ──`, onde as tarefas seguintes acrescentam seções.

- [ ] **Step 1: Escrever o teste que falha** — `tests/helpers/driving_alarms.test.php`:

```php
<?php
/**
 * Dirigibilidade (v4.21.0) — guarda da classificação e da fiação das telas.
 *
 * Não precisa de banco: lê a migração e os fontes. O que protege é a LISTA
 * aprovada pelo dono do produto em 14/09/2026 (spec
 * docs/superpowers/specs/2026-09-14-rastreadores-dirigibilidade-design.md §3)
 * e as bordas — nenhum ADAS/DMS de IA pode virar "condução" e perder o vídeo.
 *
 * Uso: php tests/helpers/driving_alarms.test.php
 */

$raiz   = dirname(__DIR__, 2);
$falhas = 0;

function confere(bool $cond, string $desc): void
{
    global $falhas;
    if ($cond) {
        echo "  OK   $desc\n";
    } else {
        $falhas++;
        echo "  FALHA $desc\n";
    }
}

// ── 1) Migração: a lista marcada é exatamente a aprovada ────────────────────
$esperado = [
    'JIMI|144', 'JTT|1024', 'JTT|1042',                              // arrancada
    'JIMI|48', 'JIMI|145', 'JTT|1025', 'JTT|1043',                   // freada
    'JIMI|76', 'JIMI|146', 'JTT|1026', 'JTT|1044',                   // curva
    'JIMI|6', 'JIMI|135', 'JIMI|202', 'JIMI|95', 'JTT|1027',         // velocidade
    'JIMI|44', 'JIMI|147', 'JTT|1029', 'JTT|1046',                   // colisão
    'JIMI|45', 'JIMI|106', 'JIMI|183', 'JTT|1047',                   // capotamento
    'JIMI|55', 'JIMI|75', 'JIMI|78', 'JIMI|79',                      // impacto/inclinação
];
$proibido = [
    'JIMI|77', 'JIMI|116',                                            // fora por decisão
    'JIMI|143', 'JIMI|151', 'JIMI|204', 'JIMI|206', 'JIMI|207', 'JIMI|229', // DMS/ADAS JIMI
    'JTT|264-1', 'JTT|264-3', 'JTT|264-4', 'JTT|265-2',              // DMS/ADAS JT/T
];

$arqMig = $raiz . '/mysql/migration_v4.21.0.sql';
$sql    = is_file($arqMig) ? file_get_contents($arqMig) : '';
confere($sql !== '', 'mysql/migration_v4.21.0.sql existe');

$marcados = [];
if (preg_match('/INSERT INTO tmp_driving[^;]*;/s', $sql, $bloco)) {
    preg_match_all("/\('(JIMI|JTT)',\s*'([0-9-]+)'\)/", $bloco[0], $m, PREG_SET_ORDER);
    foreach ($m as $par) {
        $marcados[] = $par[1] . '|' . $par[2];
    }
}
sort($marcados);
$ordenado = $esperado;
sort($ordenado);
confere($marcados === $ordenado, 'lista marcada = lista aprovada (' . count($esperado) . ' códigos)'
    . ($marcados === $ordenado ? '' : ' — sobra: ' . implode(',', array_diff($marcados, $esperado))
        . ' falta: ' . implode(',', array_diff($esperado, $marcados))));
confere(!array_intersect($marcados, $proibido), 'nenhum código proibido (ADAS/DMS, 77, 116) marcado');
confere(str_contains($sql, "add_column_if_not_exists('alarm_types', 'is_driving'"), 'coluna criada de forma idempotente');
confere(str_contains($sql, 'COLLATE utf8mb4_unicode_ci'), 'tabela temporária com a collation de alarm_types');

$deploy = (string)file_get_contents($raiz . '/scripts/deploy.sh');
confere(str_contains($deploy, 'run_migration "4.21.0" "mysql/migration_v4.21.0.sql"'), 'deploy.sh aplica a v4.21.0');

// ── novas seções entram acima desta linha ──

printf("\n%s\n", $falhas === 0 ? 'TUDO OK' : "FALHOU ({$falhas})");
exit($falhas === 0 ? 0 : 1);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/helpers/driving_alarms.test.php`
Expected: `FALHA mysql/migration_v4.21.0.sql existe` … `FALHOU (…)`, exit 1.

- [ ] **Step 3: Criar `mysql/migration_v4.21.0.sql`**

```sql
-- ============================================================================
-- Migração v4.21.0 — Alertas de Dirigibilidade (alarm_types.is_driving)
-- ============================================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- Decisão do dono do produto (14/09/2026): os eventos de condução — arrancada,
-- freada, curva, excesso de velocidade, colisão, capotamento, impacto e
-- inclinação — saem de "Alertas Videomonitoramento" e ganham a tela
-- "Alertas Dirigibilidade", venham de câmera ou de rastreador (JM-VL). Nessas
-- linhas não há função de vídeo. Spec:
-- docs/superpowers/specs/2026-09-14-rastreadores-dirigibilidade-design.md
--
-- 🔴 Por CÓDIGO, nunca por nome nem por categoria: `conducao` também guarda
-- Ociosidade, Motorista Alterado e Condução Prolongada, e a colisão mora em
-- `veiculo`/`acidente`. Renomear e recategorizar já desligou dois motores em
-- silêncio (CLAUDE.md). Fora de propósito: 77 (Mudança Abrupta de Faixa),
-- 116 (Velocidade Normalizada) e todo ADAS/DMS de IA (FCW, PCW…).
--
-- Código novo de condução cadastrado depois desta versão tem de receber
-- `is_driving = 1` na MESMA migração — senão cai em Videomonitoramento.

SET time_zone = '+00:00';

DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;
DELIMITER //
CREATE PROCEDURE `add_column_if_not_exists`(IN p_table VARCHAR(128), IN p_column VARCHAR(128), IN p_definition TEXT)
BEGIN
    DECLARE col_count INT;
    SELECT COUNT(*) INTO col_count FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column;
    IF col_count = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

CALL add_column_if_not_exists('alarm_types', 'is_driving',
    "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Evento de dirigibilidade: tela Alertas Dirigibilidade, sem vídeo (v4.21.0)' AFTER `is_diagnostic`");

DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;

-- Lista única: marca E confere. Collation explícita porque o padrão do banco
-- (MySQL 8) é utf8mb4_0900_ai_ci e o JOIN com alarm_types recusaria a mistura.
DROP TEMPORARY TABLE IF EXISTS tmp_driving;
CREATE TEMPORARY TABLE tmp_driving (
    protocol   VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    alarm_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (protocol, alarm_code)
);
INSERT INTO tmp_driving (protocol, alarm_code) VALUES
    -- Arrancada / aceleração brusca
    ('JIMI','144'), ('JTT','1024'), ('JTT','1042'),
    -- Freada / frenagem brusca
    ('JIMI','48'), ('JIMI','145'), ('JTT','1025'), ('JTT','1043'),
    -- Curva acentuada
    ('JIMI','76'), ('JIMI','146'), ('JTT','1026'), ('JTT','1044'),
    -- Excesso de velocidade (202 = Aviso de Velocidade, 95 = em Cerca)
    ('JIMI','6'), ('JIMI','135'), ('JIMI','202'), ('JIMI','95'), ('JTT','1027'),
    -- Colisão
    ('JIMI','44'), ('JIMI','147'), ('JTT','1029'), ('JTT','1046'),
    -- Capotamento
    ('JIMI','45'), ('JIMI','106'), ('JIMI','183'), ('JTT','1047'),
    -- Impacto / inclinação
    ('JIMI','55'), ('JIMI','75'), ('JIMI','78'), ('JIMI','79');

UPDATE alarm_types a
  JOIN tmp_driving t ON t.protocol = a.protocol AND t.alarm_code = a.alarm_code
   SET a.is_driving = 1;

SELECT 'is_driving: código esperado AUSENTE do catálogo (deve vir vazio)' AS conferencia;
SELECT t.protocol, t.alarm_code
  FROM tmp_driving t
  LEFT JOIN alarm_types a ON a.protocol = t.protocol AND a.alarm_code = t.alarm_code
 WHERE a.id IS NULL;

SELECT 'is_driving: MESMO NOME de um marcado e sem marca (conferir um a um)' AS conferencia;
SELECT s.protocol, s.alarm_code, s.alarm_name_pt
  FROM alarm_types s
 WHERE s.is_driving = 0
   AND s.alarm_name_pt IN (SELECT m.alarm_name_pt FROM alarm_types m WHERE m.is_driving = 1);

SELECT 'is_driving: ADAS/DMS marcado (deve vir vazio)' AS conferencia;
SELECT protocol, alarm_code, alarm_name_pt FROM alarm_types
 WHERE is_driving = 1 AND category IN ('DMS','ADAS');

SELECT 'is_driving: marcados' AS conferencia;
SELECT protocol, alarm_code, category, alarm_name_pt FROM alarm_types
 WHERE is_driving = 1 ORDER BY alarm_name_pt, protocol, alarm_code;

DROP TEMPORARY TABLE IF EXISTS tmp_driving;
```

- [ ] **Step 4: Listar no deploy** — em `scripts/deploy.sh`, logo após a linha do 4.20.0:

```bash
    run_migration "4.21.0" "mysql/migration_v4.21.0.sql" "alarm_types.is_driving - Alertas Dirigibilidade (condução sem vídeo, câmera e rastreador)"
```

- [ ] **Step 5: Rodar e ver passar**

Run: `php tests/helpers/driving_alarms.test.php && php tests/helpers/migracoes_no_deploy.test.php`
Expected: `TUDO OK` nos dois.

- [ ] **Step 6: Commit**

```bash
git add mysql/migration_v4.21.0.sql scripts/deploy.sh tests/helpers/driving_alarms.test.php
git commit -m "feat(db): alarm_types.is_driving por codigo (v4.21.0)"
```

---

### Task 2: Helpers de classificação em `includes/functions.php`

**Files:**
- Modify: `includes/functions.php` — logo depois do fechamento de `alarm_label_sql()` (antes do docblock de `alarm_category_label()`)
- Test: `tests/helpers/driving_alarms.test.php` (seção nova)

**Interfaces:**
- Consumes: `alarm_label_sql()` (joins com aliases `a`, `atc`, `atb`).
- Produces:
  - `alarm_types_has_driving_flag(PDO $db): bool`
  - `alarm_driving_expr(bool $temColuna): string` → `'COALESCE(atc.is_driving, atb.is_driving, 0)'` ou `'0'`
  - `device_has_camera_sql(string $d = 'd', string $dm = 'dm'): string` → `"COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) > 0"`
  - `occurrence_no_video_sql(bool $temColuna, string $o = 'o'): string` (expressão 1/0)
  - `is_driving_alarm(PDO $db, string $alarmType, ?string $compositeCode, int $msgClass): bool`

- [ ] **Step 1: Teste que falha** — inserir acima do marcador:

```php
// ── 2) Helpers (includes/functions.php) ─────────────────────────────────────
require_once $raiz . '/includes/functions.php';
foreach (['alarm_types_has_driving_flag', 'alarm_driving_expr', 'device_has_camera_sql',
          'occurrence_no_video_sql', 'is_driving_alarm'] as $fn) {
    confere(function_exists($fn), "$fn() existe");
}
if (function_exists('alarm_driving_expr')) {
    confere(alarm_driving_expr(true) === 'COALESCE(atc.is_driving, atb.is_driving, 0)', 'expr com coluna usa os joins do rótulo');
    confere(alarm_driving_expr(false) === '0', 'expr sem coluna (janela da migração) = 0');
    confere(device_has_camera_sql('d', 'dm') === 'COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) > 0', 'regra de câmera = v4.16.0');
    $semCol = occurrence_no_video_sql(false);
    confere(str_contains($semCol, 'nvd.imei = o.imei') && !str_contains($semCol, 'is_driving'), 'sem coluna: só "sem câmera"');
    $comCol = occurrence_no_video_sql(true, 'oc');
    confere(str_contains($comCol, 'nve.occurrence_id = oc.id') && str_contains($comCol, 'LEFT JOIN alarm_types atc')
        && str_contains($comCol, 'COALESCE(atc.is_driving, atb.is_driving, 0) = 1'), 'com coluna: condução por código dos alarmes agrupados');
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/helpers/driving_alarms.test.php`
Expected: `FALHA alarm_types_has_driving_flag() existe` (e demais), exit 1.

- [ ] **Step 3: Implementar** — inserir após o `}` que fecha `alarm_label_sql()`:

```php
/**
 * A coluna `alarm_types.is_driving` já existe neste banco? (v4.21.0)
 *
 * 🔴 Migração nova NÃO roda no deploy que a traz (CLAUDE.md). No intervalo,
 * uma consulta que citasse a coluna derrubaria /relatorios/alarmes com
 * SQLSTATE[42S22]. Com esta guarda as telas se comportam como antes da
 * v4.21.0 e a de Dirigibilidade avisa que falta a migração.
 *
 * Só o `true` fica em cache: o worker é processo longo, e um `false` guardado
 * sobreviveria à migração aplicada logo depois.
 *
 * @param PDO $db Conexão
 * @returns bool
 */
function alarm_types_has_driving_flag(PDO $db): bool
{
    static $tem = false;
    if ($tem) {
        return true;
    }
    try {
        $st = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE table_schema = DATABASE() AND table_name = 'alarm_types'
                             AND column_name = 'is_driving'");
        $tem = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        $tem = false;
    }
    return $tem;
}

/**
 * Expressão SQL "este alarme é de dirigibilidade" (1/0), sobre os joins de
 * alarm_label_sql() — composto antes da base, na mesma ordem do rótulo.
 *
 * A linha "Fim de Alarme" herda o código base e, com ele, a marca: sai da
 * tela de vídeo e aparece na de dirigibilidade junto com a abertura.
 *
 * @param bool $temColuna Saída de alarm_types_has_driving_flag()
 * @returns string        Expressão para usar como `($expr) = 1`
 */
function alarm_driving_expr(bool $temColuna): string
{
    return $temColuna ? 'COALESCE(atc.is_driving, atb.is_driving, 0)' : '0';
}

/**
 * Expressão SQL "o equipamento tem câmera" — a regra das telas de vídeo da
 * v4.16.0: `camera_count = 0` é rastreador (linha JM-VL). Sem contagem nem
 * modelo, o `1` do fim mantém o equipamento como câmera (comportamento
 * anterior); alarme de IMEI ausente de `devices` (LEFT JOIN vazio) também.
 *
 * @param string $d  Alias de `devices`
 * @param string $dm Alias de `device_models` (LEFT JOIN por d.device_model_id)
 * @returns string
 */
function device_has_camera_sql(string $d = 'd', string $dm = 'dm'): string
{
    return "COALESCE(NULLIF($d.camera_count, 0), $dm.camera_count, 1) > 0";
}

/**
 * Expressão SQL "esta ocorrência não tem função de vídeo" (1/0).
 *
 * Verdadeira quando QUALQUER alarme agrupado é de dirigibilidade — por
 * código: `occurrences.alarm_type` guarda o NOME, e classificar por nome é a
 * armadilha do CLAUDE.md — ou quando o equipamento não tem câmera.
 *
 * @param bool   $temColuna Saída de alarm_types_has_driving_flag()
 * @param string $o         Alias de `occurrences` na consulta externa
 * @returns string
 */
function occurrence_no_video_sql(bool $temColuna, string $o = 'o'): string
{
    $semCamera = "EXISTS (SELECT 1 FROM devices nvd
                            LEFT JOIN device_models nvm ON nvm.id = nvd.device_model_id
                           WHERE nvd.imei = $o.imei AND NOT (" . device_has_camera_sql('nvd', 'nvm') . "))";
    if (!$temColuna) {
        return $semCamera;
    }
    ['joins' => $joins] = alarm_label_sql();
    return "($semCamera OR EXISTS (SELECT 1 FROM occurrence_events nve
                                     JOIN alarms a ON a.id = nve.alarm_id
                                     $joins
                                    WHERE nve.occurrence_id = $o.id
                                      AND " . alarm_driving_expr(true) . " = 1))";
}

/**
 * O alarme é de dirigibilidade? Espelho de is_diagnostic_alarm(), para o PHP
 * que decide sobre UM alarme (pedido de vídeo, motor de ocorrências).
 *
 * Falha para o lado de "não é": sem a coluna (janela da migração) ou com erro
 * de banco, o comportamento é o anterior à v4.21.0.
 *
 * @param PDO         $db            Conexão
 * @param string      $alarmType     Código base (`alarms.alarm_type`)
 * @param string|null $compositeCode Código composto quando há subtipo
 * @param int         $msgClass      0 = JIMI, 1 = JT/T 808 (ADR-001)
 * @returns bool
 */
function is_driving_alarm(PDO $db, string $alarmType, ?string $compositeCode, int $msgClass): bool
{
    if (!alarm_types_has_driving_flag($db)) {
        return false;
    }
    $protocol = $msgClass === 1 ? 'JTT' : 'JIMI';
    try {
        $stmt = $db->prepare("SELECT is_driving FROM alarm_types WHERE protocol = :p AND alarm_code = :code LIMIT 1");
        if ($compositeCode !== null && $compositeCode !== '') {
            $stmt->execute([':p' => $protocol, ':code' => $compositeCode]);
            $achado = $stmt->fetchColumn();
            if ($achado !== false) {
                return (bool)$achado;
            }
        }
        $stmt->execute([':p' => $protocol, ':code' => $alarmType]);
        $achado = $stmt->fetchColumn();
        return $achado === false ? false : (bool)$achado;
    } catch (Throwable $e) {
        return false;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php -l includes/functions.php && php tests/helpers/driving_alarms.test.php`
Expected: `No syntax errors` e `TUDO OK`.

- [ ] **Step 5: Commit**

```bash
git add includes/functions.php tests/helpers/driving_alarms.test.php
git commit -m "feat: helpers de dirigibilidade e de equipamento sem camera (v4.21.0)"
```

---

### Task 3: Telas "Alertas Videomonitoramento" e "Alertas Dirigibilidade" + rota + menu

**Files:**
- Create: `handlers/rel_dirigibilidade.php`
- Modify: `handlers/rel_alarmes.php`, `handlers/router.php`, `web/layout_base.php`, `tests/navigation.spec.js`
- Test: `tests/helpers/driving_alarms.test.php` (seção), `tests/dirigibilidade.spec.js` (novo)

**Interfaces:**
- Consumes: `alarm_types_has_driving_flag()`, `alarm_driving_expr()`, `device_has_camera_sql()` (Task 2).
- Produces: variável de modo `$ALARM_REPORT_MODE` (`'driving'` | ausente = vídeo); rota `/relatorios/dirigibilidade`; chave de modelos `rel_dirigibilidade`.

- [ ] **Step 1: Testes que falham** — seção acima do marcador:

```php
// ── 3) Telas, rota e menu ───────────────────────────────────────────────────
$router = (string)file_get_contents($raiz . '/handlers/router.php');
confere((bool)preg_match("/'dirigibilidade'\s*=>\s*'rel_dirigibilidade\.php'/", $router), 'router: /relatorios/dirigibilidade');
confere((bool)preg_match("/'rel_dirigibilidade\.php'\s*=>\s*'relatorios'/", $router), 'router: permissão relatorios');
$layout = (string)file_get_contents($raiz . '/web/layout_base.php');
confere(str_contains($layout, "'label' => 'Alertas Videomonitoramento'"), 'menu: rótulo Alertas Videomonitoramento');
confere((bool)preg_match("/'route' => 'rel_dirigibilidade',\s*'label' => 'Alertas Dirigibilidade',\s*'href' => '\/relatorios\/dirigibilidade'/", $layout), 'menu: Alertas Dirigibilidade');
$dirig = is_file($raiz . '/handlers/rel_dirigibilidade.php') ? file_get_contents($raiz . '/handlers/rel_dirigibilidade.php') : '';
confere(str_contains($dirig, "\$ALARM_REPORT_MODE = 'driving';") && str_contains($dirig, "require __DIR__ . '/rel_alarmes.php';"), 'rel_dirigibilidade.php só define o modo');
$relAl = (string)file_get_contents($raiz . '/handlers/rel_alarmes.php');
confere(str_contains($relAl, "<?php if (!\$modoDirig): ?><th>Vídeo</th><?php endif; ?>"), 'coluna Vídeo só no modo vídeo');
confere(str_contains($relAl, "<?php if (!\$modoDirig): // vídeo só existe em Videomonitoramento ?>"), 'modal/JS de vídeo só no modo vídeo');
```

`tests/dirigibilidade.spec.js`:

```js
// @ts-check
/**
 * Alertas Videomonitoramento × Alertas Dirigibilidade (v4.21.0).
 *
 * A tela de dirigibilidade reúne os eventos de condução de câmera E de
 * rastreador, e por decisão do dono do produto não tem NENHUMA função de
 * vídeo. A de videomonitoramento mantém a coluna Vídeo.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

test.describe('Relatórios de alarmes divididos (v4.21.0)', () => {
    test('menu Relatórios traz as duas telas com os rótulos novos', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/alarmes');
        const menu = authedPage.locator('#sidebar');
        await expect(menu.locator('a[href="/relatorios/alarmes"]')).toContainText('Alertas Videomonitoramento');
        await expect(menu.locator('a[href="/relatorios/dirigibilidade"]')).toContainText('Alertas Dirigibilidade');
    });

    test('Alertas Videomonitoramento mantém a coluna Vídeo', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/alarmes');
        await expect(authedPage.locator('h2', { hasText: 'Alertas Videomonitoramento' })).toHaveCount(1);
        await expect(authedPage.locator('table thead th', { hasText: 'Vídeo' })).toHaveCount(1);
    });

    test('Alertas Dirigibilidade não tem nenhuma função de vídeo', async ({ authedPage }) => {
        const resp = await authedPage.goto('/relatorios/dirigibilidade');
        expect(resp?.status()).toBe(200);
        await expect(authedPage.locator('h2', { hasText: 'Alertas Dirigibilidade' })).toHaveCount(1);
        await expect(authedPage.locator('table thead th', { hasText: 'Vídeo' })).toHaveCount(0);
        await expect(authedPage.locator('#video-modal')).toHaveCount(0);
        await expect(authedPage.getByText('Pedir vídeo')).toHaveCount(0);
    });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/helpers/driving_alarms.test.php`
Expected: FALHA nas 7 linhas da seção 3, exit 1.

- [ ] **Step 3: `handlers/rel_dirigibilidade.php`**

```php
<?php
/**
 * JIMI Webhook System — Alertas Dirigibilidade v4.21.0
 * Rota: /relatorios/dirigibilidade
 *
 * Eventos de condução (arrancada, freada, curva, excesso de velocidade,
 * colisão, capotamento, impacto/inclinação — `alarm_types.is_driving = 1`),
 * de câmera E de rastreador, sem nenhuma função de vídeo.
 *
 * ⚠️ É a MESMA grade de Alertas Videomonitoramento, num modo: copiar as 650
 * linhas de rel_alarmes.php criaria duas telas que divergem na primeira
 * correção. Aqui só se escolhe o modo.
 */

$ALARM_REPORT_MODE = 'driving';
require __DIR__ . '/rel_alarmes.php';
```

- [ ] **Step 4: `handlers/rel_alarmes.php` — edições**

(a) Docblock do topo: trocar `Relatório de Alarmes v4.0.0` / `Rota: /relatorios/alarmes` por:

```php
 * JIMI Webhook System — Alertas Videomonitoramento (e Alertas Dirigibilidade) v4.21.0
 * Rotas: /relatorios/alarmes e /relatorios/dirigibilidade (modo, ver abaixo)
```

(b) Substituir

```php
handle_template_actions('rel_alarmes', '/relatorios/alarmes');

$page_title = 'Relatório de Alarmes';
$current_route = 'rel_alarmes';
$db = Database::getInstance()->getConnection();
```

por

```php
// ── Modo da tela (v4.21.0) ──────────────────────────────────────────────────
// Este arquivo serve DUAS telas com a mesma grade: "Alertas Videomonitoramento"
// (esta rota) e "Alertas Dirigibilidade" (handlers/rel_dirigibilidade.php, que
// só define o modo e inclui este arquivo). Decisões do dono do produto em
// docs/superpowers/specs/2026-09-14-rastreadores-dirigibilidade-design.md.
$modoDirig  = (($ALARM_REPORT_MODE ?? 'video') === 'driving');
$rotaTela   = $modoDirig ? '/relatorios/dirigibilidade' : '/relatorios/alarmes';
$chaveTela  = $modoDirig ? 'rel_dirigibilidade' : 'rel_alarmes';
$tituloTela = $modoDirig ? 'Alertas Dirigibilidade' : 'Alertas Videomonitoramento';

handle_template_actions($chaveTela, $rotaTela);

$page_title = $tituloTela;
$current_route = $chaveTela;
$db = Database::getInstance()->getConnection();
// Sem a coluna (migração v4.21.0 ainda não aplicada) a tela de vídeo segue
// como antes e a de dirigibilidade mostra aviso — alarm_types_has_driving_flag().
$temFlagDirig = alarm_types_has_driving_flag($db);
```

(c) `$podeVerDiagnostico = ($user['role'] ?? '') === 'admin';` →

```php
// Nenhum tipo de condução é diagnóstico: o modo não existe em Dirigibilidade.
$podeVerDiagnostico = !$modoDirig && ($user['role'] ?? '') === 'admin';
```

(d) Placas: substituir a linha do `$dvStmt = $db->prepare("SELECT imei, device_name FROM devices WHERE customer_id = :cid AND is_active = 1 ORDER BY device_name");` por

```php
    // Videomonitoramento não oferece rastreador (camera_count = 0); a tela de
    // dirigibilidade oferece todo equipamento, porque os dois geram condução.
    $dvStmt = $db->prepare("SELECT d.imei, d.device_name FROM devices d
                              LEFT JOIN device_models dm ON dm.id = d.device_model_id
                             WHERE d.customer_id = :cid AND d.is_active = 1"
                           . ($modoDirig ? '' : ' AND ' . device_has_camera_sql('d', 'dm')) . "
                             ORDER BY d.device_name");
```

(e) Depois do bloco `$where .= $verDiagnostico ? … : " AND ($alarmDiagExpr) = 0";` acrescentar

```php

// Recorte da tela (v4.21.0). Dirigibilidade: só os tipos marcados, de qualquer
// equipamento. Videomonitoramento: nem condução, nem equipamento sem câmera —
// alarme de rastreador que não é de condução fica só na ficha do veículo.
$alarmDrivingExpr = alarm_driving_expr($temFlagDirig);
$where .= $modoDirig
    ? " AND ($alarmDrivingExpr) = 1"
    : " AND ($alarmDrivingExpr) = 0 AND " . device_has_camera_sql('d', 'dm');
```

(f) Nas três consultas (export, count, data), trocar `LEFT JOIN devices d ON d.imei = a.imei` (replace_all) por

```php
LEFT JOIN devices d ON d.imei = a.imei
    LEFT JOIN device_models dm ON dm.id = d.device_model_id
```

(g) No `stream_export(...)`: `'relatorio_alarmes'` → `$modoDirig ? 'relatorio_alertas_dirigibilidade' : 'relatorio_alertas_videomonitoramento'` e `'Relatório de Alarmes'` → `$modoDirig ? 'Relatório de Alertas de Dirigibilidade' : 'Relatório de Alertas de Videomonitoramento'`.

(h) Tipos do filtro: substituir `$types = $db->query("SELECT DISTINCT alarm_name_pt … category IN ('DMS','ADAS') …")->fetchAll();` por

```php
if ($modoDirig) {
    // Dirigibilidade: o catálogo marcado (v4.21.0) — descreve o catálogo, não
    // o histórico, pela mesma razão do filtro DMS/ADAS acima.
    $types = $temFlagDirig
        ? $db->query("SELECT DISTINCT alarm_name_pt AS alarm_name FROM alarm_types
                       WHERE is_driving = 1 ORDER BY alarm_name_pt")->fetchAll()
        : [];
} else {
    $types = $db->query(
        "SELECT DISTINCT alarm_name_pt AS alarm_name
           FROM alarm_types
          WHERE category IN ('DMS','ADAS')
          ORDER BY alarm_name_pt"
    )->fetchAll();
}
```

(i) `$temTs = false;\nforeach ($rows as $r) {` → `$temTs = false;\nforeach ($modoDirig ? [] : $rows as $r) {`

(j) Cabeçalho: `>Relatório de Alarmes</h2>` → `><?= htmlspecialchars($tituloTela) ?></h2>`; `report_back_button('/relatorios/alarmes')` → `report_back_button($rotaTela)`; `render_template_bar('rel_alarmes', '/relatorios/alarmes')` → `render_template_bar($chaveTela, $rotaTela)`.

(k) Antes de `<?php if ($verDiagnostico): ?>` inserir

```php
<?php if ($modoDirig && !$temFlagDirig): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid var(--warning);font-size:13px;color:var(--muted);">
    <strong style="color:var(--ink);">Classificação de dirigibilidade indisponível.</strong>
    Falta aplicar a migração v4.21.0 (<span class="text-mono">alarm_types.is_driving</span>) neste banco —
    até lá esta tela fica vazia e os eventos de condução continuam em Alertas Videomonitoramento.
</div>
<?php endif; ?>

```

(l) `<th>Vídeo</th>` → `<?php if (!$modoDirig): ?><th>Vídeo</th><?php endif; ?>`; `<td colspan="9">` → `<td colspan="<?= $modoDirig ? 8 : 9 ?>">`.

(m) Na linha da grade, antes de `$midiaKind = media_kind($r['file_url'], $r['file_type']);` abrir

```php
                $videoOk = false;
                $temVideo = false;
                $midiaKind = null;
                $midiaPorCanal = [];
                if (!$modoDirig) {   // Dirigibilidade não resolve mídia (v4.21.0)
```

e reindentar (+4) as linhas existentes até `$videoOk = !empty($midiaPorCanal);`, fechando com `                }` logo depois dela.

(n) A `<td>` da coluna Vídeo (a que começa com `<?php if ($videoOk): ?>` e termina em `<?php else: echo '—'; endif; ?>\n                </td>\n            </tr>`) fica entre `<?php if (!$modoDirig): ?>` e `<?php endif; ?>`.

(o) Antes de `<!-- ── Modal do vídeo do alarme (v4.9.8; player duplo 26/08/2026)` inserir `<?php if (!$modoDirig): // vídeo só existe em Videomonitoramento ?>`; logo após o `</script>` que antecede `<?php if ($mapPoints): ?>`, inserir `<?php endif; ?>`.

- [ ] **Step 5: Rota e menu**

`handlers/router.php` — docblock: após ` *   /relatorios/alarmes               → rel_alarmes.php` acrescentar ` *   /relatorios/dirigibilidade        → rel_dirigibilidade.php (mesma grade, modo condução)`. No array `'relatorios'`, após `'alarmes'      => 'rel_alarmes.php',`:

```php
            'dirigibilidade' => 'rel_dirigibilidade.php',
```

No `$screenByHandler`, após `'rel_alarmes.php'           => 'relatorios',`:

```php
    // v4.21.0 — mesma grade de rel_alarmes.php em outro modo: mesma chave, e
    // por isso NÃO entra na matriz de grupos_permissao.php.
    'rel_dirigibilidade.php'    => 'relatorios',
```

`web/layout_base.php` — trocar a linha do `rel_alarmes` por:

```php
            // v4.21.0 — "Alarmes" virou duas telas (decisão do dono do produto):
            // o que é de câmera/IA e o que é de condução (câmera e rastreador).
            ['route' => 'rel_alarmes',        'label' => 'Alertas Videomonitoramento', 'href' => '/relatorios/alarmes'],
            ['route' => 'rel_dirigibilidade', 'label' => 'Alertas Dirigibilidade', 'href' => '/relatorios/dirigibilidade'],
```

`tests/navigation.spec.js` — após `'/relatorios/alarmes',` acrescentar `'/relatorios/dirigibilidade',`.

- [ ] **Step 6: Rodar e ver passar**

Run: `for f in handlers/rel_alarmes.php handlers/rel_dirigibilidade.php handlers/router.php web/layout_base.php; do php -l $f; done && php tests/helpers/driving_alarms.test.php && node --check tests/dirigibilidade.spec.js && node --check tests/navigation.spec.js`
Expected: sem erro de sintaxe; `TUDO OK`.

- [ ] **Step 7: Commit**

```bash
git add handlers/rel_alarmes.php handlers/rel_dirigibilidade.php handlers/router.php web/layout_base.php tests/navigation.spec.js tests/dirigibilidade.spec.js tests/helpers/driving_alarms.test.php
git commit -m "feat: Alertas Videomonitoramento x Alertas Dirigibilidade (v4.21.0)"
```

---

### Task 4: Ocorrências sem vídeo quando condução ou sem câmera

**Files:**
- Modify: `handlers/ocorrenciasdata.php`, `handlers/ocorrencias_dashboard.php`
- Test: `tests/helpers/driving_alarms.test.php` (seção), `tests/dirigibilidade.spec.js` (teste novo)

**Interfaces:**
- Consumes: `occurrence_no_video_sql()`, `alarm_types_has_driving_flag()`.
- Produces: campo `no_video` (bool) em cada linha de `/ocorrenciasdata`; `$detailNoVideo` no detalhe.

- [ ] **Step 1: Testes que falham** — seção:

```php
// ── 4) Ocorrências ──────────────────────────────────────────────────────────
$occData = (string)file_get_contents($raiz . '/handlers/ocorrenciasdata.php');
confere(str_contains($occData, 'occurrence_no_video_sql(') && str_contains($occData, "'no_video' =>"), '/ocorrenciasdata devolve no_video');
$occDash = (string)file_get_contents($raiz . '/handlers/ocorrencias_dashboard.php');
confere(str_contains($occDash, 'if (r.no_video)'), 'grade: célula Vídeo respeita no_video');
confere(substr_count($occDash, '$detailNoVideo') >= 6, 'detalhe: mídia, coluna e player condicionados a $detailNoVideo');
```

E em `tests/dirigibilidade.spec.js`, dentro do `describe`:

```js
    test('/ocorrenciasdata informa no_video em toda linha', async ({ authedPage }) => {
        const r = await authedPage.request.get('/ocorrenciasdata?page=1');
        expect(r.status()).toBe(200);
        const j = await r.json();
        for (const row of j.data.rows) expect(typeof row.no_video).toBe('boolean');
    });
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/helpers/driving_alarms.test.php`
Expected: FALHA nas 3 linhas da seção 4.

- [ ] **Step 3: `handlers/ocorrenciasdata.php`**

Antes de `$dataStmt = $db->prepare("`:

```php
    // v4.21.0 — dirigibilidade (por código dos alarmes agrupados) ou equipamento
    // sem câmera: a grade não oferece vídeo nem "Pedir vídeo".
    $noVideoSql = occurrence_no_video_sql(alarm_types_has_driving_flag($db));
```

Na lista do SELECT, trocar `… AS has_event_media` por `… AS has_event_media,\n               ($noVideoSql) AS no_video`. No `$data[] = [...]`, após `'repr_alarm_id' => …,`:

```php
            'no_video' => !empty($r['no_video']),
```

- [ ] **Step 4: `handlers/ocorrencias_dashboard.php`**

(a) Após `$detailChannels = [];     // canal(1|2) => …`:

```php
$detailNoVideo = false;   // v4.21.0 — condução ou sem câmera: sem função de vídeo
```

(b) Logo após `if ($detailOcc) {`:

```php
            // v4.21.0 — dirigibilidade ou equipamento sem câmera: não se
            // resolve mídia nem se oferece pedido (decisão do dono do produto).
            $nv = $db->prepare("SELECT (" . occurrence_no_video_sql(alarm_types_has_driving_flag($db)) . ")
                                  FROM occurrences o WHERE o.id = :id");
            $nv->execute([':id' => $detailOcc['id']]);
            $detailNoVideo = (bool)$nv->fetchColumn();

```

(c) `if (!empty($detailOcc['media_file_id'])) {` → `if (!$detailNoVideo && !empty($detailOcc['media_file_id'])) {`; as duas ocorrências de `            if (!$detailMedia) {` (degraus 2 e 3) → `            if (!$detailNoVideo && !$detailMedia) {`; `foreach ($detailEvents as $ev) {\n                if (empty($ev['file_url'])) continue;` → `foreach ($detailNoVideo ? [] : $detailEvents as $ev) {\n                if (empty($ev['file_url'])) continue;`.

(d) `<thead><tr><th>Alarme</th><th>Data/Hora</th><th>Vídeo</th></tr></thead>` → `<thead><tr><th>Alarme</th><th>Data/Hora</th><?php if (!$detailNoVideo): ?><th>Vídeo</th><?php endif; ?></tr></thead>`; a `<td>` que contém `<?php if ($ev['file_url']): ?>` fica entre `<?php if (!$detailNoVideo): ?>` e `<?php endif; ?>`.

(e) `<!-- Mídia + Mapa -->\n        <div>` seguido de `<?php if ($detailChannels): …` → inserir `            <?php if (!$detailNoVideo): ?>` antes do `<?php if ($detailChannels)`; após `            <?php endif; endif; ?>` inserir `            <?php endif; // !$detailNoVideo ?>`. No `<h3 …margin:20px 0 8px;">` de "Localização" → `margin:<?= $detailNoVideo ? '0' : '20px' ?> 0 8px;`.

(f) No JS `updateTable`, trocar `if (r.has_media) {` por:

```js
        if (r.no_video) {
            // Dirigibilidade ou equipamento sem câmera (v4.21.0): não há vídeo
            // para mostrar nem para pedir.
            videoCell = '<span class="text-muted">—</span>';
        } else if (r.has_media) {
```

- [ ] **Step 5: Rodar e ver passar**

Run: `php -l handlers/ocorrenciasdata.php && php -l handlers/ocorrencias_dashboard.php && php tests/helpers/driving_alarms.test.php && node --check tests/dirigibilidade.spec.js`
Expected: sem erro; `TUDO OK`.

- [ ] **Step 6: Commit**

```bash
git add handlers/ocorrenciasdata.php handlers/ocorrencias_dashboard.php tests/helpers/driving_alarms.test.php tests/dirigibilidade.spec.js
git commit -m "feat: ocorrencias de conducao e de rastreador sem funcoes de video (v4.21.0)"
```

---

### Task 5: Nenhum pedido de vídeo para condução ou sem câmera (servidor)

**Files:**
- Modify: `includes/alarm_video_request.php` (`request_alarm_video()`), `includes/occurrence_engine.php` (`process_alarm_to_occurrence()`), `scripts/video_upload_backfill.php`
- Test: `tests/helpers/driving_alarms.test.php` (seção)

**Interfaces:**
- Consumes: `is_driving_alarm()`, `alarm_label_sql()`, `alarm_driving_expr()`, `alarm_types_has_driving_flag()`.

- [ ] **Step 1: Teste que falha** — seção:

```php
// ── 5) Pedido de vídeo (manual, automático, backfill) ───────────────────────
$avr = (string)file_get_contents($raiz . '/includes/alarm_video_request.php');
confere(str_contains($avr, 'AS cams_efetivas') && str_contains($avr, 'is_driving_alarm($db, (string)$al[\'alarm_type\']'), '/solicitarvideo recusa sem câmera e condução');
$eng = (string)file_get_contents($raiz . '/includes/occurrence_engine.php');
confere((bool)preg_match('/\$mediaId === null\s*&& !is_driving_alarm\(\$db, \$alarmType, \$compositeCode/', $eng), 'motor não agenda vídeo de condução');
$vub = (string)file_get_contents($raiz . '/scripts/video_upload_backfill.php');
confere(str_contains($vub, 'AS is_driving') && str_contains($vub, '$totalConducao'), 'backfill ignora condução e conta');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/helpers/driving_alarms.test.php`
Expected: FALHA nas 3 linhas da seção 5.

- [ ] **Step 3: `request_alarm_video()`** — no SELECT, trocar `SELECT a.id, a.imei, a.file_url, a.alarm_label, dm.protocol, dm.camera_count,` por

```php
        SELECT a.id, a.imei, a.file_url, a.alarm_label, dm.protocol, dm.camera_count,
               a.alarm_type, a.alarm_subtype, a.msg_class,
               COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) AS cams_efetivas,
```

e logo após o `if (!$al) { return …; }`:

```php
    // v4.21.0 — as telas escondem o botão, mas botão escondido não é
    // autorização: um POST direto mandaria comando a um equipamento que não
    // grava vídeo, ou pediria um vídeo que nenhuma tela mostra.
    if ((int)$al['cams_efetivas'] === 0) {
        return ['ok' => false, 'msg' => 'Este equipamento é um rastreador, sem câmera: não há vídeo para pedir.'];
    }
    $composto = ($al['alarm_subtype'] !== null && $al['alarm_subtype'] !== '')
        ? $al['alarm_type'] . '-' . $al['alarm_subtype'] : null;
    if (is_driving_alarm($db, (string)$al['alarm_type'], $composto, (int)$al['msg_class'])) {
        return ['ok' => false, 'msg' => 'Alarme de dirigibilidade: sem função de vídeo.'];
    }
```

- [ ] **Step 4: motor** — em `process_alarm_to_occurrence()`, trocar `    if ($occId && $mediaId === null) {` por

```php
    // v4.21.0 — dirigibilidade não tem função de vídeo em tela nenhuma: pedir o
    // anexo só gastaria franquia do SIM (decisão do dono do produto, 14/09/2026).
    if ($occId && $mediaId === null
        && !is_driving_alarm($db, $alarmType, $compositeCode, (int)($alarm['msg_class'] ?? 1))) {
```

- [ ] **Step 5: backfill** — após `$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);` (e antes do `if (!$devices)`):

```php

// v4.21.0 — alarme de dirigibilidade não tem função de vídeo: fica fora do
// reenvio, contado à parte para não inflar "sem linha local".
['joins' => $vubJoins] = alarm_label_sql();
$vubDriving = alarm_driving_expr(alarm_types_has_driving_flag($db));
```

`$totalErroApi     = 0;` → acrescentar `\n$totalConducao    = 0; // dirigibilidade — sem função de vídeo (v4.21.0)`. Substituir o SELECT de `$locais` por

```php
    $stmt = $db->prepare("
        SELECT a.id, a.alarm_label, a.file_url, ($vubDriving) AS is_driving,
               DATE_FORMAT(CONVERT_TZ(a.alarm_time, '+00:00', '-03:00'), '%Y-%m-%d %H:%i:%s') AS local_ts
          FROM alarms a
          $vubJoins
         WHERE a.imei = :imei
           AND a.alarm_label IS NOT NULL AND a.alarm_label <> ''
           AND a.alarm_time BETWEEN DATE_SUB(:ini, INTERVAL 1 DAY) AND DATE_ADD(:fim, INTERVAL 1 DAY)
    ");
```

`    $semLinha = 0;` → `    $semLinha = 0;\n    $conducao = 0;`. Após `        $al = $locais[$label];`:

```php
        if (!empty($al['is_driving'])) {
            $conducao++;
            continue;
        }
```

`    $totalSemLinha += $semLinha;` → `    $totalSemLinha += $semLinha;\n    $totalConducao += $conducao;`. No `echo` por device, após `"{$semLinha} sem linha local | "` inserir `. "{$conducao} de dirigibilidade | "`. Após o `echo "Sem linha local …\n";` do resumo:

```php
echo "Dirigibilidade (sem função de vídeo, v4.21.0): {$totalConducao}\n";
```

- [ ] **Step 6: Rodar e ver passar**

Run: `php -l includes/alarm_video_request.php && php -l includes/occurrence_engine.php && php -l scripts/video_upload_backfill.php && php tests/helpers/driving_alarms.test.php && php tests/helpers/alarm_video_match.test.php`
Expected: sem erro; `TUDO OK`.

- [ ] **Step 7: Commit**

```bash
git add includes/alarm_video_request.php includes/occurrence_engine.php scripts/video_upload_backfill.php tests/helpers/driving_alarms.test.php
git commit -m "feat: nenhum pedido de video para conducao ou equipamento sem camera (v4.21.0)"
```

---

### Task 6: Rastreador fora de Downloads e do Mapa de Risco

**Files:**
- Modify: `handlers/video_downloads.php` (consulta `$devStmt`), `handlers/mapa_risco.php` (`mr_vehicle_options()`), `scripts/risk_builder.php` (`rb_load_points()`)
- Test: `tests/helpers/driving_alarms.test.php` (seção)

**Interfaces:**
- Consumes: `device_has_camera_sql()`.

- [ ] **Step 1: Teste que falha** — seção:

```php
// ── 6) Rastreador fora das telas de câmera ──────────────────────────────────
$vd = (string)file_get_contents($raiz . '/handlers/video_downloads.php');
confere(str_contains($vd, "device_has_camera_sql('d', 'dm')"), 'Downloads: filtro sem rastreador');
$mr = (string)file_get_contents($raiz . '/handlers/mapa_risco.php');
confere(str_contains($mr, 'di.removed_at IS NULL') && str_contains($mr, "device_has_camera_sql('d', 'dm')"), 'Mapa de Risco: seletor sem veículo com rastreador');
$rb = (string)file_get_contents($raiz . '/scripts/risk_builder.php');
confere(str_contains($rb, 'WHERE d.imei = g.imei AND NOT (') , 'risk_builder: exposição sem ponto de rastreador');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/helpers/driving_alarms.test.php`
Expected: FALHA nas 3 linhas da seção 6.

- [ ] **Step 3: `handlers/video_downloads.php`** — substituir a consulta `$devStmt` por

```php
// Equipamentos oferecidos no filtro, já no escopo resolvido. v4.21.0: sem
// rastreador (camera_count = 0) — não há gravação dele para baixar.
$devStmt = $db->prepare("
    SELECT d.imei, COALESCE(NULLIF(d.device_name,''), d.imei) AS device_name
    FROM devices d
    LEFT JOIN device_models dm ON dm.id = d.device_model_id
    WHERE d.is_active = 1 AND " . device_has_camera_sql('d', 'dm')
    . ($scopeCust !== null ? ' AND d.customer_id = :cid' : '') . "
    ORDER BY d.device_name
");
```

(o comentário `// Equipamentos oferecidos no filtro, já no escopo resolvido.` existente é substituído pelo acima.)

- [ ] **Step 4: `mr_vehicle_options()`** — corpo novo:

```php
function mr_vehicle_options(PDO $db, ?int $cust): array
{
    // v4.21.0 — o mapa é ADAS/DMS: veículo com RASTREADOR instalado agora não
    // tem o que mostrar aqui. Veículo sem instalação continua — o histórico de
    // câmera dele ainda vale.
    $semRastreador = "NOT EXISTS (SELECT 1 FROM device_installations di
                                    JOIN devices d ON d.id = di.device_id
                                    LEFT JOIN device_models dm ON dm.id = d.device_model_id
                                   WHERE di.vehicle_id = v.id AND di.removed_at IS NULL
                                     AND NOT (" . device_has_camera_sql('d', 'dm') . "))";
    if ($cust !== null) {
        $stmt = $db->prepare("SELECT v.id, v.plate FROM vehicles v
                               WHERE v.customer_id = :c AND v.is_active = 1 AND $semRastreador
                               ORDER BY v.plate LIMIT 2000");
        $stmt->execute([':c' => $cust]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $db->query("SELECT v.id, v.plate FROM vehicles v
                        WHERE v.is_active = 1 AND $semRastreador
                        ORDER BY v.plate LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
}
```

- [ ] **Step 5: `rb_load_points()`** — trocar o prepare por

```php
    // v4.21.0 — ponto de equipamento SEM câmera (rastreador JM-VL) não entra na
    // exposição: o índice é ADAS/DMS por hora dirigida, e as horas de um
    // veículo que não tem como gerar alerta diluíam o risco da frota com câmera.
    $stmt = $db->prepare("
        SELECT g.gps_time, g.acc, g.speed, g.latitude, g.longitude, g.driver_id, g.customer_id
        FROM gps_data g
        WHERE g.vehicle_id = :v AND g.gps_time >= :f AND g.gps_time < :t
          AND NOT EXISTS (SELECT 1 FROM devices d
                            LEFT JOIN device_models dm ON dm.id = d.device_model_id
                           WHERE d.imei = g.imei AND NOT (" . device_has_camera_sql('d', 'dm') . "))
        ORDER BY g.gps_time, g.id" . ($limit > 0 ? " LIMIT $limit" : ''));
```

- [ ] **Step 6: Rodar e ver passar**

Run: `php -l handlers/video_downloads.php && php -l handlers/mapa_risco.php && php -l scripts/risk_builder.php && php tests/helpers/driving_alarms.test.php && php tests/helpers/risk_map.test.php`
Expected: sem erro; `TUDO OK` / 77/77.

- [ ] **Step 7: Commit**

```bash
git add handlers/video_downloads.php handlers/mapa_risco.php scripts/risk_builder.php tests/helpers/driving_alarms.test.php
git commit -m "feat: rastreador fora de Downloads e do Mapa de Risco (v4.21.0)"
```

---

### Task 7: Agendamentos e Exportar nos dois recortes

**Files:**
- Modify: `includes/schedule.php` (`schedule_report_types()`), `scripts/worker.php` (`buildReportSource()`, `case 'alarms'`), `handlers/exportar.php` (`<select name="report_type">`)
- Test: `tests/helpers/driving_alarms.test.php` (seção)

**Interfaces:**
- Consumes: `alarm_label_sql()` (chave `diag`), `alarm_driving_expr()`, `alarm_types_has_driving_flag()`, `device_has_camera_sql()`.
- Produces: `report_type = 'driving_alarms'`.

- [ ] **Step 1: Teste que falha** — seção:

```php
// ── 7) Agendamentos e Exportar ──────────────────────────────────────────────
$sch = (string)file_get_contents($raiz . '/includes/schedule.php');
confere(str_contains($sch, "'alarms'      => 'Alertas Videomonitoramento'") && str_contains($sch, "'driving_alarms' => 'Alertas Dirigibilidade'"), 'schedule_report_types(): dois recortes');
$wk = (string)file_get_contents($raiz . '/scripts/worker.php');
confere((bool)preg_match("/case 'alarms':\s*case 'driving_alarms':/", $wk), 'worker reconhece driving_alarms');
$exp = (string)file_get_contents($raiz . '/handlers/exportar.php');
confere(str_contains($exp, '<option value="driving_alarms">Alertas Dirigibilidade</option>'), 'Exportar oferece Alertas Dirigibilidade');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/helpers/driving_alarms.test.php`
Expected: FALHA nas 3 linhas da seção 7.

- [ ] **Step 3: `schedule_report_types()`** — trocar `'alarms'      => 'Alarmes',` por

```php
        // v4.21.0 — os dois recortes das telas de alarme (spec 2026-09-14).
        'alarms'      => 'Alertas Videomonitoramento',
        'driving_alarms' => 'Alertas Dirigibilidade',
```

- [ ] **Step 4: worker** — substituir o `case 'alarms':` inteiro (até o `];` do `return`) por

```php
        case 'alarms':
        case 'driving_alarms':
            // Nome do alarme resolvido na leitura — ver alarm_label_sql().
            // Até a v4.8.x este relatório imprimia `a.alarm_type` CRU, isto é,
            // o código numérico, sem nem o rótulo genérico que a tela tinha.
            //
            // v4.21.0 — o mesmo recorte das duas telas: `alarms` é Alertas
            // Videomonitoramento (sem condução, sem equipamento sem câmera, sem
            // diagnóstico — que este relatório nunca filtrava) e
            // `driving_alarms` é Alertas Dirigibilidade.
            ['joins' => $alarmJoins, 'expr' => $alarmExpr, 'diag' => $alarmDiag] = alarm_label_sql();
            $drivingExpr = alarm_driving_expr(alarm_types_has_driving_flag($db));
            $recorte = $type === 'driving_alarms'
                ? " AND ($drivingExpr) = 1"
                : " AND ($drivingExpr) = 0 AND " . device_has_camera_sql('d', 'dm');
            $stmt = $db->prepare("
                SELECT COALESCE(d.device_name, a.imei) as device_name, $alarmExpr AS alarm_label,
                       a.alarm_time, a.status, a.speed, a.latitude, a.longitude, " . GEO_ADDR_SQL . "
                FROM alarms a
                JOIN devices d ON d.imei = a.imei AND d.customer_id = :cid
                LEFT JOIN device_models dm ON dm.id = d.device_model_id
                $alarmJoins
                " . geo_join('a.latitude', 'a.longitude') . "
                WHERE a.alarm_time BETWEEN :df AND :dt
                  AND ($alarmDiag) = 0 $recorte
                ORDER BY a.alarm_time DESC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
            $statusLabels = ['active' => 'Ativo', 'resolved' => 'Resolvido'];
            return [
                ['Placa', 'Data/Hora', 'Nome do Alarme', 'Status', 'Velocidade (km/h)', 'Endereço', 'Mapa'],
                $stmt,
                fn($r) => [$r['device_name'], fmt_brt($r['alarm_time'], 'd/m/Y H:i:s'),
                           $r['alarm_label'] ?: '—', $statusLabels[$r['status']] ?? $r['status'],
                           $r['speed'], $r['endereco'] ?? '—',
                           export_map_link($r['latitude'], $r['longitude'])],
                [1.0, 1.35, 2.4, 0.8, 0.9, 3.2, 0.6],
            ];
```

- [ ] **Step 5: `handlers/exportar.php`** — trocar `<option value="alarms">Alarmes</option>` por

```php
                    <option value="alarms">Alertas Videomonitoramento</option>
                    <option value="driving_alarms">Alertas Dirigibilidade</option>
```

- [ ] **Step 6: Rodar e ver passar**

Run: `php -l includes/schedule.php && php -l scripts/worker.php && php -l handlers/exportar.php && php tests/helpers/driving_alarms.test.php`
Expected: sem erro; `TUDO OK`.

- [ ] **Step 7: Commit**

```bash
git add includes/schedule.php scripts/worker.php handlers/exportar.php tests/helpers/driving_alarms.test.php
git commit -m "feat: agendamento e exportacao de Alertas Dirigibilidade (v4.21.0)"
```

---

### Task 8: Documentação, versão e verificação final

**Files:**
- Modify: `handlers/wiki.php` (§ `rel-alarmes` + índice), `CHANGELOG.md`, `STATUS.md`, `CLAUDE.md`, `.env.example`

- [ ] **Step 1: Wiki** — no índice, trocar `<a href="#rel-alarmes" …>Alarmes</a>` por dois links (`#rel-alarmes` "Alertas Videomonitoramento", `#rel-dirigibilidade` "Alertas Dirigibilidade"). Na seção: `<h3 id="rel-alarmes">Alarmes</h3>` → `<h3 id="rel-alarmes">Alertas Videomonitoramento</h3>`; primeiro parágrafo passa a dizer que a tela mostra os alarmes de **câmera** que não são de condução; no callout "Nem todo alarme tem vídeo" trocar "como excesso de velocidade" por "(os de condução estão em Alertas Dirigibilidade)". Antes de `<h4 …>Eventos de diagnóstico` inserir:

```html
<h3 id="rel-dirigibilidade">Alertas Dirigibilidade</h3>
<p><strong>Objetivo:</strong> Eventos de condução — arrancada e freada bruscas, curva acentuada, excesso e aviso de velocidade (inclusive dentro de cerca), colisão, capotamento, impacto e inclinação — de <strong>câmeras e rastreadores</strong>. Mesmos filtros, mapa e exportação de Alertas Videomonitoramento, sem coluna de vídeo: esses eventos não têm função de vídeo no sistema.</p>
<div class="callout info">
<strong>Rastreador não aparece nas telas de câmera.</strong> Equipamentos sem câmera (linha JM-VL) ficam fora de Vídeos, Configurações IA, Mapa de Risco e Alertas Videomonitoramento. Os alarmes deles que não são de condução (roubo, partida ilegal, desmontado) ficam na aba Alertas da ficha do veículo. Ocorrências de condução — de qualquer equipamento — não oferecem vídeo.
</div>
```

- [ ] **Step 2: CHANGELOG** — renomear `## [Unreleased] — 4.20.0` para `## [4.20.0] — 2026-09-14` e inserir acima dele `## [Unreleased] — 4.21.0` com: resumo em uma frase; seção **Adicionado** (tela Alertas Dirigibilidade, `alarm_types.is_driving`, tipo de agendamento/export `driving_alarms`, helpers); **Alterado** (menu "Alertas Videomonitoramento", recortes, ocorrências sem vídeo, pedido automático e backfill, Downloads/Mapa de Risco sem rastreador, exposição do risk_builder); **Pós-deploy** (segundo deploy ou `.sql` à mão; `php scripts/risk_builder.php --desde=2026-06-10`; conferências da migração).

- [ ] **Step 3: STATUS.md** — cabeçalho `v4.20.0` → `v4.21.0`; nova entrada `### 📍 14/09/2026 — Rastreadores fora das telas de câmera + Alertas Dirigibilidade (v4.21.0)` no topo, com decisões (tabela §2 da spec), entregue, verificação local (resultado real dos testes) e **Pendente em produção**: aplicar migração no segundo deploy, conferir as 4 consultas da migração, reprocessar o risk_builder, abrir as duas telas logado. Mover a 4ª entrada datada mais antiga para `docs/status-history/STATUS_ARCHIVE.md` (regra das 3 inline).

- [ ] **Step 4: CLAUDE.md** — após o bullet `Mapa de Risco (v4.20.0)`:

```markdown
- 🔴 **Alertas Dirigibilidade (v4.21.0): `alarm_types.is_driving` separa CONDUÇÃO de videomonitoramento, por CÓDIGO — e código de condução sem a marca cai na tela de vídeo.** Decisão do dono do produto (14/09/2026): condução (arrancada, freada, curva, velocidade — inclusive 202 e 95 —, colisão, capotamento, impacto/inclinação) vai para `/relatorios/dirigibilidade` venha de câmera ou de rastreador, e **nenhuma** linha de condução tem função de vídeo (grade, detalhe de ocorrência, `/solicitarvideo`, pedido automático e backfill). `Alertas Videomonitoramento` (`/relatorios/alarmes`) exclui condução **e** equipamento sem câmera — alarme de rastreador que não é de condução fica só na ficha do veículo. **Migração que cadastra código de condução tem de marcar `is_driving` junto** (mesma armadilha do `risk_group`); ADAS de IA (FCW/PCW) não é condução. Leitura só pelos helpers de `includes/functions.php` (`alarm_driving_expr()`, `occurrence_no_video_sql()`, `is_driving_alarm()`, `device_has_camera_sql()`), que caem no comportamento anterior enquanto a coluna não existe. `tests/helpers/driving_alarms.test.php` trava a lista.
```

- [ ] **Step 5: `.env.example`** — `SYSTEM_VERSION=4.20.0` → `SYSTEM_VERSION=4.21.0`.

- [ ] **Step 6: Verificação final**

Run:
```bash
find handlers config core includes scripts web -name "*.php" -type f -exec php -l {} \; | grep -v "^No syntax errors" ; \
php tests/helpers/driving_alarms.test.php && php tests/helpers/migracoes_no_deploy.test.php && \
php tests/helpers/risk_map.test.php && php tests/helpers/alarm_video_match.test.php && \
for f in tests/*.spec.js; do node --check "$f" || echo "FALHA $f"; done
```
Expected: nenhuma linha de erro de lint; todos os helpers `TUDO OK`/OK; nenhum `FALHA`.

- [ ] **Step 7: Commit e push**

```bash
git add handlers/wiki.php CHANGELOG.md STATUS.md CLAUDE.md .env.example docs/status-history/STATUS_ARCHIVE.md
git commit -m "docs: v4.21.0 - Alertas Dirigibilidade, rastreadores fora das telas de camera"
git push
```
