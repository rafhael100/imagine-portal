# PORTAL-F49 — Validação estrita do contrato de handoff v1 (sem comunicação externa)

**09/11/2026 · PHPUnit PHP puro · somente contrato DEMO e sem serviços externos.**

## Problema resolvido

O F48 definiu um envelope JSON de **16 propriedades em ordem fixa**
para preparar a futura troca de metadados entre Portal e
Imagine Sites Engine. Um consumidor não pode confiar que a
mensagem recebida tem o mesmo produto, organização, versão
ou sinalizadores de liberação que a prévia originalmente
aprovada — nem pode aceitar propriedades extras não negociadas.

A F49 constrói um **validador de contrato do lado receptor,
ainda dentro do repositório Portal**, para testar rejeição de
mudanças. Não é um endpoint do Sites Engine, assinatura,
credencial ou processo de ingestão real.

## Semântica

`SiteEngineHandoffDemoContractValidator::inspect($incomingJson,
$expectedCandidate,$localReview)`:

1. Recusa payload vazio ou maior que **1024 bytes**;
2. Decodifica JSON com erros fatais; exige objeto, nunca
   scalar ou lista;
3. Exige que a revisão local F43 do candidato seja uma
   única decisão editorial fictícia `approved_demo`;
4. Constrói o envelope de referência F48 a partir do
   candidato e diário fornecidos pelo teste;
5. Compara **byte a byte** contra o JSON canônico exato
   do envelope esperado, em tempo constante na comparação.
   Essa verificação é mais restrita que uma checagem
   de propriedades soltas, e rejeita também:
   - ordem de chaves alterada ou espaços desnecessários;
   - chaves repetidas (que `json_decode` pode sobrescrever);
   - campos desconhecidos ou texto pessoal acrescentado;
   - troca de organização, referência, produto, SHA ou revisão;
   - alteração dos três booleanos de bloqueio de transporte,
     publicação e aprovação autenticada;
   - adição de outro documento JSON após o objeto canônico.

O retorno `matching_demo_preview` significa **apenas**
que os bytes fornecidos pelo próprio teste correspondem aos
metadados sintéticos esperados. O SHA-256 associado NÃO
autentica origem, prova de consentimento, sessão ou pessoa.

`SiteEngineHandoffDemoValidationResult` mantém sempre
`isAuthenticatedTransport=false`,
`isVerifiedCustomerApproval=false` e
`permitsSitePublishing=false`, mesmo no caso positivo.

## Testes

`SiteEngineHandoffDemoContractValidatorTest.php`: 11 casos
unitários contra payload válido, revisão não aprovada,
versões de briefing/conteúdo defasadas, outra organização,
outra referência, outro produto, SHA ou status alterado,
flags indevidamente promovidas, chave desconhecida,
duplicada, ordenação e espaços, documentos inválidos,
listas/escalars, limite de bytes, idempotência e resultado
que tenta alegar permissão operacional.

## Limites importantes

A comparação é deliberadamente rígida para o laboratório.
No contrato real, é necessário negociar canonicalização
JSON de forma independente entre linguagens, autenticação
do emissor Portal-only, autorização de tenant e domínio,
nonce/idempotency e antifraude, assinatura do conteúdo
efetivamente aprovado, proteção contra replay/rollback,
transporte seguro, storage e auditoria confiáveis, e
consentimento e retenção LGPD. A validação local não
demonstra que o motor Sites aceitará ou processará nada.

**Nenhuma rota HTTP, controller, migration, worker,
assinatura de cliente, ligação com imagine-sites, VPS
Leaseweb, Cloudflare, CRM, n8n ou dados reais foi criada.**
PR DRAFT sem merge/deploy, sem custos adicionais.
P0/D02/D03, SEC-R03/Z4, T3/OPEN, IAM/MFA e AppSec/DBA
seguem pendentes.
