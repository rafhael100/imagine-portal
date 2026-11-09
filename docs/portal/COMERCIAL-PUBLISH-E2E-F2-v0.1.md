# Portal Comercial F2 — Teste real PHP → Astro (isolado)

## Objetivo
Comprovar em um único workflow que a assinatura Ed25519 gerada pelo Laravel/PHP produz um catálogo que o site Imagine Comercial/Astro **efetivamente consome durante seu build**.

## Pipeline implementado
1. PHP 8.3 gera um par de chaves Ed25519 efêmeras **dentro do runner**, sem armazenar chave privada.
2. Um emissor PHP já testado produz envelope `imagine-catalog-v1` fictício em diretório temporário.
3. GitHub Actions faz checkout do Imagine Comercial **com SHA fixo** (`54853ffbb063292b26d45ded7746f895dbd693f7`) e executa `npm ci`.
4. O pipeline prepara pin de integridade do releaseId e hash SHA-256 correspondente à carga assinada.
5. Executa `npm run build` do Astro usando o envelope PHP, confirma o HTML gerado e valida o sitemap.
6. Um segundo build com hash incorreto **deve falhar**. O teste é negativo e não publica artefatos.

## Resultados esperados
- Emissão PHP / consumo Astro compatíveis e protegidos contra erro de integração de formato.
- HTML público exibe somente metadados aprovados por contrato; sem preços ou dados pessoais.
- Falha de integridade interrompe o build.

## Restrições
O contrato é **apenas de laboratório**: não vincula sessão de usuário, tenant, MFA, aprovação editorial, versionamento no banco, outbox transacional, revogação ou API real do Portal. A chaves são descartadas. Não executa deploy, checkout, emissão fiscal, DNS, pagamento ou comandos na VPS.

Os PRs continuam em rascunho. A publicação real exige aprovação exclusiva e homologação de segurança do Portal.
