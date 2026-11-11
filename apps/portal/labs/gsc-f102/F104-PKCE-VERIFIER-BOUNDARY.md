# GSC-F104 — Não divulgar o PKCE verifier

O contrato de início da transação OAuth no laboratório retorna somente `state`, `pkceChallenge`, `pkceMethod`, escopo readonly, expiração e sinalizadores de HOLD. **Jamais retorna `pkceVerifier`**. O verifier é mantido exclusivamente na transação interna do servidor, pois será necessário no futuro no token exchange feito pelo backend, e não pelo navegador.

## Garantias testadas

- Estado e challenge gerados separadamente, aleatórios e distintos.
- Payload externo não contém verifier, client secret ou tokens.
- Fluxo de consumo continua one-time, associado a ator/empresa/propriedade/callback, com TTL de cinco minutos e rejeição cross-tenant, expired e replay.
- Zero chamadas a Google; sem tokens, sessão real ou autorização de produção.

## Gate posterior

Em backend real, persistir apenas estado criptograficamente protegido/hasheado e verifier criptografado em armazenamento com TTL e consumo atômico; nunca registrar o verifier em logs, redirect ou frontend. A identidade, MFA, grant, consentimento e associação exata da propriedade devem ser comprovados por autoridades próprias do Portal antes de qualquer troca de código.
