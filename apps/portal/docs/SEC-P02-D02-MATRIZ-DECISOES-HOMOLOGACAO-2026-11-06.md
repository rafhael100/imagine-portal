# SEC-P02-D02 — Matriz de decisões, responsáveis, testes e gates (proposta para ratificação)

**Data:** 06/11/2026 · **Estado:** PROPOSTA, SEM HOMOLOGAÇÃO. Relacionada à Issue #22, PR #23 integrado em dev e PR #19 documental não mesclado.

## 1. Itens de decisão técnica submetidos à Direção

A aprovação anterior foi para AVANÇAR NA DEFINIÇÃO. As alternativas e parâmetros abaixo são apresentados agora pela primeira vez; **não foram implicitamente ratificados pela resposta “aprovado”**. Recomenda-se a alternativa A como diretriz inicial de desenho, sujeita a parecer de Segurança, Arquitetura e Dados. Cada linha requer deliberação específica.

| ID | Alternativa A recomendada | Alternativa B / tradeoff | Dependência ou ressalva |
| --- | --- | --- | --- |
| SEC02-01 Identidade | Emissor e conta exclusivos Portal; verificação de e-mail; nenhuma equivalência por ID/e-mail com CRM | Federação/SSO futuro após contrato próprio | D02-SEC01/03/05, autoridade de identidade |
| SEC02-02 Autenticador | Hash de senha com Argon2id + MFA TOTP, WebAuthn/passkey avaliado; step-up para ações sensíveis | Passkey-first após avaliação de compatibilidade e recuperação | Política de MFA/enrolment e antiabuso, sem código agora |
| SEC02-03 Sessões | Sessões opacas e revogáveis no servidor, cookie isolado Portal, rotação, CSRF | Token stateless mais complexo de revogar | Armazenamento/infra Portal, segurança e revogação |
| SEC02-04 Tempos candidatos | Inatividade 30 min, vida máxima 12 h, step-up 5 min; validar por risco | Outros intervalos por perfil e dispositivo | NÃO homologar números sem ensaio/usabilidade |
| SEC02-05 Recuperação | Reset single-use e expiração candidata 15 min; revogar sessões; MFA perdido = fluxo forte com alçada | Suporte assistido com aprovação dupla e maior atraso | Segurança e LGPD; sem bypass por e-mail |
| SEC02-06 Contexto empresa | Resolver vínculo/grants/ownership no servidor a cada ação, negar sem autoridade | Cache curto apenas com invalidação demonstrada | PR #19, G-BB03/04, D02-SEC02 |
| SEC02-07 Persistência e isolamento | FKs compostas + menor privilégio + RLS como segunda barreira, contexto transacional | Isolamento físico por base em caso de tradeoff futuro | PR #19, G-BB01–08, D03 físico, QA |
| SEC02-08 Auditoria | Eventos mínimos de identidade e autorização, retenção por finalidade aprovada | Eventos adicionais só com finalidade e acesso restrito | Privacidade, Segurança, operações |
| SEC02-09 Publicação | Sem rotas reais até autenticação, tenancy, testes negativos e revisão independente | Preview isolado via teste sem exposição à internet | Gate de segurança, release e go-live distintos |

**Observação:** ratificação documental de uma alternativa não autoriza implantação, migração, SQL, MFA ou login reais, testes com dados privados, acesso ao cliente nem merge. Os tempos e opções de MFA são recomendações, não configurações definitivas.

## 2. Matriz mínima de prova negativa (ensaios futuros, NÃO executados)

