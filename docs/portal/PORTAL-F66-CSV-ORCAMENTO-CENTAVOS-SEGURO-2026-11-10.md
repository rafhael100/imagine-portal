# Portal F66 — exportação CSV offline dos cenários de orçamento BRL

Data: 10/11/2026. Base Portal F65, contrato F64, orçamento F107 do Performance.

## Funcionalidade implementada

O utilitário CLI `apps/portal/tools/f66-export-budget-calendar-csv.php` aceita somente arquivo JSON **local**, sem symlink e até 128 KB. Antes de enviar qualquer byte à saída padrão, valida integralmente os cenários DEMO com o contrato tipado F64: orçamento inteiro em centavos, divisão Google/Meta em pontos-base, dias UTC inclusivos, somatórios conciliados e flags operacionais falsas.

Exporta CSV com **BOM UTF-8**, separador **ponto e vírgula** e linhas CRLF, adequado para importação em planilhas no Brasil. Colunas: `cenario_demo;data_utc;google_ads_centavos;meta_ads_centavos;total_centavos`. Os valores monetários são **centavos inteiros**, não floats nem quantias já gastas. O identificador do cenário permite somente `scenario-demo-...` alfanumérico com hífens; não aceita fórmula de planilha, campo arbitrário, ID real ou texto não confiável. Usa `fputcsv` com escape explícito vazio.

O exportador nunca publica arquivos, faz requisições HTTP, abre rotas, envia anúncios ou altera saldo. Funciona somente com `APP_ENV=testing` e flags de laboratório F64/F66.

## Evidências

[GitHub Actions Portal F66 #42841878491 — SUCCESS](https://github.com/rafhael100/imagine-portal/actions/runs/42841878491) executou **52 verificações próprias** do CSV (BOM/CRLF, 3 dias, 29/02 bissexto, soma R$100,01, Google R$55,00, Meta R$45,01, proteção contra injeção de fórmulas e recusa de flags/JSON adulterado, produção e symlinks). Passaram também **60 verificações F64** e **42 verificações F65 HTML**.

A integração com JSON **realmente produzido no PostgreSQL16 descartável** será verificada na etapa Performance F110, com checkout fixo deste código do Portal.

**NO-GO de produção:** IAM/MFA/S2S Portal, revisão AppSec/DBA, aprovação e limite de orçamento do anunciante, OAuth/permissões Google/Meta, LGPD, VPS, homologação e gates P0/HT-R01/SEC-R03/T3. Nenhuma alteração em `main`, deploy, contas publicitárias, fornecedores ou cobrança.
