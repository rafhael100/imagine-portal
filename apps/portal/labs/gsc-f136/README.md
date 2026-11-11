# GSC-F136 — Prova executável de contrato entre SEO Node e Portal PHP

A CI de revisão baixa o código do Imagine SEO Engine no SHA fixo `7cc025823dbc0af5f680885c5f5e2f4b96302fe4` (F51). Executa `reviewOfflineGscReadiness` (F50) com empresa/domínio e consultas **sintéticos**, gravando a saída sanitizada somente em arquivo temporário de CI.

O validador PHP lê o mesmo DTO produzido pelo Node e exige o contrato exato, métricas coerentes, consulta pessoal suprimida e todas as permissões reais falsas. Não existe conexão OAuth, Google, rota, credencial, banco, cliente ou publicação. O arquivo temporário é apagado na execução.

**Limitação:** paridade sintética de DTO não demonstra identidade do usuário, propriedade Google, segregação de produção ou autorização legal. Manter `HOLD` até AppSec/DBA/LGPD, IAM/MFA, consentimento, token vault e homologação autenticada. Sem mudança na `main` e sem deploy.
