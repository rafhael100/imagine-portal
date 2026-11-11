# GSC-F151 — Intenção segura da próxima página de Search Analytics

O componente `GoogleSearchConsoleNextPageIntent` recebe a resposta **v2** do SEO Engine RC28 e revalida integralmente o contrato agregado PHP F143/F148. Somente se `pageSize`, `lastPageRows`, `mayHaveMore`, `nextStartRow`, número de páginas, tenant e fingerprint estiverem coerentes, ele utiliza o planejador F144 para preparar o formato da próxima consulta `query,page`. Limite máximo: quatro lotes de até 1.000 registros. Não aceita continuação de contrato v1 sem tamanho explícito ou lotes já completos.

O teste de CI usa dois lotes sintéticos, verifica a próxima posição `startRow=4` com `rowLimit=2`, rejeita tentativa de alterar propriedade, permissões, limites, dados recebidos, modo produção e alteração do próprio envelope. **Esse planejamento não envia HTTP, não busca nem armazena credenciais e não autoriza produção.** A conexão OpenSEO existente é separada da autenticação do Portal.

Release bloqueado até OAuth de aplicação, IAM/MFA do tenant, vault e revogação, segurança de transporte, rate limits, revisão independente AppSec/DBA/LGPD e staging. Sem dados reais em GitHub, DNS, 301, deploy, merge `main` ou novos custos.
