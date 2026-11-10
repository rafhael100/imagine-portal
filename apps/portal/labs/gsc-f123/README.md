# GSC-F123 — Cadastro de propriedades candidatas por empresa (não operacional)

## O que muda no Laravel

A migration `2026_11_10_000008_create_gsc_property_candidates.php` cria `portal_gsc_property_candidates` em PostgreSQL. Cada candidato preserva o `site_url` **exato** do Search Console, seu SHA-256, o tipo (domínio ou prefixo URL), a organização, o identificador de quem cadastrou, revisão de segurança, status e possível revogação. Existe índice de busca por empresa/status e restrição de unicidade **por empresa + hash**. A mesma propriedade pode ser cadastrada como candidata em duas empresas, mas isso não concede acesso a nenhuma.

O fundador deve possuir um vínculo Portal na própria empresa, assegurado por chave estrangeira composta. O banco rejeita tipo diferente do prefixo do endereço, digests inválidos, **digest divergente do `site_url` calculado com SHA-256 nativo do PostgreSQL 18**, duplicidades na mesma empresa, estados não previstos e revogação sem data. A comparação do hash nativo com PHP é testada.

## Modelo de autorização: fechado até revisão independente

A tabela é criada com RLS `ENABLE` + `FORCE` e **nenhuma policy**. Não existe permissão de leitura/gravação para a role da aplicação, nem endpoint, cliente Google, token, aceite de credenciais ou estado `approved`/ `connected`. Mesmo conceder `SELECT, INSERT` a uma role ordinária em testes não expõe linhas sem uma policy de RLS específica.

Os registros são **candidatos não verificados**, não evidências de propriedade no Google ou autorização da empresa. A chave estrangeira comprova somente o vínculo relacional, não a sessão autenticada/MFA, status do membro ou consentimento. O endereço precisa ser validado novamente pelo parser F120 ao acessar e **a resposta da Google API autenticada** deve ser tratada como fonte de comprovação de permissão. SHA-256 é identidade de comparação, não assinatura.

A reversão recusa executar enquanto houver candidatos, sem apagar dados implicitamente. Um operador com privilégios administrativos deverá fazer eliminação explícita, backup e auditoria antes de qualquer rollback. O ambiente de CI usa apenas fixtures artificiais, PostgreSQL 18 descartável, PHP 8.3/8.4 e testes de isolamento e revogação.

**HOLD:** ninguém deve aplicar esta migration em VPS/produção sem AppSec/DBA independente, consentimento LGPD, política de retenção, papéis SQL e auth reais, secrets vault e plano de deploy/rollback. Nenhuma integração real Google, rota Laravel, merge `main`, dados de clientes ou custo está contemplado.
