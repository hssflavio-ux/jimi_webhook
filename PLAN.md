# Plano de Implementação - Próximos Passos (v3.2.0)

Este plano detalha as melhorias necessárias no sistema Jimi Webhook, focando em resolver os problemas de auto-refresh do mapa ao vivo, expurgar arquivos legados, adicionar auto-refresh no dashboard e introduzir gerenciamento de usuários.

## User Review Required

> [!IMPORTANT]
> A API `/camerasdata` atualmente não filtra os dispositivos por cliente no banco de dados. Isso expõe dados de múltiplos clientes e viola o princípio de multi-tenancy. Vamos implementar a verificação de sessão e filtragem por `customer_id` neste endpoint.

## Open Questions

> [!NOTE]
> Há alguma restrição quanto à exclusão imediata dos arquivos legados da v2.0.0 (`web/dashboard_template.php`, `web/assets/js/dashboard.js`, e `includes/dashboarddata.php`)? Caso positivo, podemos movê-los para um diretório `archive/` em vez de excluí-los.

---

## Proposed Changes

### 1. Correção do Endpoint de Câmeras e Auto-Refresh do Mapa Ao Vivo

Melhorar o endpoint `/camerasdata` para dar suporte tanto à autenticação de token padrão (usando `token` ou `_token`) quanto à sessão ativa do usuário autenticado no navegador (via cookie). Além disso, enriquecer o JSON de retorno com os campos de geolocalização necessários (`lat`, `lng`, `acc`, `last`) e filtrar por cliente (`customer_id`).

#### [MODIFY] [camerasdata.php](file:///c:/Users/flavi/Documents/Antigravity/jimi_webhook/handlers/camerasdata.php)
- Inicializar autenticação via `auth_init()` para carregar a sessão do usuário.
- Se houver sessão de cliente ativa (`get_customer_id()`), filtrar a query de dispositivos por `customer_id`.
- Aceitar token via query parameter `token` além do `_token` e `X-Dashboard-Token`.
- Adicionar no array de saída de cada dispositivo:
  - `lat` (latitude)
  - `lng` (longitude)
  - `acc` (status da ignição/ACC)
  - `last` (data/hora formatada da última comunicação)

---

### 2. Auto-Refresh no Mapa do Dashboard Principal

Atualmente, o mapa do Dashboard principal carrega as posições iniciais dos ativos, mas não se atualiza automaticamente em segundo plano.

#### [MODIFY] [dashboard.php](file:///c:/Users/flavi/Documents/Antigravity/jimi_webhook/handlers/dashboard.php)
- Adicionar polling AJAX no frontend a cada 30 segundos chamando `/camerasdata` (semelhante ao `live.php`).
- Atualizar a tabela de dispositivos do Dashboard dinamicamente.
- Atualizar as posições e cores dos markers (círculos) no mapa Leaflet sem recarregar a página.

---

### 3. Limpeza de Arquivos Legados (v2.0.0)

Remover arquivos órfãos da versão anterior que não são mais utilizados no fluxo NavTrack atual.

#### [DELETE] [dashboard_template.php](file:///c:/Users/flavi/Documents/Antigravity/jimi_webhook/web/dashboard_template.php)
#### [DELETE] [dashboard.js](file:///c:/Users/flavi/Documents/Antigravity/jimi_webhook/web/assets/js/dashboard.js)
#### [DELETE] [dashboarddata.php](file:///c:/Users/flavi/Documents/Antigravity/jimi_webhook/includes/dashboarddata.php)

---

### 4. Gestão de Usuários (Administração)

Criar tela de gerenciamento de usuários vinculados aos clientes, permitindo criar, editar e desativar usuários.

#### [NEW] [usuarios.php](file:///c:/Users/flavi/Documents/Antigravity/jimi_webhook/handlers/usuarios.php)
- Rota: `/usuarios` (Apenas Administrador)
- Listar usuários, seus respectivos clientes associados e funções (`admin`, `manager`, `viewer`).
- Formulário modal para criar e editar usuários (com hashing de senha via `password_hash`).
- Ação para ativar/desativar usuários (`is_active = 0`).

#### [NEW] [perfil.php](file:///c:/Users/flavi/Documents/Antigravity/jimi_webhook/handlers/perfil.php)
- Rota: `/perfil` (Qualquer usuário logado)
- Visualizar dados pessoais (Nome, E-mail, Cliente ativo, Função).
- Formulário simples para alteração de senha (validação de senha atual + nova senha).

#### [MODIFY] [router.php](file:///c:/Users/flavi/Documents/Antigravity/jimi_webhook/handlers/router.php)
- Registrar as novas rotas `/usuarios` e `/perfil` no Front Controller.

---

## Verification Plan

### Automated Tests
*Não há suíte de testes automatizados. O projeto segue validação por análise estática e testes manuais.*
- Executar lint de todos os arquivos modificados:
  ```bash
  php -l handlers/camerasdata.php
  php -l handlers/dashboard.php
  ```

### Manual Verification
1. **Teste do Auto-Refresh do Mapa Ao Vivo**:
   - Fazer login e acessar `/live`.
   - Verificar no console de rede do navegador se a requisição AJAX a `/camerasdata` ocorre a cada 30 segundos com sucesso (HTTP 200).
   - Verificar se as coordenadas GPS de teste mudam e se o mapa move os marcadores corretamente.
2. **Teste de Multi-Tenancy em /camerasdata**:
   - Acessar o endpoint `/camerasdata` logado como Cliente A e verificar se somente os dispositivos do Cliente A são exibidos.
   - Trocar de contexto na sidebar para o Cliente B, chamar novamente o endpoint e garantir que somente os do Cliente B apareçam.
3. **Teste do Auto-Refresh no Dashboard**:
   - Acessar `/dashboard` e validar se a tabela e o mapa também se atualizam a cada 30 segundos em segundo plano.
4. **Teste de Gestão de Usuários**:
   - Logar com usuário não-admin e tentar acessar `/usuarios` diretamente. Deve retornar 403 (Acesso Restrito).
   - Logar com admin, acessar `/usuarios`, cadastrar um novo usuário, vinculá-lo a um cliente e fazer login com esse novo usuário para testar.
