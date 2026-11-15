# Imagine Portal SEO RC46 — cadeia de auditoria assinada ponta a ponta

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Revisão integrada de código contra `main`. Esta branch deriva da HEAD validada da [F126 #866](https://github.com/rafhael100/imagine-portal/pull/866).

## Escopo acumulado
A [Portal RC45 #864](https://github.com/rafhael100/imagine-portal/pull/864) reúne contratos e validações de SEO, GSC/OAuth offline, auditoria do build, assinatura Ed25519, ledger PostgreSQL 18 com RLS e retenção de nonce. A RC46 acrescenta:

- [F125 #865](https://github.com/rafhael100/imagine-portal/pull/865): assinatura feita pelo **próprio SEO Engine F121 #177**, após auditar o HTML, e verificada pelo Portal PHP.
- [F126 #866](https://github.com/rafhael100/imagine-portal/pull/866): fluxo real de código entre repositórios, com consumo em PostgreSQL18, verificação de identidade **sintética** por tenant/run/commit, recusa de replay e preservação da retenção F124.

## Prova técnica
A CI roda em PHP 8.3/8.4 e Node.js 22/24, usa HTML descartável, um commit imutável do SEO Engine e chaves efêmeras:
1. O Engine audita F114/F117 e produz diagnóstico (inclusive quando bloqueado).
2. O Engine assina com Ed25519 sem expor chave privada.
3. O Portal valida assinatura, digest, issuer/audience, empresa, run, SHA e expiração.
4. O Portal reduz os dados a contagens, sem URLs de cliente.
5. O PostgreSQL18 com FORCE RLS aceita **um** consumo e nega repetição, contexto divergente, papel inadequado e tentativas concorrentes.
6. O expurgo de nonce é limitado a registros antigos, isolado por tenant e sem permissão excessiva.

## Bloqueios de release
**Não existe serviço de produção implantado por estes testes.** O portal ainda precisa de login/sessão/MFA e permissão por empresa realmente autenticados; o Engine precisa de serviço emissor com chave sob KMS/HSM, rotação/revogação e canal autenticado; a VPS precisa de CI/CD supervisionado, backups/reversão, observabilidade, escopos e testes AppSec/LGPD.

O Google Search Console requer OAuth readonly/grants reais, e a API do IBGE ainda não foi comprovada acessível ao runner na tentativa registrada (timeout). O conteúdo municipal requer revisão de utilidade/originalidade, cobertura real e conformidade SEO.

**Nenhum teste de identidade sintética habilita cliente, publicação, indexação, deploy ou merge.** Revisão integral contra `main` e aceite formal são exigidos.
