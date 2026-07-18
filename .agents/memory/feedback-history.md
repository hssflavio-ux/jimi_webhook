---
type: feedback
created: 2026-07-06
updated: 2026-07-18
---

# Feedback History

## 2026-07-06 — Sessão de implementação YUV Parity (8 fases)

### Positivo
- Usuário usa "Continue" para avançar fases (não "sim", "ok", "vai") — workflow implícito de aprovação
- Prefere tabelas de resumo ao final de cada fase (já padronizado)
- Valoriza `STATUS.md` como entregável de handoff (solicitou documentação detalhada)
- Aprecia verificação de lint em cada fase (68 arquivos, 0 erros)
- Implementação completa da fase antes de passar para a próxima
- Usa "Proceda com @PROJETO_YUV.md" como comando de início

### Ações do Usuário
- Disse "Continue" 5 vezes consecutivas para avançar Fases 1→2→3→4+5→6+7
- Pediu documentação detalhada do estado atual ("Documente detalhadamente o estado atual")
- Perguntou sobre memory-system e pediu para torná-lo obrigatório

### Padrões Observados
- Não gosta de perguntas "quer continuar?" — prefere ação direta
- Não precisa de explicações longas de código
- Confia na implementação e verifica via lint
- Sessões longas são aceitáveis (8 fases em sequência)

## 2026-07-18 — Revisão da Wiki (/wiki) para usuário final

### Diretrizes recebidas (valem para TODO conteúdo voltado ao usuário final)
- **Nunca** expor termos técnicos: números de comando (proNo 37121 etc.), AJAX, polling, nomes de biblioteca, nomes de tabela/campo — descrever só a ação e o resultado visível
- **Nunca** expor caminhos de URL (/setup, /bi, /perfil...) — referenciar telas pela função no menu lateral
- Mockups de mapa devem usar **imagem real de mapa** (recorte OSM em `assets/img/wiki_map_*.png`), não retângulo vazio com pontos
- Wiki NÃO cobre webhooks/integração, motor de ocorrências ou segurança — isso é assunto de documentação de dev (STATUS.md, PROJETO_YUV.md)
- Sempre atualizar o STATUS.md ("arquivo de controle do desenvolvimento") ao fechar a tarefa
