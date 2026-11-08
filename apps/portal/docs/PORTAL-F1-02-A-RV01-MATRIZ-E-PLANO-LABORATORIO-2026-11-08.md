# PORTAL-F1-02-A-RV01 — Revisão, matriz sintética e plano de laboratório futuro

**Data:** 08/11/2026, America/Sao_Paulo. **Natureza:** revisão assistida e documental.
**Autorização desta entrega:** [RV01 do PR #46][rv01], exclusivamente refinar o
[contrato principal](PORTAL-F1-02-A-CONTRATO-EMISSOR-SESSOES-2026-11-08.md).
**Estado:** GO somente para documentação. **NO-GO para F1-02-B**, SQL, migrations,
criação de banco, login/MFA/sessões reais, custo, integração, merge ou deploy.
Todos os gates técnicos continuam **ABERTOS**. Não há parecer independente
AppSec/DBA emitido por este documento.

## 1. Revalidação e rastreabilidade

| Referência inspecionada | SHA / situação observada | Consequência |
| --- | --- | --- |
| Base original F1-02-A | `174eeb71b7b32c89be9ac30ee95a1bbc2124a7f3`, fronteira sintética do PR #41 | Preservar origem da análise; não substituir silenciosamente |
| Dev vigente | `47514f4eac64a3d7ab0a0924cc890fb5a124d8b8` | Referência documental atual, sem atualizar a árvore desta branch |
| UI-04 integrada em dev | `a78faf1f827aefad9416296fb9c4214482db55e3` | Serviços/solicitações fictícios, não autenticação |
| UI-05 integrada em dev | `7207fd1ac7f89751b853f20aabaf3bad21b47137` | Ajuda fictícia, não fonte de autoridade |
| UI-06 integrada em dev | `47514f4eac64a3d7ab0a0924cc890fb5a124d8b8` | Avisos fictícios, não emissão/revogação real |
| Main | `50b683a499e674641df5749051f3d05f7ac3f627` | Somente leitura de referência; não alterada |
| PR #46 antes da RV01 | `44e847befbe059450640c22bb797d02df4472966`, open/draft | Alterar apenas o contrato e este anexo |
| PR #19 | `75b32cef4afa0e7eae83e3b30fdc13c571a767d4`, open/draft | Fonte de D01–D05/DEC-01–04, sem importar/mesclar |
| PR #24 | `e76a9089727d8ccefd492cd660014ce3b22dc5d8`, open/draft | Fonte de identidade, MFA/recuperação e gates, sem merge |
| PR #33 | `9eb8264409000367a70998e079dbd9489b4a555c`, open/draft | R02–R05; filha documental de #24, não integrada |

O intervalo base original → dev tem **três commits**, com 39 arquivos de
apresentação, views, testes e relatórios: 1.648 adições/12 remoções.
A inspeção do intervalo não executa testes de composição nem homologa esses
contratos. O [documento da fronteira F1][boundary] e o código em dev confirmam:
`PortalBoundaryVerdict::canAuthorizeHttp()` retorna sempre `false`.
Nenhuma interface, fake, snapshot ou DTO passa a ser emissor operacional.

[Issue #27][review27] observada aberta, sem assignees. Os comentários de revisão
assistida e as ratificações gerenciais não são assinaturas técnicas independentes.
Não foram inventados revisores, pareceres ou aprovações; a revisão humana segue pendente.

## 2. Precedência, inconsistências e decisões ainda abertas

Esta matriz vale para o **desenho documental**. Não faz merge entre branches,
não reescreve aprovações históricas e não promove propostas físicas a política.

| Fonte congelada | Alcance vigente / inconsistência identificada | Tratamento nesta RV01 |
| --- | --- | --- |
| [#19 D05][bb05] e DEC-01–04 | Diretrizes tenant/FK/roles/RLS; patch/driver e desenho físico não homologados | Manter G-BB01–08; escolher série não comprova ambiente instalado |
| [#24 ADR-01][adr01] | Emissor humano Portal candidato; tempos 30min/12h/5min/15min não homologados | Exigir máquina de estados e política própria; nenhum número vira configuração |
| [#24 ADR-02][adr02] | Lock somente de membership é candidato histórico insuficiente para conta/sessão/role/org/global | Não usar M isolada como garantia; inventário de writers e hierarquia completos |
| [R02-DEC-01][r02] | D01-B hierárquica e D02-A reforçada ratificadas documentalmente | Substituem preferência histórica de G global exclusiva por operação; risco de runtime hostil não aceito |
| [R03-HIER-01][hier] / [R03-GATE-01][r03gate] | P→A→S→O→M→R, modos candidatos e provas H/P | Ordem-alvo, não algoritmo físico provado; locks implícitos/FKs/ORM e leitura longa precisam parecer |
| [R04-DEC-02][r04] | D01-A..D05-A ratificadas só como diretrizes; PG18/PHP8.4/PDO direto; último gestor pode ser suspenso | D01 de R04 detalha D01-B de R02, não retorna à alternativa global antiga; recuperação não cria staff universal |
| [R05-IMPL][impl] / [R05-QA][qa] / [R05-GATE][gate] | Contratos por cinco P0, 22 casos NOT RUN e marcos F0–F5 | Base do crosswalk abaixo; aprovação da consolidação não autoriza F2/laboratório |
| [R01 da Issue #27][r01] | R01-01/02/03/09/10 são bloqueadores; R01-04..08/11..14 continuam relevantes | Preservar riscos de tempos/retry, bootstrap, MFA, cookie, outbox e restore |
| [Fronteira F1][boundary] | Código puro com autoridades negadoras; não prova origem ou persistência | Não ligar a middleware/rotas; resultado sintético nunca é token de allow |
| Contrato F1-02-A original | Formatação com backticks escapados; base antiga; “falha sem criar sessão” ambígua em commit incerto | Corrigir Markdown, preservar SHA histórico e distinguir aborto conhecido de commit indeterminado |

Não há conflito resolvido por “último texto vence” sem autorização explícita.
R02/R04 ratificam somente as alternativas identificadas; R03/R05 detalham e
organizam prova. Aprovações históricas limitadas permanecem válidas no seu recorte,
mas todos os gates ficam abertos.

## 3. Contratos revisados: o que precisa ser provado

| Área | Invariante candidata para revisão | Lacuna/risco e prova necessária |
| --- | --- | --- |
| Emissor Portal | Conta opaca local, origem servidora, namespace exclusivo; canal verificado não é mandato empresarial | Cadastro/convite/CRM/pagamento não criam gestor; T01/T09 |
| Separação de estados | Conta, desafio, fator e sessão são máquinas distintas; pré-MFA não lista empresas | Transições e revisões vinculadas a sujeito, propósito e desafio, sem desafio de outra sessão; T02/T03 |
| Emissão/rotação | Desafio consumido e sessão criada sob unidade coordenada; predecessor não volta a autorizar | Fixation, duas abas, resposta perdida, fator alterado e emissão simultânea; T03/T06 |
| Sessão e validação | Resolver verificador em autoridade Portal; checar revisões/epoch/tempo/fator sob barreiras até o efeito | Store separado não cria transação distribuída; falha fecha acesso, sem allow em cache; T04/T06 |
| MFA e step-up | Enrolment restrito não é sessão privada; step-up por ação/propósito e revisão vigente | Prova criptográfica e canal/fator reais não podem ser homologados por dummy; T02 complementar |
| Recuperação | Reset single-use não remove MFA, não cria login e não restaura grants; perda de fator exige alçada própria | Corrida de tokens iguais/distintos, troca de canal, abuso e recuperação assistida; T02/T09 |
| Revogação | Corte observável por commit/barreira, releitura após espera; todas as autoridades afetadas participam | Ação que commitou antes pode existir; não prometer revogação retroativa de bytes/efeitos externos; T04/T05 |
| Tenant/grants | Organização é intenção não confiável; ator, vínculo, concessão e owner vêm da autoridade | FKs compostas, owner imutável, concedente atual, capabilities desconhecidas negadas; T05/T07 |
| Falha/commit incerto | Antes do commit: rollback integral. Conexão perdida durante commit: resultado desconhecido, sem retry cego | Reconciliar idempotência na autoridade primária antes de nova tentativa; erro ou auditoria ausente não prova rollback; T06 |
| Cookie/CSRF/abuso | Perfil host-only/Secure/HttpOnly, SameSite, origem de recuperação e CSRF dependem de AppSec | __Host-, path, TLS/proxy, XSS/login-CSRF, rate limiting e anti-enumeração não testáveis só por CLI |
| Restore/reingresso | Nenhuma sessão, challenge, credencial, grant ou intent antigo ressuscita | Epoch no mesmo snapshot pode regredir; sem fonte confiável externa ao corte, manter serving privado fechado; T08 |

Nenhuma política de entropia, algoritmo de fator, timeouts de sessão, canal de
recuperação, retenção ou alçada é escolhida por silêncio nesta revisão.
Acesso ao banco por role privilegiada e comprometimento do próprio harness são
ameaças diferentes; fixtures não provam segurança contra operador/runtime hostil.

## 4. Convenções para todos os testes futuros

**Estado de cada família e subcaso abaixo: BLOCKED / NOT RUN.** Falta autorização
de laboratório e faltam fontes/implementação/manifesto e pareceres. Esperado
não significa observado. Nenhum PASS ou contagem de teste executado é atribuído.

**Setup comum proposto:** organizações fictícias A/B; uA somente A, uB somente B,
uAB em ambas; dois gestores fictícios em A e um em B; recursos sentinela A/B;
contas sem vínculo e identidade CRM fake com ID textual deliberadamente igual.
Desafios/fatores e credenciais somente de teste, sem possibilidade de autenticar
produto real. Nenhum ID, e-mail, CNPJ ou segredo de cliente será reutilizado.

**O0 — Oráculo comum de ausência de efeito:** comparar estado antes/depois por
observador do harness, separado da role runtime. Contar linhas e versões de
recursos, sessões utilizáveis, vínculos/grants, idempotência e intents por operação
fictícia; conferir também ausência de conteúdo/ID/contagem sentinela B em saída.
Negativas podem registrar tentativa/consumo inválido ou auditoria de negação
**somente se previsto em allowlist de efeitos auxiliares**. Isso não permite
sessão utilizável, grant, escrita, aprovação ou intent autorizados indevidos.
Resultado de erro, HTTP 403 ou ausência de linha de auditoria isolados não bastam.

**E0 — Rastro sanitizado:** SHA de código/plano/fixtures; manifesto de versões;
alias de run/caso/ator/tenant/operação; role efetiva e privilégio inspecionado;
vetor de revisões antes/depois; pontos de barreira e sequência observada de
transações/commits; resultado, contagens e sentinelas apenas inventadas.
Não publicar senha, handle/verificador de sessão, desafio, OTP, cookie, URL de
reset, segredo, dump bruto, DSN com credencial ou log de payload privado.
O coletor separa logs técnicos restritos de evidência pública sanitizada.

**Concorrência reprodutível:** processos e conexões independentes; barreiras do
harness observáveis, sem endpoints de bypass no produto. Em cada corrida executar
as duas ordens controladas (ação antes de revogação e revogação antes da ação),
incluindo releitura após espera. Sleep/horário de chegada não prova linearização.
Registrar também ordem dentro da classe e locks implícitos; comparar revisão
sem competir na barreira não resolve TOCTOU.

**Resultado futuro:** PASS somente com oráculos satisfeitos no escopo autorizado;
FAIL para violação demonstrada; BLOCKED para premissa/autoridade/oráculo ausente;
INCONCLUSIVE se commit/observação não puder ser reconciliado. Nunca converter
exclusão de ameaça ou teste que não rodou em PASS. Todos os gates seguem abertos
mesmo se um experimento futuro passar, até revisão e decisão específicas.

## 5. Matriz detalhada F1-02-T01 a T09

### F1-02-T01 — Origem de identidade e falsificação de principal

- **Vínculos:** R05-01/T01.a e R05-02/T01.b; R01-01/09;
  G-ID01/07/09, G-BB03/08, D02-SEC01/03–06, D03/RLS como dependência.
- **Setup:** aliases Portal/CRM de mesmo texto, conta sem vínculo e conta uA; nenhuma
  sessão emitida pela autoridade Portal.
- **Injetar:** cookie/credencial CRM, e-mail/CNPJ coincidente, DTO/headers com ator,
  organização, grants ou MFA falsos; repetir com outro propósito/namespace.
- **Esperado:** não emitir sessão Portal nem allow, não criar vínculo e não listar
  organizações privadas. Nenhum login humano em CRM/Performance é emitido pelo Portal.
- **O0 específico:** zero sessão utilizável ou membership nova; saída sem aliases
  privados/sentinelas, inclusive erro/enumeração.
- **E0 adicional:** origem declarada versus autoridade realmente consultada e motivo
  genérico de negação, nunca os valores de credenciais.
- **Residual/parecer:** colisão textual não testa provedor real nem timing completo;
  Identidade/Backend prepara, AppSec independente revisa. BLOCKED / NOT RUN.

### F1-02-T02 — Pré-MFA, replay, recuperação e separação de propósitos

- **Vínculos:** R05-03/T02.a; R01-01/06/07/08; G-ID01–04/09,
  G-BB03/08, D02-SEC01/03, dependência D03/RLS.
- **Setup:** desafio primary_verified/factor_pending, fator dummy revisado,
  duas sessões de desafio e dois tokens fictícios de recuperação.
- **Injetar:** expiração antes/durante espera, replay igual/concorrente, desafio de
  outra conta/sessão/propósito, revisão de fator alterada, relógio cliente forjado.
- **Complementares T02.b–d (além dos 22 R05):** enrolment inicial sem fator não abre
  privado; tokens reset iguais e distintos em corrida têm consumo coerente e não
  removem MFA; troca de canal/perda de fator não permite bypass por suporte/e-mail.
- **Esperado:** nenhum acesso privado pré-MFA; single-use atômico; reset não é login,
  não concede tenant e não baixa nível de fator. Recuperação sem alçada definida nega.
- **O0 específico:** zero sessão privada/elevação; consumo e revisões somente na
  transição permitida; segunda execução não repete alteração.
- **E0 adicional:** aliases de desafio e propósito, consumo/revisões e sequência
  concorrente, sem token/OTP ou verificador.
- **Residual/parecer:** dummy prova apenas coordenação, não segurança do fator.
  Cookie/CSRF/phishing/entrega e MFA real exigem outra fase, BLOCKED. Identidade
  prepara; AppSec e Produto/Privacidade revisam. Família BLOCKED / NOT RUN.

### F1-02-T03 — Emissão/rotação concorrente com reset ou suspensão

- **Vínculos:** R05-06; R01-01/02/07; G-ID02/03/04/09, G-BB03/06/08,
  D02-SEC01, dependência D03/RLS.
- **Setup:** duas conexões emitem/rotacionam a partir de fator e revisões r1;
  terceira muda fator/credencial ou suspende conta para r2.
- **Injetar:** mudança entre verificação e emissão; duas emissões; resposta perdida
  após commit; tentativa de reutilizar handle predecessor.
- **Esperado:** se corte r2 precede autorização final, nenhuma sessão r1 utilizável.
  Se emissão precede corte, sessão emitida deixa de autorizar após revogação.
  Rotação não permite ressuscitar predecessor por retry.
- **O0 específico:** contar sessões utilizáveis por vetor atual e operação; no máximo
  transições previstas, zero predecessor aceito; reconciliar commit desconhecido.
- **E0 adicional:** ordem P/A/S, corte e revisão na emissão versus validação posterior.
- **Residual/parecer:** co-localizar stores não prova atomicidade; Backend/Identidade
  prepara, AppSec e DBA independentes validam protocolo. BLOCKED / NOT RUN.

### F1-02-T04 — Revogação e efeitos entre empresas diferentes

- **Vínculos:** R05-05/T04.a, R05-07/T04.b; R01-02/03/04/11;
  G-ID03/05/09, G-BB04/06/08, D02-SEC01/02, D03/RLS.
- **Setup:** uAB tem sessões distintas e ações pendentes em A/B; uB é ator
  independente; uma conexão faz logout individual, outra global/suspensão.
- **Injetar:** revogar antes e depois de adquirir barreiras; expirar sessão/step-up
  durante espera; manter snapshot anterior para tentar gravar com r1.
- **Esperado:** logout individual afeta sua sessão; global/suspensão invalida o ator
  em ambas empresas. Operação cujo commit precedeu revogação não é retroativamente
  desfeita. Ator/tenant sem dependência comum continua progredindo.
- **O0 específico:** zero nova escrita/leitura materializada fora do corte aprovado;
  comparar A/B separadamente. Conta comum é conflito legítimo, não “tenant independente”.
- **E0 adicional:** lock set P→A→S→O→M→R, modos/esperas/commits; progresso do controle
  independente, latências p95/p99 sem meta de escala inventada.
- **Residual/parecer:** leitura longa/bytes já entregues têm corte próprio; sem
  oráculo de corte, BLOCKED. DBA/Backend prepara, AppSec/DBA independente revisa.

### F1-02-T05 — Grants forjados, concorrência de política e integridade tenant

- **Vínculos:** R05-09/T05.a, 10/b, 11/c, 13/d, 14/e, 15/f, 16/g;
  R05-02 também exercido em T05.h. R01-03/05/09; G-ID05/06/09,
  G-BB02/03/04/05/06/08, D02-SEC02–06, D03/RLS.
- **Setup:** roles e recursos distintos em A/B, uA sem poder em B, uAB com alçadas
  diferentes por organização; concedente/alvo e catálogo versionados.
- **Injetar:** remover role local ou capability global enquanto ação aguarda;
  concedente perde poder; grant direto inventado/unknown capability;
  role B em atribuição A; mudar tenant/owner; adivinhar recurso B, listar sem filtro,
  e provocar erro FK/unique que revelaria B.
- **Esperado:** nenhuma autoelevação, role estrangeira, transferência de tenant ou
  grant stale após corte; política global disputa P; role local disputa O;
  concedente e alvo são revalidados, não apenas M do alvo.
- **O0 específico:** inventário antes/depois de FKs, owner, memberships, grants e
  efeitos; nenhuma linha/contagem/sentinela de B retornada por IDs, lista ou erro.
- **E0 adicional:** vetor P/O/M/R, FK/capability por alias e resultado sanitizado,
  sem detalhes de erro que revelem outro tenant.
- **Residual/parecer:** oracles e timing lateral exigem análise própria; catálogo
  e alçadas finais faltam. Backend/Dados prepara, DBA/AppSec independente e Produto
  revisam delegação. BLOCKED / NOT RUN.

### F1-02-T06 — Falhas do banco, retry, contexto e resultado indeterminado

- **Vínculos:** R05-08/T06.a, R05-20/T06.b; R01-02/04/11/13;
  G-ID03/06/08/09, G-BB01/05/06/07/08, D02-SEC01/02, D03/RLS.
- **Setup:** operações idempotentes fictícias, primário, duas conexões e fronteiras
  controladas de auditoria/efeito; reuso sequencial A→B na mesma conexão de teste.
- **Injetar:** indisponibilidade de sessão/identidade/membership/owner/auditoria,
  timeout, deadlock, cancelamento, queda antes/durante/depois do commit,
  savepoint/rollback e retry; contexto ausente ou residual de A.
- **Esperado:** dependência falha fecha acesso sem allow antigo. Aborto conhecido
  desfaz efeito inteiro. Commit sem confirmação é indeterminado: parar, consultar
  autoridade/idempotência quando disponível e não repetir efeito cegamente.
  A→B não herda contexto ou privilégio.
- **O0 específico:** observador primário reconcilia resultado e conta efeito/intents
  por chave escopada a ator/tenant/ação/payload; zero duplicação, nenhum acesso B
  com contexto A. Falta de observação confiável permanece INCONCLUSIVE/BLOCKED.
- **E0 adicional:** ponto da falha e do commit, tentativas limitadas e releituras;
  nem exceção nem ausência de auditoria isolada são oráculos.
- **Residual/parecer:** pool externo não participa da baseline inicial; variante de
  pool/prepared statements é adiada e BLOCKED até desenho/manifesto próprios.
  Plataforma/Backend prepara, DBA/AppSec independente revisa. NOT RUN.

### F1-02-T07 — RLS por comando versus contexto inteiramente forjado

- **Vínculos:** R05-17/T07.a, 18/b, 19/c, 21/d; R01-09/10/11;
  G-ID05/06/09, G-BB02/03/05/06/08, D02-SEC02–06, D03/RLS.
- **Setup:** A/B e role runtime realmente mínima; caminhos por views/funções/
  herança/partições inventariados; observador separado. Sem role privilegiada
  como runtime de teste.
- **Injetar:** leitura/DML sem filtro e de B; contexto ausente; tentativa de assumir
  owner, DDL/TRUNCATE/EXECUTE elevado; policy permissiva OR insegura; separadamente,
  capacidade adversarial de falsificar todo o contexto ator/tenant.
- **Esperado:** omissão de filtro e DML cruzado devem ser contidos pela defesa
  autorizada; composição OR insegura deve ser detectada como FAIL, não ignorada.
  **Não prometer negação de SQL arbitrário que controla o contexto**: pode haver
  bypass no modelo A, a ser reportado como violação da garantia pretendida/risco
  não aceito e NO-GO. Não transformar essa ameaça em “fora do escopo, PASS”.
- **O0 específico:** conteúdo/linhas A/B e efeitos por SELECT/INSERT/UPDATE/DELETE,
  owner antigo/novo, inventário de privilégios e caminhos de bypass.
- **E0 adicional:** role efetiva, composição de policy e contexto por aliases;
  classificar separadamente omissão de filtro e falsificação total.
- **Residual/parecer:** se a ameaça exigida não puder ser contida pela fronteira,
  arquitetura deve ser reaberta antes do código. DBA/AppSec independentes e
  decisão explícita da Direção; BLOCKED / NOT RUN. Não “aceitar risco” nesta RV01.

### F1-02-T08 — Restore anterior à revogação e reingresso

- **Vínculos:** R05-22; R01-02/13; G-ID03/04/08/09, G-BB04/06/07/08,
  D02-SEC01/02, D03/RLS.
- **Setup:** snapshot futuro apenas sintético anterior à suspensão/reset; fonte de
  corte/revogação confiável fora do ponto restaurado a definir; nova encarnação
  de vínculo, com sessão/desafio/intent antigos rotulados como tal.
- **Injetar:** restaurar revisões antigas; remover ou tornar indisponível a fonte de
  reconciliação; reproduzir token/job de encarnação anterior após novo aceite.
- **Esperado:** serving privado fechado até reconciliação verificável/invalidação
  segura de todas as autoridades afetadas. Reingresso não restaura grants nem
  permite mensagem/sessão antiga. Sem fonte de corte, não reabrir.
- **O0 específico:** tentativas com material antigo negadas antes/depois do restore;
  zero autoridade ressuscitada. Audit log no mesmo snapshot não é fonte suficiente.
- **E0 adicional:** identificadores não secretos do snapshot/corte e revisões
  reconstruídas; jamais publicar dump ou segredo/verificador restaurado.
- **Residual/parecer:** retenção, backup e indisponibilidade não decididos; dado
  perdido não se reconstrói por suposição. Plataforma prepara, DBA/AppSec e
  Privacidade independentes revisam. BLOCKED / NOT RUN.

### F1-02-T09 — Bootstrap, último gestor e recuperação restrita

- **Vínculos:** R05-04/T09.a, R05-12/T09.b; T09.c suspensão de segurança;
  R01-01/03/05/06/07; G-ID01/02/04/05/08/09, G-BB03/04/06/07/08,
  D02-SEC01/02/03, dependência D03/RLS.
- **Setup:** cadastro/convite fictício sem mandato; dois gestores em A e um em B;
  suporte sem privilégio universal; operações com concedente e alvo.
- **Injetar:** pagamento/e-mail/CNPJ como pretensa alçada; convite encaminhado,
  vencido/repetido ou cujo concedente perdeu poder; dupla remoção/rebaixamento;
  suspensão legítima do último gestor por segurança; recuperação sem mandato.
- **Esperado:** nenhum owner automático ou staff global; sem zero gestores por
  write skew normal. Suspensão por segurança não é bloqueada para “salvar gestor”;
  empresa entra em recuperação restrita, sem conceder acesso automaticamente.
- **O0 específico:** contar gestores efetivos sob O e M de concedente/alvo; conferir
  que recovery não cria sessão, grant ou remoção de MFA por mero contato.
- **E0 adicional:** prova sintética de alçada e motivo de suspensão/negação, sem
  documentos pessoais reais ou identidade jurídica inventada como válida.
- **Residual/parecer:** se alçada/canal não estão definidos, teste correspondente
  é BLOCKED, nunca bypass temporário. Produto/Identidade prepara; AppSec/DBA e
  Privacidade independentes revisam. NOT RUN.

## 6. Crosswalk integral dos 22 casos R05

Mapeamento **de escopo**, não equivalência de prova ou homologação. Complementos
de MFA/CSRF/abuso, leituras longas e efeitos externos não são “cobertos” só por
esta tabela. Cada linha continua BLOCKED / NOT RUN.

| R05 | Família/subcaso primário | P0 de origem | Foco da prova futura |
| --- | --- | --- | --- |
| R05-01 | F1-02-T01.a | R01-01 | CRM/Portal e namespaces |
| R05-02 | F1-02-T01.b (também T05.h) | R01-01 | DTO/header/grant/MFA falsos |
| R05-03 | F1-02-T02.a | R01-01 | Pré-MFA, expiração e replay |
| R05-04 | F1-02-T09.a | R01-01 | Bootstrap sem mandato |
| R05-05 | F1-02-T04.a | R01-02 | Logout global versus efeito |
| R05-06 | F1-02-T03 | R01-02 | Reset/MFA versus emissão |
| R05-07 | F1-02-T04.b | R01-02 | Ator multiempresa suspenso |
| R05-08 | F1-02-T06.a | R01-02 | Falha, deadlock e replay |
| R05-09 | F1-02-T05.a | R01-03 | Revogação de role local |
| R05-10 | F1-02-T05.b | R01-03 | Mudança global de catálogo |
| R05-11 | F1-02-T05.c | R01-03 | Concedente perde poder |
| R05-12 | F1-02-T09.b | R01-03 | Dois últimos gestores |
| R05-13 | F1-02-T05.d | R01-09 | FK tenant cruzada |
| R05-14 | F1-02-T05.e | R01-09 | Owner/tenant imutável |
| R05-15 | F1-02-T05.f | R01-09 | Recurso/listagem B por A |
| R05-16 | F1-02-T05.g | R01-09 | Enumeração por erro/constraint |
| R05-17 | F1-02-T07.a | R01-10 | RLS por DML |
| R05-18 | F1-02-T07.b | R01-10 | Roles/funções/bypass |
| R05-19 | F1-02-T07.c | R01-10 | Policy OR insegura |
| R05-20 | F1-02-T06.b | R01-10 | Contexto ausente/reuso/pool adiado |
| R05-21 | F1-02-T07.d | R01-10 | Filtro omitido versus contexto forjado |
| R05-22 | F1-02-T08 | R01-10 | Restore e revogação |

### 6.1 Crosswalk dos gates — todos ABERTOS

| Gate/família | Provas relacionadas | Dependência ainda impeditiva |
| --- | --- | --- |
| G-ID01 emissor | T01/T03/T09 | Proveniência Portal, cadastro/bootstrap e revisão independente |
| G-ID02 MFA | T02/T03/T09 | Fator, enrolment e step-up, sem confundir dummy com autenticador real |
| G-ID03 sessão | T02/T03/T04/T06/T08 | Store, cookie/tempo/rotação e barreira efetiva |
| G-ID04 recuperação | T02/T03/T08/T09 | Consumo/mandato/canal, sem remover MFA ou criar login |
| G-ID05 organização | T04/T05/T07/T09 | Autoridade, alçadas, ownership e último gestor |
| G-ID06 banco | T05/T06/T07 | Modelo físico, role real, FK/RLS e isolamento |
| G-ID07 integração | T01 e complemento externo | Identidade técnica/fencing; nenhum provedor real no laboratório |
| G-ID08 operação | T06/T08/T09 | Auditoria/retentiva/incidentes/restore e privacidade |
| G-ID09 qualidade | T01–T09 e complementos | Oráculos, execução e parecer por SHA |
| G-BB01 ambiente | Todos | Manifesto/versões/recursos/custo e autorização |
| G-BB02 esquema | T05/T07/T09 | ERD, constraints, tenant em relações privadas |
| G-BB03 autoridade | T01/T03/T05/T07/T09 | Origem Portal e projeção de poderes |
| G-BB04 papéis/revogação | T04/T05/T08/T09 | Catálogo, delegação/reingresso e invariante de gestão |
| G-BB05 RLS | T05/T06/T07 | Roles/grants/funções e limite de ameaça |
| G-BB06 concorrência | T03–T09 | Locks, isolamento, relógio, conexão/retry/efeito |
| G-BB07 auditoria/LGPD | T06/T08/T09 | Minimização/retentiva/restore e acesso à evidência |
| G-BB08 testes | T01–T09 | Complementar H01–H12/C01–C10/P01–P12, sem substituir 34 casos |
| D02-SEC01 | T01/T02/T03/T04/T08/T09 | Identidade/MFA/sessão/recuperação |
| D02-SEC02 | T04/T05/T07/T09 | Membership, gestão e revogação |
| D02-SEC03–06 | T01/T05/T07 e fronteiras por produto | Separação de identidades, experiência e integração ainda a revisar |
| D03 físico/RLS | T05/T06/T07/T08/T09 | Desenho físico e provas reais futuras; não satisfazível por DTO |
| R01-01/02/03/09/10 e release | Todos, por P0 na tabela R05 | Nenhum P0 ou gate é fechado por documento/CI/contagem PHPUnit |

Gates não detalhados individualmente em D02-SEC03–06 permanecem distintos na
fonte [matriz SEC-P02-D02][secd02]; agrupamento de rastreio não os funde.
ID-A01–06, REV-01–10 e H/C/P continuam complementares e NOT RUN.

## 7. Plano reprodutível de futuro laboratório PG18 — sem executar

### 7.1 Entrada e manifesto candidato

Antes de qualquer provisionamento, a Direção precisa autorizar um recorte F1-02-B
por escrito com branch/SHA, ações, ambiente, teto de custo e parada/rollback.
Pareceres independentes AppSec/DBA devem avaliar o desenho e a ameaça previamente;
não precisam declarar gates fechados para permitir uma experiência delimitada.

| Item do manifesto futuro | Proposta documental | Estado hoje |
| --- | --- | --- |
| PostgreSQL | Série 18, primário único descartável, sem extensões não justificadas | Patch, distribuição, digest, disponibilidade e suporte **a confirmar** |
| Aplicação/harness | Laravel 13, PHP 8.4; PHP 8.3 somente matriz adicional se autorizado/disponível | Versões exatas/lock/compatibilidade a confirmar para o laboratório |
| Driver | PDO_PGSQL + libpq identificados por versão efetiva; conexão direta não persistente | Binários, TLS local e atributos/prepared statements a revisar pelo DBA |
| Pool | Nenhum pool externo no caminho inicial de autoridade | PgBouncer e comparação de pool adiados, não instalados nem testados |
| Isolamento transacional | Candidato a escolher entre alternativas revisadas, com leituras pós-lock e corte temporal definido | READ COMMITTED/SERIALIZABLE, locks/módulos e níveis ainda sem seleção final |
| Processo/conexão | Uma conexão dedicada por processo/participante concorrente, mesma conexão do início ao fim da unidade | Afinidade e cleanup a comprovar, não presumir pelo ORM |
| Recursos | Namespace/VM/container descartável próprio; teto inicial de recursos proposto pelo responsável do ambiente | Host, RAM/CPU/disco, quotas e viabilidade **a confirmar** |
| Rede | Sem porta pública, VPS ou conexão a CRM/produção; rede local isolada do laboratório | Controle de egress/exposição e acesso humano a confirmar |
| Custo | Zero somente se capacidade já disponível, gratuita e aceita for comprovada | **A confirmar; não há autorização de gasto ou consumo de quota paga** |
| Evidência | Destino restrito para logs técnicos e cópia pública somente sanitizada, retenção/cleanup aprovados | Destino, capacidade e responsáveis a confirmar |

Nenhuma imagem foi baixada, container iniciado, porta aberta, pacote PG instalado,
DSN testado ou instância inspecionada para este plano. Não apresentar a matriz
como inventário de servidor existente. Sem números de desempenho/SLA escolhidos.

### 7.2 Roles lógicas e fronteiras de privilégio (nenhuma criada)

| Papel candidato | Finalidade futura no laboratório | Restrições a provar |
| --- | --- | --- |
| owner sem uso em request | Possuir objetos privados de teste | Não é login do harness runtime nem papel assumível por ele |
| migrator separado | Preparação/reset autorizado do esquema fictício | Credencial fora do runtime; nunca usada para passar os casos RLS |
| runtime_web | Representar papel de operação interna, apesar de não haver HTTP de negócio | Sem superuser/BYPASSRLS/owner/DDL/TRUNCATE/elevação genérica; heranças inspecionadas |
| runtime_job | Simular unidade diferida apenas quando incluída no recorte | Mesmo limite tenant, sem allow carregado; não é fila/dispatcher real |
| observador de prova | Comparar sentinelas/efeitos da fixture | Canal do harness isolado, não adaptador exposto no produto |
| audit_reader / maintenance_recovery | Leitura restrita de evidências e reset/restore descartável | Alçada separada, sem staff universal, sem credencial de produção |

A matriz final deve inventariar privilégios diretos/transitivos, SET ROLE,
schemas/EXECUTE/search_path, funções/views/triggers/partições, owner e composição
de policies por comando. FORCE RLS não restringe superuser/BYPASSRLS.
Nenhum comando SQL/GRANT ou migration é fornecido nesta revisão.

### 7.3 Sequência futura, somente após autorização

1. **Congelar:** registrar autorização, SHA da dev novamente conferido, branch filha
   F1-02-B nova, fontes documentais e manifesto de binários/digests. Sem cherry-pick
   silencioso das branches documentais. O diff é revisto antes de executar.
2. **Isolar:** criar somente o ambiente descartável aprovado; sem volumes, redes,
   credenciais, dados ou rotas existentes de Portal/CRM/VPS. Fechar egress de
   provedores reais e portas públicas; guardar apenas credenciais efêmeras de teste.
3. **Preparar após nova ordem:** implementar o menor esquema/harness que o recorte
   futuro autorizar; ERD/FKs/roles/RLS revistos, dados inventados e observers
   separados. Não produzir agora scripts, DDL, migrations ou Dockerfiles.
4. **Autotestar o harness:** falha de observador, role errada ou sentinela de outro
   run interrompe a coleta; teste com role owner não vale como teste runtime.
   Registrar configuração efetiva sem publicar segredos.
5. **Reset por caso:** restaurar fixture limpa e identidades efêmeras, nenhuma
   sessão/grant/epoch/contexto de outro teste. Processo e conexão independentes
   por concorrente; aliases determinísticos e seed não secreto registrados.
6. **Executar o subset autorizado:** T01–T09 e respectivos subcasos/casos R05
   explicitamente incluídos, ambas ordens de corrida, O0/E0 e controles positivos
   mínimos; complementos fora do subset continuam BLOCKED.
7. **Conter falhas:** timeout/deadlock/cancelamento abortam e exigem nova resolução
   das autoridades. Definir antes do run teto de espera, duração de transação,
   tentativas de retry e deadline do caso; valores só após revisão DBA/Plataforma.
   Exceder qualquer teto é parada/FAIL ou BLOCKED, nunca retry infinito.
8. **Reconciliar:** resultado de commit incerto não é rollback presumido. Validar
   estado no primário via chave idempotente antes de repetir; sem fonte, manter
   serving privado fechado e classificar INCONCLUSIVE.
9. **Encerrar:** coletar manifesto/contagens sanitizadas e hashes dos artefatos;
   remover instância, volumes, contextos, arquivos sensíveis e credenciais efêmeras
   do laboratório. Registrar sucesso ou falha de cleanup sem expor material.
10. **Revisar:** QA reporta resultados reais, AppSec/DBA independentes emitem parecer
    por SHA. O PR futuro continua draft; merge, main ou deploy exigem nova decisão.

Essa sequência não é autorização de iniciar. F1-02-B não expõe HTTP, não entrega
cookie ou fator a usuário real, não chama provedor, não converte
canAuthorizeHttp() em true nem conecta o demo ao container operacional.

### 7.4 Evidências, rollback e regras de parada

- Artefato futuro por subcaso: manifesto, seed/fixture, agendas de barreira,
  papel efetivo, revisões/contagens/sentinelas antes/depois, resultado e limitações,
  risco residual, sanitização, responsável e hashes. Captura de erro sozinha não basta.
- Logs brutos e qualquer snapshot sintético que contenha material de autenticação
  ficam restritos e são descartados segundo política aprovada; não subir dump,
  cookie, handle ou verificador para repo público/CI.
- **Rollback transacional:** abortar unidade falha e verificar O0. Não confundir
  desfazer transação com restaurar um backup nem com reverter ação externa.
- **Rollback do laboratório:** parar todos os processos de teste e serving privado
  experimental, destruir somente recursos descartáveis identificados no manifesto,
  invalidar credenciais efêmeras, limpar artefatos sensíveis e retornar o checkout
  isolado a SHA aprovado. Manter apenas evidência sanitizada permitida.
- **Parada imediata:** ambiente/role inesperado, acesso a host/dado real, custo não
  autorizado, porta pública, dependência externa, ausência de isolamento/reset,
  vazamento de contexto/sentinela, auditoria necessária ausente, bypass de RLS,
  cronologia não observável, deadlock não explicado ou commit não reconciliável.
- T07 de falsificação total sem mitigação aceita é NO-GO arquitetural, não
  justificativa para relaxar policy, trocar para owner ou liberar risco.
- Falha de cleanup permanece pendência e impede novo run até responsável revisar.
  Não apagar recurso de outra frente; nenhum rollback em main/dev/VPS/produção.

### 7.5 Fora do laboratório mínimo

Cookies/CSRF/headers de proxy reais, MFA/provedores de recuperação reais, exposição
web, SSO, entrega e-mail/SMS/WhatsApp, CRM, Performance/Google/Meta, pagamentos,
migração do legado, carga operacional e backup de produção não participam.
Outbox é no máximo intenção fictícia e receptor dummy, se autorizado: nenhuma
garantia de efeito externo é herdada do commit. Contrato futuro de fencing/
idempotência aceito pelo receptor e estado accepted/rejected/unknown exige fase
separada. Não prometer pausa instantânea de campanha ou recall de bytes exportados.

## 8. Riscos e decisões pendentes para parecer independente

| Decisão / risco | Mitigação candidata, não aprovada como implementação | Parecer/responsável requerido | Estado |
| --- | --- | --- | --- |
| D-L01: SQL/runtime forja ator e tenant | Rever fronteira ou autoridade que o adversário não possa forjar; parametrização/privilégios não provam contenção total | AppSec + DBA independentes; Direção decide requisito/risco explicitamente | ABERTO, impeditivo |
| D-L02: stores de sessão e efeito separados | Co-localização transacional candidata ou protocolo de fencing verificável; negar sem coerência | Backend/Arquitetura + DBA/AppSec | ABERTO |
| D-L03: fator, entropia, enrolment e reset | Máquina por propósito/sujeito/revisão, single-use, alta garantia e sem login implícito | Identidade + AppSec + Produto/Privacidade | ABERTO |
| D-L04: bootstrap/último gestor/mandato | Alçada externa definida, O e M ordenados, suspensão de segurança permitida, recovery restrito | Produto + AppSec/DBA independentes | ABERTO |
| D-L05: locks/isolation/tempo | Inventário fechado de writers e locks implícitos, ordem P→A→S→O→M→R, ponto de corte e oráculo por leitura/efeito | DBA/Backend; métricas revisadas por Plataforma | ABERTO |
| D-L06: FK/tenant/roles/RLS | ERD e matriz de comandos/privilégios, controle de erro e composição OR | Dados + DBA/AppSec independentes | ABERTO |
| D-L07: cookie/CSRF/abuso/tempos | Perfil host-only e origem confiável; política temporal/antiabuso após threat model | AppSec/Identidade/Produto | ABERTO, fora de prova só CLI |
| D-L08: auditoria, restore, reingresso | Fonte de corte além do snapshot, serving fechado até reconciliação, minimização/retentiva aprovadas | DBA/Plataforma/AppSec + Privacidade | ABERTO |
| D-L09: leitura longa/outbox/efeitos externos | Corte por lote; fencing/dedupe no receptor, reconciliação de unknown; sem integração agora | Backend/Arquitetura/AppSec, responsáveis dos produtos | ABERTO |
| D-L10: ambiente/versões/recursos/custo | Manifesto completo, quota/isolamento e teto previamente autorizados; não presumir custo zero | Plataforma/DBA + Direção | ABERTO, impeditivo |
| D-L11: revisão independente e autorização | Nome/função, SHA, escopo, limitações, exigências e conclusão por parecer; não inventar sign-off | Direção designa; humanos AppSec/DBA revisam | ABERTO, impeditivo |

Funções acima são propostas, não pessoas designadas. A titularidade do repositório,
um comentário assistido ou CI verde não atesta independência. Nenhuma aceitação
tácita de risco, política numérica ou alçada decorre desta matriz.

## 9. Checklist para submeter novo pedido de laboratório

O próximo pedido deverá identificar: versão revisada deste plano e SHAs fontes;
subset mínimo de T/R05 e complementos; contratos/ameaça e tratamento de D-L01;
pareceres AppSec/DBA identificados; decisão de emissor/store/coerência; ERD e
inventário completo de writers/roles/locks; manifesto de ambiente descartável e
custo/isolamento; parâmetros de timeout/retry/reset; coleta/sanitização/destino;
cleanup/rollback e regras de parada; limites sem HTTP real/integrações; branch e
PR draft próprios. Aprovação deve nomear exatamente o recorte e eventuais
pendências admitidas, sem homologar os demais gates.

**Nesta RV01 esse checklist não está aprovado nem completo.** Não executar
F1-02-B por este texto. F1-02-C/login operacional, homologação, merge documental
em dev, merge em main e deploy são decisões posteriores distintas.

## 10. Verificação documental e limitações

Executado nesta revisão: leituras de PRs/documentos/Issue #27; revalidação de
SHAs e histórico dev/main; inspeção estática da fronteira F1; conciliação de
precedência e 22 referências R05; revisão Markdown/links, escopo do diff e
ausência de backticks indevidamente escapados no contrato.
O comentário de encerramento do PR #46 registra comandos de verificação,
resultado efetivo e novo SHA. Não é relatório de laboratório.

**Não executado:** PHPUnit/HTTP/login/MFA, banco, SQL/migrations, PG18, RLS,
concorrência, restore, carga, pentest ou integração. Nenhum recurso provisionado,
credencial criada ou dado real utilizado. Os nove T e os 22 R05 estão
**BLOCKED / NOT RUN**, e todos os cinco P0, G-ID01–09, G-BB01–08,
D02-SEC01–06, D03/RLS, H/C/P e release permanecem **ABERTOS**.
Nenhuma revisão assistida substitui o parecer humano independente.

## Fontes congeladas

[rv01]: https://github.com/rafhael100/imagine-portal/pull/46#issuecomment-6723530429
[review27]: https://github.com/rafhael100/imagine-portal/issues/27
[r01]: https://github.com/rafhael100/imagine-portal/issues/27#issuecomment-6690120467
[bb05]: https://github.com/rafhael100/imagine-portal/blob/75b32cef4afa0e7eae83e3b30fdc13c571a767d4/docs/portal/PORTAL-F1-01-B-B-D05-PACOTE-PRE-IMPLEMENTACAO-GATES-CRITERIOS-PROPOSTA-2026-11-06.md
[adr01]: https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-ADR-01-AUTORIDADE-IDENTIDADE-PORTAL-PROPOSTA-2026-11-06.md
[adr02]: https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-ADR-02-MEMBERSHIP-REVOGACAO-TRANSACIONAL-PROPOSTA-2026-11-06.md
[r02]: https://github.com/rafhael100/imagine-portal/blob/9eb8264409000367a70998e079dbd9489b4a555c/apps/portal/docs/SEC-P02-R02-DEC-01-RATIFICACAO-D01-B-D02-A-REFORCADA-2026-11-06.md
[hier]: https://github.com/rafhael100/imagine-portal/blob/9eb8264409000367a70998e079dbd9489b4a555c/apps/portal/docs/SEC-P02-R03-HIER-01-ESPECIFICACAO-LOCKS-HIERARQUICOS-2026-11-06.md
[r03gate]: https://github.com/rafhael100/imagine-portal/blob/9eb8264409000367a70998e079dbd9489b4a555c/apps/portal/docs/SEC-P02-R03-GATE-01-MATRIZ-VALIDACAO-2026-11-06.md
[r04]: https://github.com/rafhael100/imagine-portal/blob/9eb8264409000367a70998e079dbd9489b4a555c/apps/portal/docs/SEC-P02-R04-DEC-02-RATIFICACAO-D01-A-A-D05-A-2026-11-06.md
[impl]: https://github.com/rafhael100/imagine-portal/blob/9eb8264409000367a70998e079dbd9489b4a555c/apps/portal/docs/SEC-P02-R05-IMPL-01-CONTRATOS-CINCO-P0-2026-11-07.md
[qa]: https://github.com/rafhael100/imagine-portal/blob/9eb8264409000367a70998e079dbd9489b4a555c/apps/portal/docs/SEC-P02-R05-QA-01-CASOS-SINTETICOS-CINCO-P0-2026-11-07.md
[gate]: https://github.com/rafhael100/imagine-portal/blob/9eb8264409000367a70998e079dbd9489b4a555c/apps/portal/docs/SEC-P02-R05-GATE-01-PLANO-DESENVOLVIMENTO-CONTROLADO-2026-11-07.md
[secd02]: https://github.com/rafhael100/imagine-portal/blob/e76a9089727d8ccefd492cd660014ce3b22dc5d8/apps/portal/docs/SEC-P02-D02-MATRIZ-DECISOES-HOMOLOGACAO-2026-11-06.md
[boundary]: https://github.com/rafhael100/imagine-portal/blob/47514f4eac64a3d7ab0a0924cc890fb5a124d8b8/apps/portal/docs/PORTAL-F1-AUTHORITY-BOUNDARY-2026-11-07.md
