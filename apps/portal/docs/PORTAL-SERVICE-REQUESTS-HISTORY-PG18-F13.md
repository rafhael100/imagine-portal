# Imagine Portal — solicitações vinculadas a projetos, com histórico transacional

## Entrega de dados PostgreSQL 18 (sem rotas)

A migration `2026_11_13_000021_create_portal_service_request_candidates.php` acrescenta as tabelas `portal_service_requests` e `portal_service_request_transitions`, vinculadas a um projeto e uma organização por chave estrangeira composta. Cada solicitação possui referência única dentro da organização, status e revisão monotônica. Esses são **candidatos de laboratório**, sem cliente autenticado nem execução de atendimento.

As transições seguem o mesmo desenho do domínio `ServiceRequestWorkflowPreview`, com sete caminhos permitidos:

- `draft → submitted`
- `submitted → in_review`
- `in_review → waiting_on_customer`
- `waiting_on_customer → in_review`
- `in_review → resolved`
- `resolved → in_review` (revisão)
- `resolved → closed`

`closed` é terminal. Tentativas de pular etapas, alterar o identificador, a referência, a empresa, o projeto ou a revisão são rejeitadas pelo banco. Uma alteração aprovada cria automaticamente, na **mesma transação**, uma linha na tabela de transições, contendo estado anterior/novo, revisão e horário do banco. O evento inicial de rascunho também é registrado. A tabela de histórico rejeita INSERT direto, UPDATE e DELETE comuns, mas não é proteção criptográfica contra um administrador privilegiado e **não registra identidade humana nem aprovação assinada**.

## Isolamento e testes

Ambas as tabelas têm `ENABLE/FORCE ROW LEVEL SECURITY`, **zero policies permissivas**, nenhum grant ao papel de execução do navegador e nenhuma rota HTTP. O banco apenas permite inserir ou transicionar em projetos ainda disponíveis e de empresas ativas, sempre sob lock de consulta. Testes em PostgreSQL 18 descartável com PHP 8.3/8.4 cobrem ciclo de estados, trilha sequencial, usuário sem permissões, empresa errada, projeto inexistente ou retirado, status inicial inválido, alteração de revisão/escopo e tentativa concorrente de atualizar o mesmo registro.

## Limites

A trigger de estados **não autentica quem executou** a transição; apenas a valida como candidata técnica. O papel de runtime continua sem qualquer acesso a essas tabelas. Fluxo real de solicitações exige vinculação a identidade Portal/MFA, permissões e RLS verdadeiramente autorizado, idempotência externa, prova de ativo/contrato, entrega de arquivos, antifraude, segurança de restauração independente (#64), homologação e validação AppSec/DBA (#341).

Sem gasto extra, deploy, merge em `main`/`dev`, API ou alteração de VPS. PR DRAFT/NO-GO.
