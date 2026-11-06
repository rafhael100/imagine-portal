# SEC-P02-E01 — Inventário de evidências e lacunas para autenticação e tenant

**Data:** 06/11/2026. **Status:** levantamento documental de fontes, NÃO homologação.
**Referências:** PR #24 D01–D06/DEC-01–04; PR #23 SEC-P01 sintética; PR #19 D01–D05 e DEC-01–04 (documental, não mesclado); PORTAL-TEC-D02.

## Evidência existente x o que falta

| Ordem SEC06-D01 | Gates | O que já foi documentado | Entrega verificável ainda ausente | Revisor técnico proposto | Estado |
| --- | --- | --- | --- | --- | --- |
| 1 | G-ID01 / G-BB03 | SEC-P02-D03/D05 e PR #19 D03–D05 exigem identidade exclusivamente Portal | ADR emissor, origem da conta, estados/revisão, bootstrap e testes que rejeitam identidade CRM com ID textual idêntico | Identidade/Segurança/Arquitetura | Parcial, sem emissor real |
| 2 | G-ID02–04 | D01/D04 descrevem MFA, sessão, reset, step-up; SEC-P01 apenas simula | Threat model e política MFA, senha, sessão, CSRF, recovery atômico, antiabuso, tempos revistos | Segurança/Backend/QA | Parcial, sem fator/store reais |
| 3 | G-ID05 / G-BB04 | PR #19 D03–D05 exige membership canônica, papéis locais e revogação | Matriz concedente–alvo, último gestor, reingresso, fonte canônica, revisão e barreira concorrente | Produto/Dados/Segurança | Parcial, sem revogação real |
| 4 | G-ID06 / G-BB01/02/05/06 | PR #19 define diretrizes FKs compostas, RLS de segunda barreira, pool | ADR PostgreSQL/driver/pool, ERD, GRANTs, roles, RLS por operação, plano SQLi/rollback e revisão DBA | DBA/Plataforma/Segurança | Parcial, nenhum SQL aprovado |
| 5 | G-ID07 | Login humano Portal e CRM separados, Performance acessível pelo Portal | Contrato de identidade de serviço, escopo, revogação, idempotência, sem credenciais humanas compartilhadas | Arquitetura/Segurança Integrações | Parcial |
| 6 | G-ID08 / G-BB07 | D05 e PR #19 mencionam trilha mínima, LGPD e restore | Categorias/finalidade, acesso, retenções, restore sem ressuscitar grants | Privacidade/Segurança | Parcial, retenção não definida |
| 7 | G-ID09 / G-BB08 | AUT01–15, IDN01–10, REC01–10, H/C/P do PR #19 são cenários propostos | Ligar cada cenário a ameaça, pré-condição, negação, efeito proibido, coletor, revisor e CI | QA/Segurança/DBA | Parcial, nenhum E2E de auth/RLS |
| 8 | Autorização laboratório | SEC-P02-D06 delimita experimento descartável sintético | Branch/SHA, orçamento, topologia, dados fictícios, least-privilege, rollback, critérios de parada e autorização expressa | Direção após parecer técnico | Pendente |

**Parcial** significa texto candidato, não teste executado. Nenhum dos gates acima está fechado; não há prova de emissor, autenticação real, autorização persistente ou isolamento em PostgreSQL.

## Evidência mínima e critérios de negação obrigatórios

- **Emissor Portal (1):** uma conta CRM com o MESMO identificador textual, e-mail ou CNPJ nunca cria sessão Portal ou membership. Precisa de proveniência de emissor e estado/revisão verificáveis pelo servidor.
- **Sessão/MFA (2):** pré-MFA, cookie adulterado, token antigo, replay, reset duplicado e sessão revogada são negados; novas credenciais e fator de recuperação não elevam privilégios por si.
- **Membership (3):** ator de A não lê/escreve recurso B; nenhum grant de cache obsoleto; último gestor e reingresso não criam autoelevação; revogação bloqueia efeitos após barreira definida.
- **Banco (4):** sob role runtime real sem BYPASSRLS/DDL, SELECT/INSERT/UPDATE/DELETE fora do tenant falham; pool/rollback sem contexto residual; SET LOCAL não certifica identidade.
- **Serviço (5):** sessão Portal não autentica CRM ou contas Google/Meta; motor Performance exige autoridade e escopo próprio.
- **LGPD (6):** logs sem senhas, códigos e tokens, retenção sujeita a decisão legal, restore conciliado com revogações.
- **QA (7):** provar negação E ausência de efeito/leitura indevida, com evidência sanitizada, revisão independente; status HTTP isolado não basta.
- **Ambiente (8):** laboratório sintético e descartável só se aprovado em ato separado, sem PII, custos ou produção presumidos.

## Conciliação objetiva com o PR #19

G-ID01 corresponde ao **G-BB03 (autoridade do ator e fonte de identidade Portal)**. G-ID05 corresponde ao **G-BB04 (membership, grants e revogação)**. G-ID06 depende de G-BB01/02/05/06 (versão PG, esquema, RLS e pool). Os contratos SEC-P02 não substituem decisões físicas de PR #19; nenhum merge fica autorizado.

**Próximo avanço documental útil:** concluir um contrato candidato revisável de emissor de identidade + membership/revogação (SEC-P02-E02) e obter pareceres concretos de Segurança, Identidade, Backend e Dados. Depois fechar os contratos de MFA, PG, RLS, observabilidade e QA. Não produzir novo ciclo de aprovação genérica no lugar dessas evidências.