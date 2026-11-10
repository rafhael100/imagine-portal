# Portal F65 — tela estática de planejamento de orçamento BRL

Data: 10/11/2026. Base Portal F64; nenhuma rota pública.

O utilitário CLI **apps/portal/tools/f65-render-budget-calendar.php** aceita exclusivamente arquivo JSON local de até 128 KB, sem links simbólicos, e exige a validação independente do Portal F64. Os dados são cenários fictícios provenientes do motor F105 via PostgreSQL F106 e leitor Laravel F107.

O HTML responsivo mostra orçamento total em BRL, distribuição Google Ads/Meta em percentuais de pontos-base, períodos e tabela diária de centavos conciliados. A formatação monetária usa exclusivamente operações inteiras (sem floats de moeda), e os campos são escapados em HTML. Uma política Content Security Policy nega scripts, conexões externas, imagens e formulários; o arquivo não inclui links, botões, autenticação, transmissão de mídia ou cobrança.

A mensagem **DEMONSTRAÇÃO NÃO HOMOLOGADA** permanece visível e explica que nenhum orçamento foi enviado ao Google Ads ou à Meta, que não existe aprovação humana verificada nem execução real.

## Evidências

[GitHub Actions Portal F65 #42839352549 — SUCCESS](https://github.com/rafhael100/imagine-portal/actions/runs/42839352549): **42 verificações F65** de HTML, valores R$100,01 total / R$55,00 Google / R$45,01 Meta, percentuais 55%/45%, dia bissexto 29/02, sem scripts/links/inputs e negativas de JSON adulterado, dados reais, orçamento inválido, flags de cobrança/execução e execução fora do laboratório. As **60 verificações do Portal F64** também foram reexecutadas com sucesso.

O CI inicial detectou somente divergência de maiúsculas/minúsculas na expectativa textual do aviso de não execução; o teste foi corrigido sem reduzir as regras de segurança e o CI final ficou verde.

A integração PostgreSQL→Laravel→Portal HTML ocorre na etapa Performance F109 com checkout pinado do Portal. **NO-GO para produção:** IAM/MFA/S2S, AppSec/DBA, OAuth/autorizações Google/Meta, LGPD, políticas reais de orçamento e aprovadores, VPS/homologação e gateways financeiros continuam pendentes.
