# PORTAL-R07-F2 — Matriz de cenários negativos (somente planejamento)

**Issue:** https://github.com/rafhael100/imagine-portal/issues/52
**Estado:** TODOS OS CASOS NOT RUN. Não confundir com 20 verificações reais da PoC R07-F1, que não possui autorização de transação vinculada.

| Caso | Cenário malicioso / falha | Oráculo esperado da arquitetura futura | Estado |
| --- | --- | --- | --- |
| TX-N01 | Z1 usa sessão válida A para iniciar troca de conta bancária sem canal externo | Nenhum efeito sem confirmação independente C3 | NOT RUN |
| TX-N02 | Z1 altera valor/destinatário depois de emitir challenge | Invalidar intento, challenge e prova; sem efeito | NOT RUN |
| TX-N03 | Assinar challenge de operação A e aplicar à operação B | B não consome prova de A | NOT RUN |
| TX-N04 | Consumir mesma resposta de autorização 2x em concorrência | Máximo um efeito, retry idempotente sem novo commit | NOT RUN |
| TX-N05 | Reutilizar challenge após expiração ou trocar propósito | Negar e registrar tentativa sem enumerar usuário | NOT RUN |
| TX-N06 | Sessão revogada/epoch alterado depois da confirmação | Efeito não ocorre após corte; consentimento invalidado | NOT RUN |
| TX-N07 | Role, membership, entitlement ou owner alterados antes do commit | Revalidar na unidade protegida, negar se insuficiente | NOT RUN |
| TX-N08 | Z1 fornece actor, tenant, MFA/UV=true, device ou status confirmed no payload | Z2 ignora claims e exige evidência própria | NOT RUN |
| TX-N09 | OTP ou WebAuthn legítimo para login usado como aprovação genérica C3 | Não autorizar transação sem challenge/digest específico | NOT RUN |
| TX-N10 | Browser comprometido mostra a operação A mas Z2 tem intenção B | Detalhes significativos do Z2 apresentados na superfície confiável; negar/alertar divergência | NOT RUN |
| TX-N11 | Canal independente indisponível, dispositivo perdido, recuperação não homologada | C3 bloqueada sem fallback fraco ou override informal | NOT RUN |
| TX-N12 | Operador de suporte/CLI/job/n8n contorna contrato de intenção | Mesma política de aprovação e auditoria ou operação proibida | NOT RUN |
| TX-N13 | Duas requests tentam editar o mesmo alvo/intent simultaneamente | Estado linearizável sem write skew, deadlock tratado | NOT RUN |
| TX-N14 | Backend cai após preparar efeito/outbox e antes da resposta | Sem cobrança/efeito duplicado, reconciliação determinística | NOT RUN |
| TX-N15 | Restauração de backup anterior ressuscita challenge/epoch | Serving bloqueado até reconciliação e invalidação | NOT RUN |
| TX-N16 | URI/ID/resource de empresa B injetados em intent A | Nenhum dado/efeito B e erro não enumerável | NOT RUN |
| TX-N17 | Provedor de autenticação ou canal comprometido aceita confirmação falsa | Threat model e garantias/reduções de dano documentados, sem alegação de proteção total | NOT RUN |
| TX-N18 | Vazamento de dispositivo, IP, mensagens e logs | Minimização de PII, nenhum segredo/challenge reutilizável em logs | NOT RUN |
| TX-N19 | Alteração do fator MFA ou recuperação durante intent aberta | Invalidar intent em revisões/epoch e impedir ressurreição | NOT RUN |
| TX-N20 | Ação C3 autorizada com exibição apenas na UI Z1 comprometida | NO-GO: falta prova independente de dados reconhecidos | NOT RUN |

### Critérios de evidência para eventual laboratório

Registrar SHA, política/C2/C3 aprovada, ator e tenant falsos, descrição da intenção canônica, digest/challenge protegido, estado antes/depois, versão/epoch, idempotency key, cronologia e efeito observável. Contar zero efeito da ação negada, não apenas HTTP 403. Não usar identidades reais, serviços externos, segredos ou VPS sem nova autorização.

### Relação com os gates

- R01-01: emissor humano Portal, fator, origem da assinatura; R01-02: sessão/revogação; R01-03: membership/grants concorrentes.
- R01-09: integridade do alvo e tenant, FK, owner; R01-10: nenhum canal SQL privado da Z1, papel SQL mínimo e RLS na Z2.
- T3 permanece parcialmente não contido mesmo após step-up se superfície de confirmação não for independente ou for comprometida. Provar limites sem declarar resultado absoluto.

**Todos NOT RUN.** Documentação aprovada para revisão, não código nem homologação.
