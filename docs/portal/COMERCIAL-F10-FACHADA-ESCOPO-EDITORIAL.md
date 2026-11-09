# Portal Comercial F10 — Fachada de assinatura condicionada a permissões tipadas

## Recorte implementado
- `TenantScopedPublicCatalogIssuer` exige organização de recurso, três memberships distintos e ativos, e os grants `commercial.catalog.edit`, `commercial.catalog.approve`, `commercial.catalog.publish` antes de executar assinatura Ed25519.
- As identidades de editor, aprovador e publicador declaradas nos eventos precisam coincidir com os atores dos memberships utilizados; organização idêntica em todas as etapas.
- Os papéis de etapa usados na rotina F3 são **reconstruídos** pela fachada após autorizações tipadas; ela não confia em campos `roles` do evento de entrada.
- O payload continua sem dados de cliente, tenant, preço, token ou papel; a etapa de integridade rejeita conteúdo modificado após aprovação.
- O fixture de CI PHP → Astro agora passa pela fachada tipada, com identidades sintéticas. O mini-autoloader do script de teste carrega apenas classes `App\` do próprio repositório; não gera dependência de fornecedor.

## Limites de segurança fundamentais
Uma membership instanciada no PHP **não é uma concessão confiável de acesso** até que seja lida de autenticação própria do Portal, com MFA, versão de grants e consulta transacional atualizada. Este laboratório não possui esse resolvedor nem banco ativo, e não estabelece uma barreira contra revogação concorrente; essa exigência permanece para integração operacional com F7.

`PublicCatalogSigner` e `ApprovedPublicCatalogIssuer` ainda existem como componentes de nível inferior. O serviço operacional final precisará expor **somente uma fachada autorizada** a um chamador interno confiável, mantendo os componentes inferiores inacessíveis fora do processo. Não existe rota de publicação nem endpoint.

Sem operações na main, VPS, Cloudflare, DNS, banco operacional ou pagamentos.
