# CODEX — Ordem de trabalho aprovada: PORTAL-F1-01-B-A

**Data:** 05/11/2026  
**Status da autorização:** **APROVADA** por manifestação expressa do responsável, conforme [PORTAL-F1-01-B-DEC-01](PORTAL-F1-01-B-DEC-01-APROVACAO-PROGRAMACAO-CONTROLADA-2026-11-05.md).  
**Status da execução:** **NÃO INICIADA POR ESTE DOCUMENTO**; a instrução abaixo está pronta para ser utilizada no Codex.  
**Repositório:** `rafhael100/imagine-portal` (privado).  
**Base autorizada:** `dev/fast-track-portal-2026-11-05`; SHA anteriormente conferido `098cdfd50024ea872861f3ba3a058409e5f976b7` (revalidar no início).  
**Branch nova sugerida:** `dev/portal-f1-01-b-domain-2026-11-05`.  
**Destino obrigatório do PR novo:** `dev/fast-track-portal-2026-11-05` **e NÃO `main`**.

## Instrução integral a executar

### 1. Diagnóstico e preparação

1. Conferir que o repositório e branch de base são os corretos; ler `apps/portal/README.md`, `apps/portal/AGENTS.md`, `apps/portal/composer.json`, `apps/portal/composer.lock` e o status do PR #11. **Não alterar a branch base ou o PR #11**.
2. Ler os documentos **na branch documental** `docs/portal-f1-00-2026-11-05`:
   - `docs/portal/PORTAL-F1-01-B-MODELO-ORGANIZACOES-MEMBERSHIPS-PERMISSOES-PROPOSTA-v0.1-2026-11-05.md` — conteúdo e critérios;
   - `docs/portal/PORTAL-F1-01-B-DEC-01-APROVACAO-PROGRAMACAO-CONTROLADA-2026-11-05.md` — **autorização normativa específica**;
   - `docs/portal/PORTAL-F1-01-A-RV01-REVISAO-PR11-INTEGRACAO-CONTROLADA-2026-11-05.md` — limites do PR #11 e base técnica;
   - `docs/portal/PORTAL-TEC-D02-LOGINS-INDEPENDENTES-PORTAL-CRM-DECISAO-2026-11-05.md` — logins separados;
   - `docs/portal/PORTAL-TEC-D03-R02-LEGADO-PROTOTIPO-FLUXOS-NEGOCIO-DADOS-TESTE-2026-11-05.md` — legado é protótipo, dados só de teste;
   - `docs/imagine-sites/P0-FECHAMENTO-DOCUMENTAL-01.md` — bloqueios ainda existentes.
3. Caso o SHA da branch base tenha mudado em relação ao conferido, registrar o novo SHA, conferir o diff e **interromper para revisão** se forem mudanças fora do escopo autorizado, em vez de mesclar silenciosamente.
4. Criar a nova branch a partir do head conferido da branch de desenvolvimento. **Não partir da `main`** nem modificar `main`/VPS.

### 2. Implementação permitida: apenas domínio PHP, sem efeitos colaterais

Criar dentro de `apps/portal/app/` um módulo de domínio isolado, de preferência com nomes claros em `Domain/Identity`, `Domain/Organizations` e `Domain/Authorization`, ou organização equivalente justificada.

Conceitos mínimos:
- `PortalUserId` / `PortalActor`: identificadores próprios do Portal, **sem senha, sessão, credencial ou registro real**.
- `OrganizationId` / `CustomerOrganization`: contexto explícito; não usar CNPJ ou e-mail como vínculo de autorização.
- `OrganizationMembership`: associação expressa de usuário/organização, com estado `invited`, `active`, `suspended`, `revoked` (ou equivalentes documentados) e permissões atreladas à empresa, não ao usuário globalmente.
- `OrganizationRole` e/ou `Permission`: catálogo restrito de permissões de exemplo, com comportamento de negação por padrão; não presumir superadmin global ou acesso à conta CRM.
- `AuthorizationContext`, `AuthorizationDecision` e avaliador **puro** de autorização (sem banco e sem rede): exige ator, organização selecionada, vínculo ativo, permissão solicitada e organização proprietária do recurso; nega acesso quando houver qualquer omissão, divergência ou estado inválido.
- Objetos **imutáveis** ou value objects quando apropriados, sem acoplamento a HTTP, ORM/Eloquent, controllers, guards de autenticação, webhooks ou políticas de produção.

