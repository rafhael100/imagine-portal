# GSC-F109 — Emissão de state condicionada a grant SEO (laboratório)

## Entrega

O laboratório PostgreSQL 18 acrescenta uma barreira para a emissão de state OAuth cifrado. A permissão exigida é **exatamente** `seo.gsc.connect`, associada a digest SHA-256 de sessão sintética, ator UUID, empresa UUID, fingerprint da propriedade, confirmação fictícia de MFA, prazo e revogação.

A função SQL de emissão F109 valida o grant **antes** de executar o emissor F108; a mesma transação persiste state e envelope PKCE ou não insere nada. O cliente técnico perde EXECUTE nas funções legadas de emissão F106 e F108, evitando que contorne o novo controle. O grant fica em tabela FORCE RLS sob papel NOLOGIN, sem SELECT do cliente. O consumidor F108 mantém consumo único e validação do contexto.

CI PostgreSQL 18 + PHP 8.3/8.4 exercita negação padrão, ausência de MFA, sessão/empresa/ator/propriedade divergentes, expiração, revogação, formato inválido, tentativa de invocar emissores antigos e leitura proibida da tabela de grants. Os testes F106/F108 são executados antes da instalação do gate F109; após a instalação, os emissores antigos devem ficar inacessíveis ao papel cliente.

## Advertência indispensável

**Este NÃO é o cadastro real de permissões do Portal.** O grant é criado por dados artificiais do teste, sem login, autoridade Laravel, sessão persistente autenticada, verificação real de MFA, vínculo com identidade, consentimento ou validação Google. A origem da sessão/empresa segue não confiável no laboratório. Um backend real deverá derivá-los apenas da sessão Portal autenticada e verificar as revisões de segurança, grants em PostgreSQL e titularidade exata antes de emitir state; nenhuma rota HTTP é criada aqui.

Não foram alteradas migrations operacionais, interfaces do Portal, cookies, main, dados reais, tokens, fornecedores ou produção. O laboratório é incapaz de autorizar Google e permanece NO-GO até o parecer AppSec/DBA independente e a homologação autenticada.
