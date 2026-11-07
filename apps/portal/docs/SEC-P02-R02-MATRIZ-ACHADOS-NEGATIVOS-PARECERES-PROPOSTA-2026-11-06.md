# SEC-P02-R02 — Matriz de achados, negativas, dependências e pareceres

**Data:** 06/11/2026 (America/Sao_Paulo). **Atividade:** [CODEX-SEC-P02-R02 / Issue #30](https://github.com/rafhael100/imagine-portal/issues/30).
**Estado:** CORREÇÕES DOCUMENTAIS CANDIDATAS — GATES ABERTOS — AGUARDANDO PARECER HUMANO.

**Proveniência:** complemento novo, sem substituir nem editar os documentos históricos. Base documental do PR #24: `e76a9089727d8ccefd492cd660014ce3b22dc5d8`; PR #19: `75b32cef4afa0e7eae83e3b30fdc13c571a767d4`. Achados: [R01, comentário 6690120467](https://github.com/rafhael100/imagine-portal/issues/27#issuecomment-6690120467). Referências completas e matriz de provas no [documento de rastreabilidade](SEC-P02-R02-MATRIZ-ACHADOS-NEGATIVOS-PARECERES-PROPOSTA-2026-11-06.md).

**Limites:** somente Markdown. Nenhum código funcional, SQL executável, DDL, migration, conexão a banco, login/MFA real, laboratório, teste operacional, integração, dados reais, custo, merge, main/dev, alteração dos PRs #19/#24 ou VPS. Este texto não ratifica arquitetura, não resolve P0 por si só e não homologa gate. Termos de lock, chave e policy são requisitos em prosa, não implementação.


## 1. Escopo, leitura, versões e precedência

Autorização limitada da Issue #30 e comentário de encaminhamento [6690337462 na #27](https://github.com/rafhael100/imagine-portal/issues/27#issuecomment-6690337462). A revisão R01 desta mesma conversa leu os textos integrais do diff documental por trechos e o código de referência; a R02 reutiliza essas fontes congeladas, confere o relatório R01 publicado e revalida metadados dos PRs e refs antes de escrever. Os documentos R02 são novos; nenhum histórico é reescrito ou declarado aprovado.

| Referência conferida no início da R02 | SHA / estado | Consequência |
|---|---|---|
| PR #24, docs/portal-sec-p02-identidade-sessoes-2026-11-06 | e76a9089727d8ccefd492cd660014ce3b22dc5d8; aberto/draft/sem merge | Única base da branch filha R02; documentos ADR-01/02, D01..06, E01/02, RV01 e DEC-01..04 |
| PR #19, docs/portal-f1-01-b-b-proposta-2026-11-06 | 75b32cef4afa0e7eae83e3b30fdc13c571a767d4; aberto/draft/sem merge | Dependência só por referência: D01..05, DEC-01..04, G-BB e H/C/P; não integrar/editar |
| dev/fast-track-portal-2026-11-05 | 663e7d26f6bced2bc8bae53c962786401b12a0b7 | Avanço de UI demo independente; nenhuma incorporação, rebase ou merge na documentação |
| main | 50b683a499e674641df5749051f3d05f7ac3f627 | Apenas conferência de ref; nenhuma escrita |
| Código da R01 | 0aef6838b0452fcbb195fcad2e50aed748ec41c5 | PortalUserId, OrganizationMembership, OrganizationAuthorizationService, DemoPortalSession/Policy/Verdict, routes/web.php e composer.json; não equivale à dev atual nem prova runtime real |
| Instrução local aplicável lida por GitHub | apps/portal/AGENTS.md na base e76a908… | Limites de identidade/CRM, banco, dados reais, merge e infraestrutura preservados; autorização específica R02 só para novos Markdown |
| Filha autorizada | docs/sec-p02-r02-p0-correcoes-2026-11-06 | Consultada antes da criação, inexistente na busca exata; partir do SHA e76a908…, sem sobrescrever branch concorrente |

Fonte humana independente não está disponível/designada. Nenhum teste funcional do código/base atual, auditoria completa de dependências, PG/driver/versões instaladas, CVE, ambiente de produção ou dados reais foi inspecionado. Ausência de prova não é prova de ausência de vulnerabilidade.

### Fontes documentais congeladas

PR #24, textos inspecionados na R01 e mantidos como referência da R02:

- [SEC-P02-ADR-01-AUTORIDADE-IDENTIDADE-PORTAL-PROPOSTA-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-ADR-01-AUTORIDADE-IDENTIDADE-PORTAL-PROPOSTA-2026-11-06.md)
- [SEC-P02-ADR-02-MEMBERSHIP-REVOGACAO-TRANSACIONAL-PROPOSTA-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-ADR-02-MEMBERSHIP-REVOGACAO-TRANSACIONAL-PROPOSTA-2026-11-06.md)
- [SEC-P02-D01-CONTRATOS-IDENTIDADE-MFA-SESSOES-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-D01-CONTRATOS-IDENTIDADE-MFA-SESSOES-2026-11-06.md)
- [SEC-P02-D02-MATRIZ-DECISOES-HOMOLOGACAO-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-D02-MATRIZ-DECISOES-HOMOLOGACAO-2026-11-06.md)
- [SEC-P02-D03-CONTRATOS-PROVENIENCIA-IDENTIDADE-SESSAO-ORGANIZACAO-PROPOSTA-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-D03-CONTRATOS-PROVENIENCIA-IDENTIDADE-SESSAO-ORGANIZACAO-PROPOSTA-2026-11-06.md)
- [SEC-P02-D04-CONTRATOS-RECUPERACAO-MFA-REVOGACAO-PROPOSTA-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-D04-CONTRATOS-RECUPERACAO-MFA-REVOGACAO-PROPOSTA-2026-11-06.md)
- [SEC-P02-D05-MODELO-AMEACAS-EVIDENCIAS-GATES-PROPOSTA-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-D05-MODELO-AMEACAS-EVIDENCIAS-GATES-PROPOSTA-2026-11-06.md)
- [SEC-P02-D06-EVIDENCIAS-LABORATORIO-PROPOSTA-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-D06-EVIDENCIAS-LABORATORIO-PROPOSTA-2026-11-06.md)
- [SEC-P02-DEC-01-RATIFICACAO-DIRETRIZES-SEC02-01-A-09-A-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-DEC-01-RATIFICACAO-DIRETRIZES-SEC02-01-A-09-A-2026-11-06.md)
- [SEC-P02-DEC-02-RATIFICACAO-IDN-REC-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-DEC-02-RATIFICACAO-IDN-REC-2026-11-06.md)
- [SEC-P02-DEC-03-RATIFICACAO-SEC05-D01-A-06-A-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-DEC-03-RATIFICACAO-SEC05-D01-A-06-A-2026-11-06.md)
- [SEC-P02-DEC-04-RATIFICACAO-SEC06-D01-A-04-A-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-DEC-04-RATIFICACAO-SEC06-D01-A-04-A-2026-11-06.md)
- [SEC-P02-E01-INVENTARIO-EVIDENCIAS-LACUNAS-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-E01-INVENTARIO-EVIDENCIAS-LACUNAS-2026-11-06.md)
- [SEC-P02-E02-CONTRATO-CANDIDATO-IDENTIDADE-MEMBERSHIP-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-E02-CONTRATO-CANDIDATO-IDENTIDADE-MEMBERSHIP-2026-11-06.md)
- [SEC-P02-RV01-REVISAO-TECNICA-E01-E02-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-RV01-REVISAO-TECNICA-E01-E02-2026-11-06.md)

PR #19, textos inspecionados e correlacionados, sem alteração:

- [PORTAL-F1-01-B-B-D01-MODELO-FISICO-CANDIDATO-ISOLAMENTO-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/75b32cef4afa0e7eae83e3b30fdc13c571a767d4/docs/portal/PORTAL-F1-01-B-B-D01-MODELO-FISICO-CANDIDATO-ISOLAMENTO-2026-11-06.md)
- [PORTAL-F1-01-B-B-D02-CONTRATOS-PERMISSOES-REVOGACAO-RLS-PROPOSTA-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/75b32cef4afa0e7eae83e3b30fdc13c571a767d4/docs/portal/PORTAL-F1-01-B-B-D02-CONTRATOS-PERMISSOES-REVOGACAO-RLS-PROPOSTA-2026-11-06.md)
- [PORTAL-F1-01-B-B-D03-CONTRATOS-FISICOS-IDENTIDADE-RLS-REVOGACAO-PROPOSTA-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/75b32cef4afa0e7eae83e3b30fdc13c571a767d4/docs/portal/PORTAL-F1-01-B-B-D03-CONTRATOS-FISICOS-IDENTIDADE-RLS-REVOGACAO-PROPOSTA-2026-11-06.md)
- [PORTAL-F1-01-B-B-D04-MATRIZ-HOMOLOGACAO-EVIDENCIAS-GATES-PROPOSTA-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/75b32cef4afa0e7eae83e3b30fdc13c571a767d4/docs/portal/PORTAL-F1-01-B-B-D04-MATRIZ-HOMOLOGACAO-EVIDENCIAS-GATES-PROPOSTA-2026-11-06.md)
- [PORTAL-F1-01-B-B-D05-PACOTE-PRE-IMPLEMENTACAO-GATES-CRITERIOS-PROPOSTA-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/75b32cef4afa0e7eae83e3b30fdc13c571a767d4/docs/portal/PORTAL-F1-01-B-B-D05-PACOTE-PRE-IMPLEMENTACAO-GATES-CRITERIOS-PROPOSTA-2026-11-06.md)
- [PORTAL-F1-01-B-B-DEC-01-RATIFICACAO-DIRETRIZES-BB01-BB08-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/75b32cef4afa0e7eae83e3b30fdc13c571a767d4/docs/portal/PORTAL-F1-01-B-B-DEC-01-RATIFICACAO-DIRETRIZES-BB01-BB08-2026-11-06.md)
- [PORTAL-F1-01-B-B-DEC-02-RATIFICACAO-DIRETRIZES-BBD02-01-A-06-A-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/75b32cef4afa0e7eae83e3b30fdc13c571a767d4/docs/portal/PORTAL-F1-01-B-B-DEC-02-RATIFICACAO-DIRETRIZES-BBD02-01-A-06-A-2026-11-06.md)
- [PORTAL-F1-01-B-B-DEC-03-RATIFICACAO-BBD03-01-A-06-A-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/75b32cef4afa0e7eae83e3b30fdc13c571a767d4/docs/portal/PORTAL-F1-01-B-B-DEC-03-RATIFICACAO-BBD03-01-A-06-A-2026-11-06.md)
- [PORTAL-F1-01-B-B-DEC-04-RATIFICACAO-BBD04-01-A-06-A-2026-11-06.md](https://github.com/rafhael100/imagine-portal/blob/75b32cef4afa0e7eae83e3b30fdc13c571a767d4/docs/portal/PORTAL-F1-01-B-B-DEC-04-RATIFICACAO-BBD04-01-A-06-A-2026-11-06.md)

DEC históricas ratificam diretrizes preliminares, não detalhe R02, esquema, função RLS ou autorização de laboratório. Inventários E01/E02/RV01 anteriores aos ADRs preservam estado histórico; estes quatro novos documentos propõem tratamento adicional. Aprovação de texto, aprovação arquitetural, autorização de executar, prova operacional e promoção/merge são decisões distintas.

### Entregas R02 e âncoras

- [ADR01 R02](SEC-P02-R02-ADR01-CONTRATO-IDENTIDADE-SESSAO-P0-PROPOSTA-2026-11-06.md): §1 premissa do núcleo Portal; §2 autoridades/capacidades; §3 namespace/revisões; §4 estados; §5 sincronização; §6 limites.
- [ADR02 R02](SEC-P02-R02-ADR02-AUTORIDADES-BARREIRAS-REVOGACAO-P0-PROPOSTA-2026-11-06.md): §1 G global candidata; §2 todos os writers; §3 ordem/isolamento/tempo; §4 interleavings; §5 último gestor; §6 externo; §7/8 falhas/restore.
- [ADR03 R02](SEC-P02-R02-ADR03-TENANCY-RLS-LIMITES-AMEACA-P0-PROPOSTA-2026-11-06.md): §1 fronteira A/B; §2 integridade composta; §3 projeção; §4 bootstrap; §5 roles; §6 policies; §7 SQLi/contexto/pool.
- Este documento: crosswalk, todos os ID-A/REV e H/C/P, provas futuras e decisões humanas pendentes.

## 2. Premissas e contraexemplos dos cinco P0

| P0 | Contraexemplo R01 preservado | Tratamento candidato R02 | Premissa / risco residual, ainda aberto |
|---|---|---|---|
| R01-01 | ID/e-mail/CNPJ/cookie CRM ou DTO coerente tratado como ator Portal | ADR01 §2–5: autoridades, namespace local, provas server-side e sessões não derivadas do CRM | Bootstrap, fator e biblioteca não aprovados; processo comprometido pode falsificar estado sem fronteira forte. P0 não encerrado |
| R01-02 | Conta/sessão revogada após check mas antes de write protegido só por membership | ADR01 §1/4/5 e ADR02 §1–4: sessões/conta/efeito no mesmo núcleo e G exclusiva para emissor/revogador/ação | Store externo muda premissa; co-localização e contenção precisam parecer. G não sincroniza serviço externo, relógio nem runtime hostil |
| R01-03 | Role/org/catálogo muda em outra linha, ação mantém grant antigo | ADR02 §2–4: writer inventory, G comum, revisions globais/tenant e releitura após aquisição | Escritor omitido, DML direto, policy em código divergente ou manutenção fora de G quebra contrato; revisão de privilégios obrigatória |
| R01-09 | FK simples, tenant mutável ou owner/grants recebidos do request permitem cruzar empresas | ADR03 §2–4: par único, referências compostas, tenant imutável, fonte única e projeção efêmera | ERD/constraints reais e inventário final de tabelas não existem; erro de constraint pode enumerar; discovery/convites bloqueados até alçada |
| R01-10 | Runtime altera contexto SQL, herda BYPASSRLS ou função/view amplia acesso | ADR03 §1/5–7: limite de ameaça, roles/privilégios/policies e mitigação SQLi | Fronteira A não resiste necessariamente a contexto inteiramente forjado. Se resistência a runtime hostil for requisito, redesenhar; aceitar risco exige decisão explícita, não este texto |

Opções A/B e DEC-R02-A/B/C/D são propostas. Nenhum contrato R02 é ratificado; nenhum dos cinco P0 é reclassificado como resolvido.

## 3. Crosswalk completo R01 → RV01 → R02 → gates → prova → revisor

Para todas as linhas: **status do achado ABERTO; tratamento DOCUMENTAL CANDIDATO; cenários PLANEJADO / NÃO EXECUTADO; parecer independente PENDENTE**. Siglas I = Identidade/AppSec independente; D = DBA independente; P = Produto; B = Backend; Q = QA/Plataforma. Papéis não são nomeações pessoais; parecer do Codex não substitui I/D.

| R01 ID / prioridade | RV01 ID | Mudança documental R02 | Gate G-ID / G-BB / D02-SEC / P0 (todos abertos) | Negativa planejada | Evidência observável necessária | Revisor independente / apoio |
|---|---|---|---|---|---|---|
| R01-01 P0 | ID-01/ID-02 | ADR01 §2–5; ADR03 §3–4 | G-ID01/07; G-BB03; D02-SEC01/03/05/06; P0-DOC06/G-04 | N01, ID-A01/02/06 | Origem verificada, nenhuma sessão/vínculo/lista/token estrangeiro por igualdade ou DTO/header | I; B |
| R01-02 P0 | ID-01/MEM-02 | ADR01 §1/4/5; ADR02 §1–4 | G-ID03; G-BB03/06; D02-SEC01/02; P0-DOC06/G-04 | N02/03/15, REV-01/02/10 | Ordem commit/G/revisões, zero efeito novo obsoleto e nenhum login emitido em revisão inválida | I + D; B |
| R01-03 P0 | MEM-01/MEM-02 | ADR02 §2–4 | G-ID05; G-BB04/06; D02-SEC02; P0-DOC06/G-04 | N05/06, REV-03/06 | Revogar role/org/capability sem editar M1/M2 invalida ambas; zero grant residual | D + I; B |
| R01-04 P1 | MEM-02 | ADR02 §3/7, detalhamento quantitativo pendente | G-ID03/05; G-BB06; D02-SEC01/02; P0-DOC06 | N07/08 | Espera, snapshot, clock e retry documentados; nenhuma duplicação/efeito parcial | D; Q/B |
| R01-05 P1 | GOV-01/MEM-02 | ADR02 §3/5; DEC-R02-C pendente | G-ID05; G-BB04/06; D02-SEC02; P0-DOC06 | N06, REV-06/08 | Sem autoelevação/write skew; suspensão de segurança não burlada; recuperação sem staff global | I + D; P |
| R01-06 P1 | ID-01/GOV-01 | ADR01 §4/6; ADR03 §4, fluxos bloqueados | G-ID01/02/04/05; G-BB03/04; D02-SEC01/02; P0-DOC06 | N04/06, ID-A02/05, REV-08 | Pre-enrolment e convite não listam empresas nem concedem gestor; aceite com alçada corrente | I; P/B |
| R01-07 P1 | ID-01/ID-02/QA-01 | ADR01 §3–6; protocolo MFA/reset integral pendente | G-ID02/03/04; G-BB03/06; D02-SEC01; P0-DOC06 | N03/04, ID-A03/05 | Consumo único, rotação e epoch sem handle anterior ou desafio reaproveitável | I; B/Q |
| R01-08 P1 | ID-01/QA-01 | ADR01 §6; política quantitativa pendente | G-ID03/04/09; G-BB03/08; D02-SEC01; P0-DOC06 | N04/15; AUT-11/REC-01/07 | CSRF/host/cookie/replay/abuso negados sem vazamento/enumeração/DoS arbitrário | I; Q/B |
| R01-09 P0 | MEM-01/DB-01 | ADR03 §2–4/6 | G-ID05/06; G-BB02/03/05; D02-SEC02; D03 físico/RLS; P0-DOC06/G-04 | N09/10, REV-04 | Zero leitura/escrita/FK/tenant deslocado; erro genérico e projeção oficial | D + I; B |
| R01-10 P0 | DB-01 | ADR03 §1/5–7; DEC-R02-D pendente | G-ID06; G-BB03/05; D02-SEC01/02; D03 físico/RLS; P0-DOC06/G-04 | N10/11/12 | Grants/policies/contexto reais; negativo ou insuficiência da fronteira hostil explicitamente registrado | D + I |
| R01-11 P1 | DB-01/MEM-02 | ADR02 §3/6; ADR03 §7 | G-ID03/05/06; G-BB06; D02-SEC01/02; D03 físico/RLS; P0-DOC06 | N08/12, REV-07 | Pool/worker sem resíduo; lote seguinte revalida; entrega após materialização tem limite declarado | D + I; Q |
| R01-12 P1 | MEM-02 | ADR02 §6/7, contrato externo separado | G-ID07; G-BB04/06; D02-SEC03/05/06; P0/INTEG-01 | N13, REV-05 | Fencing/dedupe/reconciliação no receptor, sem promessa de campanha externa instantaneamente revogada | I; B/Integrações |
| R01-13 P1 | MEM-02/DB-01/QA-01 | ADR01 §3; ADR02 §7/8 | G-ID08; G-BB04/07; D02-SEC01/02; P0-DOC06 | N14/15, REV-09 | Restore bloqueado até reconciliação; token/job antigo não revive; trilha mínima sem segredos | D + I; Privacidade/Q |
| R01-14 P2 | QA-01 | Matriz §1–8 e links de precedência | G-ID09; G-BB08; D02-SEC01..06; P0 | Todos, inspeção documental | SHA, fontes, diferença texto/prova e parecer assinado por área rastreáveis | I + D; Q |

IDs RV01 na tabela mantêm prefixo canônico RV01- (por exemplo, ID-01 = RV01-ID-01); nenhum achado original foi renumerado ou fechado.

## 4. Catálogo de negativas futuras R02

Cada ensaio precisa dados exclusivamente fictícios, autorização de ambiente separada, versão/role reais documentadas e execução somente em outra atividade autorizada. Coletor futuro Q registra estado inicial/final mínimo, IDs opacos, revisions/epochs, pontos de pausa, ordem de aquisição/commit/rollback e contagem de efeitos/intents/eventos; nunca handles/tokens/seeds/PII. Negação deve incluir ausência de efeito indevido **e** ausência de vazamento; 403 isolado ou mock de allow não serve.

| Caso | Pré-condição e tentativa hostil / agenda | Resultado esperado e evidência futura |
|---|---|---|
| R02-N01 Origem | Contas fictícias Portal/CRM com IDs/e-mail/CNPJ coincidentes; cookie CRM, actor/MFA/role em header e DTO fabricado | Não emitir sessão/vínculo/grant/lista de empresas. Provar autoridade Portal do sujeito aceito e ausência das emissões indevidas |
| R02-N02 Conta/sessão versus efeito | Duas transações: ação e suspensão/reset/fator/logout individual/global; agendar ambas as ordens em G | Ação primeiro conclui/aborta antes de revogação; revogação primeiro faz decisão negar sem mutação/intent. Mostrar conta/sessão/revisões, G e commits |
| R02-N03 Emissão/rotação | Credencial/desafio verificado em r7; reset/logout produz r8 antes do emissor; duas abas rotacionam | Prova r7 não gera handle após r8; predecessor não autentica; falha de resposta não ressuscita sessão. Nenhuma emissão parcial |
| R02-N04 MFA/reset/bootstrap | Pré-MFA/enrolment, desafio em outra sessão/propósito, tokens iguais/distintos em corrida, convite encaminhado ou concedente revogado; CSRF/Host alterado | Nenhuma área privada/gestão por fluxo restrito; um consumo conforme política e revisões atuais; sem login implícito/MFA removido/segredo no log. Fluxos sem política continuam bloqueados |
| R02-N05 Dependências globais | M1/M2 compartilham role; mudar grant/role/org/entitlement/capability global em G durante ação, nas duas ordens | Nenhuma decisão posterior ao commit usa poder antigo, sem fan-out parcial. Mostrar epochs globais/tenant e zero efeitos novos indevidos |
| R02-N06 Concessão/último gestor | Dois gestores removem/rebaixam simultaneamente; concedente perde alçada; autoedição de role; expiração/suspensão da última conta | Serialização não dá autoelevação; política pendente bloqueia fluxo. Suspensão não é revertida para preservar gestor; contingência não cria staff global; trilha |
| R02-N07 Isolamento/retry | Snapshot/ORM carregado antes da espera; deadlock/timeout/cancelamento e retry | Leituras pós-G atuais; rollback integral; nova autorização, idempotência por escopo/payload e zero duplicatas; nenhuma conexão indeterminada reutilizada |
| R02-N08 Tempo/leitura | Membership/step-up expira durante espera; export em lotes durante revogação | Relógio autoritativo pós-espera; ponto de elegibilidade explícito. Lote posterior nega; bytes materializados/entregues antes têm limite registrado, sem promessa retroativa |
| R02-N09 Integridade tenant | Ator A em X pede recurso/role de Y; duplicidade de membership concorrente; FK simples/troca de organization_id | Constraints compostas e escopo impedem escrita/FK/cópia cruzada; zero dados Y inclusive mensagens de erro; tenant imutável |
| R02-N10 Policies e caminhos indiretos | Consultas sem filtro/contexto, SELECT/INSERT/UPDATE/DELETE cruzados, upsert, view/trigger/função/partição e policy OR | Negação sob runtime real e operação autorizada corretamente escopada; demonstrar estado antigo/novo e inventário. Negativa por falta de GRANT não substitui teste da policy |
| R02-N11 Runtime hostil | SQL arbitrário altera actor/tenant/contexto; tenta BYPASSRLS/SET ROLE/herança/owner/DDL/TRUNCATE ou EXECUTE elevado | Privilégios proibidos negados. Contexto forjado pode passar na fronteira A: registrar lacuna e impedir aceitação como B. Nunca inventar negação geral nem executar em infraestrutura real |
| R02-N12 Pool/worker | X→Y na mesma conexão/worker após commit, erro, rollback total/parcial/savepoint, timeout, prepared statement e retry | Sem contexto/allow/objeto residual; conexão certa e G até fim; zero leitura/escrita cruzada |
| R02-N13 Outbox | Revogação entre check e send/aceite; lease expira com dois dispatchers; timeout após aceite; intent de encarnação antiga | Revalidação local e dedupe; corte estrito externo só com fencing no receptor. Estado incerto reconciliado sem duplicar campanha/cobrança; risco não coberto continua bloqueador do fluxo |
| R02-N14 Restore | Backup anterior a revogação de conta/fator/sessão/role/grant e job atrasado após reingresso | Serving fechado até reconciliação/novo epoch confiável; nenhum handle/token/grant/intent antigo reabre; ausência de fonte confiável mantém indisponibilidade |
| R02-N15 Falha/auditoria/abuso | Fonte/G/banco/store/clock/audit indisponível; logs com payload hostil; brute force/reenvio/enumeração | Rollback sem efeito parcial ou cache positivo, trilha mínima sem segredo/injeção/PII, resposta não enumerável; política de limites quantitativos permanece pendente |

## 5. Todos os ID-A e REV — PLANEJADO / NÃO EXECUTADO

A coluna cobertura significa contrato em texto, não teste passado. Nenhum cenário abaixo é homologado. Nxx refere-se a R02-Nxx.

| Caso obrigatório | Texto R02 / detalhe futuro | Negativas | PR #19 relacionado |
|---|---|---|---|
| ID-A01 Origem Portal/CRM | ADR01 §2/3/5, ADR03 §3 | N01 | H02/C03/P01 |
| ID-A02 Cookie antigo/forjado/pré-MFA | ADR01 §4–6; crypto/CSRF dependem de AppSec | N01/03/04 | H02/C03, complemento AUT-01/02/03 |
| ID-A03 Suspensão/credencial/fator/logout global | ADR01 §4/5, ADR02 §2–4 | N02/03 | H03/H08/H09/C04/C05/P04 |
| ID-A04 Falha store/identidade | ADR01 §1/5/6, ADR02 §7 | N15 | H07/H09/C05/C08/P07 |
| ID-A05 Reset/códigos MFA replay | ADR01 §4/6; algoritmo fator/recovery completo pendente | N03/04/15 | H02 não basta: complementar REC-02/03/04/05/09, AUT-03/09 |
| ID-A06 Sem sessão humana CRM/Performance | ADR01 §2/3, ADR02 §6 | N01/13 | C03 e B-B-D01 §7; integração tem contrato separado |
| REV-01 Efeito obtém barreira primeiro | ADR02 §3/4 | N02 | H08/C04/P04 |
| REV-02 Revogação commita primeiro | ADR02 §3/4 | N02/05 | H08/H09/C04/C05/P04 |
| REV-03 Role/grant sem mudança M | ADR02 §2–4 | N05 | H08/C01/C04/P04/P05 |
| REV-04 Duas empresas/URL adulterada | ADR03 §2–4/6 | N01/09/10 | H01/H04/H05/C06/P01/P02/P06 |
| REV-05 Job/outbox antiga | ADR02 §6/7; fencing externo pendente | N13 | H09/C05/P09 |
| REV-06 Revogações/concessões simultâneas | ADR02 §3–5 | N05/06/07 | H08/H10/C02/C04/P03/P05 |
| REV-07 Pool/rollback troca tenant | ADR03 §7, ADR02 §3 | N12 | H07/C08/P07 |
| REV-08 Último gestor suspenso/revogado | ADR02 §5; contingência/alçada não aprovadas | N06/08 | H10/C02/P03/P12 |
| REV-09 Restore anterior a revogação | ADR02 §8; procedimento/custódia pendentes | N14 | H12/C09/P11 |
| REV-10 Store/banco indisponível | ADR01 §5/6, ADR02 §7 | N15 | H07/H09/H11/C05/C10/P07/P10 |

## 6. Correlação exaustiva H/C/P do PR #19

Cada linha abaixo está **PLANEJADO / NÃO EXECUTADO**. Não substitui o critério original do PR #19 nem autoriza sua execução. H/C/P se complementam; N não comprova MFA/SQL real enquanto só há texto. A evidência da §4 deve ser guardada por SHA/versão e revista por I/D conforme superfície.

| Caso PR #19 | Risco / vínculo R02 | Prova negativa e evidência a exigir |
|---|---|---|
| H01 Matriz ator/tenant/recurso | N01/09; R01-01/09 | Recurso Y sob X e papel só em X negados; zero conteúdo/efeito Y |
| H02 Contexto/ID/origem forjados | N01/04/11; R01-01/10 | Cookie/DTO/actor CRM não autentica; contexto hostil tem limite A/B expresso |
| H03 Estados/vigência | N02/05/08; R01-02/03/04 | Invited/suspended/revoked/expirado e org inativa negam; revisões atuais |
| H04 FKs/par único | N09; R01-09 | Constraint composta, duplicidade concorrente e tenant imutável sem enumeração |
| H05 DML runtime sem filtro | N09/10; R01-09/10 | Todas as operações/caminhos aprovados, contexto ausente e zero linha cruzada |
| H06 Bypass/owner/view/policy OR | N10/11; R01-10 | Inventário/grants reais e tentativas indevidas negadas, sem falsa garantia contra superuser |
| H07 Pool/reuso | N07/12/15; R01-11 | Conexão certa, rollback/savepoint/retry limpos, nenhum resíduo |
| H08 Uso/revogação/concessão | N02/05/06/07; R01-02/03/04/05 | Duas ordens, G/commit, revisões e ausência de efeito pós-barreira |
| H09 Cache/réplica/job/export | N05/08/13; R01-03/11/12 | Autoridade atual, próximo lote nega e intent antigo não autoriza; limite externo |
| H10 Staff/alçada/último gestor | N04/06; R01-05/06 | Negar mandato inexistente e contingência não homologada, sem bypass |
| H11 Logs/auditoria | N15; R01-13/14 | Audit falha aborta efeito; logs mínimos sem segredo/PII e sem oracle |
| H12 Restore/rollback de política | N14; R01-13 | Reconciliação antes de serving; política antiga não reabre grant |
| C01 Projeção de role | N05/09; R01-03/09 | Só catálogo reconhecido e role ativa; nenhum grant direto concorrente |
| C02 Alçadas | N06; R01-05/06 | Sem autoelevação, terceiro acima de mandato ou perda de gestão por fluxo aprovado |
| C03 Emissor/IDs iguais | N01; R01-01 | Sem sessão/vínculo/SSO por texto, e-mail ou CNPJ |
| C04 Barreira | N02/05/07; R01-02/03/04 | Ordem G/commit e efeito atômico, não só revisão comparada |
| C05 Cache/réplica | N05/08/13/15; R01-03/11/12 | Zero novo allow obsoleto; externo requer contrato próprio |
| C06 RLS sem contexto | N10/11; R01-10 | Negar sem contexto; forja completa tratada conforme fronteira, não resultado inventado |
| C07 Recursão RLS | N10/11; R01-10 | Grafo de metadados/funções sem ciclo, privilégios mínimos e sem oracle |
| C08 Pooling | N12; R01-11 | A→B após todas as falhas sem vazamento, em driver/pooler escolhido |
| C09 DDL/restore | N11/14; R01-10/13 | Runtime sem DDL/TRUNCATE/alteração de RLS; restore fechado até reconciliar |
| C10 Auditoria | N15; R01-13 | Append protegido e falha obrigatória aborta; acesso privado e logs mínimos |
| P01 Seleção/CRM/membership estrangeira | N01/09; R01-01/09 | Origem e par corretos, zero empresas/recursos de terceiros |
| P02 Unicidade/FK | N09; R01-09 | Rejeição atômica concorrente e mensagem externa não enumerável |
| P03 Concessão/autoelevação | N06; R01-05 | Concedente atual e matriz de alçadas; negação auditada conforme finalidade |
| P04 Revogação simultânea | N02/05; R01-02/03 | Ambas as ordens, zero efeito autorizado por versão antiga após commit |
| P05 Concessões/reativação concorrentes | N05/06/07/14; R01-03/04/05/13 | Sem duplicata ou reingresso automático; retry revalida e usa nova encarnação |
| P06 DML outra empresa | N09/10; R01-09/10 | SELECT/INSERT/UPDATE/DELETE com/sem filtro, estado antigo/novo e zero dados cruzados |
| P07 Contexto/falhas | N07/12/15; R01-11 | Limpeza comprovada, conexão incorreta e pool statement impedidos |
| P08 View/função/trigger/recursão | N10/11; R01-10 | Nenhuma elevação genérica/oracle/ciclo; owner/search_path/invoker revistos |
| P09 Job/export/cache | N08/13; R01-11/12 | Lotes revalidados, resultado externo incerto reconciliado sem duplicata |
| P10 Audit falha | N15; R01-13 | Concessão/revogação/efeito aborta sem estado parcial |
| P11 Restore | N14; R01-13 | Fonte de revogações/epoch confiável, ausência mantém bloqueio |
| P12 Privacidade/conta técnica/gestor | N04/06/13/15; R01-05/06/12/13 | Sem PII/segredo, sessão humana não é credencial técnica, sem staff universal |

## 7. Gates e decisões humanas, todos pendentes

| Decisão / dependência | Quem deve avaliar | Evidência documental candidata / falta remanescente |
|---|---|---|
| DEC-R02-A Núcleo de conta/sessão e efeito no mesmo primário | Arquitetura + I + D | ADR01 §1 e ADR02 §1; confirmar topologia, proteção de fator/chaves, isolamento CRM, custo/latência. Nenhuma arquitetura ratificada |
| DEC-R02-B G global e isolamento candidato | D + Backend + Plataforma + Produto | Inventário de writers, acesso mínimo a G, proibição de DML genérico, READ COMMITTED/releituras, locks implícitos, tempo e retry; medir contenção só em laboratório futuramente autorizado |
| DEC-R02-C Último gestor/bootstrap/reingresso | Produto + I + D | Alçada externa, invariante de gestor e recuperação; suspensão de segurança não bloqueada para preservar conta; nenhum fluxo real aprovado |
| DEC-R02-D Modelo de ameaça RLS | I + D + Arquitetura + Produto | Fronteira A não atesta runtime hostil; B exige redesenho. Parecer deve registrar aceitação/rejeição e risco residual explicitamente |
| G-ID01/03 | I + Identidade | Autoridades/estados/revisões e sessão/epoch escritos; store/cookies/fator/abuso não homologados |
| G-ID05/06 | I + D + Produto | Membership/projeção/tenancy/G escritas; ERD final, catálogo/alçada, roles/policies e versão/driver/pool não homologados |
| G-BB03/04 | I + D + Produto | Fonte de ator e grants/barreira propostas; escolhas e invariantes de ciclo ainda sem aceite independente |
| G-BB05/06 | D + I + Plataforma | Inventário de privilégios/contexto/policies e ordem candidata; função concreta/grafo, grants transitivos, pool e ensaios inexistentes |
| G-ID02/04/07/08/09 e G-BB01/02/07/08 | Áreas respectivas + I/D | MFA/reset/integração/restore/privacidade/ambiente/tipos/QA continuam dependências, sem preenchimento por suposição |
| D02-SEC01..06, D03 físico/RLS, P0 e release | Revisores por área e Direção no gate próprio | Nenhum fechamento por novo Markdown, PR draft, comentário, CI ou parecer automatizado |

Parecer futuro deve identificar pessoa/área, independência/conflito de interesse, SHA exato dos quatro documentos e das bases, opinião por P0, premissa aceita/rejeitada, risco residual, testes exigidos e conclusão limitada. Sem pessoa indicada não solicitar/atribuir review nominal fictício. Revisão humana independente obrigatória antes de qualquer pedido de laboratório.

## 8. Verificação documental da entrega e sequência

Critérios verificáveis do PR filho:

1. Branch filha de e76a908… com nome autorizado e comparação do SHA antes de escrever; se preexistente, inspecionar antes de qualquer alteração, sem force/overwrite.
2. Diff contra a branch documental do PR #24 contém somente os quatro Markdown novos desta atividade, dentro de apps/portal/docs/. Zero edição dos documentos históricos, AGENTS, código, tela, Blade, rota, workflow ou SQL.
3. PR permanece draft, base docs/portal-sec-p02-identidade-sessoes-2026-11-06, não dev/main. Dependência do PR #24 explícita; não incorporar avanço da dev nem conteúdo do PR #19.
4. Registrar no PR e nas Issues #30/#27 o SHA final, base, diff, limitações e riscos abertos. Comparação final deve conferir os quatro paths adicionados e textos persistidos. Não fechar issues/gates nem aprovar/mesclar PR.
5. Validação nesta atividade é leitura de texto, links/crosswalk/tabelas e diff via GitHub; cenários de segurança/HTTP/PHP/JS/SQL/PG/login/MFA e testes H/C/P/ID-A/REV não são executados.

Ordem futura recomendada: parecer I/D sobre os cinco P0 e DEC-R02-A/B/D → deliberação explícita das premissas e pendências de Produto/C → revisão de ERD/roles/policies/contratos completos e P1 → avaliação de elegibilidade para solicitar autorização separada de laboratório sintético. Nenhum desses passos futuros é executado/autorizado pela R02.

**Conclusão da proposta:** os cinco P0 têm tratamento documental revisável, com contraexemplos, autoridades, sincronização, falhas, fronteiras e provas futuras. R01-01/02/03/09/10 permanecem abertos até parecer independente e evidências aplicáveis; R01-04/05/06/07/08/11/12/13/14 continuam dependências abertas. **CORREÇÕES DOCUMENTAIS CANDIDATAS — GATES ABERTOS — AGUARDANDO PARECER HUMANO.**
