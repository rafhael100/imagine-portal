# Portal F134 — Exportação atômica e privada da prévia interna

A F129 introduziu a nona página HTML de demonstração, destinada à revisão de entregas. O exportador está restrito a ambientes `local/testing` e impede sobrescrever o alvo, mas sua implementação anterior criava o diretório final e ia gravando arquivos individualmente. Uma exceção de Blade ou de escrita após várias páginas deixava **uma pasta parcial** que parecia uma prévia válida.

F134 grava as nove páginas primeiro em um diretório irmão aleatório e privado (`0700`), com arquivos `0600`. Só depois de concluir e validar todas as páginas executa uma operação de `rename` no mesmo filesystem, substituindo a publicação parcial por uma publicação em bloco. Se qualquer falha ocorrer, as páginas de estágio são removidas e a exceção é propagada; não há diretório final incompleto.

A suíte Laravel acrescenta um erro intencional no render do Blade de revisão **depois** das sete primeiras páginas, comprovando ausência do diretório final e ausência de sobras de estágio. Também confere as permissões Unix, a contagem de nove arquivos e a impossibilidade de sobrescrever uma exportação existente.

**Limites:** somente exportação interna local de HTML fictício; não é deploy, site hospedado, acesso a cliente, storage distribuído, assinatura de artefato nem controle de concorrência global. Um filesystem não confiável ou que permita trocar o alvo entre a verificação e o rename ainda precisa ser tratado fora desta função. Sem `main`, VPS, produção ou novas despesas.