| ID | Cenário | Resultado obrigatório | Evidência futura |
| --- | --- | --- | --- |
| AUT-01 | Sessão inexistente, expirada, revogada, cookie inválido ou pre-auth | Negar recursos e não permitir sessão reutilizável | Testes HTTP, logs sanitizados |
| AUT-02 | Fixação de sessão, troca de credenciais, rotação e logout global | Novo identificador, antiga sessão inválida em todas as abas/dispositivos | E2E/concorrência |
| AUT-03 | MFA ausente, vencido, replay, recuperação e step-up exigido | Negar ação; códigos single-use e revogados; impedir bypass | Integração autenticador |
| AUT-04 | Usuário Portal e CRM com MESMO ID textual, e-mail ou CNPJ | Nenhuma sessão/vínculo Portal criada por coincidência | Matriz de origem/issuer |
| AUT-05 | Pessoa com empresas A e B, permissão em A somente | Recurso B negado, inclusive troca manual do ID/URL | Teste tenant e RLS |
| AUT-06 | Membership convidada, suspensa, revogada, obsoleta em cache | Negação imediata após barreira de revogação | Teste concorrência/pool |
| AUT-07 | Role diferente, grant inexistente ou tentativa de autoelevação | Negação e trilha mínima, nenhuma persistência indevida | Backend e banco |
| AUT-08 | Usuário comum tenta billing, gestão de membros ou contrato | Negar sem permissão granular nem depender do menu | Testes de ação real |
| AUT-09 | Recuperação de senha para conta inexistente, replay ou token velho | Resposta não enumerável, token rejeitado, sem MFA implícito | Fluxo de e-mail fictício |
| AUT-10 | Falha em pool/contexto SQL, rollback, réplica, job ou recurso de outro tenant | Sem vazamento nem escrita fora do tenant; falha fechada | PostgreSQL isolado |
| AUT-11 | Requisição forjada sem CSRF, brute force, abuso de login | Mutação negada, limites monitorados sem indisponibilizar legítimos | E2E/segurança |
| AUT-12 | Portal indisponível ou sessão revogada com painel Performance aberto | Sem execução de ação externa/consulta fora de escopo | Contrato serviço-a-serviço |
| AUT-13 | Suspensão/cancelamento do serviço Imagine | Revogar ações Imagine, NÃO presumir pausa de campanhas externas | Integração, reconciliação |
| AUT-14 | Restauração de backup antigo após revogação | Não reativar privilégios antigos; reconciliar versões/eventos | Teste de restore |
| AUT-15 | Menu oculto mas chamada direta à API | Backend igualmente nega acesso e não retorna conteúdo | Teste de rotas/autorizações |

Nenhum destes testes é considerado aprovado pelo CI de 103 testes de SEC-P01: aquela suíte valida apenas contratos sintéticos sem fontes confiáveis.

## 3. Bloqueios por área com responsáveis propostos

| Frente | Responsável técnico proposto | Condição para avaliar homologação | Estado |
| --- | --- | --- | --- |
| D02-SEC01 login/MFA/sessão/recuperação | Identidade + Segurança | Especificação e threat model, parâmetros e fluxos de reset aprovados | ABERTO |
| D02-SEC02 membership e gestão | Backend + Produto + Segurança | Fonte canônica, permissões, último gestor, delegações, barreira de revogação | ABERTO |
| D02-SEC03–06 fronteiras e experiência | Arquitetura + Produto + Segurança | Portal/CRM independentes; serviço a serviço; UI e direitos por produto | ABERTO |
| G-BB01–08, D03/RLS | DBA + Dados + Segurança + Plataforma | Documento D05/PR #19 revisto, ambiente e testes negativos autorizados | ABERTO |
| P0-DOC e contratos integrações | Direção + responsáveis dos serviços | Avaliação de compatibilidade e gates transversais | ABERTO |
| Navegação Performance AI | Produto + Backend + QA | Identidade Portal real validada, ownership, elegibilidade e serviço seguros | ABERTO |
| Publicação comercial e produção | Direção + Operação + Segurança | CI/E2E, observabilidade, revisão independente e aprovação de release | ABERTO |

Responsáveis são funções sugeridas, não designações nominais. A Direção de Produto poderá decidir as alçadas; o profissional de Segurança/DBA com revisão independente permanece uma necessidade técnica, não um gate automaticamente satisfeito pela Direção.

## 4. Sequência recomendada sem saltos

1. Direção delibera SEC02-01 a SEC02-09 como diretrizes candidatas e escolhe parâmetros provisórios, sem declarar gates fechados.
2. Elaborar contrato executável **somente em papel** de identidade emissora, proteção de sessão, membership atual e política de recuperação com threat model.
3. Conciliar PR #19 (D05 e G-BB01–08), inclusive versão PG, RLS, revogação e laboratório, sem merge implícito.
4. Solicitar revisão Segurança/Arquitetura/Dados de forma rastreável, registrar evidências ainda faltantes.
5. Solicitar autorização específica e limitada para laboratório isolado e código por slice, com fixtures sintéticas e rollback. Não habilitar login de clientes nessa fase.
6. Só após testes e homologação independentes, pedir decisões separadas de merge em dev, main e deploy; só depois ativar rota de login e painel em release controlado.

**Diretriz de segurança:** o uso do painel demonstrativo P02-D depende de uma fonte verdadeira de identidade e autorização que ainda NÃO existe. Não promover o demo como portal funcional.
