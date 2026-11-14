# P0 — Comercial → Portal → Sites → CRM com três bancos e contatos fictícios

## Novidade

A prova anterior da PR #696 termina corretamente com **zero leads CRM**, porque apenas o formato dos metadados foi verificado. O novo candidato testa uma **segunda fase explicitamente sintética** com persistência CRM, sem relaxar aquela negativa.

A mesma execução GitHub Actions, com **código de quatro repositórios reais pinados**, faz:

1. Compilar site Astro Comercial e clicar no navegador nos quatro botões de contratação.
2. Concluir pedidos fictícios HTTP Laravel/CSRF e briefings em `portal_purchase_rehearsal` (PostgreSQL18 descartável).
3. Admitir apenas Onepage e Landing no segundo banco `sites_f360_lab`, assinar comandos com chaves de teste, gravar dois eventos outbox e confirmar ACK via trabalhador fictício restrito.
4. Continuar validando a fronteira de **metadados não-autorizados** Sites→CRM: zero submissões permitidas quando consentimento/binding não foram comprovados.
5. **Em fase separada**, carregar a implementação CRM P0 de laboratório na SHA fixa `50c1eb574aa0ed81055dc256e77302acd1781d71`, criar terceiro PostgreSQL18 `crm_p0_consent_lab`, configurar dois bindings **artificiais** pelo superusuário do CI e emitir uma simulação de consentimento positivo para dois **e-mails reservados `@example.invalid`**, um por evento Sites já ACKado.
6. Exigir duas gravações CRM duráveis e idempotentes, bloqueio de duplicação, MAC adulterado, mudança de empresa, submissão alterada, consentimento ausente, expiração e e-mail real.

A correspondência entre os dois eventos Site e os dois bindings CRM é **posicional, inventada explicitamente para a fixture**. Não é prova de identidade de empresa, integração de serviços, autorização de cliente ou consentimento LGPD. Nenhuma captura de formulário de visitante real ocorre.

## Relatórios

O workflow preserva os três artefatos anteriores e acrescenta `imagine-p0-four-project-fake-consented-crm-pg18`, JSON sanitizado com SHA256 de integridade (não assinatura), contagens de quatro pedidos, dois projetos, dois ACKs e dois leads fictícios; flags de liberação todas falsas e `NO_GO`. Arquivo retido por sete dias no GitHub Actions.

## Impedimentos para ativação

Antes de ativar captação de qualquer informação pessoal, faltam API service-to-service com issuer validado e transporte protegido, vínculo assinado de serviço/tenant/cliente, consentimento com prova de aceitação e finalidade e texto LGPD versionado, revogação e antirreplay externos, resposta durável idempotente, filas e retry real, política de privacidade e retenção, IAM/MFA e propriedade individual dos pedidos, gateway/contrato reais, homologação privada na VPS e revisão de segurança independente.

A implementação CRM importada está **DRAFT**, o Portal continua em vários branches não consolidados, o banco da prévia atual contém ainda GRANT SELECT compartilhado e a revogação anti-rollback não está aprovada. **Nunca interpretar os leads falsos como autorização de publicação, faturamento ou processamento de dados reais**.

**Status: DRAFT/NO-GO. Sem merge automático, VPS, DNS, dados reais ou custos externos.**
