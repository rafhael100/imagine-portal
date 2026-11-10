# GSC-F118 — Contrato de resposta de token OAuth somente leitura no domínio Laravel

## Implementação

A classe `GoogleSearchConsoleReadOnlyTokenResponse` valida em memória **apenas a forma** de uma futura resposta de troca OAuth Google. Respostas admitidas têm `access_token`, `token_type=Bearer`, `scope` exatamente `https://www.googleapis.com/auth/webmasters.readonly`, `expires_in` inteiro entre 60 e 3600 segundos e `refresh_token` opcional. A validação recusa scopes de escrita ou combinados, campos desconhecidos (incluindo `id_token`), tokens vazios, controles, tipagem inválida e expiração fora dos limites. A lista fechada segue o contrato offline F105, agora tipado no código da aplicação.

O método recebe o payload como parâmetro sensível de PHP 8.3+ e **retorna somente booleano**, nunca access token, refresh token ou client secret. Não faz chamadas de rede, logging ou persistência, e os métodos de capacidade para acessar Google/armazenar tokens retornam sempre `false`. Os testes verificam formas válidas e dezenas de respostas adversariais, além de confirmar que mesmo URL F116 + callback F117 + resposta F118 válidos em sintaxe **não** equivalem a OAuth autorizado.

## Limites de segurança

Este contrato não confirma que a resposta veio do endpoint Google, nem que houve troca legítima de código, consentimento, MFA, grant da empresa, verificação da propriedade, token vault ou autorização de produção. Isso exige backend confiável que execute POST HTTPS em `https://oauth2.googleapis.com/token`, com credenciais em cofre e fluxo transacional F111/F112, seguido de validação específica da propriedade.

O requisito de escopo presente e exato é intencionalmente fechado: se uma resposta do provedor não trouxer `scope`, a integração futura deve usar uma evidência segura da permissão efetiva, sem simplesmente tratar ausência como sucesso. Não aceitar tokens por suposição.

Sem endpoints novos, migrações, credenciais, deploy, merge main ou custos. Auditoria AppSec/DBA independente, homologação na VPS e autorização de liberação permanecem obrigatórias.
