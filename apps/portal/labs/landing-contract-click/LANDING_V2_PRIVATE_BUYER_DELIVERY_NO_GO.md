# Landing R29 — recibo e link de entrega privada após leitura independente (laboratório NO-GO)

Base exata: Portal #1060 HEAD `220db16fc42bab0dfb2a85137ed80d39b46b61ff`. Referências não duplicadas: Sites #9 (ACK não prova release), #474 (mTLS privado), Portal #1055 (clique v2), #1057 (transporte mTLS), #1059 (captação fictícia separada) e #1060 (estado do provider após perda de ACK). Sites #580 não é autorizador operacional; #541/#542 são apenas históricas.

## Avanço verificável

Somente depois de o provider **local fictício** registrar APPLIED com um único efeito após ACK 504, o Portal emite um recibo de **entrega sintética**. Antes disso, a operação `issue` deve falhar, mesmo que exista aceite v2 ou ACK alegado. A origem é conferida por outra conexão e role PostgreSQL 18 RLS, consulta HTTP ao estado do provider e segundo GET dos bytes servidos; correlaciona empresa, comprador, pedido, site, release v2, SHA do briefing, SHA HTML, geração e desafio de aceite v2.

O recibo é persistido em tabela descartável com expiração de dez minutos e lido pela role `landing_r29_reader` com `FORCE ROW LEVEL SECURITY`. Um endereço **loopback** `/lab/delivery` só aparece após leitura corrente, e o link `/lab/delivery/html?ref=...` só devolve ao comprador fictício da sessão os mesmos bytes já observados. Conhecer o link não autoriza acesso. Testar anônimo, outro comprador da mesma empresa, outro tenant, POST, referência arbitrária e divergência dos bytes após adulteração do ledger simulado. Revogar a fonte corrente deve invalidar o recibo, sem confiar no estado histórico APPLIED ou reutilizar aceite antigo. Nenhum domínio ou URL de produção é gerado.

A sessão de login, aceite do contrato, aprovação do briefing e vínculo de release permanecem **fixtures**, não writers oficiais. O provider HTTP é simulado e o recibo não é assinado. O HTML continua sem formulário, sem canal de leads, sem publisher e sem destino. A entrega privada fictícia não equivale à publicação, entrega comercial, autenticação nativa ou homologação. Nenhuma mudança de IAM, chaves, gateways, Cloudflare, DNS, VPS, código principal, main, Asaas ou deploy.

## Critérios e separação

A nova prova é somente o **recibo privado ao comprador com verificação independente da release**. Os testes anteriores de clique, geração, mTLS, evento de lead e reconciliação de ACK são pré-requisitos, não serão novamente contados como novas provas. Etapa 15 de lead CRM/Vendas real permanece NO-GO sem destino autorizado, release e formulário previamente aceitos, transporte independente e confirmação real. OnePage e API OpenAI não são parte desta trilha.

Pendente operacional: writers oficiais de autenticação/MFA, contrato e aprovação/revogação do briefing; fonte confiável de autorização para publicação, release no destino autorizado, recibo assinado, domínio/rota privada real, link de entrega ao comprador nativo e leitura independente CRM/Vendas. Gates Sites #38, IN127-R05/R06 permanecem bloqueados; rollback automático não foi autorizado.


## R33 — limite efetivo na leitura HTTP do provider local

A R32 confirma idempotência na emissão concorrente, mas `r29http()` ainda aceitava o corpo completo de HTTP 200 em memória antes de verificar o limite da prévia. Nesta candidata, o estado JSON tem orçamento de **4.096 bytes** e o HTML de **32.768 bytes**, aplicados por callback de streaming do cURL. Exceder qualquer orçamento interrompe o recebimento e falha fechado antes do parse e do recibo. Sem follow redirects, sem URL dinâmica, retries ou tráfego externo.

O ensaio usa exclusivamente servidor adversarial em `127.0.0.1:8791` que devolve HTTP 200 com estado de 12.288 bytes e HTML de 65.536 bytes; ambos são rejeitados. Depois o servidor é encerrado e a prova integrada anterior com provider local normal continua, verificando que o limite não impede a release sintética válida. Não é teste de OpenAI, CRM/VEN, login nativo, dados reais nem publicação.

## R34 — identidade única e canônica do link privado

O receptor da entrega local recusava URLs com parâmetros extras, chave `ref` duplicada ou alias codificado de `ref` de maneira apenas parcial: o PHP transforma `$_GET` antes de fazer a leitura. Agora só aceita a forma exata `/lab/delivery/html?ref=<32 hex minúsculos>`, comparando `QUERY_STRING` integral e `$_GET` sem parâmetros adicionais. Isso impede que duas representações distintas de URL sejam tratadas como um único recibo no registro/auditoria, mantendo sessão do comprador e digest atual obrigatórios.

Novo ensaio negativo em Chromium: comprador válido recebe HTTP 403 para parâmetro extra, chave repetida e nome de chave percent-encoded; o link original continua verificado pelo SHA e protegido por sessão. Os testes preexistentes R29–R33 são pré-requisitos, não novos marcos. Sem autenticação nativa, writer oficial, CRM/VEN, publicação, rede externa ou mudança de gates.\n