# Portal SEO F112 — callback OAuth vinculado à mesma transação da sessão

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
Continuação da F111 ([PR #849](https://github.com/rafhael100/imagine-portal/pull/849)).

## Mudança de arquitetura

A F111 verificava sessão, carimbo MFA sintético, membership, recurso
e grant GSC em PostgreSQL18. A F110 consumia o state OAuth em
outra operação. A F112 combina **as duas verificações em uma única
transação tenant RLS** antes de efetuar o CAS do state PKCE:

1. Validar a entrada e abrir transação com variável tenant local no PostgreSQL
2. Verificar identidade de sessão, MFA sintético, membership e recurso
   na mesma transação, usando papel de leitura sem UPDATE nas três tabelas
3. Recuperar o ator **da linha de sessão**, nunca de campos do cliente
4. Bloquear o grant GSC readonly com FOR SHARE e conferir
   tenant/ator/fingerprint/expiração
5. Classificar o callback com dados PKCE cifrados e consumir state uma vez
6. Confirmar o state e entregar somente metadados de revisão seguros,
   sem token, código, verifier, sessão, domínio ou identificação do usuário

Se faltar qualquer condição, retorna null. Sessão/membership/recurso
revogados antes da chamada impedem o consumo do state. A perda de
permissão não gera um callback permitido a partir de um resultado
previamente calculado em transação separada.

## Testes

CI: .github/workflows/portal-seo-f112-atomic-oauth-pg18-ci.yml

PHP 8.3/8.4 e PostgreSQL18 descartável executam as suites F108,
F109, F110, F111 e F112 em ordem com dados inteiramente sintéticos.

Os cenários F112 incluem: outra empresa, outro token de sessão,
recurso/propriedade incorretos, scope OAuth adulterado, membership
revogado e sessão revogada *com state ainda pendente*, callback
válido de uso único, replay em duas conexões, consentimento negado,
state expirado, produção bloqueada e ausência de segredos na saída.

## Limites que continuam exigindo trabalho

**Este código NÃO implementa login real nem valida MFA de verdade.**
Ele usa registros de laboratório para demonstrar composição
transacional e não tem rota HTTP. Mesmo com state corretamente
consumido, nenhuma permissão de token exchange, acesso à Google,
exibição de dados, publicação ou acesso produtivo é concedida.

A transação agrupa a revisão de sessão e o consumo do state,
mas as leituras de sessão/membership/recurso NÃO bloqueiam
revogação concorrente no papel de leitura usado neste teste.
Há uma janela de corrida a endereçar com serialização/locks
aprovados antes de produção. O grant GSC usa bloqueio distinto.
Também permanecem pendentes avaliação de starvation, timeouts,
SQL injection, GUC e pool sob carga em homologação.
Uma autorização GSC armazenada não é prova de consentimento
OAuth atual nem de posse da propriedade junto ao Google.

Próximas barreiras: emissores reais Portal, MFA, mecanismos de
revogação e autorização confiáveis, transporte Google HTTPS,
token vault com segredos fora do repo, LGPD, auditoria e
homologação controlada VPS com backup/rollback. Sem isso,
as contagens **Visitas, Leads e Clientes** seguem indisponíveis.
