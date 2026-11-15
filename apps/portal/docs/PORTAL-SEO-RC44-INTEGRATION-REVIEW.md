# Imagine Portal SEO RC44 — contrato do Engine e dashboard administrativo

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Revisão integrada da frente SEO do Portal contra `main`.

## Conjunto de entregas

O histórico desta branch inclui os contratos SEO anteriores F106–F117, com salvaguardas GSC/OAuth e laboratório PostgreSQL, e as entregas:

- [F118/F119/F120 #857](https://github.com/rafhael100/imagine-portal/pull/857): contrato PHP para ler métricas técnicas do build do SEO Engine de forma restrita; teste real com o código fixado do Engine RC44; tela administrativa ilustrativa.
- [SEO Engine RC44 #173](https://github.com/rafhael100/imagine-seo-engine/pull/173): auditoria estática de páginas, idiomas, sitemaps, vazamento de prévias e importação/captura de referência territorial.

## Evidência técnica já comprovada

Em CI da branch F120:
- PHP 8.3 e 8.4 (testes Laravel) passaram.
- Node 22/24 Portal passaram.
- Integração real Engine imutável `2a153cedd0b5fc8ad3d5d26ce103a2043be38a72` → JSON F114 → PHP F118 passou em quatro cenários (normal, página ausente, idioma divergente, prévia vazada).

**A integração usa arquivos fictícios de HTML em runner descartável**. A validação não prova origem assinada da auditoria, identidade real do cliente nem acesso em VPS. O dashboard é uma view interna SEM rota HTTP. Mesmo contagens limpas são apenas `HUMAN_REVIEW_REQUIRED`, não permissão de publicação.

## Gates de produção

1. Sessão confiável do Portal Laravel, MFA, tenant e autorização por empresa/serviço; toda verificação deve usar contexto resolvido exclusivamente no servidor.
2. Emissor confiável e versionado para os relatórios do SEO Engine, com integridade de dados, segregação e auditoria.
3. Google OAuth real readonly por propriedade e empresa, rotação/revogação/armazenamento seguro dos tokens e verificação de titularidade.
4. Base territorial IBGE autenticamente obtida e analisada por edição, incluindo unidades especiais; teste ao vivo continua pendente após timeout no GitHub.
5. Analytics/CRM reais com atribuição para Visitas → Leads → Clientes; clique GSC não deve ser exibido como visita.
6. AppSec/LGPD/DBA independentes, integração de gateways apenas quando necessária, staging VPS, SSL, backup/restauração, reversão e revisão de indexação.
7. Aprovação explícita de publicação, após revisão integral deste diff contra main.

## Proibições

Nenhum merge, deploy, rota pública, cliente real, DNS, conta Google, VPS, cobrança ou dados sensíveis foram alterados. A CI não remove a condição NO-GO.

**ReleaseStatus: HOLD_AUTHENTICATED_PORTAL_OAUTH_SIGNED_ENGINE_STAGING.**
