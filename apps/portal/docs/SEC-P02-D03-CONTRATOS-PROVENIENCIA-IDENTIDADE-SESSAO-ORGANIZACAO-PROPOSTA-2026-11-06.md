# SEC-P02-D03 — Contratos candidatos de proveniência de identidade, sessão e organização

**Data:** 06/11/2026
**Estado:** PROPOSTA DE DETALHAMENTO DOCUMENTAL PARA DELIBERAÇÃO — NÃO HOMOLOGADA.
**Precedência:** SEC-P02-DEC-01 ratifica A de SEC02-01..09 apenas como diretrizes preliminares; PORTAL-TEC-D02 mantém logins Portal/CRM independentes; PR #19/D05 segue em aberto.

## 1. Contrato de confiança: quem pode afirmar cada campo

| Campo lógico | Origem candidata confiável | Nunca usar como prova |
| --- | --- | --- |
| actor_portal_id + identity_revision | Autenticador exclusivo Portal, após verificação e conta vigente | ID do navegador, e-mail, CNPJ, ID CRM igual, parâmetro do request |
| session_handle + session_revision | Servidor de sessão exclusivo Portal, após rotação e conclusão dos fatores | Cookie não verificado, JWT sem política, sessão de CRM |
| authentication_level | Registro server-side do desafio realmente concluído e ainda vigente | Booleano "mfa=true" do cliente, mock SEC-P01 |
| selected_org_id | Escolha do usuário como mera intenção; resolução vinculada ao ator | Slug de URL, seletor de empresa, header ou subdomínio isolado |
| current_membership + grants_revision | Repositório oficial Portal, com vínculo canônico, status e versão atuais | Instância PHP montada a partir do cliente, papel "owner" implícito |
| resource_owner_org_id | Fonte privada oficial de propriedade e FK composta por tenant | Recurso referenciado só por UUID, JSON de terceiros |
| service_entitlement | Domínio contratual próprio, vinculado à organização, estado e vigência | Menu visível, pagamento presumido, plano de CRM |
| permission_decision | Backend avaliando ator, ação e recurso já autenticados; validade curta e sem reaproveitamento | Resultado allow emitido pelo frontend ou pelo demo |
| performance_execution_permission | Autoridade do motor Performance + contrato serviço-a-serviço autenticado | Sessão Portal usada como credencial Google/Meta ou do motor |

**Obrigatório para segurança:** a autenticação determina o ator Portal; a autorização determina a capacidade específica e o recurso da organização; o banco RLS é defesa adicional. Nenhum desses fatores substitui os demais.

## 2. Contrato de estado de conta (candidato)

| Estado lógico | Pode autenticar? | Restrições e eventos |
| --- | --- | --- |
| awaiting_verification | Não abrir sessão privada | Envio/reenvio verificado e limitado; não criar empresa por coincidência de e-mail |
| eligible | Apenas com credencial verificada e fatores exigidos | Estado vigente, versão e políticas verificadas no servidor |
| suspended | Não | Bloqueia sessões e operações; trilha e motivo interno minimizado |
| deactivated | Não | Desligamento, retenção e revisão para reativar, sem auto-reingresso |
| deleted_or_anonymized | Não | Retenção conforme obrigação legal, sem manter acesso residual |

**Invariantes:** ID Portal gerado pelo servidor; revisão monotônica de identidade; status não reativado por restore antigo; alterações sensíveis com autenticação recente e alçada. Cadastro e verificação NÃO concedem membership por si sós.

## 3. Contrato de sessão (candidato)

Sequência sem execução:
1. Pedido de login produz apenas um estado de desafio com dados mínimos; resposta a usuário inexistente deve ser indistinguível.
2. Verificação da credencial primária; registrar tentativa limitada, sem senha/código em logs.
3. Fator exigido pendente: nenhuma operação de cliente nem listagem de organizações privadas permitida.
4. Confirmação válida do fator: criar ou rotacionar identificador opaco, atualizar estado autenticado e registrar autenticação server-side.
5. A cada requisição privada: consultar sessão atual e revisões vigentes de conta, vínculo, política e organização; expiração no servidor.
6. Troca de organização: nova seleção é intenção, backend resolve membership vigente e ownership do recurso, sem carregar grants de outra empresa.
7. Step-up: exigido para alteração de credencial, fator, vínculo/permissão, cobrança, contas publicitárias e demais operações sensíveis após matriz específica aprovada.
8. Logout, revogação, suspensão ou mudança crítica: invalidar sessão pertinente e credenciais derivadas, incluindo abas/dispositivos conforme política aprovada.
9. Falha de store, fonte de autorizações, clock ou revogação: negar, sem fallback para allow em cache.

