# Plano: Redesign do Dashboard com DESIGN.md (Cursor Design System)

## Objetivo
Substituir o visual Bootstrap 5.3 padrão do dashboard pelo design system Cursor-inspired descrito em DESIGN.md.

## Arquivos a modificar

### 1. `web/dashboard_template.php` — Reescrita completa do CSS + HTML
- **CSS**: Substituir todas as classes visuais Bootstrap (bg-*, btn-*, badge, card, table, shadow, alert) por tokens do DESIGN.md via CSS custom properties + classes próprias
- **HTML**: Adaptar estrutura para o novo visual (cards de alarme, protocol toggle como pills, tabela com hairlines)
- **Manter**: PHP logic, Bootstrap grid (row/col), utilitários de espaçamento (gap-*, mb-*, p-*), tabs Bootstrap (data-bs-toggle), modals, flv.js

### 2. `web/assets/js/dashboard.js` — Atualização de classes
- Substituir referências: `cs-pending/sent/executed/failed` → `ds-cmd-pending/sent/executed/failed`
- Substituir referências: `src-alarm/src-dashboard` → `ds-origin-alarm/ds-origin-dashboard`
- Atualizar `bg-${color}` dinâmico → `ds-pill-${color}`
- Adicionar novo protocol toggle (pill selector substitui radio buttons)
- Sincronizar com funções existentes do template

### 3. `handlers/dashboard.php` — Sem alterações
O controller permanece idêntico.

## Changelog visual

| Componente | Antes | Depois |
|---|---|---|
| Cor de fundo | #f0f2f5 (cinza) | #f7f7f4 (cream) |
| Tipografia | System UI | Inter 400/500/600 + JetBrains Mono |
| Cor primária | #0d6efd (azul) | #f54e00 (laranja) |
| Profundidade | Shadows | Hairlines 1px |
| Radius | rounded-pill | 8px (CTA), 12px (cards) |
| Status pills | Bootstrap badges | Timeline pastels |
| Tabs | Bootstrap nav-tabs | Editorial nav com underline laranja |
| Navbar | bg-dark | bg-canvas cream |
| Tabelas | table-hover com zebra | Hairline borders, hover suave |
| Alarmes | Tabela densa | Cards individuais com borda de severidade |
| Forms | Bootstrap form-control | ds-input (44px, 8px radius) |
| Code blocks | bg-dark | ds-code-block (canvas-soft) |

## Tokens aplicados (DESIGN.md)

- `primary` #f54e00 → CTAs, tab ativa, links
- `ink` #26251e → Títulos, texto forte
- `body` #5a5852 → Texto corrido
- `muted` #807d72 → Labels, metadados
- `canvas` #f7f7f4 → Fundo da página
- `surface-card` #ffffff → Cards, tabelas
- `hairline` #e6e5e0 → Bordas
- `timeline-*` → Status pills (pending=thinking, ACC ON=grep, JTT=read, executed=done)
- `semantic-error` #cf2d56 → Critical severity
- `semantic-success` #1f8a65 → Online indicators

## Verificação
- Abrir /dashboard em um navegador
- Conferir se todas as 5 abas renderizam corretamente
- Verificar refresh silencioso de câmeras (pulsing dot)
- Testar envio de comando (JIMI + JTT)
- Testar modal de detalhes do comando
- Testar VIDEOUPLOAD na aba Alarmes
- Testar galeria de mídia
- Testar consulta/alteração de parâmetros na aba Configuração
- Verificar responsividade em viewport mobile
