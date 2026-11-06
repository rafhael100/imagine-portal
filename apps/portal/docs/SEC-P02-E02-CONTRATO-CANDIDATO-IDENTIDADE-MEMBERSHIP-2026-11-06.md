# SEC-P02-E02 — Contrato técnico candidato para identidade Portal e vínculo canônico

**Data:** 06/11/2026. **Estado:** candidato à revisão técnica; NÃO homologado, NÃO implementado.
**Fonte de decisões:** SEC06-D01..04 ratificadas documentalmente na DEC-04, SEC-P02-D03/D05, PR #19 G-BB03/04 e D05.

## A. Origem confiável do ator

Proposta: autenticador exclusivo do Portal, integrado ao backend Laravel e a fonte oficial de identidade do Portal. Só a autoridade servidora pode emitir prova de login: ID local opaco, status da conta, revisão de identidade, revisão de credencial, revisão de fatores e sessão server-side válida, verificados junto a um fator exigido pela política futura. Strings, e-mail/CNPJ, ID de CRM com mesmo texto, campos de requisição, cookie não validado e classes DemoPortalSession/PortalUserId construídas em memória NÃO são certificados de origem.

Campos LÓGICOS candidatos (não schema SQL): identificador local gerado no servidor; estado de verificação; estado da conta; revisões monotônicas de identidade/credenciais/MFA; auditoria mínima de revogação. O emissor preciso, parâmetros de estado, tipo UUID e bootstrap do primeiro gestor estão pendentes de parecer.

Nenhum login humano Portal autoriza automaticamente sessão CRM, Performance AI, Universidade ou Google/Meta. Identidade de integração serviço-a-serviço tem contrato separado.

## B. Fonte canônica de membership, poderes e proprietário

| Conceito | Invariante candidato | Falha negada |
| --- | --- | --- |
| Membership ator+organização | Par canônico com ID, status e revisão, concedido por fluxo expresso | Mesmo e-mail ou CNPJ não cria vínculo |
| Estados | Invited/active/suspended/revoked; só active concede acesso, após demais verificações | Convite, suspensão, revogação, expiração |
| Grants | Papéis locais versionados e permissões explícitas, separando concedente de alvo | Owner/staff sem privilégio implícito; nenhuma autoelevação |
| Empresa selecionada | Pedido não confiável de contexto; resolver no backend junto ao ator | Header/URL de outro tenant não troca privilégios |
| Recurso | Titularidade em fonte privada, chaves compostas por organização | ID de recurso desacompanhado do tenant não é prova |
| Revogação | Barreira de concorrência e revisão, impedindo novos efeitos após ponto definido | Cache antigo, job atrasado ou sessão velha não autorizam |
| Último gestor | Impedir perda não recuperável ou elevação automática; alçada separada e auditável | Recuperação de staff sem verificação independente |
| Restore | Reconciliação obrigatória das versões e revogações | Backup antigo não reativa direitos |

## C. Contrato candidato de requisição (sem endpoint)

1. Validar HTTPS, sessão opaca do próprio Portal e fatores exigidos; não aceitar objeto de demo como sessão.
2. Resolver identidade ativa e revisões de credenciais/MFA no backend. Se fonte indisponível ou inconsistente: NEGAR.
3. Resolver membership canônica e poderes vigentes da empresa selecionada; empresa é intenção, não autoridade. Falha de estado/vínculo: NEGAR.
4. Resolver proprietário do recurso e permissão granular específica, nunca apenas ocultar menu. Fora do tenant: NEGAR.
5. Para efeito sensível, revalidar MFA recente, alçada e revisão; garantir barreira perante revogação antes de escrita. Erro de concorrência: NEGAR/ABORTAR.
6. Executar sob transação com escopo tenant e role SQL mínima, com RLS como segunda barreira. Falha do pool/contexto: ROLLBACK, sem acesso cruzado.
7. Registrar trilha mínima conforme finalidade aprovada, sem senhas, cookies, sementes MFA ou tokens.

**Limite de confiança:** RLS/SET LOCAL não provam quem fez login; injeção SQL e runtime comprometido exigem modelagem própria. Revisão independente de roles e função mínima é condição anterior a dados privados.

## D. Matriz adversarial para revisão (NÃO executada)

| Caso | Simulação futura | Critério verificável |
| --- | --- | --- |
| AUTH-01 | Usuário CRM e Portal com mesmo ID textual | Nenhum SSO, membership ou sessão automática |
| AUTH-02 | Sessão pré-MFA, expirada, rotacionada ou revogada | Nenhum recurso privado |
| AUTH-03 | Manipular empresa na URL/header | Zero leitura/escrita em outra empresa |
| AUTH-04 | Membership convidada, suspensa ou revogada | Negação inclusive após cache |
| AUTH-05 | Concedente tenta tornar-se owner ou dar permissão superior | Negação/rollback e auditoria |
| AUTH-06 | Revogação e escrita simultâneas | Nenhum efeito posterior à barreira |
| AUTH-07 | Pool devolve conexão de outra organização | Nenhum resíduo de tenant, leitura ou escrita |
| AUTH-08 | Job/export atrasado após revogação | Revalidar/cancelar, sem novos efeitos |
| AUTH-09 | Restore antigo com grant removido | Impedir reativação sem reconciliação |

**Evidência ainda faltante para o parecer:** versão/forma do emissor, fonte de memberships e estados, política do último gestor, algoritmo de barreira/retry, revisão de níveis de privilégio e mapeamento a testes H/C/P do PR #19. Não há autorização de SQL, migrations, cookies, login, banco, laboratório, main, merge ou deploy.

**Próximo passo:** revisão técnica por Identidade, Segurança, Dados/DBA e Backend, com risco classificado e decisões abertas apontadas por item. O documento é uma especificação candidata em papel, não prova de funcionamento.