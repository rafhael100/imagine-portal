# GSC Slice W — origem verificável do callback antes do planejamento de tokens

O Lote W corrige uma lacuna de integridade entre os componentes J e L: um objeto `GoogleSearchConsoleOAuthCallbackOutcome` podia ser construído manualmente por outro trecho PHP e apresentar um campo `stateConsumed=true` sem que o ledger PostgreSQL tivesse consumido o estado.

## Correção

- `GoogleSearchConsoleOAuthCallbackCoordinator` registra **somente em `WeakMap` privada e local ao processo** os objetos retornados após a conclusão do `UPDATE ... RETURNING` do ledger e conferência do verificador PKCE. Isso vale para retorno com código e para consentimento negado.
- `safeReview().stateConsumed` é verdadeiro somente para objetos emitidos pelo coordenador; um objeto fabricado ou clonado retorna **false**.
- `GoogleSearchConsoleOAuthTokenExchangePlan` agora exige `issuedByConsumedState($outcome)` antes de acessar código ou verificador e antes de montar a requisição de troca OAuth. Campos de status autodeclarados não são mais aceitos como prova de consumo.
- A identidade dessa prova é **um objeto PHP na mesma execução**, não um JWT, cookie, token de sessão, grant Google ou autorização para enviar HTTP. Um processo distinto não pode reutilizá-la; a preparação de troca deve ocorrer no mesmo request backend autenticado após o CAS.
- A classe ainda retorna `authorizesHttp=false` e continua sem enviar Google HTTP, persistir tokens ou exibir dados de clientes.

## Testes

O laboratório L verifica que callbacks construídas artificialmente não produzem um token plan. Os testes com PostgreSQL **real descartável** em J/N confirmam que o caminho legítimo, com state efetivamente consumido, continua montando o formulário correto. O laboratório J também testa objeto clonado, tentativa de usar status falsificado, config de callback hostil, negação consumida e não exposição de código/verificador.

## Limites de segurança

`WeakMap` é uma proteção contra **uso acidental ou falsificação de DTO dentro do processo PHP**, não uma prova remota de identidade. A aplicação ainda depende de sessão Portal/MFA, isolamento por tenant, SQL protegido, backend HTTP auditado, callbacks registrados e confirmação real Google. Não converter este componente em API pública; nunca serializar os segredos.

**DRAFT/NO-GO:** sem merge em dev/main, deploy, OAuth real ou dados de clientes. Exige revisão AppSec/DBA/LGPD antes de habilitar troca real de tokens.
