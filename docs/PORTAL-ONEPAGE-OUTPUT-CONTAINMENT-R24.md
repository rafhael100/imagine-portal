# Portal OnePage R24 — contenção de saída da fonte privada (23/11/2026)

**DRAFT / CI-only / NO-GO.** Filho direto da PR #1098, HEAD fixa `a3bf63ff48f57415ef8dfed51669e17737ab9958`. Não alterar main, DEV, Sites ou runtime de produção.

## Defeito e correção

A fronteira `OnepageApprovedBriefExportGate::prepare` consulta portas privadas injetadas para contrato, briefing e aprovação. Uma implementação de leitura confiável porém defeituosa poderia escrever no stdout com `echo` antes de retornar ou lançar exceção. O gate convertia o erro em `ONEPAGE_APPROVED_BRIEF_EXPORT_DENIED`, mas não desfazia os bytes já emitidos. Uma resposta HTTP que chamasse esse gate poderia vazar o briefing mesmo em tentativa negada.

A correção inicia um buffer de saída exclusivo **antes da primeira consulta** e descarta os buffers da execução, inclusive os abertos pelas portas, em sucesso e erro. Buffers já pertencentes ao chamador não são encerrados. Permanece uma defesa contra saída **acidental**: uma porta maliciosa que deliberadamente descarte ou dê flush de buffers não é isolada; adaptações operacionais ainda exigem revisão.

## Prova sintética

`tests/onepage-approved-brief-output-r24.php` reutiliza o teste-base e as classes originais: verifica resultado válido com saída acidental, revogação durante segunda leitura privada, buffer aninhado e exceção da porta, exigindo ausência de marcador na saída, restauração do nível do buffer, erro uniforme, leituras mantidas e quatro flags de autoridade false. Sem novas fixtures comerciais, dados pessoais, credenciais, HTTP, banco, API ou dependências pagas.

Workflow DRAFT-only PHP 8.3/8.4 com checkout pinado na SHA exata, sem deploy. Aguardar o resultado real da HEAD do PR antes de declarar CI aprovado. Outras suítes globais podem executar automaticamente, mas o teste de laboratório não promove nenhum gate.

## Dependências e bloqueios

- Portal [#976](https://github.com/rafhael100/imagine-portal/issues/976): nenhuma fonte de contrato/briefing/aprovação operacional autenticada e revogável.
- Sites [#38](https://github.com/rafhael100/imagine-sites/issues/38): homologação, Cloudflare, IAM/AppSec/DBA/LGPD e fonte independente de aceite ainda NO-GO.
- Não demonstra atomicidade entre leitores; adaptador de produção precisa de transação/CAS e revisão própria.
- Sem merge, deploy, VPS, Cloudflare, dados de clientes, cobrança, APIs Ads/OpenAI ou alteração de regras comerciais.
