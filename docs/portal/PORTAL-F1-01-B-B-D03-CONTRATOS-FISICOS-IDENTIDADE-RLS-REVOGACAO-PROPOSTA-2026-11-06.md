# PORTAL-F1-01-B-B-D03 — Contratos físicos candidatos, identidade, RLS e revogação

**Data:** 06/11/2026 · **Estado original:** proposta documental. **Atualização posterior:** as alternativas A de BBD03-01 a BBD03-06 foram ratificadas **apenas como diretrizes preliminares** na [DEC-03](PORTAL-F1-01-B-B-DEC-03-RATIFICACAO-BBD03-01-A-06-A-2026-11-06.md); contratos físicos, gates de segurança e testes permanecem abertos. **Não há autorização de SQL, migrations, código funcional, merge ou deploy.**  
**Precedentes:** [D01](PORTAL-F1-01-B-B-D01-MODELO-FISICO-CANDIDATO-ISOLAMENTO-2026-11-06.md), [DEC-01 (BB01–BB08 A)](PORTAL-F1-01-B-B-DEC-01-RATIFICACAO-DIRETRIZES-BB01-BB08-2026-11-06.md), [D02](PORTAL-F1-01-B-B-D02-CONTRATOS-PERMISSOES-REVOGACAO-RLS-PROPOSTA-2026-11-06.md) e [DEC-02 (BBD02-01–06 A)](PORTAL-F1-01-B-B-DEC-02-RATIFICACAO-DIRETRIZES-BBD02-01-A-06-A-2026-11-06.md).  
**Limite:** detalhar alternativas e gates em papel. **Nenhuma aprovação de D03 foi presumida**. Proibidos SQL executável, migrations, conexão PG, Eloquent, backend real, login/MFA, SSO, execução de testes persistentes, merge, `main`, VPS e deploy.

## 1. Dependências que precisam de contrato antes do recorte programável

As decisões anteriores escolheram **diretrizes arquiteturais**, não o DDL nem a autoridade de identidade. O domínio B-A recebe objetos de usuário, empresa, vínculo e permissões em memória; a origem oficial e atualidade de cada objeto permanecem ausentes.

A implementação futura deverá separar quatro etapas: (a) identidade exclusivamente Portal autenticada e vigente, cuja implementação pertence a gate próprio; (b) seleção da organização como solicitação não confiável; (c) resolução pela autoridade servidora do vínculo canônico, papéis/grants vigentes e titularidade do recurso; (d) decisão mais efeito sob barreira/tenant correto. Ter ID opaco ou configuração de contexto de banco **não comprova** nenhuma dessas etapas isoladamente.

A arquitetura candidata separa **dados globais mínimos** (identidade Portal, catálogo técnico), **dados privados de organização** (vínculos, papéis, atribuições, recursos) e **auditoria protegida**. O caminho de bootstrap para ver somente empresas ligadas ao próprio usuário precisa de política independente do acesso privado em tenant, sem listar outras empresas nem usar bypass genérico.

## 2. Dicionário de integridade e ciclo de vida — contrato candidato

| Objeto | Identidade e integridade exigidas | Autoridade e cenários negativos |
|---|---|---|
| Usuário Portal | ID local opaco, estado, versão de autorização e origem Portal; sem FK ao CRM | Ator externo com mesmo texto, suspenso/revogado, identidade ausente → negar |
| Organização | ID estável, estado, versão, proprietária dos recursos da organização | Organização inexistente, suspensa ou não selecionada → negar |
| Membership | ID estável, par único usuário–organização, estado, vigência, versão, auditoria | Duplicidade, convite, revogação, validade encerrada, empresa errada → negar |
| Papéis locais | Chave composta organização + ID, código local único, estado e versão | Papel da empresa B atribuído ao vínculo A → rejeitar |
| Atribuição e catálogo | Chave do papel no escopo da empresa + código global fechado; revisão e origem controladas | Wildcard, concessão sem alçada, papel revogado, grant direto paralelo → negar |
| Recurso privado futuro | ID + organização proprietária; referências compostas pela organização | FK/edição/cópia tentando cruzar organizações → rejeitar |
| Auditoria | Identificador, correlação, versões antes/depois, autoria, operação, resultado e instante mínimo | Falha ao gravar concessão ou revogação sensível → abortar ação |

