# Rastreadores fora das telas de câmera + Alarmes de Dirigibilidade (v4.21.0)

Data: 14/09/2026 · Status: aprovado pelo dono do produto (desenho e os três pontos em aberto)

## 1. Problema

Os rastreadores da linha JM-VL (`device_models.family = 'tracker'`, `camera_count = 0`)
não têm vídeo nem DMS/ADAS, mas geram eventos de condução iguais aos das câmeras:
arrancada e freada bruscas, curva acentuada, excesso de velocidade, colisão. Hoje:

- o relatório "Alarmes" mistura tudo e oferece vídeo onde não existe;
- ocorrências de condução oferecem "Pedir vídeo"/player;
- telas exclusivas de câmera (Downloads, Mapa de Risco) ainda listam rastreadores —
  Ao Vivo, Playback, Config. IA, abas de vídeo da ficha e Comandos já filtram (v4.16.0).

## 2. Decisões do dono do produto (14/09/2026)

| # | Pergunta | Decisão |
|---|---|---|
| 1 | Condução gerada por CÂMERA vai para qual tela? | **Dirigibilidade** — o critério é o TIPO do alarme, qualquer equipamento |
| 2 | Vídeo em dirigibilidade de câmera? | **Some em todas** as linhas de dirigibilidade |
| 3 | Tipos além dos cinco citados | **Capotamento**, **Aviso/Velocidade em Cerca**, **Impacto/Inclinação** (Mudança Abrupta de Faixa fica fora) |
| 4 | Alarmes de rastreador que NÃO são de condução | **Só na ficha do veículo** (saem dos relatórios) |
| 5 | Pedido automático de vídeo (motor + backfill) para dirigibilidade | **Para** — gastava franquia num vídeo que nenhuma tela mostra |
| 6 | Mapa de Risco | Rastreador sai do **seletor e da exposição** (denominador), com reprocessamento |
| 7 | Agendamentos | "Alarmes" segue o filtro de Videomonitoramento; tipo novo **"Alarmes Dirigibilidade"** |

## 3. Classificação: `alarm_types.is_driving`

Coluna `TINYINT(1) NOT NULL DEFAULT 0`, marcada **por protocolo + código** na
`mysql/migration_v4.21.0.sql` — nunca por nome nem por categoria (a categoria
`conducao` contém Ociosidade, Motorista Alterado, Condução Prolongada; a colisão
está em `veiculo`; e renomear/recategorizar já desligou motores em silêncio).

| Grupo | JIMI | JT/T |
|---|---|---|
| Arrancada / aceleração brusca | 144 | 1024, 1042 |
| Freada / frenagem brusca | 48, 145 | 1025, 1043 |
| Curva acentuada | 76, 146 | 1026, 1044 |
| Excesso de velocidade | 6, 135, 202 (Aviso de Velocidade), 95 (em Cerca) | 1027 |
| Colisão | 44, 147 | 1029, 1046 |
| Capotamento | 45, 106, 183 | 1047 |
| Impacto / inclinação | 55, 75, 78, 79 | — |

Fora, de propósito: 77 (Mudança Abrupta de Faixa), 116 (Velocidade Normalizada),
todo ADAS/DMS de IA (FCW `204`/`229`/`264-1`, PCW etc.).

A migração termina com duas conferências: códigos esperados ausentes do catálogo, e
tipos **com o mesmo `alarm_name_pt`** de um marcado que ficaram sem marca (irmãos).

**Leitura**: `alarm_label_sql()` ganha a chave `'driving' => "COALESCE(atc.is_driving, atb.is_driving, 0)"`,
dos mesmos joins do rótulo (composto antes da base). Linha "Fim de Alarme" herda o
código base e portanto a marca — correto.

**Janela entre os dois deploys** (migração não roda no deploy que a traz): função
`alarm_types_has_driving_flag(PDO)` (cache estático, `information_schema`). Sem a
coluna, a expressão `driving` vira `0`: Videomonitoramento se comporta como hoje e
Dirigibilidade mostra aviso "aplique a migração v4.21.0" em vez de SQLSTATE.

## 4. "Equipamento sem câmera"

Mesma regra das telas da v4.16.0: `COALESCE(NULLIF(d.camera_count,0), dm.camera_count, 1) = 0`.
Não depende de `family`. Expressão única em `includes/functions.php`
(`device_has_camera_sql(string $d, string $dm)`), com os joins a cargo de quem chama.

## 5. Telas

### 5.1 Menu (web/layout_base.php, grupo Relatórios)
- `rel_alarmes`: rótulo "Alarmes" → **"Alertas Videomonitoramento"** (URL `/relatorios/alarmes` mantida).
- Novo `rel_dirigibilidade`: **"Alarmes Dirigibilidade"** → `/relatorios/dirigibilidade`, logo abaixo.
- Permissão: chave `relatorios` (prefixo `rel_` no menu; `rel_dirigibilidade.php => 'relatorios'` em `$screenByHandler`). Nenhuma chave nova na matriz.

