# PORTAL-PAY-03 — Tela de administração de gateways (preview sem rota)

Demonstração Blade somente para revisão visual, empilhada nas PRs #784 e #788.

A view \`portal.preview.gateway-routing\` mostra:
- estado Asaas/C6, modo manual ou menor custo elegível;
- modalidades e situação por PSP, sem credenciais, sem tarifas comerciais reais;
- proteção de cobranças pré-existentes e obrigação de conciliar timeout;
- identidade de referência #F9941D e avisos explícitos de ambiente fictício.

**Sem rota HTTP, formulário ou script de ação. Não há qualquer alteração no checkout, nas permissões, no banco ou na VPS.**

Próximo gate real: política armazenada com revisão imutável; RBAC e MFA do administrador; operação autenticada de salvar, auditar e publicar alterações; comparação com tarifas comerciais conferidas; provas Asaas/C6 de sandbox e rollback. Não associar a view a páginas públicas ou ao admin sem validação de autenticação/IAM.

Teste: \`cd apps/portal && php artisan test --filter=PortalMultiGatewayAdminPreviewTest\`.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE.**
