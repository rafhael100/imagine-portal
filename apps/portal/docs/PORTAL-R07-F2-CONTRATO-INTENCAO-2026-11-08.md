# PORTAL-R07-F2 — Autorização vinculada à intenção da operação

**Emissão:** 08/11/2026. **Issue:** https://github.com/rafhael100/imagine-portal/issues/52
**Estado:** CONTRATO CANDIDATO, apenas para revisão. Autorizado o planejamento pelo responsável; não homologado, não executado e sem código operacional.
**Origem técnica:** PR #51 (PoC R07-F1), commit 4f3e6ae5f509fd8e39a681af7dfe67f5764750c8, GitHub Actions 42503351851: 20 verificações sintéticas em três fases; demonstrado residual T3 quando uma borda hostil retém sessão válida de A. PRs #49/#51 permanecem draft, sem merge. Cinco P0 e todos gates continuam abertos.

## 1. Modelo de ameaça e objetivo

O objetivo não é adicionar um pedido genérico de senha/MFA no Portal. O objetivo candidato é dificultar que uma aplicação web Z1 comprometida obtenha uma sessão válida e a utilize para executar uma operação sensível sem que o usuário reconheça e confirme os **detalhes significativos daquela operação** por um canal de confiança independente de Z1. A autoridade Z2 deverá validar identidade, vínculo, intenção, fatores e estado até o efeito.

Premissas e limitações:
- Z0 browser e Z1 web podem mentir sobre ator, organização, permissões, valores, alvo, status MFA ou confirmação. Z1 não recebe chave emissora nem acesso SQL privado.
- Z2 valida sessão Portal, vínculo, resource owner, grants, entitlement, fator, intents, revisões e efeitos; Z3 mantém dados e transações. Z4 contém operação e recuperação privilegiadas segregadas.
- Uma sessão legítima de A não é prova de que A quis mudar conta, exportar dados, conceder poderes ou pagar. Autorização de leitura comum difere de autorização para efeitos sensíveis.
- Se Z1 controla a única interface em que o usuário vê os detalhes, pode induzir aprovação de intenção falsa. WebAuthn com verificação de usuário em navegador comprometido não garante que o usuário tenha visto os dados da transação.
- Um canal externo confiável e a gestão de dispositivo, recuperação, consentimento e fraude ainda não existem; NÃO afirmar contenção de T3 ou resistência absoluta a phishing.

## 2. Catálogo candidato de criticidade (requer decisão do Produto)

| Classe | Exemplos | Política inicial sugerida |
| --- | --- | --- |
| C0 — consulta ordinária | Página própria, estado de serviço | Sessão válida + grants, sem step-up por ação; limites de dados |
| C1 — mutação de baixo impacto | Preferência visual ou rascunho reversível | Sessão + autorização granular, auditoria, idempotência |
| C2 — ação sensível | Exportar dados da empresa; mudar dados de cobrança ou acesso de usuário | Reautenticação/step-up mais intenção imutável vinculada à operação, canais e limites a deliberar |
| C3 — ação crítica | Conceder administrador/último gestor, trocar ou recuperar fator, pagamento irreversível, transferência de dados, reset amplo | Confirmação de detalhes em superfície confiável independente de Z1, prova de fator forte, dupla alçada quando adequada e bloqueio seguro se canal indisponível |

Classificação final, exceções, cobranças e as categorias que exigem dupla alçada são decisões de Produto, Segurança e risco regulatório. Nenhuma ação real é liberada por esta classificação.

## 3. Contrato lógico de uma intenção imutável na Z2

A Z1 apenas solicita uma operação tipada; **Z2 monta e persiste a intenção por consulta a fontes próprias**. Campos lógicos obrigatórios, por classe:
- domínio/versionamento do contrato e identificador opaco único de intenção, propósito específico, tipo de comando e estado;
- ator Portal autenticado e sessão/fator verificados (identificador interno, revisões/epoch), empresa derivada da autoridade, concessões e versão do entitlement;
- alvo e resource owner resolvidos no servidor, transição de estado antigo/novo, valores relevantes em representação canônica (dinheiro em unidade mínima + moeda, nunca ponto flutuante), destinatário, escopo do dado, prazo e limite;
- hash/digest versionado e não ambíguo dos campos significativos, com domain separation e canonicalização determinada em futuro contrato criptográfico; nonce/challenge único associado ao digest, ator, sessão, tenant, propósito, revisão e validade;
- timestamps de criação/validade em autoridade, contagem de tentativas, estado de consumo único, idempotency key por efeito, trilha do canal de confirmação e alçadas.

**Campos materiais não mudam após emissão.** Se destinatário, quantia, alçada, estado do recurso, escopo ou política mudar, invalidar intenção e exigir novo reconhecimento. Referências, nome de tenant, versão do contrato, esquema de canonicalização, hash e reuso de nonce NÃO podem depender da borda.

**Não fixado:** algoritmo final, tamanho e formato do desafio, TTL, classe de fator, UX, limites de retry, recuperação e quais dados podem aparecer em notificação. Precisam de parecer AppSec e aprovação de Produto antes de implementar.

## 4. Máquina de estados candidata