**IDs e tipos candidatos:** UUID gerado exclusivamente no servidor para entidades locais, sem usar e-mail/CNPJ como chave; formato e versão UUID, versão principal do PostgreSQL e suporte do driver ainda exigem **decisão de compatibilidade**. `timestamptz` para instantes persistidos com referência UTC; vigência com início inclusivo e fim exclusivo. Não aceitar estado indefinido.

**Reingresso após revogação:** vínculo canônico pode manter o mesmo identificador e histórico, mas não retorna a ativo automaticamente. O responsável de Segurança/Produto deve aprovar fluxo, alçada e evidência do novo aceite, evitando reabrir privilégios antigos; a primeira programação pode deliberadamente bloquear reingresso até esse contrato existir.

**Último gestor autorizado:** não permitir autoexclusão ou revogação que deixe empresa sem recuperação controlada. Tampouco criar staff global com acesso implícito. O fluxo de recuperação e sua trilha são gates futuros.

## 3. Matriz de ações e separação de responsabilidades — proposta mínima

Códigos existentes em B-A (`services.view`, `services.manage`, `billing.view`, `members.manage`) são **exemplos de vocabulário fechado**, não autorização de emissão fiscal, contratação, cancelamento ou aceites jurídicos. O catálogo real precisa ser versionado por capacidade de negócio e por operação (leitura, alteração, administração), sem inferir privilégios de `owner`.

O produto deve distinguir (i) **gerenciar a relação de membros** da empresa, (ii) **conceder papéis** a terceiros, (iii) **alterar a definição dos papéis**, (iv) **administrar contratos e pagamentos**, (v) **ler auditoria**. São poderes diferentes e não derivados da mesma string, do status de pagador ou do título de dono.

**Invariante proposta:** o concedente não pode dar poder superior ao seu mandato; alterações sensíveis exigem verificação atual da matriz de alçadas, tenant correto, registro mínimo e proibição de autoelevação. A lista completa de capacidades comerciais, perfis iniciais e recuperação permanece **pendente**.

## 4. RLS, pool, transações e privilégio — pontos obrigatórios de desenho

- RLS é uma segunda barreira, não substitui autorização da aplicação ou FKs compostas. Definir quais relações globais podem ser consultadas e quais tabelas de tenant usam predicados obrigatórios.
- Runtime de requisições, owner/DDL, avaliador privado, auditoria e executor de migrations devem ter privilégios segregados. **Runtime sem superuser, BYPASSRLS, DDL ou funções arbitrárias com elevação**.
- Evitar combinação permissiva de policies que permita OR ampliar tenant; atenção a operações de alteração que exijam checar tanto linha antiga quanto a nova, e imutabilidade da organização do recurso.
- Avaliador privado candidato **não pode executar SQL dinâmico arbitrário nem revelar a existência de empresas de terceiros**; deve ter revisão de grants, busca recursiva, funções/trigger/view e caminho de nomes controlado.
- O contexto de tenant/ator deve ser estabelecido **antes de qualquer leitura privada**, somente para a transação correta e a conexão correta; erro, timeout, rollback, troca de empresa e retry não podem carregar estado de usuário anterior.
- O mecanismo de configuração local de transação não autentica contra runtime comprometido nem substitui proteção a SQL injection; definir o limite de confiança e os testes de pooler reais antes da implementação.
- Ação sensível e revogação deverão compartilhar barreira/revisão: sem promessa de revogação instantânea contra bytes já exportados, porém sem novos efeitos autorizados com concessões obsoletas após commit.
- Permissões/versões de cache não podem ser reutilizadas automaticamente em sessão, fila ou réplica; na dúvida, negar.

## 5. Cenários documentais obrigatórios para um futuro laboratório isolado

