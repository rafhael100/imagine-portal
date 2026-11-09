# PORTAL-SEC-R03-C01 — Modelo C híbrido, prova de conceito descartável

**Data:** 09/11/2026. **Aprovação da Direção:** Modelo C como diretriz para estudo e PoC sintética, sem despesas, produção, integrações reais ou homologação de segurança. Referências: PRs #51, #67 e #69, Issue #64.

## Objetivo e o que é realmente implementado

Simular o risco de restaurar um banco antigo contendo **sessão e membership revogados** e testar uma alternativa híbrida:

- **Z3 fictício (restaurável):** um arquivo SQLite temporário com duas organizações, sessões, memberships e efeitos locais sem dados pessoais.
- **Z4 fictício (fora do snapshot Z3):** outro arquivo SQLite temporário que guarda geração, sequência para compare-and-swap, estado DENY/READY/OPEN, epoch global de sessões, revogações e recibo de cópia restaurada.
- **Modelo C:** restaurar Z3 não restaura Z4; antes do restore Z4 deve estar DENY. Depois do restore todos os tokens de sessão antigos são invalidados e revogações sintéticas são reaplicadas. Só um estado READY reconciliado permite uma transição OPEN **com uma flag de aprovação exclusivamente fictícia**.
- **Barreira de commit restrita à simulação:** locks SQLite de Z4 serializam os efeitos no Z3 de teste contra a revogação, mas **não** fornecem atomicidade distribuída entre serviços, jobs, pagamentos ou bancos.
- **Guardas negativas:** sem Z4, modo incerto, geração anterior, sessão revogada, outro tenant ou writer não listado → negar; nenhum efeito local criado. Uma transição CAS concorrente vence; outras são rejeitadas.
- **Recibo de restore em Z4:** `reconcile()` nega sem registro de cópia restaurada com digest compatível. O recibo é controlado pelo próprio laboratório e **não** é atestado por verificador independente.

Os caminhos são provisionados unicamente por `tempfile.TemporaryDirectory`, nunca em VPS/ambiente real. O workflow exige somente Python padrão, GitHub Actions com `contents: read`, sem Docker, PostgreSQL, dependências externas, tokens, segredos nem fornecedores.

## Execução local e CI

```sh
python3 -m unittest discover -s apps/portal/labs/r07-f1/r03/model_c_lab -p 'test_*.py' -v
```

O arquivo `.github/workflows/portal-sec-r03-model-c-lab.yml` executa sintaxe e testes adversariais quando o PR altera o laboratório; **conclusões só devem ser registradas após inspeção do CI da HEAD exata**.

## Cobertura de laboratório vs. matriz SEC-R03-X01–X24

| Assunto da matriz | Experimento parcial nesta PoC | Limitação crítica |
| --- | --- | --- |
| X01/X02 — restore antigo / geração | Snapshot Z3 antigo, Z4 conserva geração e nega A | Mesmo runner; não é restore de host |
| X03/X22 — rollback/cópia antiga do próprio Z4 | **KNOWN GAP reproduzido:** uma cópia antiga de Z4, sem âncora independente, autoriza o ator A já revogado pela Z4 atual | **Falha de segurança propositalmente demonstrada; NÃO passou o requisito de anti-rollback** |
| X04/X05 — Z4 ausente | Arquivo Z4 retirado em teste, requisições negadas | Sem serviço externo HA ou timeout real |
| X06/X24 — concorrência/repetição CAS | Duas threads tentam recuperar; uma vence | Sem dois coordenadores em hosts distintos |
| X07/X08/X23 — writers e revogação | Lock SQLite impede commit sintético após corte; geração velha negada | Não prova commits distribuídos nem writers reais |
| X10/X11 — sessões e revogação | Sessão global invalidada, A revogado continua sem acesso | Não cobre refresh, MFA, recovery nem credenciais reais |
| X15/X19 — writer desconhecido / cross-tenant | Identificadores fictícios fora da allowlist são recusados | Não prova identidade, RLS nem proteção contra Z2 comprometida |
| X17/X18 — backup / reconciliação | Hash incompatível, recibo ausente e reconciliação parcial negam abertura | Recibo e hash não são prova independente de integridade |
| X14 — idempotência simulada | UNIQUE do efeito local impede duplicação no mesmo SQLite | Não cobre webhook, outbox, pagamento ou efeito externo |

**Toda a matriz X01–X24 permanece NOT RUN para homologação operacional.** As linhas acima são analogias de cenários em testes sintéticos, **não** executam nem encerram os casos completos da matriz.

## Limitações e próximas decisões

1. **Independência física de Z4 NÃO demonstrada — e lacuna reproduzida.** O teste `test_known_gap_stale_z4_clone_can_authorize_without_independent_anchor` confirma que copiar a autoridade antes de uma revogação permite que uma instância desatualizada autorize um ator revogado pela autoridade vigente. O teste termina com `OK` porque verifica corretamente a existência desta **falha conhecida**, não porque o sistema seja seguro contra rollback de Z4. Dois arquivos no mesmo GitHub runner podem ser perdidos/alterados no mesmo desastre; falta âncora monotônica externa, prevenção de split-brain, cópias geograficamente independentes e ameaça real a administradores.
2. **OPEN NÃO autentica aprovação humana.** O parâmetro `approval_fixture=True` é só teste de contrato e não equivale a assinatura de Diretor, controle de dupla custódia, WebAuthn ou autorização comercial.
3. **Revogação e commit são simulados por locks locais.** Não existe transação distribuída Z3↔Z4, prova de linearização em PostgreSQL, workers/filas/n8n/CRM/Sites/Performance, revogação de tokens reais, sessões HTTPS, mTLS ou auditoria independente.
4. **Z4 não é um produto selecionado ou contratado.** A topologia com domínio de falha separado, consistência CAS, custo, backups externos, manifesto completo de writers e recuperação de Z4 seguem sem escolha/homologação.
5. **Nenhum release real:** gates G-ID/G-BB, D02-SEC, D03/RLS, SEC-R03/T3, P0, AppSec/DBA e decisão OPEN permanecem ABERTOS. Nunca replicar este laboratório em `routes/web.php`, migrations ou endpoints.
6. **Diretrizes:** Modelo C estudado e reautenticação total na recuperação **simulada**. A política operacional e a aprovação de infraestrutura, orçamento e OPEN dependem de decisão da Direção, sem inferência de aceite nesta prova de conceito.

Proibido: merge automático, `main`, `dev`, VPS, deploy, tráfego externo, segredos, dados reais, fornecedores pagos, publicação ou mudança de grants/ALLOW. Este laboratório é complementar ao PR #69 e não substitui a PoC PostgreSQL do PR #67.
