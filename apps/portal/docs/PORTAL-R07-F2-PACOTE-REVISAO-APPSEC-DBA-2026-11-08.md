# PORTAL-R07-F2 — Pacote para revisões humanas independentes

**Issue mãe:** https://github.com/rafhael100/imagine-portal/issues/52
**Escopo:** submissão documental para pareceres de AppSec/Identidade e DBA/PostgreSQL; sem revisão concluída, sem reviewers nomeados pelo usuário e sem autorização técnica para aplicar decisões.

## Evidência entregue para avaliar

- PR #51, PoC Z1/Z2/PG18 e workflow real 42503351851: 20 checks em três fases, 18 IDs T00–T17; Z1 sem credencial SQL, B negado, grant/epoch revogados negados; A possui um efeito sintético e B zero. T3 residual explicitamente reproduzido: sessão legítima A permite ações autorizadas A.
- PR #49, diretrizes documentais R06/R07 e requisitos de contenção T2 via Modelo B; ainda sem merge.
- Contrato PORTAL-R07-F2 e matriz TX-N01..TX-N20: propostas NÃO IMPLEMENTADAS; todos casos NOT RUN.
- PR #47, LAB-T07 reproduziu contexto RLS alterável por role SQL; mesmo na Z2 a RLS por GUC não oferece imunidade a SQL arbitrário.

## Parecer AppSec/Identidade — questões a responder

1. Os limites de ameaça T2 e T3 estão adequados? Quais cenários Z1 comprometida com sessão legítima podem continuar sem proteção?
2. Uma aprovação de operação C2/C3 pode ser falsificada, trocada, repetida ou induzida por UI da borda? Existe superfície independente que exibe os campos relevantes de fonte Z2?
3. Passkey/WebAuthn: challenge/origin/rpId/UV e binding ao digest da transação estão corretos? O projeto deixa claro que UV não prova visualização de detalhes?
4. Pareamento do dispositivo independente, chaves, antifraude, risco de engenharia social, perda de dispositivo, recovery/último gestor e step-up: quais no-go e controles adicionais?
5. Tratamento de nonce/TTL, canonicalização e algoritmos, gestão de segredos, consentimento, privacidade, logs, limites e idempotência: lacunas impeditivas?
6. Contratos de API e autorização por categoria C0–C3 evitam alegações de ator/role/tenant/MFA forjadas por Z1?

**Entregável esperado:** parecer humano datado, metodologia, arquivos e SHAs examinados, achados com severidade, ataque reproduzível, correção exigida, risco residual e status GO/NO-GO por gate. Sem atribuição automática de aprovação a AppSec.

## Parecer DBA/PostgreSQL — questões a responder

1. O estado de intenção, confirmação, consumo do desafio e efeito pode ser garantido numa transação? Quando pagamentos externos exigirem outbox, qual fencing/idempotência e reconciliação?
2. Locks/revisões/epochs sob P→A→S→O→M→R e mapa de writers são suficientes para sessões/grants concorrentes? Há deadlocks, write skew, TOCTOU ou efeito após revogação?
3. Unicidade, FK de tenant, keys de intenção e idempotência, constraints de estado, imutabilidade do payload, auditoria e backup/restore: quais contratos físicos faltam?
4. Como roles reais do Z2, owner/migrator e grants/EXECUTE/SECURITY DEFINER/search_path/RLS por DML previnem caminhos alternativos? Qual o residual se Z2 for comprometida?
5. Savepoint/rollback, pool, failover, timeouts, retries, erro ambíguo, restauração e efeitos não transacionais: como comprovar fail-closed e zero duplicação?

**Entregável esperado:** parecer humano com diagramas de transação/locks, inventário de roles, riscos e testes necessários. Nenhuma policy/DDL real autorizada nesta etapa.

## Governança

**Não disponibilizar código ou dados privados para revisão em repositório PUBLIC;** apenas massa fictícia/contratos. Revisores ainda não identificados e pareceres não recebidos: os pedidos serão registrados como PENDENTES DE DESIGNAÇÃO. Se alguém comentar no GitHub, avaliar autoria, independência, evidência, conflitos e conteúdo antes de registrar como parecer.

**NÃO fechar os cinco P0, gates ou liberar merge/produção** apenas porque existe issue de revisão.
