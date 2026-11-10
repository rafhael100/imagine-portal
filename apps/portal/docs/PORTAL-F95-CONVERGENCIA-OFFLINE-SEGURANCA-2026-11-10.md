# PORTAL-F95 — Convergência controlada F65–F73 com segurança F92–F94

**Data:** 10/11/2026. **Estado:** PR draft; não homologado para produção.

## O que foi integrado

Partindo da linha de revisão F94, foram importados **15 arquivos não conflitantes** do commit F73 `182753ea0c0072f0bd29983d7033786a653265bb`, preservando os conteúdos originais (identidade dos blobs do Git):

- Seis ferramentas CLI demonstrativas: prévia HTML F65; CSV F66; auditoria CSV F69; pacote HTML/CSV F71; verificação de integridade F72; auditoria estática de superfície Laravel F73.
- Nove suítes Node.js, F65–F73, para regressões de centavos, múltiplos cenários, injeção em planilhas, hashes, política de arquivos e bloqueio de rotas.

O contrato PHP de dados de orçamento **já existia na linha F94 e possui blob idêntico** ao da linha F73: `apps/portal/app/Domain/PerformanceDashboard/CredentialBoundBudgetPreview.php`, SHA `bc4278f266fef9a612954ad03938eef905f0ac54`. Portanto não houve substituição de código da camada de domínio.

## Compatibilidade verificável

A CI F95 executa nove suítes F65–F73 em PHP 8.3/8.4 × Node 22/24, mais as políticas F93 e F94 de Nginx/Docker, rotas e ingressos HTTP. O smoke Docker privado da F64 é disparado pela abertura do PR na branch, realizando confirmação mais forte de que ferramentas CLI não entram na imagem. Atestar aprovação **somente depois da conclusão dos workflows na HEAD**.

A convergência é por **seleção de arquivos não conflitantes**. Não foi feito merge histórico das branches F73 e F94, nem importação de workflows antigos concorrentes. Outras diferenças entre os 67 commits da F73 e a linha F92–F94 ainda exigem revisão; isso não autoriza declarar todas as features conciliadas.

## Proibições em vigor

Ferramentas sintéticas só executam com `APP_ENV=testing` e flags explícitas; não são rotas, não permitem gastar orçamento, enviar dados a Google/Meta, aprovar anunciante, cadastrar usuário real ou faturar. A imagem Docker F92–F94 copia somente runtime e **não inclui `tools/` nem `tests/`**. F93 e F94 proíbem abertura acidental do perímetro HTTP.

**NO-GO:** nenhuma alteração em main, VPS, DNS, segredos, pagamentos, mídia real, identidades CRM ou dados pessoais. F95 não substitui IAM real, revisão AppSec, PostgreSQL/RLS, KMS, SEC-R03 nem homologação independente de segurança.
