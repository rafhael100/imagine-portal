# Guardrails do Codex — Portal Imagine F1-01-A

Escopo autorizado apenas para a etapa **F1-01-A**: scaffold Laravel isolado, configuracao PostgreSQL de exemplo sem credenciais, documentacao e testes locais sem integrações externas.

1. **Nao modificar arquivos fora de `apps/portal/`**, nao tocar `server/`, `public/` na raiz, `package.json` legado, bancos e dados.
2. **Nao executar migrations**, criacao de usuarios reais, checkout, billing, PSP, CRM, n8n, Cloudflare, deploy, VPS ou qualquer chamado externo.
3. **Nao criar SSO Portal–CRM**, nem compartilhar banco de identidade ou sessões. Universidade Imagine / Performance AI ficam fora de escopo.
4. **Nao reutilizar dados/testes legados**; o antigo Portal era prototipo e serve principalmente para ideias de negocio e fluxos.
5. **Nao declarar testes aprovados** sem ter executado os comandos e guardado resultados. Sem Composer/extension, registrar limitação.
6. **Nao dar merge em `main`**, nao fazer rollback automatico nem liberar etapas F1-01-B/C ou F1-02+.
7. Consultar decisões formais na branch `docs/portal-f1-00-2026-11-05`, PR #10; em conflito com o codigo prototipo, prevalece a diretriz documentada mais recente.
