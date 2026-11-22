# OnePage Node R16 — testemunha atual antes de qualquer transição de produção (NO-GO)

## Fonte e alcance
Filha isolada de Portal [#1090](https://github.com/rafhael100/imagine-portal/pull/1090), HEAD de origem `28463501e3159bdf7641c0f5cde61486dde0f52e`. Sites [#619](https://github.com/rafhael100/imagine-sites/pull/619) permanece em trilha diferente; #541/#542 são referências históricas. Landing Page permanece sem alterações.

Na candidata Node/SQLite de #1090 havia recibos de confirmação e revisão, mas `createProduction` validava apenas o campo mutável `briefings.status='approved'` ao iniciar e não conferia mais os recibos em progress/preview/approve/publish. Uma atualização de status sem revisão ou alteração posterior dos bytes podia atingir essas transições.

O leitor local `approvedCurrent` confere, para um único pedido: status/versão, recibo append-only da confirmação do comprador titular, declaração de direitos, SHA-256 dos bytes JSON persistidos, recibo de aprovação do revisor com papel admin, mesma versão e digest, e manifesto de arquivos lidos novamente dos bytes atuais. Em qualquer erro nega. A validação é invocada no bloco de transação SQLite antes da mutação de produção, sem mudar o serviço de checkout nem introduzir endpoints de autoridade.

## Provas novas
Um pedido fictício: (a) atualização direta do status sem recibo editorial negada antes de iniciar; (b) aprovação real do revisor fictício após confirmação v1 permite início; (c) alteração de dados depois do início nega preview; (d) restauração dos bytes permite preview; (e) anexo de mesmo tamanho adulterado nega progresso e restauração permite. Testes Node 22/24 exigem CI da SHA final: sem alegar resultado antes do job.

## Limites
Esta é **somente uma candidata no Portal Node legado**; não prova origem oficial Laravel/PostgreSQL, identidade corporativa ou material imutável fora do processo. O arquivo em disco não é um snapshot arquivado e não existe transação distribuída abrangendo bytes e SQLite. A checagem impede transições da candidata quando os bytes correntes divergem, mas não revoga publicação externa já realizada. O contrato oficial Portal #976 segue com fontes negadoras; não vincular o Node a Sites. O aceite final da release e autorização de publicação permanecem separados.

Não houve acesso a VPS, produção, DNS, Cloudflare, Asaas real, chamadas OpenAI, credenciais, merge nem deploy. Sites #38 e os gates atuais seguem NO-GO.
