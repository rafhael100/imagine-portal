# SEC-P02-ADR-01 — Autoridade de identidade humana exclusiva do Portal (proposta técnica)

**Data:** 06/11/2026 · **Status:** ADR CANDIDATO EM REVISÃO; NÃO RATIFICADO NEM HOMOLOGADO.
**Escopo:** G-ID01, G-ID02/03/04 e G-BB03; relativo ao PR #24, RV01-ID-01/02 e F1-01-B-B/PR #19. Esta é uma especificação documental sem código, tabelas, provedor ou rota ativados.

## Decisão candidata precisa

**Escolha técnica recomendada para futura homologação:** backend Laravel 13 do próprio Portal como única autoridade para autenticação humana Portal, com identidade e credenciais armazenadas exclusivamente no domínio Portal e sessões opacas persistidas e revogáveis em store do Portal. A tecnologia física do store, a versão PostgreSQL/driver e sua localização isolada ainda dependem de G-BB01 e revisão de Segurança/DBA. O mesmo servidor físico que hospeda o CRM no futuro não cria identidade compartilhada: nunca reutilizar tabelas, handles de sessão, senhas, domínios de cookie ou emissor CRM.

**Alternativa retida:** provedor de identidade externo dedicado, com issuer e audience exclusivamente Portal, exigiria novo contrato para validação criptográfica, back-channel de revogação, MFA e segregação; NÃO foi selecionado. Nenhum SSO humano com CRM, Universidade ou motor Performance é autorizado.

**Por que:** há scaffold Laravel 13 e domínio Portal com valor de identidade, mas PortalUserId valida somente formato; string coincidente entre sistemas não é prova. Sessões demo SEC-P01 são construíveis e não podem servir como autenticação real.

## Autoridades e fronteiras (contrato de interface, não SQL)

| Informação | Autoridade futura | Nunca tratar como prova |
| --- | --- | --- |
| portal_actor_id | Registro próprio Portal criado no servidor por fluxo verificado; chave opaca | E-mail, CNPJ, username, ID CRM igual, formulário cliente |
| actor_status / identity_revision | Registro de conta Portal atualizado atomicamente | Flag do navegador ou cache sem invalidação provada |
| credential_revision / mfa_revision | Componentes de credencial e MFA do Portal, versão monotônica | Header mfa_verified, cookie não autenticado, código reenviado |
| session_handle | Store Portal revogável; handle imprevisível e cookie restrito ao host | Objeto DemoPortalSession ou token de CRM |
| authenticated_at / mfa_level | Evento validado pelo servidor vinculado à sessão e ao fator realmente satisfeito | Booleano do frontend, JWT arbitrário sem contrato |
| selected_org | Intenção do usuário (entrada não confiável) | Tenant implícito no subdomínio, URL ou payload |
| membership / grants | Fonte canônica server-side tratada no ADR-02 | Sessão conter role/allow definitivo ou vínculo do CRM |
| access_to_performance | Autorização Portal + elegibilidade contratual + autoridade do motor separado | Sessão de usuário Portal usada como credencial do motor Google/Meta |

**Propriedades exigidas:** classe readonly e validação sintática não atribuem proveniência; credencial verificada, fonte de identidade, sessão e organização são operações distintas.

## Máquina candidata de estados

Conta humana:
- pending_verification → eligible somente após verificação por canal autorizado; não criar membership automaticamente.
- eligible → suspended ou deactivated sob alçada e trilha, com invalidação de sessão.
- suspended/deactivated → eligible somente mediante fluxo de reativação separado, com revisão de credenciais/fatores e confirmação de segurança. Sem reingresso automático em organização.
- deleted_or_anonymized não pode autenticar; política de retenção LGPD é gate próprio.

Autenticação:
- unauthenticated → primary_verified → factor_pending (quando fator exigido) → authenticated; etapa primary_verified nunca abre recurso privado.
- authenticated → step_up_required para ação sensível; desafio e janela temporal ainda sujeitos a política aprovada.
- authenticated → expired/revoked por timeout, logout, troca de senha/fator, suspensão, risco ou revogação global.
- A transição de fator e a criação/rotação de sessão devem ser eventos persistidos, atômicos onde necessário, com trilha e anti-replay.

