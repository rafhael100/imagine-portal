# PORTAL-F22 — PHP/Laravel para leitura sintética PostgreSQL 18 via PDO

**09/11/2026. Laboratório opt-in, sem deploy, merge, migrações ou usuário real.**

## O que foi construído

O adaptador `app/Labs/F22/SyntheticPgServiceReadAdapter.php`, versionado
no mesmo projeto Laravel mas **não registrado em service providers, controllers,
middleware ou rotas**, lê exclusivamente a visão SQL fictícia
`f21.service_summary` criada no laboratório F21.

- Conexão PHP `PDO_PGSQL` injetada externamente; não escolhe credenciais
  no código, não consulta CRM, cobrança ou tabelas de clientes.
- Exige opt-in `F22_ENABLE_LAB=NON_OPERATIONAL_F22` para impedir uso
  acidental. Essa flag é facilmente forjável e **NÃO autentica, autoriza ou
  constitui barreira de segurança operacional**.
- Aceita somente empresas sintéticas `org-a` e `org-b` e exige sessão
  SQL efetiva e original `f21_org_a` ou `f21_org_b` correspondente.
  O ID fornecido pelo chamador **não prova** qual cliente está autenticado.
- Usa `REPEATABLE READ, READ ONLY` em transação própria, timeout por
  comando, `ATTR_EMULATE_PREPARES=false` e filtro SQL vinculado por
  `bindValue(..., PDO::PARAM_STR)`; nunca interpola IDs em SQL.
- Lê a coleção completa e limitada (máximo 50 serviços) antes de paginação.
  Confere ownership, referências únicas/ordenadas, textos e contagens de
  até 200 vínculos por entidade, rejeitando qualquer inconsistência.
- Reutiliza `DemoServiceRelationPage` de F20: `source_unavailable`,
  `untrusted_db_session` e demais falhas devolvem **zero registros,
  totais e cursores**, mesmo se a falha ocorrer após algum SELECT.
- O retorno preparado ainda tem todos os métodos de autorização
  **sempre false**: sem exposição de dados reais, sem serviço, contrato,
  compra ou benefício Universidade liberados.

## Como a prova é realizada

`.github/workflows/portal-f22-laravel-pg-readonly.yml` executa dois
jobs (PHP 8.3 e PHP 8.4) com `pdo_pgsql`, dependências do `composer.lock`
existente e **PostgreSQL 18 descartável em container Docker**. O banco
é exposto **somente ao loopback 127.0.0.1 do runner**, em porta aleatória;
não há domínio público, acesso do cliente ou conexão com VPS.

1. O runner cria senha aleatória para a identidade administrativa e outras
   duas senhas aleatórias para contas sintéticas A e B, usando SCRAM-SHA-256
   no PostgreSQL. As senhas não são fixadas nem enviadas para o PR.
2. SQL F21 cria tabelas/roles/políticas RLS e dados artificiais, e o job
   atribui as duas senhas locais às roles de leitura.
3. PHPUnit usa **sessões PDO diferentes e de verdade**, conectadas por
   `127.0.0.1` com credenciais distintas (sem `SET SESSION AUTHORIZATION`
   via superusuário), e executa leitura paginada através do adaptador.
4. Os ensaios negativos verificam senha errada, seleção de empresa alheia,
   usuário SQL superusuário recusado pelo adaptador, GUC `app.tenant_id`
   falsa, escritas negadas, tentativa de `SET ROLE` e de alterar RLS,
   usuário SQL sem permissão de leitura da view, cursor/limite inválidos e
   transação já aberta. O acesso volta a funcionar ao restaurar uma
   permissão de laboratório em um bloco `finally`.
5. O container é removido via `trap ... EXIT` mesmo quando um teste falha.
   O GitHub CI padrão Laravel continua separado e sem conexão com esse banco.

## Sobre a confiança real

**Passar aqui NÃO significa IAM de produção homologado.** A suíte de
integração fabrica credenciais e identidades, não um login Laravel real.
O banco aceita conexão de qualquer processo do próprio runner que possua
a senha, e o administrador do runner pode manipular o container, as senhas
ou os estados. O teste não substitui vínculo legal com o cliente, MFA,
auditoria, Z4 anti-rollback, RBAC, RLS completo e validação AppSec/DBA.

A exposição de porta **127.0.0.1** não é equivalente a `--network none`
do F21, mas não publica o serviço na rede externa. É um compromisso
restrito ao laboratório para conectar PHP no host ao PostgreSQL descartável.

O arquivo PHPUnit `tests/Integration/F22PgServiceReadIntegrationTest.php`
**não é descoberto** pelas suites padrão `tests/Unit` e
`tests/Feature`; é executado explicitamente por este CI com opt-in.
Nenhum `.env` de cliente, segredo de provedor ou credencial persistida
é criado pelo código de aplicação. As dependências são instaladas
exclusivamente no runner temporário.

### Critérios que continuam abertos

- Emissor de identidade confiável para Portal e verificação de
  membership/empresa/serviço; conexões SQL que não aceitem spoofing.
- RLS de todas as tabelas operacionais, controle de owner, pool de conexões,
  sessões, MFA, grants, auditoria e revogação real.
- Recuperação/anti-rollback SEC-R03, locks, testemunha externa, efeitos
  de commits concorrentes entre motor/Portal, e validação formal de
  segurança.
- Evolução de F20/F22 para fonte de dados real depende de design e
  aprovações específicos; não habilitar estes laboratórios por um flag
  em ambiente operacional.

**PR DRAFT/SEM MERGE.** Sem contratos reais, recursos Cloudflare, VPS,
`main`, `dev`, usuários, importações, dados pessoais ou serviços pagos.
O CI utiliza minutos do GitHub, mas não provisiona fornecedores externos.