| ID | Cenário | Critério de aceitação futuro |
|---|---|---|
| P01 | Seleção de empresa sem membership, membership estrangeira e ator CRM com mesmo ID textual | Negar acesso e não revelar recursos/empresas |
| P02 | Unicidade do vínculo e FK entre organizações | Rejeitar sem mutação e com erro externo não enumerável |
| P03 | Concessão a terceiro além da alçada e autoelevação | Negar; auditar tentativa conforme retenção |
| P04 | Revogação simultânea a alteração sensível, nas duas ordens | Nenhuma ação posterior à barreira com grant antigo |
| P05 | Concorrência de concessões/reativação, conflito de versão | Idempotência/serialização definida, sem privilégio duplicado |
| P06 | SELECT/INSERT/UPDATE/DELETE de outra empresa sob role runtime | Negar, com e sem filtro de organização na query |
| P07 | Falta de contexto, erro, rollback, timeout e conexão reutilizada | Nunca herdar tenant/ator de transação anterior |
| P08 | View/função/trigger com privilégio elevado ou recursão RLS | Não criar rota de bypass/oracle; revisão independente |
| P09 | Exportação, job e cache com permissão revogada | Revalidar e negar ou cancelar novas unidades de efeito |
| P10 | Falha de auditoria em grant/revogação | Operação sensível abortada |
| P11 | Restore recupera grants que foram revogados depois do backup | Bloquear reabertura até reconciliação segura |
| P12 | Cenários de privacidade, logs, conta técnica e último gestor | Sem vazamento de PII, sem staff implícito e sem perda de recuperação |

**Todos os ensaios P01–P12 estão PENDENTES; não executar nesta etapa**. H01–H12 e C01–C10 permanecem válidos como níveis complementares de evidência, não como resultados certificados.

## 6. Decisões BBD03 para deliberação humana (AINDA NÃO APROVADAS)

| ID | Alternativa A | Alternativa B | Alternativa C | Orientação técnica preliminar |
|---|---|---|---|---|
| **BBD03-01 — baseline PostgreSQL** | Fixar uma versão principal suportada com matriz explícita PHP/driver/pooler/CI e upgrade documentado | Suportar várias versões desde o início | Seguir versão mais recente sem ensaio | **A**, definir número somente após inspeção do ambiente e suporte real |
| **BBD03-02 — IDs e invariantes** | UUIDs locais gerados no servidor; tenant como componente obrigatório de FKs privadas; intervalos temporais consistentes | IDs locais numéricos com mesmo escopo composto | Referência por texto, CNPJ ou IDs CRM | **A**, escolher variante UUID e convenção de tempo mediante revisão |
| **BBD03-03 — catálogo de capacidades** | Definir matriz distinta para leitura, concessão, política, auditoria e contratos; nenhuma elevação implícita | Um `members.manage` controla todos os poderes | `owner` e staff ganham override automático | **A**, catálogo e alçadas ainda precisam detalhamento |
| **BBD03-04 — reingresso e último gestor** | Reingresso bloqueado até fluxo autorizado; proteção contra perda do último gestor e recuperação separada | Reativação manual genérica pelo suporte | Reativação automática com permissões antigas | **A** como opção conservadora temporária, sem liberar fluxo real |
| **BBD03-05 — políticas de acesso a dados** | RLS obrigatória com role runtime mínima e revisão independente de funções/queries indiretas | RLS parcial com filtro apenas na aplicação | Runtime com privilégios de proprietário/bypass | **A**, implementação sujeita a provas negativas, recursão e pooling |
| **BBD03-06 — laboratório e rollout** | Primeiro ensaio com fixtures totalmente fictícias, papéis reais de teste e evidência H/C/P, sem dados de clientes | Teste superficial apenas via mocks PHP | Aplicar diretamente no banco/ambiente real | **A**, gates antes de integração na branch pai ou deploy |

**Importante:** BB01–BB08-A e BBD02-01–06-A são **diretrizes documentais preliminares já ratificadas**, mas o detalhe das novas opções BBD03-01..06 **não é aprovação automática**. Nenhuma dessas escolhas autoriza migration ou aplicação de RLS em PostgreSQL.

## 7. Gates que continuam abertos e saída para a fase seguinte

- D02-SEC01..06: autenticação Portal, sessões/MFA/recuperação, separação CRM, abuse cases, delegação e relações entre serviços.
- D03 físico/RLS: DDL, FKs, combinação de policies, bootstrap, função privada, versão PG/driver/pooler, matriz real de capacidades.
- P0-DOC01..14 e P0-DOC06/G-04: segurança e aprovação transversal; privacy, retenção, plano de backup/restauração e revisão independente.
- H01–H12, C01–C10 e P01–P12: **planejados, não executados**; nenhuma evidência de isolamento real ou prontidão para produção.
- Integração B-B funcional em branch isolada, posterior merge, `main`, VPS e deploy: **NÃO AUTORIZADOS**.

**Próximo aceite possível:** ratificar ou revisar BBD03-01..06 como **diretrizes documentais**, e então definir gates técnicos finais antes de solicitar uma ordem explícita para programação isolada. Sem aprovação tácita de implementação.