**Cookies candidatos:** Secure, HttpOnly, SameSite apropriado, host/path restrito, TLS, CSRF em mutações, rotação contra fixation, sem cache de grants/segredos no navegador. Os prazos 30 min de inatividade, 12 h máximo e 5 min de step-up são REFERÊNCIAS NÃO HOMOLOGADAS. A simulação SEC-P01 usa janela arbitrária e não pode ser reaproveitada para auth real.

## 4. Contrato de decisão de acesso (proposta)

Pré-condições obrigatórias cumulativas:
- sessão Portal autenticada, íntegra, vigente, não revogada e desafio requerido concluído;
- usuário Portal ativo e revisão da identidade consistente;
- organização selecionada existe, está ativa e é vinculada por membership canônica vigente ao ator;
- permissão específica de vocabulário versionado explicitamente concedida pela fonte oficial da organização;
- recurso pertence à organização por prova do backend com FK composta (não por ID isolado ou payload cliente);
- execução sob contexto tenant autorizado, privilégio mínimo e RLS sem bypass não revisado;
- para ação sensível, sessão com step-up dentro da janela HOMOLOGADA e barreira de revogação/concorrência.

**Saída proposta:** decisão efêmera por requisição/ação/recurso. Negação sem enumeração de organizações, existência de usuários ou referências privadas. Proibir cache reutilizado de allow, autorização em jobs fora da autoridade atual ou privilégio implícito para staff, proprietário ou usuário CRM.

## 5. Segurança do isolamento PostgreSQL — contrato de interface, não desenho físico

A implementação futura deverá cumprir o modelo de banco ainda não homologado no PR #19: chave composta organization_id + resource_id, membership par canônico, grants versionados, roles limitadas e RLS por comando com ownership antigo/novo. Contexto local de transação não comprova identidade e pode ser adulterado por processo comprometido; exigir ameaça explícita SQL injection, pool, context leakage, SELECT/INSERT/UPDATE/DELETE, fila, réplica, restore e isolamento sob falha. Proibir runtime com BYPASSRLS, DDL, superuser e função de elevação genérica. Nenhuma SQL/migration aqui.

## 6. Matriz mínima de provas futuras (não realizadas)

| ID | Experimento necessário | Resultado negativo obrigatório |
| --- | --- | --- |
| IDN-01 | Identidade CRM com ID textual igual ao Portal | NUNCA ingressa como ator Portal sem prova do emissor próprio |
| IDN-02 | Sessão pré-MFA ou cookie forjado | Não lista organizações privadas, não abre painel |
| IDN-03 | Sessão revogada/expirada, em duas abas e dois dispositivos | Todos os acessos pertinentes negados, sem fixation/replay |
| IDN-04 | Ator A vinculado à empresa X tenta recurso da Y | Resposta genérica, zero leitura/escrita da empresa Y |
| IDN-05 | Troca do seletor/URL/header para empresa não vinculada | Não muda o escopo do usuário |
| IDN-06 | Revogação concorrente entre autorização e mutação | Nenhuma execução após o ponto definido de revogação |
| IDN-07 | Role/grant desatualizados, cache e réplica atrasada | Nenhuma permissão residual |
| IDN-08 | Job fora de requisição e sessão expirada | Não reutiliza token humano como identidade de serviço |
| IDN-09 | Pool entrega conexão com contexto de outro tenant | Zero vazamento, rollback/erro fechado |
| IDN-10 | Restore de backup anterior a suspensão | Suspensão prevalece, reconciliação obrigatória |

## 7. Pontos a decidir antes de implementação

**IDN-D01** — autoridade concreta de identidade: módulo Laravel Portal com fonte exclusiva e estados versionados (A recomendada); provedor externo com issuer Portal isolado (B); autenticação federada (C, não aprovada).
**IDN-D02** — regra de sessão: store revogável server-side e rotação por eventos (A recomendada) versus tokens stateless com revogação mais complexa (B).
**IDN-D03** — consulta de vínculo: fonte oficial e revisão por operação sensível (A recomendada) versus cache condicionado a invalidação comprovada (B).
**IDN-D04** — fronteira de serviço: identidade de pessoa Portal sem sessão automática CRM/Performance (A recomendada) versus SSO futuro específico (B, requer nova análise).
**IDN-D05** — situação de conta e último gestor: impedir reativação e elevação automáticas até contrato de recuperação/contingência (A recomendada) versus delegação temporária com dois aprovadores (B, dependente de alçadas).

Nenhuma alternativa IDN-D01..05 está aprovada pela DEC-01. Exigem parecer Segurança/Arquitetura e correlação explícita com G-BB03/04/05/06, D02-SEC e P0 antes de laboratório, código ou login.
