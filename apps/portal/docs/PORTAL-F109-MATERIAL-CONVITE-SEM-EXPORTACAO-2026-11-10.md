# PORTAL-F109 — Proteção de segredos de convite contra exportação de objetos

**Data:** 10/11/2026. **Status:** revisão de segurança; não habilita login, emissão nem envio de convites.

## Risco identificado

A classe `PortalInvitationSecret` introduzida na F105 já usava token aleatório Base64URL de 256 bits, armazenamento persistente somente do hash SHA-256, revelação única e `__debugInfo()` redigido. Entretanto, a classe guardava **tanto o bearer quanto o digest como propriedades privadas do objeto**. Em PHP, uma inspeção via `var_export($obj, true)` pode incluir valores de propriedades privadas sem chamar `__debugInfo`. Ambos são equivalentes a segredo enquanto o convite estiver válido. Assim, a redaction anterior não cobria todas as rotas comuns de diagnóstico.

## Alteração implementada

- A classe deixa de declarar propriedades de instância para o bearer e seu hash.
- O material reside em uma `WeakMap` privada e estática, indexada pela instância. Isso evita que `var_export`, `json_encode` ou reflexão **sobre propriedades de instância** encontrem o material. A entrada é liberável quando a instância deixa de ter outras referências, sem prometer limpeza segura da memória.
- `takeDeliverySecretOnce()` devolve o bearer ao emissor de confiança no máximo uma vez, substituindo a referência guardada por `null`. `digest()` continua retornando o SHA-256 sob chamada explícita.
- `__debugInfo` continua redigido; `__serialize` e `__unserialize` continuam bloqueados. Agora também são bloqueadas conversão implícita em string e reconstrução por `__set_state` depois de `var_export`.
- A validação canônica de token, `hash_equals` e a impossibilidade de conceder sessão/permissão por essa classe permanecem inalteradas.

## Prova automatizada

`PortalInvitationSecretTest` verifica aleatoriedade, formato, uma única revelação, token de outro serviço recusado, hash canônico, e compara inspeções **antes e depois da revelação** do bearer. Confirma que `print_r`, `var_export` e `json_encode` não mostram nem o token nem seu digest, que não existem propriedades de instância, que restauração por exportação é proibida e que conversão para texto falha.

A matriz F109 usa PHP 8.3 e 8.4; o PR executa também a validação geral Laravel e o smoke Docker com a política de somente `/up`. Os testes F105–F108 baseados em token continuam compatíveis.

## Limites

`WeakMap` **não é cofre de segredos**, e PHP não garante apagamento de strings da memória. Ferramentas com acesso a reflexão de propriedades estáticas, debugger ou memória do próprio processo podem ler os valores. Um bearer ou digest devolvido ao chamador também pode ser logado por esse chamador. O mecanismo apenas evita vazamentos **acidentais por inspeção do objeto**. A entrega de convite deve permanecer no fluxo confiável, fora de analytics, URLs e CRM, com auditoria e sanitização de logs revisadas.

Nenhum endpoint de cliente, IAM real, MFA real, entrega de e-mail, sessão, RLS operacional, deploy, merge em main, mudança de VPS ou despesa foi autorizado. **PR draft / NO-GO.**
