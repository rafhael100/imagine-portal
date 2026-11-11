# Portal F139 — Claim exclusivo para exportação local da prévia

A F134 prepara as nove páginas HTML em um diretório privado e as publica com `rename` após as verificações F135–F138. Duas execuções simultâneas do mesmo comando de revisão ainda poderiam preparar o mesmo destino em paralelo e disputar o rename.

F139 usa um arquivo marcador irmão `<destino>.exporting`, aberto com `fopen(..., 'x+b')` (**criação exclusiva**) antes de montar a prévia. Se outro processo já reservou esse destino, o segundo processo é recusado e não grava HTML. O marcador é removido no bloco `finally` após sucesso ou erro. Se o processo morrer abruptamente, o marcador permanece e exige verificação/limpeza manual: o comportamento é deliberadamente **fail-closed**.

Os testes Laravel cobrem marcador pré-existente (sem exclusão por segundo processo), falha tardia de Blade, limpeza após sucesso e ausência de diretório final parcial. As provas anteriores de HTML sintético, CSP, navegação, manifesto, links e permissões de arquivo continuam ativas.

**Limitação:** trata-se de serialização cooperativa entre processos do próprio exportador, **não** exclusão garantida contra programas externos que adulterem o diretório fora desse protocolo. A implementação não fornece `renameat2(RENAME_NOREPLACE)`, assinatura de artefato, segurança de diretório compartilhado com usuários adversariais nem publicação operacional. Restrito a `local/testing`, sem acesso real, deploy, CRM, Sites, VPS ou despesas.
