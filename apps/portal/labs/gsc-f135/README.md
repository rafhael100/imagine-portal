# GSC-F135 — Contrato de leitura offline do motor SEO no Portal

O Portal recebe em **modo de teste CLI** o DTO `seo-gsc-offline-workflow-v1` definido no SEO Engine F50. A classe `OfflineGscWorkflowPreview` faz uma validação estrita do envelope e dos totais, mas nunca o trata como autorização, mesmo se o conteúdo declarar status positivo. Dados brutos de consultas, tokens e campos arbitrários são recusados.

O teste aceita exclusivamente a empresa sintética `imagine-demo` e o fingerprint de `sc-domain:example.com`. Requer `APP_ENV=testing` e `PORTAL_GSC_WORKFLOW_LAB=1`, além de execução PHP CLI. Nenhuma rota, serviço Laravel, chave, OAuth, chamada Google, migração ou banco é criado.

Gates externos para uso real: conta Google autorizada, grants de tenant verificados independentemente, consentimento, token vault seguro, IAM/MFA ativo, homologação e revisão independente SEG/DBA/LGPD. **NO-GO para produção**, sem merge `main` nem deploy.
