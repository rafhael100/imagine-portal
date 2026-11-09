# Portal Comercial F4 — Interface de revisão editorial interna (sem rota HTTP)

## Entrega
- Leitura comparativa de dois catálogos públicos: itens adicionados, alterados, preservados, removidos ou bloqueados.
- Bloqueio visual de alterações de slug, versões antigas, conteúdo modificado sem incremento e itens não publicados.
- Tela Blade reutiliza o layout e os componentes de acessibilidade do Portal, mas NÃO entra em navegação nem cria rota web.
- Exemplo com serviços ilustrativos e sem preços, contratos, dados reais ou chaves.
- Testes PHPUnit de lógica, escaping XSS, proteção contra dados privados e inalterabilidade das rotas e sessões.

## Garante que NÃO faz
- Nenhum botão de salvar, aprovar, publicar, enviar lead, cobrar ou alterar DNS.
- Não é um painel CMS operacional e não fornece AuthN/MFA/RBAC; a tela somente torna o fluxo editorial visualizável para homologação de interface.
- Não valida a verdade de uma afirmação comercial. Editor e aprovador reais precisarão ver evidências e versionar o conteúdo com controle de concorrência.

## Próximos gates
Construir adaptadores autenticados ao repositório transacional do Portal, permissões de editor/revisor/publicador, outbox e trilha de auditoria por empresa; só depois expor rota protegida. Testar alterações na integridade do catálogo, rollback e envio assinado ao build Astro.

PR draft empilhado sobre F3, sem merge main ou deploy.
