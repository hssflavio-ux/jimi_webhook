---
name: status-archive
description: Arquiva entradas antigas do log cronológico no topo do STATUS.md (blocos "ESTADO EM DD/MM" ou sessão avulsa datada) para docs/status-history/, mantendo só as 3 mais recentes inline. Use quando o STATUS.md crescer demais, antes de uma sessão de trabalho longa, ou quando o usuário pedir para "arquivar o status" / "compactar o histórico".
---

# Status Archive

`STATUS.md` tem duas partes bem diferentes, nessa ordem:

1. **Log cronológico não estruturado**, do topo do arquivo até a primeira
   linha que casa `^## ` — hoje isso é uma seção tipo `## 0. Iniciativa...`,
   mas o número muda com o tempo; **localize sempre com grep, nunca hardcode
   a linha**. Dentro desse trecho, cada sessão de trabalho vira um bloco que
   começa em `> ### <emoji> ...` contendo uma data `DD/MM/AAAA` (formatos
   observados: `ESTADO EM DD/MM/AAAA — ...`, ou sessão avulsa tipo
   `DD/MM/AAAA — 🔴 ...`, ou `vX.Y.Z (DD/MM/AAAA) — ...`), e termina na linha
   `---` solta (fora do blockquote) que vem antes do próximo bloco datado.
2. **Conteúdo estrutural/de referência**, a partir daquele primeiro `## `:
   arquitetura, rotas, schema do banco, seções numeradas de iterações já
   organizadas etc. **Nunca mexer aqui** — não é log, é documentação viva.

Só a parte 1 cresce sem limite (é anexada no topo a cada sessão e nunca
arquivada). É ela que essa skill compacta.

## Procedimento

1. `grep -n "^## " STATUS.md | head -1` para achar onde a parte 1 termina.
2. Dentro da parte 1, `grep -n "^> ### .*[0-9]\{2\}/[0-9]\{2\}/[0-9]\{4\}"` para
   listar os blocos datados, do mais recente (topo) para o mais antigo.
3. Mantenha os **3 primeiros blocos** (mais recentes) exatamente como estão.
4. Pegue tudo do 4º bloco em diante até a linha anterior ao primeiro `## `
   (ou seja, o restante inteiro da parte 1) e **prependa** esse trecho —
   sem resumir, sem reescrever, cópia literal — ao topo de
   `docs/status-history/STATUS_ARCHIVE.md` (crie o arquivo com o cabeçalho
   `# Histórico de STATUS.md` se ainda não existir).
5. No STATUS.md, no lugar de onde o conteúdo saiu, deixe uma única linha:
   `> Entradas anteriores a DD/MM/AAAA arquivadas em docs/status-history/STATUS_ARCHIVE.md.`
   (a data é a do 3º bloco mantido).
6. Confira que o STATUS.md resultante ainda abre com `# STATUS.md — ...` e
   que os blockquotes/headers não ficaram quebrados (nenhuma linha `>` órfã
   no meio de texto normal).

## Regras

- **Nunca resuma o conteúdo movido.** É arquivamento, não compressão com
  perda — causas-raiz, medições em câmera real e números ficam intactos,
  só saem do caminho de leitura padrão.
- **Nunca toque na parte estrutural** (seções numeradas `## 0.` em diante).
  Ela já é referência organizada, não faz parte do problema que essa skill
  resolve.
- `CHANGELOG.md` não entra nessa skill — no formato Keep a Changelog o
  histórico completo é o ponto, e o tamanho atual (~25 linhas/versão) está
  normal.
- Depois de arquivar, rode `wc -l STATUS.md docs/status-history/STATUS_ARCHIVE.md`
  para confirmar visualmente que o corte aconteceu.
