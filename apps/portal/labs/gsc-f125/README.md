# GSC-F125 — Referências do cofre de tokens, sem tokens no PostgreSQL

## Objetivo

A F125 propõe persistir no Portal **somente hashes de referências de um cofre externo** de credenciais. Não persiste refresh token, access token, client secret, ciphertext, chave KMS ou referência legível. Este banco **não consegue recuperar credenciais** por conta própria. No futuro, uma autoridade independente do cofre deverá mapear referências reais sem expor seus segredos ao Portal.

A migration real cria `portal_gsc_vault_reference_candidates` em PostgreSQL, com hash SHA-256 do localizador, versão de chave sem material secreto, escopo fixo `https://www.googleapis.com/auth/webmasters.readonly`, organização, propriedade candidata, revisão e status. O vínculo com a propriedade F123 usa uma **chave estrangeira composta (organization_id,candidate_id)**, não apenas o ID da propriedade; isso impede associações cruzadas entre empresas mesmo em SQL. Hashes duplicados para duas empresas não são prova de autorização — a tabela representa apenas metadados *pendentes de revisão*.

Nenhum status `active` ou `connected` é admitido. Estados aceitos são `pending_review` e `revoked`, e toda criação começa pendente. A tabela tem **FORCE RLS sem policies**, nenhum acesso da aplicação, nenhum dado automático e rollback negado enquanto houver linhas. O CI comprova as constraints, isolamento, ausência de colunas secretas, SELECT/INSERT bloqueados com grants SQL ordinários e limpeza sintética em PostgreSQL 18, PHP 8.3/8.4.

## Limitações e riscos

Um hash de referência não cifra um token e pode vazar metadados se houver registros com baixa entropia. Os testes usam somente referências fictícias de laboratório. Não se deve armazenar referência de baixa entropia, credenciais nem URL legível no campo. Uma autoridade/serviço de cofre precisa criar referências aleatórias e manter o material criptográfico isolado, com MFA, controle de tenant, auditoria e revogação. O banco também não comprova autorização Google, titularidade da propriedade, consentimento nem sessão Portal real.

**HOLD:** não aplicar migração, criar papéis/policies, inserir referências ou habilitar OAuth antes de homologação VPS, client Google próprio, cofre revisado e parecer AppSec/DBA independente. Nenhuma rota HTTP, token real, consulta Google, merge `main`, deploy ou custo foi realizada.
