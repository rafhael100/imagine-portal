# Portal SEO F126 — prova completa Engine → assinatura → Portal → PostgreSQL

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**

A F125 verificou a compatibilidade criptográfica Engine→Portal. As F123/F124 comprovaram o consumo único e retenção no PostgreSQL 18, mas com um assinador de laboratório no Portal. A F126 une esses contratos em um caminho efetivo **com código de dois repositórios** e PostgreSQL real descartável:

1. SEO Engine [F121 #177](https://github.com/rafhael100/imagine-seo-engine/pull/177) — SHA imutável `3c75b8e7b64f4173a17f4daab45cea1b8bdc5554` — gera HTML sintético local e o audita com F114/F117.
2. **O Engine assina** seu diagnóstico com chave Ed25519 temporária (a chave privada nunca é gravada).
3. Portal [F122](https://github.com/rafhael100/imagine-portal/pull/860) verifica a assinatura, hash, tenant/run/commit e janela temporal, e reduz o DTO para contagens sem URLs.
4. Portal [F123](https://github.com/rafhael100/imagine-portal/pull/862) consome uma única vez o nonce em **PostgreSQL 18 real com FORCE RLS** e papel de banco de privilégio mínimo.
5. Uma tentativa de reutilização, outra empresa, outro run ou outro commit é recusada sem novo registro.
6. O laboratório [F124](https://github.com/rafhael100/imagine-portal/pull/863) continua comprovando o expurgo conservador em 500+2 linhas, antes da prova F126.

## Execução
O workflow `.github/workflows/portal-seo-f123-evidence-pg18-ci.yml` executa todos os passos em PHP 8.3 e 8.4. O banco, HTML e chaves são descartáveis. Uma nova prova F126 é rodada ao final do pipeline PG18 já existente, depois dos testes de concorrência e expurgo.

## Falta para produção
- Serviço assinador do Engine em processo/container isolado com chaves em KMS e identidade mútua, não uma função de teste.
- Emissor autenticado e confiança pública configurada exclusivamente no servidor do Portal.
- Sessão real do Portal com MFA, tenant, RBAC e revisão independente de permissão.
- RLS em banco de homologação, métricas de concorrência e audit trail de consumo/revogação.
- Política definitiva LGPD de retenção, job aprovado, backup/restore e reversão.
- VPS staging, verificação da edição territorial IBGE, Google OAuth real, originalidade editorial e aprovação formal.

**Não publicar este painel nem ativar esse laboratório para clientes.**
