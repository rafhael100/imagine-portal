# Imagine Portal RC49 — administração auditável, SEO segregado e staging preparado

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Branch de revisão geral contra `main`, sem serviços ou clientes reais.

## Consolidação de entregas

Inclui o histórico técnico [RC47 SEO #873](https://github.com/rafhael100/imagine-portal/pull/873), [F130 SQL/Audit #875](https://github.com/rafhael100/imagine-portal/pull/875), [F131 revogador de privilégios mínimos #876](https://github.com/rafhael100/imagine-portal/pull/876) e [RC48 paralela #880](https://github.com/rafhael100/imagine-portal/pull/880) como referência. Esta RC avança com a linha de autenticação mais recente:

- [F132 #877](https://github.com/rafhael100/imagine-portal/pull/877): login administrativo real Laravel, senha e TOTP com sessão em dois estágios, sem acesso a clientes.
- [F133 #878](https://github.com/rafhael100/imagine-portal/pull/878): criação de administrador pela CLI segura, segredo TOTP em arquivo privado, conta inativa por padrão.
- [F134 sessões #881](https://github.com/rafhael100/imagine-portal/pull/881): contador persistente por conta permite revogar todos os cookies e desafios de MFA pendentes.
- [F135 #883](https://github.com/rafhael100/imagine-portal/pull/883): revogação de sessões com evento de auditoria atômico, somente de acréscimo.
- [F136 #884](https://github.com/rafhael100/imagine-portal/pull/884): concilia a [F134 SEO por organização #879](https://github.com/rafhael100/imagine-portal/pull/879) com o controle de sessão/auditoria; permissão `seo.view` não substitui MFA ou sessão válida.
- [F137 #885](https://github.com/rafhael100/imagine-portal/pull/885): migration da tabela `sessions` e preflight conservador para staging HTTPS + PostgreSQL + cookies Secure/HttpOnly + payload de sessão criptografado.

## Estado do código

Na comparação da HEAD `5a335332d221a103708f0f5205320da020f72482` com `main` (SHA `50b683a499e674641df5749051f3d05f7ac3f627`): **396 commits à frente, zero atrás**. A resposta padrão de comparação do GitHub retorna **300 entradas de arquivos**, que podem ser truncadas; não afirmar inventário completo com essa consulta. **Diff extenso: revisão independente de segurança e reconciliação de PRs paralelas obrigatórias antes de qualquer merge.**

## Matriz de testes exigida para esta RC exata

1. Laravel PHP 8.3/8.4, Node 22/24 no Portal.
2. PostgreSQL18 real F132–F137: login, TOTP anti-replay, MFA, provisionamento, revogação global, trilha de auditoria, grant `seo.view`, sessões persistentes e preflight seguro/inseguro.
3. PG18 F123–F131: Engine assina relatório Ed25519, Portal verifica por trust registry, controle de nonce único, isolamento tenant, concorrência/revogação de chaves, auditoria e mínimo privilégio do revogador.
4. Contrato cruzado Engine → Portal F118/F119 com Engine imutável: página limpa, ausente, idioma incorreto e preview privado.
5. Separar sucesso de CI de reais provas de OAuth Google, origem IBGE, MFA operacional, dados de clientes e publicação: nenhum deles é comprovado pelos testes sintéticos.

## Bloqueios para testes na VPS / lançamento

- **Servidor:** configurar homologação separada na Leaseweb, TLS real, proxy, firewall, PostgreSQL usuário próprio sem bypass e migrations revisadas, APP_KEY/segredos externos, backups/restauração e rollback.
- **Admin:** validar identidade de operador, acesso TTY provisionado, MFA real e recuperação segura, Redis rate limits distribuídos, retenção de eventos e auditoria imutável fora do banco de aplicação.
- **Clientes:** implementar login de clientes independente do CRM, associação de usuários e empresas, serviço ativo contratado, permissão por recurso, revogação, RLS de banco e trilha de aceites.
- **SEO/GSC:** autenticar emissor operacional do SEO Engine, KMS/rotação de chaves, OAuth GSC readonly de propriedade real, integridade e origem dos dados IBGE, páginas editoriais/idiomas e indexação revisadas.
- **Diretrizes:** AppSec/DBA/LGPD independentes, teste real de segurança e observabilidade, aceite formal para staging público e publicação. **Não liberar acesso real por simples flag de configuração.**

## Controles

Nenhum merge, deploy, DNS, credencial de cliente, checkout de pagamento ou integração Google real é executado por esta RC. As rotas administrativas estão desabilitadas por padrão; mesmo quando habilitadas em staging autorizado, a tela de revisão SEO contém só aviso e não exibe dados de clientes.

**ReleaseStatus: HOLD_VPS_STAGING_REAL_IDENTITY_ENTITLEMENT_KMS_AND_SECURITY_APPROVAL.**
