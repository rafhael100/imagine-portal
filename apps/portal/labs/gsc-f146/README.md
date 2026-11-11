# GSC-F146 — Paridade planejador PHP ↔ resposta paginada Node

A CI consulta apenas código, não o Google. O SEO Engine RC25 é fixado por SHA `a5a3b7b2532cebe6df2c8b026b515fca0ec6ca31` e gera duas páginas de dados sintéticos. O validador PHP F143 confere o envelope, enquanto o novo teste F146 verifica que o planejador F144 produziria o mesmo par de offsets (`startRow=0` e `2`, limite `2`) e que a continuação apontada (`startRow=4`) permanece dentro dos limites.

O teste exige a propriedade sintética exata, tenant demo, período definido e todas as permissões de produção falsas. Nenhum token, chamada HTTP, acesso real, rota, migração, DNS, deploy, gasto ou publicação é habilitado; o planejamento de uma URL Google não autentica a conta.

Executar somente em CI/PHP CLI com `APP_ENV=testing`, `PORTAL_GSC_OVERLAP_LAB=1`, `PORTAL_GSC_PAGINATION_LAB=1` e `PORTAL_GSC_REQUEST_LAB=1`. A conexão do OpenSEO é separada. Revisão AppSec/DBA/LGPD e homologação autenticada necessárias antes de qualquer uso real.
