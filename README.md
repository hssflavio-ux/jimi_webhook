# Jimi Webhook System

Gateway PHP para dispositivos IoT Jimi — recebe webhooks de GPS/heartbeat/alarme/evento do Jimi IoT Hub (`jimicloud.com`), persiste em MySQL e fornece painel Bootstrap para monitoramento, visualização de mídia, envio de comandos e configuração remota de dispositivos.

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

### Painel de Controle (5 abas)
| Aba | Funcionalidades |
|---|---|
| **Câmeras** | Telemetria dos dispositivos + player de vídeo ao vivo (HTTP-FLV via flv.js) |
| **Alarmes** | Lista de alarmes com links de mapa e arquivo, botão VIDEOUPLOAD para JTT |
| **Comandos** | 16 presets JIMI + 17 presets JTT, histórico com modal de detalhes JSON |
| **Mídia** | Galeria de arquivos (imagem/vídeo/áudio) com download e player |
| **Configuração** | Ler e alterar parâmetros do dispositivo remotamente (proNos 33027-33031) |

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
| `SYSTEM_VERSION` | Versão do sistema | `2.0.0` |
| `FILE_STORAGE_URL` | URL base para download de arquivos de mídia | `http://IP:23010/download/` |
| `STREAM_URL` | URL base para streams HTTP-FLV ao vivo/playback | `http://IP:8881` |

## Documentation

| Documento | Descrição |
|---|---|
| [Cobertura de API](./docs/API_COVERAGE.md) | Endpoints implementados × documentação oficial com tabelas de parâmetros |
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