### 5.2 Alertas Videomonitoramento (`handlers/rel_alarmes.php`, modo `video`)
- Grade, contagem, mapa e export: `AND driving = 0 AND <equipamento tem câmera>`.
- Filtro de placa: sem rastreadores.
- Título "Alertas Videomonitoramento"; export `relatorio_alertas_videomonitoramento`.
- Resto inalterado (coluna Vídeo, Pedir vídeo, modo diagnóstico, tipos DMS/ADAS).

### 5.3 Alarmes Dirigibilidade (`handlers/rel_dirigibilidade.php` → mesmo código, modo `driving`)
- Handler fino: define `$ALARM_REPORT_MODE = 'driving'` e inclui `rel_alarmes.php`. Uma grade só.
- Grade: `AND driving = 1`, qualquer equipamento. Placa: todos os equipamentos.
- Tipos no filtro: `alarm_types WHERE is_driving = 1`.
- **Sem** coluna Vídeo, sem Pedir vídeo, sem modo diagnóstico (nenhum tipo de condução é diagnóstico).
- Modelos salvos com chave própria `rel_dirigibilidade`. Export "Relatório de Alarmes de Dirigibilidade".

### 5.4 Ocorrências
Uma ocorrência **não tem vídeo** quando algum alarme agrupado é de dirigibilidade
(por código, via `occurrence_events → alarms → alarm_types`) **ou** o equipamento não tem câmera.
- `handlers/ocorrenciasdata.php`: campo `no_video` por linha; `has_media`/`repr_alarm_id` ignorados nesse caso.
- `handlers/ocorrencias_dashboard.php` (grade JS): célula Vídeo "—" quando `no_video`.
- Detalhe: some a coluna Vídeo dos Alarmes Agrupados e o bloco do player/"Solicitar vídeo"; o mapa sobe.
- `includes/alarm_video_request.php` → `request_alarm_video()`: recusa alarme de dirigibilidade
  ou de equipamento sem câmera (defesa no servidor; o botão escondido não é autorização).

### 5.5 Automático
- `queue_event_video_request()` (`includes/occurrence_engine.php`): não agenda para ocorrência de dirigibilidade.
- `scripts/video_upload_backfill.php`: ignora alarmes de dirigibilidade.

### 5.6 Rastreador fora das telas de câmera
- Vídeo › Downloads: filtro de equipamento sem rastreadores.
- Mapa de Risco: `mr_vehicle_options()` exclui veículo com instalação aberta de rastreador;
  `rb_load_points()` ignora pontos de IMEI sem câmera (exposição e jornada). Reprocessar:
  `php scripts/risk_builder.php --desde=2026-06-10`.
- Alarmes de rastreador não-condução: ficam só na aba Alertas de `/ativos/{id}` (já existe).

### 5.7 Agendamentos
- `schedule_report_types()`: `alarms` → "Alertas Videomonitoramento"; novo `driving_alarms` → "Alarmes Dirigibilidade".
- `scripts/worker.php` `buildReportSource()`: `alarms` aplica o filtro de 5.2 (e o de diagnóstico, que faltava);
  `driving_alarms` aplica o de 5.3.

## 6. Fora do escopo
BI, Painel, notificações, regras de geração de ocorrência, aba Alertas da ficha.

## 7. Verificação
- `php -l` nos arquivos tocados; `tests/helpers/migracoes_no_deploy.test.php`.
- Novo `tests/helpers/driving_alarms.test.php` (sem banco): a lista de códigos da migração
  bate com a tabela da §3; nenhum código ADAS/DMS marcado; `alarm_label_sql()` devolve `driving`;
  `schedule_report_types()` tem `driving_alarms` e o worker o reconhece; `rel_dirigibilidade.php`
  está no router e no menu.
- Playwright: `/relatorios/dirigibilidade` em `navigation.spec.js`/`filtros.spec.js`; a tela
  não tem cabeçalho "Vídeo".
- **Limite**: sem MySQL local e leitura de produção bloqueada nesta sessão — as consultas só
  rodam contra banco real no deploy. Conferências SQL de pós-deploy registradas no STATUS.md.

## 8. Documentação
CHANGELOG `[4.21.0]`, STATUS.md (entrada do dia + pós-deploy), CLAUDE.md (bullet: código novo de
condução precisa de `is_driving` na migração, mesma armadilha do `risk_group`), wiki (§ Alarmes →
duas seções), `SYSTEM_VERSION` 4.21.0, `deploy.sh` com `run_migration "4.21.0"`.
