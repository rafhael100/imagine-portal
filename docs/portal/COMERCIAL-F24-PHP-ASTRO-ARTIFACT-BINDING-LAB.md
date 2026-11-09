# Portal + Comercial F24 — Validação cruzada do release assinado e do HTML

09/11/2026 — Laboratório de CI, sem integração operacional.

## Objetivo
Verificar que o catálogo sintético emitido pela cadeia PHP do Portal chega ao Astro Comercial com os mesmos metadados e corresponde ao SHA-256 de todos os arquivos do build. O Comercial já controla Ed25519, releaseId e digest do catálogo. A fase F24 acrescenta a ligação entre os dados assinados, o HTML e o inventário estático.

## Reprodutibilidade
O workflow .github/workflows/commercial-catalog-e2e.yml fixa o Imagine Comercial no commit 9f36f770e8b19b37f9f5ac44ed84f5ae88e3d022 (PR #24, validado com 96 testes Comercial e 35 testes SEO). Executa PHP 8.3, PostgreSQL 18 descartável, assinatura do fixture PHP, build Astro, comparação de metadados, verificação do relatório SHA-256, auditor SEO e teste de rejeição de HTML alterado.

A verificação também confirma que o releaseId e o hash do payload coincidem com os pins fornecidos ao processo de compilação. A chave Ed25519 é gerada exclusivamente para o teste e nunca integra os commits.

## Restrições
O próprio fixture declara papéis, usuários e permissões sintéticos. Não prova MFA, sessão Portal real, autorização do usuário em produção, isolamento multiempresa real, controles externos de replay/revogação ou entrega ao Cloudflare. O relatório SHA-256 não autentica quem publicou. Nenhum deploy, merge ou alteração de domínio é permitido por este teste.

## Critério de sucesso
Somente afirmar compatibilidade PHP -> Astro F24 após CI da branch desta prova executar com conclusão success. Manter pull request em rascunho empilhado sobre o Portal PR #142 e a ponta Comercial PR #24. Qualquer revisão futura do commit fixado requer nova execução de CI.
