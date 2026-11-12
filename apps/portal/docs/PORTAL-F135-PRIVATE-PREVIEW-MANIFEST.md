# Portal F135 — Manifesto e verificação da prévia offline

A prévia local do Portal gera nove páginas HTML inteiramente fictícias, como demonstração de telas e fluxos — não é interface de cliente nem deployment.

O exportador F134 já prepara as páginas em diretório privado antes de publicá-las por `rename`. F135 acrescenta uma **verificação de integridade do pacote antes desse rename**. O pacote contém nove páginas HTML mais `manifest.json` com: versão explícita do esquema, indicador sintético, negações de autorização e a lista **fechada** de nove arquivos com tamanho e SHA-256.

A classe `DemoPortalArtifactManifest` valida inventário exato, nomes em ordem, ausência de symlinks, tamanhos limitados, hashes, formato JSON e flags de acesso/desdobramento fechadas. O comando `php artisan portal:demo-verify` usa somente o diretório fixo `storage/app/portal-demo-preview`, sem aceitar caminhos externos ou URLs; a verificação é proibida em ambiente de produção. O manifesto é gerado e testado no diretório staging, antes da alteração atômica do diretório final.

A suíte de testes do Laravel cobre pacote íntegro, corrupção do índice, arquivo não autorizado, substituição por symlink, permissões `0600` do manifesto, formato fechado e integração com a exportação de nove páginas. O tratamento de falha também elimina o manifesto de staging, sem deixar arquivo parcial ou criar rota de acesso.

**Limitações essenciais:** SHA-256 local detecta corrupção involuntária/alterações de conteúdo em relação ao manifesto; **não autentica o autor**. Quem conseguir alterar tanto HTML quanto manifesto pode gerar hashes coerentes. Isso não é assinatura digital, controle de acesso, publicação, prova de LGPD ou liberação operacional. A revisão independente e homologação seguem pendentes. Sem `main`, deploy, conexão real com CRM/Sites, dados de cliente, credenciais ou novas despesas.
