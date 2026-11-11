# Portal GSC-F155 — Handoff de revisão SEO-F74, somente laboratório

A classe PHP `OfflineGscQueryPageReviewPreview` aceita exclusivamente o envelope agregado `seo-portal-query-page-review-offline-v1` do motor SEO-F74. Ela verifica formato fechado, tenant/fingerprint sintéticos de laboratório, SHA, período, contadores, consistência aritmética da paginação, regras de ocultação e vocabulário de revisão.

Nunca exibe consultas/URLs, não recebe OAuth, grants reais, dados privados ou tokens. As permissões de exibição/publicação/leitura são obrigatoriamente falsas, assim como em F74. Se houver linhas ocultadas ou batch parcial, qualquer sugestão de revisão é recusada. O código não tem acesso a rede nem rotas públicas.

Os testes adversariais em `test_php_query_page_review.php` são chamados pela suíte F152 já presente no workflow CI, para cobrir mutações de permissões, contadores, escopo e sinais. **Isso ainda não comprova paridade Node RC32 → PHP**: o workflow F79 está pinado à antiga RC29 e requer atualização controlada do SHA e testes de integração em revisão separada.

Sem SSO, OAuth, Google HTTP, dados reais, migrations, deploy, DNS ou merge main/dev. Segurança AppSec/DBA/LGPD e homologação são gates obrigatórios.

Um tamanho de página diferente, mas matematicamente consistente, é aceito como evidência apenas sintática: **não** equivale a demonstrar qual limite o Google realmente recebeu. O backend autenticado precisa confrontar o relatório com o plano de requisição original antes de exibir qualquer dado.
