# SEC-P02-R02 — ADR01: contrato candidato de identidade e sessão para os P0

**Data:** 06/11/2026 (America/Sao_Paulo). **Atividade:** [CODEX-SEC-P02-R02 / Issue #30](https://github.com/rafhael100/imagine-portal/issues/30).
**Estado:** CORREÇÕES DOCUMENTAIS CANDIDATAS — GATES ABERTOS — AGUARDANDO PARECER HUMANO.

**Proveniência:** complemento novo, sem substituir nem editar os documentos históricos. Base documental do PR #24: `e76a9089727d8ccefd492cd660014ce3b22dc5d8`; PR #19: `75b32cef4afa0e7eae83e3b30fdc13c571a767d4`. Achados: [R01, comentário 6690120467](https://github.com/rafhael100/imagine-portal/issues/27#issuecomment-6690120467). Referências completas e matriz de provas no [documento de rastreabilidade](SEC-P02-R02-MATRIZ-ACHADOS-NEGATIVOS-PARECERES-PROPOSTA-2026-11-06.md).

**Limites:** somente Markdown. Nenhum código funcional, SQL executável, DDL, migration, conexão a banco, login/MFA real, laboratório, teste operacional, integração, dados reais, custo, merge, main/dev, alteração dos PRs #19/#24 ou VPS. Este texto não ratifica arquitetura, não resolve P0 por si só e não homologa gate. Termos de lock, chave e policy são requisitos em prosa, não implementação.


## 1. Problema, contraexemplos e preferência candidata

Trata R01-01 e a parte identidade/sessão de R01-02; depende do [ADR02 R02](SEC-P02-R02-ADR02-AUTORIDADES-BARREIRAS-REVOGACAO-P0-PROPOSTA-2026-11-06.md) para serialização e do [ADR03 R02](SEC-P02-R02-ADR03-TENANCY-RLS-LIMITES-AMEACA-P0-PROPOSTA-2026-11-06.md) para privilégios e tenant. A classe PortalUserId valida sintaxe e igualdade, não emissor. OrganizationAuthorizationService avalia valores recebidos; DemoPortalSession não é autenticação.

Contraexemplo R01-01: request traz ID CRM textual igual a um ID local, header MFA e membership coerentes; construir DTOs com esses valores não estabelece quem autenticou. Contraexemplo R01-02: uma ação valida revisão 7, outra transação suspende conta para revisão 8, e a primeira grava depois usando só lock de membership.

**Alternativa A, preferida apenas para análise:** núcleo autoritativo Portal no mesmo banco primário e na mesma unidade transacional que os efeitos internos protegidos. Registro de conta, verificadores de credenciais/fatores, desafios, registro revogável de sessões e revisões participam dessa autoridade. Segredos de fatores exigem proteção/gestão de chaves, não resolvida por colocar dados no banco. Cache/Redis, se futuramente usado, não será autoridade positiva de sessão. O CRM mantém banco, credenciais, sessões e contas próprios. Não é escolha de versão PG, topologia física ou biblioteca.

**Alternativa B, não adotada neste candidato:** store externo de sessões/IdP com coordenação. Validar a fonte e depois escrever no banco deixa check→efeito sem atomicidade. Exigiria registro de fencing no banco do efeito, protocolo de emissão/revogação com confirmação e barreira no mesmo ponto do efeito; mensagens e caches eventuais não bastam. Revogação solicitada no store externo não pode ser anunciada como concluída antes da barreira no núcleo. Se esse atraso for incompatível com Produto/risco, negar operações privadas até resolver arquitetura. Não existe transação distribuída presumida.

**Pendência DEC-R02-A:** Arquitetura/Identidade/AppSec/DBA devem avaliar co-localização lógica Portal, custo de armazenamento de sessão e a barreira global conservadora. Nenhuma opção está ratificada. Enquanto essa decisão ou autoridade estiver ausente, este contrato não habilita fluxo real.

## 2. Fronteiras e autoridades exatas propostas

Os nomes são componentes lógicos com capacidades distintas, não classes/serviços já existentes. Toda mutação autoritativa usa a barreira G do ADR02. Se dois componentes futuramente compartilham credencial SQL, a separação lógica não os protege contra comprometimento total do processo; essa limitação é explícita no ADR03.

| Componente candidato | Pode afirmar/escrever | Não pode afirmar |
|---|---|---|
| Registrador de contas Portal | Gera ID local e namespace Portal, conta pending_verification, canal a verificar, evento de criação e identity_revision inicial | Não ativa membership, não cria gestor ou fator por e-mail/CNPJ/ID CRM |
| Verificador de canal | Consome desafio ligado a conta, canal, propósito e revisão; muda somente estado de verificação sob G | Não emite sessão autenticada nem prova de identidade civil/mandato empresarial |
| Autenticador primário Portal | Valida verificador próprio e registra desafio primary_verified com revisão atual | Não aceita senha/sessão CRM; não ativa MFA, membership ou acesso privado |
| Verificador MFA Portal | Confirma desafio e posse do fator vigente, propósito, sessão de desafio e tentativa; grava evento/consumo | Não aceita booleano/header, relógio ou fator fornecido como verdade pelo cliente |
| Emissor de sessões Portal | Somente após desafios requeridos confirmados e releitura sob G, cria sessão autenticada com handle novo e snapshots de revisões | Não usa pre-auth como sessão autenticada; não emite sessão CRM/Performance |
| Validador de sessão Portal | Resolve handle exclusivamente no registro Portal, verifica revisões/status/tempos e entrega referência interna dentro da unidade protegida | Não aceita DTO, JWT genérico, tenant ou role como prova; não emite allow reutilizável |
| Gestor de segurança da conta | Com alçada própria e prova apropriada, altera estado/credencial/fator, epochs, revoga sessão individual/global; audita atomicamente | Suporte, pagador, domínio de e-mail ou CRM não constituem alçada; não concede capacidade empresarial |
| Autoridade de membership/política | Resolve organização, vínculo, papéis, grants e recurso por fontes oficiais e alçadas do ADR02/03 | Não autentica pessoa por membership e não autoriza por nome owner/staff |
| Auditoria protegida | Acrescenta evento mínimo na transação, correlação e revisões; consulta por capacidade separada | Não guarda senha, handle bruto, token, seed/código MFA, payload privado ou PII desnecessária |

Privilégios de emissão, verificação, recuperação, política e auditoria devem ser inventariados separadamente no desenho físico; nomes de componentes não demonstram segregação. Cadastro e confirmação de canal autenticam um fluxo restrito, não comprovam titularidade de empresa nem outorgam mandato.

## 3. Identidades, namespaces e registro lógico

Uma referência de sujeito local é o par lógico (namespace Portal, portal_actor_id). IDs são opacos gerados no servidor, não segredos. E-mail é meio de comunicação local, sujeito à normalização/política ainda pendente; CNPJ/CPF não é chave de identidade ou autorização. Não há FK, consulta de identidade ou importação de hash/session do CRM. Mesmo texto de ID pode existir nos dois produtos sem equivalência. Não usar prefixo textual como prova de origem: o caminho autenticado e seu repositório estabelecem a origem.

Registro candidato de conta: ID, estado, estado de canal, identity_revision, credential_revision, mfa_revision e session_epoch, todos atuais na autoridade; motivo codificado e auditoria. Registro de sessão: referência interna, verificador de handle imprevisível (segredo bruto só no cookie), actor local, namespace/propósito fixos pelo servidor, estado, sessão/revisão, epoch da conta, snapshots de identidade/credencial/MFA, evento autenticador, nível e instante MFA, emissão, último uso, limites absolutos e ociosos. Comparação segura do verificador e entropia dependem de desenho AppSec, não números inventados.

A revisão é monotônica dentro da encarnação da autoridade e incrementada atomicamente; nunca fornecida pelo request. Restore pode retroceder contadores: exige epoch de recuperação/reconciliação externo ao snapshot ou invalidação segura antes da reabertura (ADR02 §8), não promessa de monotonicidade eterna. IDs de sessão e desafio não são reciclados.

Organização selecionada, recurso e ação são intenções hostis. Nenhuma sessão carrega grants definitivos; snapshots servem para detectar divergência, não para dispensar fonte atual.

## 4. Máquina de estados e operações

| Estado ou evento | Pré-condições / transição candidata | Falha e efeito sobre autorização |
|---|---|---|
| pending_verification | Registrador cria conta sem vínculo; desafio de canal não dá sessão privada | Só operações públicas/restritas de verificação, nenhuma lista de tenants |
| eligible | Canal verificado e política de ativação satisfeita; assinatura de evento sob G | eligible não significa autenticado nem membro |
| suspended / deactivated / deleted_or_anonymized | Alçada específica; mudança de identity_revision e session_epoch sob G, com trilha | Todas as sessões anteriores deixam de autorizar; ausência de estado reconhecido nega |
| primary_verified / factor_pending | Credencial atual verificada; desafio ligado a actor, revisão, propósito e nonce; MFA pendente quando exigido | Nenhuma leitura privada, descoberta de empresas, sessão de produção ou efeito de negócio |
| enrolment_pending | Exceção de fluxo inicial restrito, se futuramente aprovada: prova primária/canal e posse de novo fator | Sem sessão privada para resolver a circularidade do primeiro MFA; bootstrap permanece bloqueado até alçada aprovada |
| authenticated | Emissão sob G relê conta/revisões, desafio não consumido e fator exigido; consome desafio e persiste handle novo atomicamente | Falha/concorrência aborta consumo e emissão; não reabre revisão antiga |
| step_up_required | Política atual requer desafio recente para ação/propósito; conta e fator vigentes | Sessão pode existir, mas a ação sensível nega; desafio de outra sessão/propósito não serve |
| rotação | Sob G, invalidar handle anterior e criar sucessor indivisivelmente; resposta após commit | Não há período de graça que autorize predecessor. Resposta perdida pode exigir reautenticação; não ressuscitar handle |
| logout individual | Sob G, sessão passa a revoked e revisão aumenta, auditoria no mesmo commit | Cookie antigo nega; remoção do cookie no browser sozinha não revoga |
| logout global | Sob G, session_epoch da conta aumenta; emissão concorrente relê epoch antes de finalizar | Todos os handles da época anterior negam, inclusive outros dispositivos |
| troca de senha/fator | Sob G, atualizar verificador/fator e revisões, session_epoch e invalidação dos desafios/tokens pertinentes com trilha | Nenhuma sessão anterior conserva allow; reset de senha não remove MFA nem concede membership |
| expired / revoked | Tempo aferido no servidor ou evento autoritativo | Terminal para aquele handle; novo login emite outro handle, sem reativação |
| reativação da conta | Fluxo separado e revisão nova; exige decisão de segurança/alçada | Não reabre sessão, fator inválido, membership ou grants antigos automaticamente |

Políticas quantitativas, fator exigido por classe de operação, enrollment, recuperação de canal/fator, suporte e reativação não estão aprovados. 30min/12h/5min/15min são referências candidatas dos documentos históricos; 600s da demo também não é contrato.

## 5. Caminho da requisição e ponto de sincronização

1. Receber cookie exclusivo Portal sob TLS e perfil de cookie/CSRF ainda sujeito a AppSec. Usar handle somente para buscar candidato a registro; lookup inicial é não autorizador e não retorna metadados privados.
2. Na conexão primária Portal, abrir unidade transacional e adquirir G exclusiva conforme ADR02 **antes de ler dados privados ou decidir**. Leituras prévias de estado são descartadas. Sem G, fonte ou registro, negar.
3. Sob G, reler conta/sessão/desafio e todas as revisões. Conferir namespace local, estado, expiração, epoch, credential/mfa/identity_revision e prova de MFA/step-up exigida. Atualização de último uso não pode reanimar sessão expirada.
4. Resolver membership/grants/empresa/ownership e ação pela autoridade atual. Tenant no cookie, header ou URL não muda poder. O avaliador puro só recebe projeção já resolvida, dentro dessa unidade, sem converter demo em middleware.
5. Revalidar validade e frescor com tempo autoritativo no ponto do efeito/materialização definido em ADR02; persistir efeito, idempotência e auditoria obrigatória na mesma transação, mantendo G até commit/rollback.
6. Só após commit confirmar sucesso. Falha de store/banco, revisão divergente, tempo inválido, auditoria obrigatória ou contexto incerto implica rollback e ausência de efeito. Falha da resposta após commit gera resultado incerto a consultar pelo mecanismo idempotente, não repetição cega.

**Ponto de revogação:** commit autoritativo sob G. Suspensão/troca de credencial/fator/logout que obtenha G primeiro torna inválida qualquer decisão posterior; se ação obtém G primeiro, deve concluir ou abortar antes daquele commit. Isso depende de todos os escritores e efeitos internos seguirem G e do estado estar na mesma autoridade transacional. Não cobre chamada externa, entrega de bytes já materializados nem runtime hostil.

## 6. Entradas hostis, falhas e riscos residuais

- ID/e-mail/CNPJ/cookie CRM, actor/MFA/role/tenant em header e DTOs caller-constructible são não autoritativos. Negar origem inadequada sem enumerar contas/organizações. Credencial CRM não é consultada nem reutilizada.
- Sessão ou desafio capturado, fixation, token replay, duas abas e login iniciado antes do reset exigem casos distintos; prova criptográfica e biblioteca não foram implementadas ou auditadas.
- Sessão no primário adiciona latência/contenda e torna indisponibilidade do núcleo impeditiva. Não fazer fallback para cache positivo. Mesmo duas fontes no mesmo host não são atomicamente uma fonte.
- Migração futura para store distinto invalida a premissa A e exige revisão explícita, não simples troca de adapter.
- Recuperação atômica, MFA anti-replay, cookies/CSRF/XSS, rate limit, notificações, primeiro administrador/gestor e perda de canal continuam P1 R01-06/07/08. Notificação é posterior ao commit por intenção durável; falha de entrega não desfaz silenciosamente reset nem autoriza acesso.
- Cookie candidato host-only, Secure/HttpOnly, SameSite adequado e avaliação de __Host-; sem cookie compartilhado entre produtos. URLs de recuperação usam origem autorizada, nunca Host arbitrário; não registrar/refletir segredo. Perfil final de domínio/path, CSRF de login/mutação/logout, abuse limits e rotação necessita parecer independente, sem parâmetros homologados.
- Reativação/restore/último gestor dependem de Produto e Segurança; nenhuma recuperação de suporte ou autoatribuição é autorizada pelo presente contrato.

## 7. Provas futuras e parecer necessário

Todos **PLANEJADO / NÃO EXECUTADO**. Os casos completos e evidências observáveis estão na matriz: ID-A01..06, REV-01/02/10; R02-N01 (origem), N02 (conta/sessão→efeito), N03 (emissão concorrente), N04 (reset/fator), N15 (falha de autoridade). Exigir evento confiável de revisão/ordem e ausência de efeito/leitura indevida, não só status HTTP.

AppSec/Identidade: revisar contrato de emissão, origem e capabilities, sessões e prova de fator, canais e recuperação. DBA/Backend: validar atomicidade no núcleo, barreira e timeout. Produto: deliberar premissas de disponibilidade, primeiro gestor e MFA. Nenhum profissional está nominalmente designado e nenhum parecer foi obtido. G-ID01..04/07/08/09, G-BB03/06/07 e D02-SEC/P0 permanecem abertos.
