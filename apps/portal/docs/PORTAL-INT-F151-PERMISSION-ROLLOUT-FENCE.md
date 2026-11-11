# PORTAL-INT-F151 — Separar permissão conhecida de permissão liberada no banco

A integração F150 reúne duas mudanças de vocabulário de permissão:
- `seo.gsc.connect`: reconhecida pelo domínio e prevista no CHECK da migração de grants do SEO, **mas não concedida automaticamente**.
- `commercial.delivery-review.view`: reconhecida pelo domínio para futuro preflight da fila comercial, mas **ainda não é elegível para uma concessão real no CHECK atual**. Isso é intencional até revisão AppSec/DBA da autoridade servidora e do fluxo de auditoria.

Esta PR acrescenta uma suíte PHPUnit executada na candidata integrada, que protege contra quatro classes de regressão:

1. Congela o conjunto fechado de seis códigos de `Permission`; nenhuma permissão coringa ou de outro sistema.
2. Exige que o esquema de grants atual permita somente os quatro códigos originais e o novo `seo.gsc.connect`, e **não** inclua a permissão comercial sem aprovação de migração controlada.
3. Verifica que as migrações de identidade/SEO preservam o CHECK, que rollback com grant SEO ativo é recusado e que não há rotina de **inserção automática de grants** nos arquivos de migration existentes.
4. Exige que o `AppServiceProvider` continue ligando sessão, associação e propriedade de recursos às autoridades `DenyingPortalAuthorities`, sem usar presença da permissão enum como autorização de operação.

**Limite técnico:** é um teste de contrato de **código-fonte**. Ele não comprova privilégios reais em PostgreSQL, RLS ou segurança da configuração em staging. Os jobs PostgreSQL18 e revisões independentes seguem sendo obrigatórios; o teste não substitui essas provas.

**NO-GO:** nenhuma permissão foi ativada, nenhuma migração real executada, nenhum usuário ou negócio recebeu grant. Não faz merge na `dev` ou `main`, não publica o Portal e não gera despesas. Alteração posterior do CHECK requer revisão específica AppSec/DBA e novos testes de revogação e downgrade.
