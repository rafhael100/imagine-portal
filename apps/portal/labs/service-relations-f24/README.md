# PORTAL-F24 — sessão fictícia assinada + revogação consultada do PostgreSQL

**09/11/2026. EXPERIMENTO ISOLADO, SEM TOKEN REAL, IAM, CLIENTE, DEPLOY OU ACESSO.**

## Objetivo

Provar que o adaptador PHP de leitura F22/F23 pode exigir simultaneamente:

1. uma prova de sessão sintética com integridade protegida e vida curta;
2. empresa selecionada compatível com a prova e a identidade SQL;
3. sessão e membership **ainda ativos** na base PostgreSQL descartável;
4. versões de sessão e membership iguais às da prova;
5. privilégio hipotético `view_services` ainda concedido;
6. RLS forçado nas duas tabelas adicionais de identidade do laboratório.

Nenhum desses itens, isoladamente ou em conjunto, **autoriza um login real**.

## Implementação

- `app/Labs/F24/F24LabSessionProof.php`: estrutura não pública de prova com
  verificador HMAC-SHA256, parâmetros canonizados, marca do emissor e audiência
  **exclusivas de laboratório**, nonce, sub, organização, sessão, revisões,
  instante de emissão/expiração e data fictícia de MFA. O próprio teste
  gera uma chave aleatória de 32 bytes em memória; não há segredo real nem
  chave no repositório. A fábrica `signFixture` é **somente para testes**.
- `app/Labs/F24/F24SyntheticAuthorizedReader.php`: **entrada opt-in somente
  de laboratório**; exige prova verificada e empresa compatível para chamar
  o F22. Não é middleware nem API, não está registrada na aplicação.
- O adaptador F22 recebe parâmetro adicional **opcional de laboratório**
  e verifica a tabela de sessão e a tabela de membership **dentro da mesma
  transação REPEATABLE READ, READ ONLY** utilizada para ler os serviços.
  Isso evita a checagem de sessão em uma transação distinta da leitura
  de serviço, mas não resolve todos os tipos de corrida.
- `labs/service-relations-f24/schema.sql`: schema fictício F24 com sessões e
  memberships de duas empresas; RLS `ENABLE/FORCE`, policies por
  identidade SQL de laboratório e permissões somente SELECT.
- `tests/Integration/F24SessionRevocationIntegrationTest.php`: provas
  negativas de assinatura, emissor, audiência, expiração, MFA, revisão,
  suspensão, revogação, permissão, troca de empresa e RLS adulterado.
- CI F22 existente agora instala o F24 no mesmo banco temporário, roda
  PHP 8.3 e 8.4, e apaga o container ao final. Continua sem VPS, Cloudflare,
  contratos, contas ou banco do Portal operacional.

## Resultados que não podem ser inferidos

- HMAC e chaves aleatórias **do runner** não são solução aprovada de
  emissão/rotação de tokens. O fluxo Portal AUTH-PERF com Ed25519 de
  outro PR é independente; F24 não o substitui e não importa código dele.
- O F24 verifica uma prova antes do SELECT, mas não opera sessão Laravel,
  MFA real, identidade originária ou vínculo confiável com a empresa.
  `org-a`, `org-b` e `DEMO-USER-*` são exclusivamente fictícios.
- Os métodos `authorizesServiceAccess()`, `mayExposeCustomerData()`,
  `grantsUniversityBenefit()` e `authorizesContractOrPayment()` retornam
  sempre **false**, mesmo nos cenários positivos de laboratório.
- A camada F22 original continua com leitura de **fixtures sem prova F24**
  para compatibilidade com testes anteriores, mas não está disponível
  via rota real. Somente `F24SyntheticAuthorizedReader` exige a prova.
- **KNOWN GAP — corrida temporal:** um commit de revogação efetuado
  **depois do snapshot da transação** pode não ser visível na leitura
  atual. F24 demonstra consulta dentro da mesma transação, mas NÃO
  homologou revogação linearizável ou barreira de escrita/outbox/worker.
- **KNOWN GAP — restauração conjunta:** restauração de estado antigo de
  sessão + membership + dados pode revalidar prova antiga se a chave
  anterior continuar válida. É necessária âncora monotônica fora do domínio
  de restauração (SEC-R03/Z4), ainda **não implementada**.
- A checagem de `pg_catalog` de F23/F24 é negativa: não audita SQL
  completo das policies, grants, superusuários, réplicas ou domínio
  administrativo. Um operador do banco pode adulterar o laboratório.
- **Não usar** F24 no Portal real como se fosse autenticação completa.
  O ambiente produtivo exigirá issuer confiável, armazenamento/rotação
  segura de chaves, sessão/MFA e membership persistidos, RLS por
  identidade derivada do servidor, proteção de revogação e aprovação
  de arquitetura/AppSec/DBA.

## Regras de mudança

PR **DRAFT**, filho do F23/F22/F21/F20. Sem merges na `main` ou `dev`,
sem migrações Laravel, sem rotas, sem VPS, sem Cloudflare, sem dinheiro de
fornecedor novo, sem dados pessoais e sem deploy. Os jobs do GitHub Actions
podem consumir minutos conforme o plano. Gates P0, D02-SEC, D03-RLS,
SEC-R03-X01..X24, T3/OPEN continuam abertos.
