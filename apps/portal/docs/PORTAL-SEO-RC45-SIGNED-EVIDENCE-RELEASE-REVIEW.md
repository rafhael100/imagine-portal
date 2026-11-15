# Portal SEO RC45 — contrato de build assinado e ledger de uso único

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Consolidação para revisão técnica contra `main`, preservando os gates anteriores da [RC44 #858](https://github.com/rafhael100/imagine-portal/pull/858).

## Escopo
- [F121 #859](https://github.com/rafhael100/imagine-portal/pull/859): Engine RC45 `7847c8c66d5be228a08a94282da562c790cdd440` fixado no pipeline cruzado Node → PHP.
- [F122 #860](https://github.com/rafhael100/imagine-portal/pull/860): assinatura Ed25519 detached de relatório técnico real do Engine em laboratório; fonte, tempo, run, tenant e commit conferidos com contexto de CI.
- [F123 #862](https://github.com/rafhael100/imagine-portal/pull/862): consumo de nonce uma vez em PostgreSQL 18 com FORCE RLS, transação tenant-scoped, bloqueio de duplicidade concorrente e impedimento de reutilização mesmo com chave rotacionada.
- [F124 #863](https://github.com/rafhael100/imagine-portal/pull/863): limpeza exclusivamente de hashes de nonces vencidos há mais de uma hora, em lotes limitados a 500, sob menor privilégio e RLS por empresa.

## Prova de engenharia
- Testes Laravel PHP 8.3/8.4 e Node.js 22/24.
- CI cruzada com Engine RC45 (4 cenários de HTML; safe DTO sem dados de cliente).
- CI Ed25519 com emissor efêmero, um cenário válido e nove negativos.
- CI PostgreSQL 18 real: primeiro consumo, replay, acesso de outra empresa, papel somente leitura, dois processos concorrentes, chave rotacionada e limpeza conservadora (502 registros em dois lotes).

## Restrição crucial
Os resultados provam **comportamento do código em ambiente de testes**, não operação segura já ativa em produção. Não há serviço operacional de assinatura, KMS/HSM, emissor verificado, chaves de produção, sessão Portal autenticamente validada, MFA, autorização de cliente, logs de auditoria/alerta, job de expurgo, política LGPD aprovada ou deploy homologado.

O F122 tem janela temporal e o F123 ledger de consumo em PG18 **de laboratório**. Ainda há risco operacional se chaves, identidades ou escala forem configuradas incorretamente. A captura real da API IBGE segue sem sucesso (timeout de acesso externo), independente desses testes.

## Gates inegociáveis antes do release
1. Revisão independente do diff completo RC45 contra `main`, inclusive migrations e papéis RLS.
2. Emissor do SEO Engine com autenticação mútua ou canal seguro, chave privada gerenciada em serviço aprovado, chave pública de confiança publicada por configuração de servidor, rotação e revogação.
3. Contexto real do Portal: usuário/sessão/empresa/MFA, autorização de visualização e de revisão editorial, segregação por tenant.
4. Política formal de retenção, job autorizado, recuperação de banco, backup/restauração e plano de rollback.
5. OAuth Google readonly, grants por propriedade, revogação e consentimento.
6. Origem IBGE verificada, conteúdo editorial único e útil, idiomas e páginas realmente publicáveis em ambiente de homologação.
7. VPS staging, TLS, logs, testes de concorrência/latência, AppSec/DBA/LGPD e aceite formal de publicação.

**Não altera `main`, VPS, produção, DNS, tokens Google, contas de clientes nem cobrança.**
