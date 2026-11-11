# PORTAL-INT-F157 — Validação cruzada de revogação em duas empresas (PostgreSQL18)

A F154 implementa uma proteção de revisão da **associação usuário–empresa** quando papel, status ou grants mudam. A F156 protege a revisão da **organização** quando seu estado muda. Cada migração tinha uma prova isolada PG18; faltava validar as duas no **mesmo banco e com o mesmo usuário pertencendo simultaneamente a empresas A e B**.

Este incremento executa as migrações reais F66, F76, F113 (vocabulário GSC), F154 e F156 em PostgreSQL18 descartável, PHP8.3 e PHP8.4, sem alterar ambientes operacionais. Cria um usuário fictício com vínculo em duas empresas, duas sessões com hashes de teste distintos e revisões iniciais 1.

A bateria verifica que:
- Conceder `services.view` ao vínculo A aumenta somente a revisão do vínculo A para 2, deixando B na revisão 1;
- Suspender a empresa A aumenta apenas sua revisão de segurança a 2; a sessão anterior de A fica defasada **em ambas as revisões**, enquanto B permanece com vínculo e organização em revisão 1 e status ativo;
- Suspender o vínculo B aumenta somente sua revisão de associação para 2, deixando a revisão da empresa A e do vínculo A intactas;
- Cada tabela sensível mantém `FORCE ROW LEVEL SECURITY` e **nenhuma policy de acesso de aplicação**;
- Após o teste, remove somente os fixtures e valida o rollback ordenado das migrações, restaurando o esquema vazio do runner.

As verificações do script usam um usuário PostgreSQL administrador **somente no contêiner descartável** para inspecionar os registros sintéticos; **não** autorizam a futura aplicação a consultar como superuser nem criar RLS ALLOW. A propriedade de isolamento testada é a revisão correta por empresa, e não o acesso operacional de clientes.

**NO-GO:** a aplicação continua usando `DenyingPortalAuthorities`; o domínio não possui issuer confiável, locks de revogação homologados, prova de Google OAuth, CRM operacional nem endereço de produção. Revisões AppSec/DBA/LGPD e homologação da VPS ainda são necessárias antes de qualquer migração ou publicação real.
