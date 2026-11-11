# Portal GSC-F143 — Compatibilidade restrita do teste de permissões entre as cadeias

A prova integrada [Portal F148](https://github.com/rafhael100/imagine-portal/pull/352) encontrou uma falha real no teste `PortalGscPermissionContractTest` ao unir a linha Portal F147 com GSC F142. A aplicação contém dois códigos de permissão distintos e sem concessão automática: `seo.gsc.connect` e `commercial.delivery-review.view`, mas o teste GSC antigo comparava o **array exato de enum cases**, impedindo qualquer extensão aprovada por outra linha.

Esta PR, derivada do SHA GSC F142 `500c7cbd2379a1869a52a8ad3133af5c62de830a`, altera **somente o teste de contrato**, não a implementação da autorização, migrações ou código OAuth.

A nova asserção:
- Exige obrigatoriamente os cinco códigos originais, inclusive `seo.gsc.connect`;
- Permite **apenas** `commercial.delivery-review.view` como código extra opcional, para a composição de duas branches;
- Rejeita permissão desconhecida, duplicada, curinga ou enum incompleto;
- Preserva os testes de revogação, isolamento de empresa/usuário, ausência de privilégios implícitos e checagem da migração SEO.

O teste passa tanto no ramo GSC isolado (cinco valores) quanto na **árvore integrada** com a permissão de revisão (seis valores). Não adiciona concessão, token OAuth, sessão, SSO, rota ou cliente; não altera `dev`, `main` ou produção.

Resultado esperado: a F148 deve usar este commit exato do GSC e **reexecutar todos os testes**, não simplesmente ignorar o teste que falhou. Seguimos NO-GO operacional até revisão AppSec/DBA/LGPD e homologação.