**Restrição importante:** a F1-01-B-A **não** cria tabelas, migrations, usuários reais, login, MFA, convites enviados ou endpoints. Se for necessário persistir algo para provar um comportamento, usar **instâncias PHP fictícias em memória** e testes; não criar SQLite alternativo, PostgreSQL real ou armazenamento temporário de clientes.

### 3. Testes que DEVEM ser implementados e rodados

Criar testes PHPUnit em `apps/portal/tests/Unit/` ou outra localização conforme a suíte realmente configurada, alterando `phpunit.xml` **somente se necessário para incluir testes de unidade**. Os cenários precisam demonstrar:
1. Membership ativa + permissão + organização do recurso correspondentes → `allow`.
2. Ator com Empresa A e B: permissão em A **não** vale para B.
3. Ator sem membership → `deny`.
4. Convite pendente → `deny`.
5. Membership suspensa → `deny`.
6. Membership revogada → `deny`.
7. Permissão ausente ou desconhecida → `deny`.
8. Ator/contexto/organização do recurso diferentes → `deny`.
9. Organização ou identidade ausente/inválida → `deny`.
10. Mesmo e-mail/nome/CNPJ fictício não produz vínculo ou privilégio implícito.
11. Identidade CRM (quando referenciada) não cria autorização do Portal.
12. Colaborador Imagine sem delegação explícita não acessa recursos privados.

Conferir se a suíte tem testes de unidade registrados no `phpunit.xml`; caso não tenha, ajustar apenas configuração e testes necessários. Evitar *assertions* tautológicos que não exercem o serviço de autorização.

### 4. Validação e entrega

- Preservar `composer.lock`; não executar `composer update` por conveniência.
- Executar `composer validate --strict`, `php -l`, `php artisan test` em PHP **8.3 e 8.4** quando o ambiente CI estiver disponível; verificar que a suíte cobre **Feature + Unit**, não apenas os testes anteriores de health-check.
- Não introduzir dependências adicionais sem justificativa e aprovação; preferir PHP/Laravel que já existem no lockfile.
- Atualizar `apps/portal/AGENTS.md` **somente na branch filha** para acrescentar a autorização B-A, preservando as restrições legadas e proibindo B-B/C e qualquer avanço automático.
- Criar PR **draft**, de `dev/portal-f1-01-b-domain-2026-11-05` → `dev/fast-track-portal-2026-11-05`, com diff limitado a `apps/portal/**` e, só se indispensável, configuração de teste do mesmo diretório.
- Informar no PR: SHA base conferido, commits/arquivos, resultados detalhados e comandos de testes, casos de isolamento, riscos, limitações, gates não fechados e **ausência de merge/deploy**.
- Não declarar sucesso, execução ou homologação caso os testes não tenham rodado. Reportar bloqueios e parar antes de extrapolar autorização.

### 5. Proibições permanentes desta ordem

**Não fazer:** alteração em `main`/PR #11/ramo base, merge (mesmo na branch de desenvolvimento), login humano, autenticação real, sessões, MFA, Eloquent persistente, migrations, schema PostgreSQL, dados reais, API, CRM, site/publicação, n8n, PSP/cobranças, Performance AI, Universidade, servidores, cloud, segredos, importação SQLite, provisionamento ou deploy. **Não** iniciar F1-01-B-B, F1-01-B-C, F1-01-C, F1-02 ou liberar gates P0/SEG.

**Critério de conclusão:** PR draft criado com testes de autorização com dados fictícios verificáveis e evidências de CI. **A implementação aprovada não equivale a autorização de integração nem merge.**

## Mensagem curta para iniciar no Codex

> Execute **somente** a tarefa **PORTAL-F1-01-B-A** aprovada, no repositório `rafhael100/imagine-portal`. Leia integralmente `docs/portal/PORTAL-F1-01-B-A-TAREFA-CODEX-APROVADA-2026-11-05.md` e `docs/portal/PORTAL-F1-01-B-DEC-01-APROVACAO-PROGRAMACAO-CONTROLADA-2026-11-05.md` na branch `docs/portal-f1-00-2026-11-05`. Parta da branch `dev/fast-track-portal-2026-11-05`, crie a branch filha `dev/portal-f1-01-b-domain-2026-11-05`, implemente contratos PHP puros e testes totalmente fictícios de isolamento por organização, rode a suíte em PHP 8.3/8.4 e crie um **PR draft destinado à branch pai**, sem merge, banco, login, CRM, deploy ou mudança na `main`. Caso exista bloqueio, pare e reporte.
