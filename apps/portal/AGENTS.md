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
