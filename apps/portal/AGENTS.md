# Guardrails do Codex — Portal Imagine F1-01-A

Escopo autorizado apenas para a etapa **F1-01-A**: scaffold Laravel isolado, configuracao PostgreSQL de exemplo sem credenciais, documentacao e testes locais sem integrações externas.

1. **Nao modificar arquivos fora de `apps/portal/`**, nao tocar `server/`, `public/` na raiz, `package.json` legado, bancos e dados.
2. **Nao executar migrations**, criacao de usuarios reais, checkout, billing, PSP, CRM, n8n, Cloudflare, deploy, VPS ou qualquer chamado externo.
3. **Nao criar SSO Portal–CRM**, nem compartilhar banco de identidade ou sessões. Universidade Imagine / Performance AI ficam fora de escopo.
4. **Nao reutilizar dados/testes legados**; o antigo Portal era prototipo e serve principalmente para ideias de negocio e fluxos.
5. **Nao declarar testes aprovados** sem ter executado os comandos e guardado resultados. Sem Composer/extension, registrar limitação.
6. **Nao dar merge em `main`**, nao fazer rollback automatico nem liberar etapas F1-01-B/C ou F1-02+.
7. Consultar decisões formais na branch `docs/portal-f1-00-2026-11-05`, PR #10; em conflito com o codigo prototipo, prevalece a diretriz documentada mais recente.

## Autorização posterior: F1-01-B-A (somente nesta branch filha)

A decisão `PORTAL-F1-01-B-DEC-01` na branch documental `docs/portal-f1-00-2026-11-05` autoriza exclusivamente código puro de organizações, vínculos e permissões com dados fictícios, testes PHPUnit e PR em rascunho contra `dev/fast-track-portal-2026-11-05`. A proibição histórica genérica da F1-01-B acima deve ser interpretada conforme essa autorização: **B-B, B-C, autenticação real, banco de dados, APIs, migrações, serviços externos, merge e deploy seguem proibidos**. Não utilizar segredos, dados de clientes ou identidades CRM para autenticação do Portal.


## Estado após merge controlado do PR #14 (06/11/2026)

A direção aprovou o merge **exclusivamente** na branch `dev/fast-track-portal-2026-11-05`; realizado **squash merge** no SHA `358af2dbee82cf34b9b0d1ed7215918ada7c3959`, após revalidar o head `916894fe9c322961d28d7a578a9335f137ceeb15`, CI e ausência de conflitos. A `main` permaneceu no SHA `50b683a499e674641df5749051f3d05f7ac3f627`. **A regra histórica "somente nesta branch filha" agora descreve a origem da implementação B-A, já integrada nesta branch de fast-track**, sem liberar novas fases por consequência.

O módulo integrado é **somente de domínio em memória**; não comprova autorização operacional com usuário, banco ou empresa real. **Permanecem abertos** a origem confiável do ator/membership e proprietário do recurso, estado da organização, sessão/MFA, isolamento PostgreSQL/RLS, origem de identidade CRM distinta, D02-SEC e P0. **Não iniciar B-B/B-C, P02-A/B/C, login, migrations, PSP, Google/Meta, deploy, novas despesas nem alterar `main` sem autorização expressa específica.**

**Próxima etapa candidata:** `PORTAL-PERF-P02-A`, domínio de catálogo do Performance AI com oferta `not_for_sale` e dados fictícios. Preparação documental não é autorização de programação. A experiência futura do cliente (catálogo, contrato, pagamentos, onboarding, relatórios) será pelo Portal; motor Performance fica tecnicamente independente, CRM com login separado. Não duplicar precificação real ou conectar bancos entre produtos.


## Diretriz superveniente da Direção — 08/11/2026 — desenvolvimento autônomo supervisionado

**Autorização expressa do responsável:** "Voce esta com autorização completa para avnçar agora em todos os potnos, So me pergunte aqui o que for realmente estreitamente critico ou necessario. Pode continuar no desenvolvimento do Imagine Portal."

Esta orientação **substitui as proibições de programação por falta de aprovação individual** nas seções históricas acima, mas **NÃO é homologação dos controles P0/D02/D03/SEC-R03, da segurança multiempresa nem liberação de produção**. Codex/IA podem executar recortes funcionais e testes controlados sem microaprovações, em branches isoladas, com revisão de CI e documentação de riscos.

É permitido especificamente no lote `PORTAL-AUTH-LAB-F1` (PR #80, branch filha do PR #78):
- criar SQL de laboratório sob `apps/portal/labs/client-auth/`, sem migrações carregadas pelo produto;
- usar PostgreSQL **descartável** em GitHub Actions e dados/credenciais inventados;
- validar HTTP sintético no **processo CLI PHPUnit**, com rotas que não existem em produção;
- desenvolver consulta e inclusão fictícia de solicitações, PK/FK composta, RLS, contas de BD restritas e testes negativos;
- criar/editar workflow de CI restrito na pasta `.github/workflows/` exclusivamente para o laboratório;
- registrar resultados completos, limitações e correções em PR draft.

Permanecem proibições explícitas até controles técnicos e decisão de risco específica: usar dados pessoais ou credenciais reais, conceder ALLOW com base em mocks, habilitar login ou áreas privadas para clientes, compartilhar sessão Portal/CRM, executar migrations na VPS, apontar para banco operacional, configurar PSP, cobrar valores, ativar integrações de produção, implantar Cloudflare/VPS, fazer merge automático em `main`, remover histórico ou contratar fornecedores. **A autonomia não substitui decisão crítica de credenciais, contratação, exposição pública, migração destrutiva e corte/abertura operacional.**

Cada lote deve ser verificável no GitHub, indicar exatamente quais testes foram executados ou não, manter gates D02-SEC/D03/RLS/P0/SEC-R03/T3 abertos até sua efetiva comprovação e seguir com a próxima entrega técnica sem solicitar confirmação rotineira. Não refazer o Portal todo sem evidências de incompatibilidade.
