---
name: deploy
description: Deploy do jimi_webhook para produção (bycamera.ia.br) ou homologação — o comando com sudo, qual chave SSH funciona de qual máquina, a chave do GitHub sob sudo em produção, e o bump obrigatório de SYSTEM_VERSION. Use ao subir código, rodar deploy.sh, fazer rollback, ou diagnosticar falha de git fetch/SSH no servidor.
---

# Deploy

Os dois ambientes (endereços, stack e papel de cada um) estão na §"Ambientes" do
`CLAUDE.md`. 🔴 Lembre: `189.22.240.43` é **homologação**, não produção.

- **Deploy** (idêntico nos dois): `ssh -t administrador@<ip> "cd /var/www/jimi_webhook && sudo ./scripts/deploy.sh"`. O `sudo` **pede senha** — daí o `-t`, senão a sessão morre sem prompt.
- **Chave SSH em produção (19/08/2026)**: **duas** chaves autorizadas em
  `/home/administrador/.ssh/authorized_keys` — a do Mac de dev (`claude-code`,
  `SHA256:jGEHWet…`) e a da máquina **Windows** (`flavi@dev-windows-jimi`,
  `SHA256:lHHRen2…`, instalada nesta data). As duas máquinas entram por chave.
  No **homolog** só a do Windows está instalada, então do Mac ele recusa com
  `Permission denied (publickey,password)` — **não é senha errada**.
  ⚠️ `authorized_keys` fica no HOME do `administrador`, fora do git: sobrevive a
  deploy, mas some se a máquina for reprovisionada. Backup em
  `~/.ssh/authorized_keys.bak-20260819`.
- **Se a chave for recusada**, o servidor aceita senha (`publickey,password`), e
  do Windows dá para deployar sem prompt interativo com o `plink` do PuTTY
  (`C:\Program Files\PuTTY\plink`), que aceita `-pw`:
  `plink -batch -ssh -hostkey <SHA256 do host> -l administrador -pw <senha> <ip> "cd /var/www/jimi_webhook && printf '%s\n' <senha> | sudo -S -p '' ./scripts/deploy.sh"`
  O `-hostkey` vem de `ssh-keygen -l -F <ip>` no `known_hosts` do OpenSSH — o
  PuTTY tem cache próprio, e sem isso o `-batch` recusa o host desconhecido.
  ⚠️ A sessão do plink abre no HOME, **não** no diretório do projeto: sem o `cd`
  o erro é `sudo: ./scripts/deploy.sh: command not found`.
- 🔴 **O `sudo` continua pedindo senha mesmo entrando por chave** — são coisas
  diferentes. Para deploy não-interativo: `printf '%s\n' <senha> | sudo -S -p '' ./scripts/deploy.sh`.
- **Em produção a chave do GitHub é do `administrador`, não do root** — e o `deploy.sh` roda sob `sudo`. Sem `core.sshCommand` no `.git/config` apontando para `/home/administrador/.ssh/id_ed25519` (+ o `known_hosts` dele), a FASE 1 morre em `✗ FALHA: git fetch falhou` e a mensagem sugere criar uma chave nova, que é o conserto errado — o erro real é `Host key verification failed`. Configurado em 14/08/2026; **some se o repo for reclonado** (detalhe na §8 do `STATUS.md`).
- **A versão anunciada mora no `.env.example`** (`SYSTEM_VERSION`), e o `deploy.sh` a propaga para o `.env` do servidor. Subir código sem subir esse número faz o `/ping` anunciar a versão antiga com o código novo no ar — foi o que as v4.9.18 e v4.9.19 fizeram.

## Flags e rollback

```bash
./scripts/deploy.sh                 # normal
./scripts/deploy.sh --force         # redeploy with no code changes
./scripts/deploy.sh --skip-migrate  # skip DB migration
./scripts/rollback.sh <TIMESTAMP>   # restore a backup from /var/backups/jimi_webhook
```

O `deploy.sh` faz: backup → git pull → migrate → chmod → `php -l` → smoke test em `/ping`.

🔴 **Quando a entrega altera o próprio `deploy.sh`** (uma migração nova entra na
lista explícita da FASE 3b), rode **duas vezes**:
`./scripts/deploy.sh && ./scripts/deploy.sh --force`. O `git pull` acontece no
MEIO do script, então a primeira passada roda um script que foi trocado embaixo
dela. Na v4.9.32 a migração nova até pegou já na primeira passada (o bash relê o
arquivo a partir do offset atual), mas isso é acidente de layout do arquivo, não
garantia — a segunda passada é o que confirma o script novo de ponta a ponta.

⚠️ **A migração nova tem de entrar na lista do `deploy.sh`.** Elas são chamadas
uma a uma, explicitamente; uma migração fora dessa lista faz o deploy passar
verde com a tabela inexistente, e a funcionalidade quebra só em produção.

**Verificar o resultado, não a saída do deploy.** O script diz "CONCLUÍDO" com
base no `/ping`; ele não sabe se a tela nova renderiza. Técnica que funciona sem
sudo: escrever um `.php` local, `pscp` para `/tmp`, `plink ... php /tmp/x.php`,
`rm` no fim — o script usa `Database::getInstance()` e pode até criar uma sessão
temporária para renderizar a tela autenticada por `curl` no localhost do próprio
servidor (apagando-a no fim). HTTP 200 não vê tela vazia.