| Estado | Autoridade e transição | Efeito permitido |
| --- | --- | --- |
| drafted | Z2 resolve ator, tenant e recurso, avalia escopo e cria intenção | Nenhum |
| awaiting_independent_confirmation | Z2 emite challenge único e expõe descrição dos campos por canal confiável independente Z1 | Nenhum |
| confirmed_pending_execution | Z2 valida origem/fator/resposta, challenge, assinatura/prova e digest da intenção vigente | Nenhum por si só; não emitir permissão reaproveitável à Z1 |
| executing | Z2 relê sessão, epoch, grants, owner, entitlements e versões sob barreiras aplicáveis; consome confirmação e cria efeito idempotente em unidade protegida | Apenas efeito imutável aprovado |
| committed | Efeito e consumo finalizados, auditoria mínima preservada | Repetição retorna estado; nunca novo efeito |
| denied / cancelled / expired / invalidated | Erro, mudança material, falha/revogação ou tempo esgotado; bloqueio com auditoria | Nenhum |

Se o efeito envolver provedor externo não transacionável (ex.: pagamento), a autorização da operação interna e gravação de outbox/idempotency/fencing precisam de protocolo próprio; rollback de banco não desfaz cobrança externa. Resposta HTTP perdida é estado desconhecido: consultar idempotência e não reenviar comando sem guarda.

## 5. Prova de intenção e fator — opções sujeitas a decisão

**Alternativa técnica candidata:** desafio WebAuthn/passkey verificado exclusivamente por Z2, associado a digest da intenção, sessão e finalidade. Z2 valida RP ID, origem e tipo esperados, challenge único, credencial ligada ao sujeito e flags de presença/verificação exigidos. Os campos do negócio devem ser mostrados de fonte Z2 a usuário por **superfície confiável que a borda hostil não consiga alterar**, especialmente para C3. Um WebAuthn assertion comum comprova uso de credencial para challenge/origin; não comprova por si que montante, destinatário e recurso foram exibidos e entendidos.

**Canal de confirmação candidato:** dispositivo ou aplicativo independente cujo backend de confiança obtenha da Z2 os dados canônicos, com pareamento, alçadas, revogação, proteção contra troca de dispositivo e controle de fraude. A decisão entre aplicativo próprio, provedor independente ou outro mecanismo ainda está aberta. Não há promessa de segurança incondicional contra malware, sequestro de conta, engenharia social ou comprometimento de ambos os dispositivos.

**Outros fatores:** TOTP ou OTP pode dar reautenticação, mas OTP genérico sem campos vinculados à operação não prova intenção específica; SMS e recovery podem aumentar risco de sequestro. Não declarar equivalência criptográfica entre fatores nem selecionar fallback silencioso que rebaixe C3.

**Necessidade futura:** categorias de transação que exigem confirmação na superfície separada; conteúdo da notificação e acessibilidade, privacidade/minimização, mecanismo criptográfico, recuperação de dispositivos, duplo controle, tempo de validade, suporte, custos e usabilidade.

## 6. Contratos anti-TOCTOU e revogação

- Z2 não envia um allow genérico para Z1 executar SQL ou efeito depois. Revalidar e executar dentro da fronteira Z2 até commit com as barreiras P→A→S→O→M→R já especificadas; mapa físico de locks e writers ainda pendente.
- Consumo do desafio deve ocorrer uma vez e ser atômico com decisão de efeito no armazenamento autoritativo ou protegido por fencing verificável. Não reutilizar aprovação depois de edição, logout, MFA reset, suspensão, restore ou grant revogado.
- Idempotência por operação/tenant/sujeito/digest, unicidade e registro de estado; replays não geram efeitos adicionais. Race de duas confirmações deve produzir no máximo um efeito, sem dar falso sucesso.
- Tentativas com outro actor, tenant, sessão, propósito, device, nonce, canal ou digest falham fechadas e não enumeram identidades.
- Expiração e estados não podem ser modificados pela borda; ausência de banco/canal/auditoria obrigatória ou ambiguidade de status exige no-go até reconciliar.
- Nenhum bypass de suporte/CLI/job/n8n sem alçada equivalente; rotas alternativas devem passar pelo mesmo controle; auditoria de segredo, privacidade e retenção ainda a detalhar.

## 7. Impedimentos expressos (NO-GO)

Não há autenticador humano, WebAuthn, MFA, OTP, push, e-mail transacional, canal separado, assinatura, schema de intenção, SQL, API ou endpoint implementados por este contrato. Também NÃO há homologação operacional interna concluída; a governança de revisão passou a ser interna, com IA/Codex e decisão gerencial, conforme GOV-SEC-INT-01 / Issue #58. O PR #51 permanece PoC sintética; um teste verde não comprova segurança da experiência de usuário nem eficácia contra Z1 comprometida com sessão legítima.

Os P0 R01-01, R01-02, R01-03, R01-09 e R01-10, G-ID, G-BB, D02-SEC, D03/RLS e release continuam ABERTOS. A exigência interna de revisão externa foi substituída pelo protocolo interno GOV-SEC-INT-01, sem gastos com fornecedores e sem alegar independência de auditoria. Merge, deploy, custos, VPS, infraestrutura e qualquer serviço real exigem autorização separada.

## 8. Referências técnicas

- OWASP Transaction Authorization Cheat Sheet — detalhes significativos, vínculo de transação, prevenção de replay/TOCTOU e validação na execução: https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html
- OWASP Authentication Cheat Sheet — reautenticação para funcionalidades sensíveis: https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html
- W3C WebAuthn Level 3 — verificação challenge/origin/RP ID/UV como semântica de autenticação, não substituto de display confiável: https://www.w3.org/TR/webauthn-3/

**Próxima decisão:** escolher classes C2/C3, política de confirmações independentes e modelo de risco/recuperação antes de aprovar um laboratório sintético; revisões internas AppSec e DBA com evidências verificáveis e aprovação expressa da Direção necessárias.
