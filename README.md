# Jimi Webhook System v4.0.0 — YUV Parity

Gateway PHP para dispositivos IoT Jimi — recebe webhooks de GPS/heartbeat/alarme/evento do Jimi IoT Hub (`jimicloud.com`), persiste em MySQL e fornece uma plataforma multi-tenant de rastreamento com telemetria de vídeo (MDVR).

> **Direção atual (v4.0.0 — "YUV Parity")**: o produto está sendo transformado em uma **cópia fiel da plataforma YUV** (`app.yuv.com.br`). O núcleo passa a ser a **gestão de ocorrências de comportamento do motorista (DMS/ADAS)** — alarmes de câmera com IA (distração, uso de celular, sem cinto) que viram ocorrências com fluxo de tratativa, classificação de risco e regras configuráveis por cliente. O gateway de webhooks é preservado; o dashboard e o design são reconstruídos.
>
> **Blueprint de implementação**: [`PROJETO_YUV.md`](./PROJETO_YUV.md). **Análise visual de origem**: [`analise_yuv/analise_yuv.html`](./analise_yuv/analise_yuv.html).

## Quick Start

```bash
# 1. Configure o ambiente
cp .env.example .env   # ou crie .env com DB_HOST, DB_NAME, DB_USER, DB_PASS, WEBHOOK_TOKEN

# 2. Crie o banco de dados (instalação nova)
mysql -u root -p < mysql/jimi_tracker.sql

# 3. Execute a migração
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql

# 4. Aponte o Apache/Nginx para o diretório raiz
#    O .htaccess já configura rewrite e headers de segurança
```

Pré-requisitos: PHP 7.4+ com PHP-FPM, MySQL 8.0+, Apache com mod_rewrite.

## Features

### Webhook Gateway
- **11 endpoints push** alinhados com a [API oficial Jimi](https://docs.jimicloud.com/integration/integration.html)
- **Processamento assíncrono** via `fastcgi_finish_request()` — resposta HTTP 200 imediata, processamento em background
- **Anti-replay** — idempotência por hash MD5 do payload com janela de 10 minutos
- **Duplo protocolo** — suporte completo a JIMI (msgClass=0) e JT/T 808 (msgClass=1) com isolamento estrito
- **Stored procedures MySQL** para estatísticas em tempo real por dispositivo

### Painel de Controle — em migração para a IA do YUV (v4.0.0)

> A estrutura abaixo descreve o painel **atual** (v3.x). Na v4.0.0 ele é reconstruído para espelhar a navegação do YUV (Resumo, Rastreamento, BI, Ocorrências, Vídeos, Relatórios, Cadastros, Comandos, Exportar) — ver [`PROJETO_YUV.md`](./PROJETO_YUV.md) §4.

| Aba (v3.x) | Funcionalidades |
|---|---|
| **Câmeras** | Telemetria dos dispositivos + player de vídeo ao vivo (HTTP-FLV via flv.js) |
| **Alarmes** | Cards de alarme com barra de severidade, links de mapa/arquivo, botão VIDEOUPLOAD para JTT |
| **Comandos** | 16 presets JIMI + 17 presets JTT com protocol toggle, histórico com modal de detalhes JSON |
| **Mídia** | Galeria de cards com thumbnails por tipo (imagem/vídeo/áudio), download e player modal |
| **Configuração** | Ler e alterar parâmetros do dispositivo remotamente (proNos 33027-33031) |

### Design System (v4.0.0 — Coinbase)
- **Coinbase Blue** (#0052ff) como única voltagem de marca — CTAs, links, foco, item ativo
- **Sidebar dark near-black** (#0a0b0d) com item ativo azul; **canvas branco**
- **CTAs pill** (100px); cards com hairline + um único nível de sombra no hover (16px)
- **Tipografia Inter** (headings de display em peso 400) + **JetBrains Mono** em todo número/IMEI
- **Números sempre em mono**; up/down em verde/vermelho (só cor de texto)
- Cores de risco DMS: Baixo (azul) / Médio (amarelo) / Alto (vermelho)
- _(Deriva de `DESIGN-coinbase.md`. As versões ≤3.x usavam a paleta Cursor; a roxa YUV foi descartada. Ver `DESIGN.md`.)_

### Infraestrutura
- **Logger unificado** com rotação diária, níveis DEBUG a CRITICAL, auto-limpeza >30 dias
- **AJAX endpoints** para consulta de tracks, heartbeats e galeria de mídia
- **Atualização silenciosa** do painel a cada 30s (sem reload de página)

## Configuration

| Variável | Descrição | Padrão |
|---|---|---|
| `DB_HOST` | Host do MySQL | `localhost` |
| `DB_PORT` | Porta do MySQL | `3306` |
| `DB_NAME` | Nome do banco de dados | `jimi_tracker` |
| `DB_USER` | Usuário do MySQL | `root` |
| `DB_PASS` | Senha do MySQL | `1029384756` |
| `WEBHOOK_TOKEN` | Token de autenticação (webhooks + painel) | `a12341234123` |
| `SYSTEM_VERSION` | Versão do sistema | `4.0.0` |
| `FILE_STORAGE_URL` | URL base para download de arquivos de mídia | `http://IP:23010/download/` |
| `STREAM_URL` | URL base para streams HTTP-FLV ao vivo/playback | `http://IP:8881` |

## Documentation

| Documento | Descrição |
|---|---|
| **[Projeto YUV Parity](./PROJETO_YUV.md)** | **Blueprint-mestre v4.0.0**: rotas-alvo, modelo de dados, specs das 22 telas, motor de ocorrências, roadmap por fases |
| **[Análise do YUV](./analise_yuv/analise_yuv.html)** | Análise visual de origem: 22 telas do YUV com screenshots, regras de negócio e lacunas |
| [Product Requirements Document](./docs/PRD.md) | PRD completo: 12 seções, arquitetura, features, database, backlog, operações |
| [Cobertura de API](./docs/API_COVERAGE.md) | Endpoints implementados × documentação oficial com tabelas de parâmetros |
| [Design System](./DESIGN.md) | Tokens de design **Coinbase** (azul `#0052ff`, sidebar dark, CTAs pill, mono nos números) — deriva de [`DESIGN-coinbase.md`](./DESIGN-coinbase.md) |
| [Guia para Agentes](./AGENTS.md) | Arquitetura, gotchas, comandos e contexto para OpenCode/Claude |
| [Registro de Decisões](./docs/adr/ADR-001.md) | ADR-001: Isolamento estrito de protocolo JIMI vs JT/T |
| [Changelog](./CHANGELOG.md) | Histórico de versões (Keep a Changelog) |
| [API Oficial Jimi](https://docs.jimicloud.com/integration/integration.html) | Documentação de referência dos endpoints push e request |

## Contributing

1. Todos os handlers devem extender `WebhookHandler` (`config/WebhookHandler.php`)
2. Comentários em **PT-BR**, PHPDoc com `@param`/`@returns`/`@throws`
3. Testar localmente com `curl` simulando payloads da documentação oficial
4. Seguir o [CHANGELOG.md](./CHANGELOG.md) (Keep a Changelog)

## License

MIT
