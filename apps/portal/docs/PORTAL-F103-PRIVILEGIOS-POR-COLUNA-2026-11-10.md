# PORTAL-F103 — Privilégios mínimos por coluna na autorização SQL (10/11/2026)

**Escopo: laboratório PostgreSQL 18 descartável; sem homologação real de cliente.**

## Por que esta etapa existe
A F102 substituiu a titularidade `postgres` (superuser) das funções de autorização F76/F101 por um proprietário dedicado, sem login e com `BYPASSRLS` restrito ao ensaio. Entretanto, esse proprietário ainda recebia `SELECT` sobre **todas as colunas** das seis tabelas, incluindo dados desnecessários para o predicado, como hashes de senha e segredos de MFA.

A F103 faz a segunda redução: retira os `SELECT` de tabela inteira e concede acesso **somente aos campos** usados nos dois predicados SQL. Nenhuma coluna de senha, segredo de MFA, email normalizado, informações descritivas de empresa, referência descritiva de recurso ou timestamp de cadastro entra na allowlist. Os leitores `portal_f76_reader` e `portal_f101_reader` seguem sem `SELECT` em qualquer coluna.

## Implementação verificável
- `labs/identity-f103/column_level_permissions.sql` aplica os grants por coluna em transação única, após criação e transferência de titularidade das funções F76/F101;
- `labs/identity-f103/test_pg_column_permissions.php` verifica via `has_column_privilege` a allowlist exata e os campos proibidos, além de garantir por `aclexplode` a ausência de privilégio de tabela inteira;
- a suíte simula `SET LOCAL ROLE portal_f102_security_owner` sob uma conexão administrativa descartável e tenta selecionar `password_hash` e `mfa_secret_ciphertext`; ambos devem produzir erro de permissão 42501;
- cria sessão/empresa/recurso artificiais e comprova que a autorização F76/F101 continua funcional com os campos permitidos, enquanto outra empresa não recebe permissão;
- testa que remover `SELECT` de uma **única coluna necessária** de recurso faz a função F101 falhar fechada, enquanto F76 continua independente; restaura a coluna somente depois;
- confirma que revogação de sessão e transferência de propriedade continuam sendo bloqueadas.

O workflow F103 executa PHP 8.3/8.4 e PostgreSQL 18 descartável, regressões F100, F101 e F102, depois restringe os grants e executa a nova suíte; por fim remove as funções/tabelas/roles de ensaio e faz rollback das migrations. A CI geral Laravel e o teste privado Docker continuam ligados ao PR.

## Limitações rigorosas
- **BYPASSRLS continua presente no papel técnico F102** por necessidade dos testes sem policies; acesso por coluna diminui o volume de dados alcançável, mas não é uma garantia suficiente para produção.
- Esta prova cobre privilégios SQL e os predicados de laboratório, não o emissor de sessão real, isolamento de conexões em pool, alternância multiempresa, MFA de clientes, transações lineares, caches, políticas RLS autorizadas, gestão de chaves, auditoria independente nem LGPD.
- Nenhum arquivo em `labs/` é copiado para a imagem executável do Portal; a aplicação mantém as três interfaces vinculadas a `DenyingPortalAuthorities` (F98), e a única rota HTTP prevista é `/up`.
- `main`, VPS Leaseweb, DNS, usuários, CRM, Google/Meta, finanças e serviços existentes **não são alterados**. Sem custos adicionais.
- PR **draft / NO-GO de produção**; revisão DBA/AppSec independente obrigatória antes de qualquer uso operacional.
