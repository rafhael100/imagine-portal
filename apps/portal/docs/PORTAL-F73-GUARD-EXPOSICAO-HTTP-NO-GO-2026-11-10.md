# PORTAL-F73 — Controle de exposição HTTP do laboratório (10/11/2026)

## Objetivo e escopo
Adicionar um bloqueio automatizado contra publicação acidental dos exportadores e verificadores sintéticos F64–F72 nas superfícies HTTP do novo Laravel. **Este gate é estático e preparatório, não é aprovação de deploy.**

A ferramenta `apps/portal/tools/f73-audit-offline-runtime-surface.mjs` percorre `routes/`, `bootstrap/`, `public/`, `config/` e `app/Http/` (quando existente) e rejeita referências a ferramentas laboratoriais e flags de ambiente. Enquanto o Portal não tem autenticação e autorização operacionais homologadas, o teste rejeita declarações `Route::` e exige o health-check `/up` no bootstrap. Os testes incluem injeção simulada de rotas login/API, importações do exportador no público, flags de laboratório, caminhos simbólicos e alterações do health-check.

## Limitações expressas
- Não é auditoria de imagem Docker, dependências, Composer classmap, JavaScript completo, IAM de clientes, banco real, OAuth, billing ou infraestrutura. A revisão/integração com o trabalho de runtime restrito da revisão F92 precisa ser tratada separadamente antes de merge.
- Não impede acesso de um administrador do sistema de arquivos a ferramentas no checkout; limita **apenas exposição involuntária por arquivos de entrada HTTP inspecionados**.
- Não presume que os scripts F65–F72 sejam adequados para uso por cliente ou produção.
- Futuras rotas legítimas de negócio exigirão substituir esta política temporária por uma allowlist de rotas autenticadas/autorizadas e testes de integração antes de ativá-las.

## Prova e liberação
Workflow `.github/workflows/portal-f73-runtime-lab-exposure-ci.yml`, Node 22/24: exige evidência de sucesso na HEAD exata. Testes passando não equivalem a produto operacional. Não modificar `main`, DNS, VPS, segredos, Ads ou cobranças. PR draft, no deploy, sem novos custos.
