# PORTAL-UNI V2 — preflight de serviço elegível (sem autorização)

**15/11/2026 · candidato técnico · DRAFT / NO-MERGE / NO-DEPLOY**

## Objetivo do produto

A Universidade Imagine é benefício para empresas que mantenham pelo menos um serviço Imagine **elegível e ativo**. Não é vendida separadamente. Quando o último serviço qualificador for cancelado, suspenso ou expirar, seu cliente perde a elegibilidade de acesso, preservado o histórico para aplicação futura da política de retenção.

## Implementação desenvolvida

- `UniversityContractAuthority`: contrato para consultar assinaturas/serviços de **uma organização**, por fonte oficial e exclusivamente servidor. Atualmente não existe adaptador de produção.
- `UnavailableUniversityContractAuthority`: provedor padrão que **sempre nega**, sem banco, chamada HTTP, segredo ou permissões.
- `UniversityContractSnapshot`: representação tipada de organização, referência única de serviço, produto, estado, elegibilidade comercial explícita, indicador de trial, revisão da fonte, data da observação e expiração.
- `UniversityContractStatus`: estados fechados `active`, `preparing`, `trial`, `suspended`, `cancelled`.
- `UniversityEligibilityPreflight`: inspeção sintética, bloqueando fonte indisponível, organização cruzada, duplicidade, dados inválidos, dados vencidos ou emitidos no futuro. TTL máximo de 60 segundos. Conta candidato apenas quando **active + elegível pelo contrato + não trial**.
- `UniversityEligibilityPreflightResult`: fornece apenas diagnóstico e contagem de candidatos; **mayIssueUniversityGrant(), provesRealEligibility() e mayExposeCustomerData() sempre retornam false**.

A classe de snapshot pode ser criada por um chamador de teste. Ela **não** é evidência criptográfica ou contábil. Mesmo um resultado positivo **não** autoriza HTTP, sessão, emissão de grants nem conteúdo de cliente. A política `OrganizationAuthorizationService` da PR anterior continua recusando `university:view/manage` incondicionalmente.

## Testes da rodada

`UniversityContractEligibilityPreflightTest`: oito métodos PHPUnit cobrindo fallback deny-all, serviço hipoteticamente ativo, trial, cancelamento do último serviço, empresa trocada, duplicidade, fonte inválida/indisponível, prazo vencido/futuro e exclusão do próprio produto Universidade. **Não reabilitar acesso com base nesses testes sintéticos**.

## Próximo avanço necessário

1. Nomear uma **fonte oficial** de contratos/serviços ativos (Portal comercial e/ou billing), e uma definição versionada de quais produtos/planos qualificam.
2. Implementar um adaptador servidor autenticado que responda por contrato, empresa e revisão; nunca usar `portal_service_resource_claims` como prova porque seus estados só são `candidate/retired` e não há política de leitura.
3. Criar vínculo validado entre identidade do Portal, empresa, serviço qualificador e recurso; emissão com TTL curto, controle de revogação e logs mínimos. Separar explicitamente `university:manage` editorial do `university:view` do cliente.
4. Executar transações concorrentes em PostgreSQL, testes de cancelamento do último serviço e indisponibilidade da origem; obter revisão AppSec/DBA/LGPD.
5. **Só então** conectar a Universidade ao Portal por backend-to-backend e avaliar homologação privada, sem deploy automático.

**Não foi modificado nenhum CHECK de grants, migração, rota, controlador, auth provider, serviço externo ou cliente real.**


## Vetor criptográfico compartilhado (somente teste)

Os repositórios Portal e Universidade contêm o mesmo arquivo público `uni-v1-ed25519-public-vector.json` em seus diretórios `tests/Fixtures`. SHA-256 do conteúdo **b6925128a7d66efe1e304710d085d51c5e854215230fa0f02505d201b4381e7c**.

O vetor foi assinado localmente com um par de chaves sintético efêmero; **somente a chave pública e a mensagem assinada foram versionadas**. A chave privada não foi publicada nem reutilizada. Os testes verificam a assinatura e a separação de domínio `uni-v1.`, sem conceder grants ou credenciais. A igualdade do SHA nos dois repositórios impede divergência silenciosa no contrato.
