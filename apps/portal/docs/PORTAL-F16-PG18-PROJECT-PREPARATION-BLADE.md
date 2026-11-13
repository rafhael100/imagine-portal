# Imagine Portal F16 — checklists PG18 de Onepage, Landing Page e Performance AI até a interface

A mesma árvore de integração agora demonstra o caminho **PostgreSQL 18 → checklists por produto → tabela Blade acessível** para três produtos de exemplo. O `DisposablePg18ProjectPreparationReader` existe **apenas em `tests/Support`**, recebe conexão privilegiada exclusivamente do banco efêmero de CI e não é resolvido pelo container de produção. Produz um `DisposablePg18ProjectPreparationResult` limitado a dez linhas, contendo serviço, projeto, estado de preparação, número de itens revisados e pendências; nenhuma linha concede contratação, publicação ou anúncio.

## Regras verificadas

- **Onepage:** `briefing`, `content`, `domain`. **Landing Page:** `briefing`, `content`, `consent`. **Performance AI:** `goals`, `ad_accounts`, `creatives`, `budget`.
- Os registros vêm de duas consultas PostgreSQL na **mesma transação `REPEATABLE READ, READ ONLY`**, filtradas por `organization_id` e IDs de projeto da própria empresa.
- Rejeita organização não ativa, projeto retirado, claim vinculado que tenha sido retirado, produto/código de checkpoint fora do contrato, checkpoint de empresa diferente ou duplicado, projeto em revisão com itens incompletos, mais de dez projetos ou quarenta checkpoints.
- Um resultado incompleto retorna `invalid_preview_input` e **lista vazia**, sem expor registros parciais, qualquer que seja o número de registros válidos já encontrados. Isso evita confundir ausência de evidências com um serviço pronto para uso.
- O resultado pode ser renderizado pelo componente `<x-portal.table>` com `caption`, escopo de cabeçalhos, foco de teclado e navegação no layout aprovado. O teste verifica **nenhuma rota HTTP**, nada de formulário, script ou dados da empresa estranha.
- O role SQL de execução do Portal continua sem acesso a estas tabelas e não consegue usar o leitor para contornar grants/RLS. Não há alteração de migrações, políticas SQL, credenciais ou runtime.

A suíte `PortalPg18ProjectPreparationProjectionTest` cobre as três especialidades, isolamento entre empresas, projeto retirado, empresa suspensa, origem retirada após vínculo, limite de projetos, IDs inválidos, role runtime bloqueado, transação concorrente do caller e renderização Blade.

**Limite operacional:** não equivale a um painel real de cliente; as telas existentes continuam com dados fictícios. Não comprova login, contrato, domínio, autorização de campanha, saldo ou permissão universitária. Necessitam-se identidade Portal/MFA, RLS/grants autenticados, serviço externo comprovado, anti-rollback independente (issue #64), homologação integrada (issue #341) e revisão AppSec/DBA. DRAFT, sem merge em main/dev, deploy/VPS ou custos.