## Protocolo futuro por requisição privada

1. Receber sessão exclusivamente por cookie seguro do Portal (TLS, Secure, HttpOnly, SameSite/CSRF e host restritos sujeitos a homologação).
2. Resolver handle no store Portal e verificar integridade, status, expiração, rotação/revogação e revisão da própria sessão. Falha do store ou handle ausente → negar.
3. Resolver ator no banco/fonte autoritativa Portal; confirmar estado elegível e revisões atuais de credencial/identidade/fator contra a sessão. Falha ou divergência → negar e invalidar conforme risco.
4. Confirmar fator exigido para a operação e autenticidade do evento MFA/step-up, sem aceitar booleano ou relógio de cliente. Regras de MFA e seus tempos ainda por homologar.
5. Tratar organização escolhida como intenção e chamar resolvedor de membership vigente e recurso servidor; nunca derivar tenant/permissions somente de sessão.
6. Autorizar ação granular e efetuar sob barreira de revogação do ADR-02; log sanitizado mínimo, sem segredo.
7. Em falhas de identidade, sessão, revogação, contexto tenant, MFA ou auditoria obrigatória: negar, sem fallback para allow no cache.

**Tempo não homologado:** 30 minutos inatividade, 12 horas absolutos, 5 minutos step-up e 15 minutos reset são somente candidatos; o limiar 600 segundos do demo NÃO é contrato operacional.

## Fluxos de recuperação e bootstrap que ainda exigem gate

- Login por senha com Argon2id e MFA forte é orientação preliminar; implementação e bibliotecas exigem avaliação de threat model, versões, abuso, segredos, dispositivos e recuperação. WebAuthn/passkeys permanece em estudo.
- Reset de senha por token de uso único, verificador seguro e consumo atômico não concede acesso à organização nem remove MFA; eventos de mudança revogam sessões conforme política homologada.
- Perda de segundo fator exige recuperação de alta garantia e alçadas independentes; suporte Imagine não pode desativar MFA por identificar e-mail/CNPJ.
- Bootstrap do primeiro administrador Portal e primeiro gestor de cada organização precisa de convite/aceite com prova de origem e alçada, e controles contra impersonation; não inferir gestor por pagamento, staff ou domínio de e-mail. Escolha de fluxo de bootstrap ainda não homologada.

## Critérios específicos de revisão, prova e falha

| Teste futuro | Negativa ou garantia esperada | Evidência e gate |
| --- | --- | --- |
| ID-A01 | Ator CRM de mesmo ID textual/e-mail não abre sessão Portal | Fonte/issuer e teste de integração; G-ID01/G-BB03 |
| ID-A02 | Cookie antigo, forjado ou pré-MFA não lista organizações | Sessões e eventos reais; G-ID02/03 |
| ID-A03 | Suspensão, troca de credencial/fator e logout global revogam handles antigos | Estado e revisão verificados a cada operação; G-ID03 |
| ID-A04 | Falha do store ou identidade indisponível não libera área privada | Fail closed; teste sob falha; G-ID01/03 |
| ID-A05 | Reset repetido e códigos MFA reutilizados não autenticam | Atomicidade e anti-replay; G-ID04 |
| ID-A06 | Login Portal não gera sessão nem token humano CRM/Performance | Separação de autoridades; G-ID07 |

Nenhum teste acima foi executado. Os testes SEC-P01 validam somente campos sintéticos, sem prova de emissor.

## Pontos fecháveis apenas por parecer e evidência (ainda ABERTOS)

Responsáveis funcionais sugeridos: Identidade/Backend elabora, Segurança e Arquitetura revisam, Produto confirma UX e Privacidade as finalidades. Não há revisor humano nomeado nem parecer emitido neste ADR. Faltam store/driver, threat model MFA, bootstrap, algoritmo de revogação da sessão, política de dispositivo, logout global, e a escolha dos números de timeout. Nenhum gate D02-SEC, G-ID ou G-BB foi fechado.

**Decisão operacional:** nenhuma. Não implementar/login, criar migration/SQL, endpoint, cookie, token real, rota, laboratório, merge em dev/main, VPS ou deploy por este ADR.
