# GSC-F157 — Paridade de tendências e ocultações SEO RC32 → Portal

O laboratório do Portal consumia tendências SEO-F65 de uma RC29 antiga. A F157 agora executa **o código RC32 fixado por SHA** (`f46b65aaef9b10e32d02e415907cd916a43b548e`), com `compareOfflineGscQueryPageOverlapPeriods` de verdade, e leva as respostas sintéticas ao validador PHP F152/F154 e ao renderer HTML F153.

## Cenários automatizados

- **Normal:** amostra com sobreposição 10%→50%, delta +40 p.p. e sinal fixo de revisão editorial, sem publicação.
- **Ocultado:** uma consulta com CPF fictício é descartada; status `REDACTED_GSC_ROWS_NO_SIGNALS`, sem delta nem sinal.
- **Parcial:** última página cheia; status `PARTIAL_GSC_BATCHES_NO_SIGNALS`, sem delta nem sinal.

O CI confirma contrato fechado no PHP, todas as flags de privilégio falsas, quatro avisos no DTO RC32, ausência de termos/URLs na saída, CSP/noindex no HTML, ausência de scripts, links ou formulários e aviso de amostra incompleta quando cabível. A CI não usa arquivo de cliente, nem acessa o Google; artefatos são temporários com limpeza automática. Permissões permanecem negadas.

Esta entrega reduz a dívida de compatibilidade Node/PHP, mas não comprova autenticação OAuth, origem de propriedade, grant por tenant, conformidade LGPD ou produção. Revisão independente AppSec/DBA/LGPD, MFA, rollback, observabilidade e homologação autorizada continuam obrigatórios antes de qualquer liberação.
