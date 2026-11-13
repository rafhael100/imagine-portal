# PORTAL-P0 — avaliação privada de prévia fictícia via HTTP loopback (NO-GO)

## Objetivo

Permitir **percorrer as 11 páginas HTML e o roteiro de seis etapas no navegador**, sem abrir rotas Laravel, alterar a VPS, publicar um domínio, ativar clientes, pagamentos, contratos ou conectar bancos. Este componente somente **verifica** a integridade do artefato HTML sintético gerado pelo Portal e, após autorização explícita do operador na linha de comando, serve **somente em 127.0.0.1**.

Não equivale à homologação operacional Portal ↔ Comercial ↔ Sites ↔ CRM. Dados e conteúdo são inteiramente fictícios, e os testes CI não dão autorização de produção.

## Testar sem abrir porta

No diretório do repositório onde o script existe, com o arquivo ZIP já obtido do artefato aprovado de CI:

\`\`\`sh
python3 apps/portal/tools/serve_private_preview.py \
  --zip /caminho/privado/preview.zip --check
\`\`\`

O script só aceita exatamente 11 nomes de HTML mais \`manifest.json\`, rejeita duplicatas, caminhos extras, symlinks ZIP, arquivos de tamanho anômalo e HTML ativo, verifica todas as somas SHA256, tamanho, identidade original da logomarca e flags obrigatórias de NO-GO. Não envia nada a servidores externos e não abre socket no modo \`--check\`.

## Visualizar somente com autorização humana, em ambiente isolado

\`\`\`sh
python3 apps/portal/tools/serve_private_preview.py \
  --zip /caminho/privado/preview.zip --serve --port 18417
\`\`\`

No **mesmo host**, abra \`http://127.0.0.1:18417/index.html\`. O script carrega tudo em memória antes de abrir a porta, serve somente os onze HTML autorizados, impede acesso a manifesto, dotfiles, pastas ou requisições de escrita; usa Host estrito, CSP, no-store, noindex e proibição de iframe. **Não execute como root.** Use usuário sem permissões de outras aplicações. Pare com Ctrl+C.

Se o operador **já dispuser de autorização e acesso SSH à VPS privada**, poderá encaminhar a porta local até o loopback da VPS via túnel SSH estabelecido **pelo próprio operador**, por exemplo:

\`\`\`sh
ssh -N -L 18417:127.0.0.1:18417 usuario@vps-autorizada
\`\`\`

Nesse caso o navegador **local** continua usando \`http://127.0.0.1:18417/index.html\`; somente o SSH do operador transporta o conteúdo. **Não criar regra de firewall, domínio, proxy público, contêiner de produção, GitHub Pages ou Cloudflare Tunnel nesta etapa.** Não incluir senha, token, credencial ou dado de cliente no ZIP. O servidor Python não oferece autenticação própria: o isolamento depende do acesso ao host/túnel autorizados.

O uso efetivo na VPS não foi executado nem comprovado por esta PR. Um relatório de CI significa somente teste em runner temporário, e não prontidão operacional da VPS.

## Segurança e critérios de aceite

O workflow \`portal-private-preview-http-ci.yml\` usa somente Python stdlib e valida: ZIP íntegro e sintético, recusa de conteúdo adulterado, flags de produção falsas, duplicatas e extensão indevida; retorno GET/HEAD no loopback; recusa POST/PUT/DELETE/PATCH, dotfiles, paths não permitidos, manipulação da origem Host e ausência de cache. Não instala aplicações, não usa GitHub Secrets, não faz deploy e não cria banco.

Antes de habilitar uma jornada real com usuários e dados, devem existir revisão humana independente de UX, revisão AppSec/DBA, identidade MFA e revogação, isolamento tenant/RLS, evidências operacionais da VPS, políticas LGPD, autorização de publicação e testes ponta a ponta. **Status da entrega: NO-GO PARA SERVIÇO REAL.**
