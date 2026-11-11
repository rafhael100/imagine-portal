# GSC-F154 — Compatibilidade conservadora com SEO-F67

O validador PHP de laboratório `OfflineGscOverlapTrendPreview` foi atualizado para reconhecer o novo estado `REDACTED_GSC_ROWS_NO_SIGNALS` introduzido pelo SEO-F67.

- Se houver `redactedRows>0` em qualquer janela, o contrato aceita apenas `coverageComplete=false`, `overlapShareChange=null`, `reviewSignals=[]` e o status específico de privacidade.
- Se não há linhas redigidas, mantém o contrato anterior de cobertura completa/parcial.
- Compatibilidade com três avisos antigos (RC29) e quatro avisos (F67+), sem aceitar quatro campos arbitrários ou autorização de publicação.
- A suíte privada PHP de F152 foi expandida para simular a saída F67 e recusar tentativas de mudar status, delta, sinais ou retirar o aviso adicional de privacidade.

**Limite de evidência:** o CI do Portal ainda está fixado no SHA da RC29, e a simulação F67 é uma verificação contratual PHP, não uma integração autenticada Node→PHP da RC32. Atualizar o pin da RC32 e verificar compatibilidade completa requer mudança de workflow revisada separadamente.

Não há OAuth, Google HTTP, dados de cliente, grant real, alteração de rotas, migrations, publicação, deploy, DNS ou merge main/dev. Revisão independente AppSec/DBA/LGPD permanece obrigatória.
