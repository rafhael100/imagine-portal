# SEC-P02-D04 — Contratos candidatos de recuperação, MFA e revogação de acesso

**Data:** 06/11/2026
**Estado:** PROPOSTA DOCUMENTAL PARA AVALIAÇÃO — NÃO HOMOLOGADA.
**Precedência:** SEC-P02-DEC-01 ratifica as diretrizes SEC02-01..09 como preliminares. Nenhum código operacional, MFA real ou recuperação está autorizado.

## 1. Objetivo e fronteiras

Recuperação de conta e de segundo fator devem preservar a identidade exclusiva do Portal, sem usar coincidências de e-mail/CNPJ, autenticação CRM, pagamentos ou atuação de suporte como substitutos de prova. A solicitação de recuperação jamais concede automaticamente sessão, acesso a tenant, poderes contratuais ou MFA. O sistema deve permanecer fechado quando o canal de entrega, as fontes de identidade ou o armazenamento de tokens estiverem indisponíveis.

## 2. Máquina de estados candidata para recuperação de senha

| Estado | Evento de entrada | Saídas e condições |
| --- | --- | --- |
| reset_requested | Requisição com identificador normalizado | Resposta externa genérica igual para conta existente/inexistente; limite antiabuso |
| token_issued | Conta elegível + canal de comunicação controlado | Criar segredo aleatório de uso único; persistir só verificador; propósito e expiração vinculados à conta |
| token_verified | Apresentação do token sob TLS | Validar assinatura/verificador, versão, propósito, validade e ausência de consumo, sem criar sessão |
| password_replaced | Troca atômica após verificação | Marcar consumo irreversível, trocar verificador da senha, invalidar tokens concorrentes pertinentes |
| sessions_revoked | Mudança segura de senha | Revogar sessões da conta conforme contrato homologado e notificar titular por canal apropriado |
| reset_expired | Prazo encerrado ou token revogado | Negar replay/uso atrasado; não abrir sessão nem restabelecer fator |
| manual_review | Perda de canal ou suspeita de tomada de conta | Verificação de alta garantia sob alçada, sem bypass automático |

**Contrato de concorrência:** duas tentativas de usar o mesmo token não podem concluir simultaneamente; consumo único precisa de operação atômica na fonte servidora autorizada. Se não puder confirmar a atomicidade, negar. O reset do fator e o reset da senha são processos distintos; em especial, trocar senha não remove o MFA existente.

**Prazo:** 15 minutos é valor CANDIDATO, não política vigente; Segurança e Produto devem avaliar ameaça e usabilidade. Não inserir tempo arbitrário no código sem aprovação própria.

## 3. Registro, uso e perda de segundo fator

- O cadastro de novo fator deve requerer sessão Portal autenticada, autorização server-side e step-up apropriado, com prova de posse do novo fator antes de ativá-lo.
- A remoção ou substituição deve invalidar material antigo, sessões afetadas e códigos de recuperação pertinentes, com confirmação adicional e trilha.
- TOTP: derivação segura do segredo, proteção em repouso, limite de tentativas, janela de clock e anti-replay a definir; nunca registrar seed nem código em logs.
- WebAuthn/passkeys: avaliar autenticação phishing-resistant, políticas de credenciais e attestation/registro, recuperação de dispositivo e sincronização, sem inferir que qualquer biblioteca esteja homologada.
- Códigos de recuperação: alta entropia, uso único, verificador seguro, revogação e exibição controlada uma vez. Recuperação de fator perdido requer fluxo robusto, revisado e auditado.
- Suporte e staff da Imagine **não** podem ativar conta ou remover MFA por mera identificação verbal, e-mail, CNPJ ou posse de contrato. Exceção de contingência, se proposta, exige alçada independente, evidências, dupla verificação e notificação.

## 4. Eventos que devem negar ou revogar (candidatos)

| Evento | Consequência pretendida | Critério de prova futura |
| --- | --- | --- |
| Logout na sessão atual | Revogar sessão atual, remover capacidade de reuso | Cookie antigo e URL protegida negados |
| Logout global do usuário | Invalidar sessões pertinentes em outros dispositivos | Todos os handles anteriores negados |
| Senha alterada | Revogar sessões e tokens de reset definidos em política | Nenhuma sessão pré-troca conserva acesso |
| Fator removido/substituído | Invalidar provas de autenticação anterior e sessões conforme risco | Challenge antigo rejeitado |
| Usuário suspenso | Negar autenticação e atos privados independentemente do tenant | Sessões e filas incapazes de usar privilégios |
| Membership da empresa revogada | Negar acesso e operações só daquela organização, sem herdar permissões de outra | Multiempresa, cache, concorrência |
| Empresa suspensa/desativada | Negar dados e operações da organização, com exceções legais sob alçada futura | Sem listagem privada, sem writes |
| Função/papel rebaixado | Reavaliar nível de autorização imediatamente | Step-up ou cache antigo não substituem grant |
| Token de recuperação expirado/consumido | Negar sempre | Testes atômicos e replay |
| Restauração de banco antigo | Não reativar credencial, sessão, membership ou grant revogado | Processo de reconciliação rastreável |

A revogação de serviço da Imagine não deve prometer interrupção automática de campanhas externas Google/Meta sem contrato operacional homologado. O usuário Portal não possui sessão CRM compartilhada.

## 5. Evidências negativas mínimas para homologação (futuras)

| Teste | Condição adversarial | Negação exigida |
| --- | --- | --- |
| REC-01 | E-mail inexistente versus existente | Não enumerar conta por resposta, tempo ou metadado acessível |
| REC-02 | Token inválido, expirado, repetido ou de outro propósito | Sem alteração de senha/fator |
| REC-03 | Uso simultâneo do mesmo token em duas solicitações | Exatamente um consumo possível; nenhum estado parcial |
| REC-04 | Reset executado com sessão antiga em outra aba | Sessão antiga revogada por política, sem login implícito |
| REC-05 | Reset de senha com MFA perdido | MFA não removido nem bypassado |
| REC-06 | Usuário da empresa A tenta recuperar fator de ator da B | Negação sem vazamento de existência |
| REC-07 | Solicitação massiva, força bruta, reenvio | Rate limit e auditoria mínimos sem bloqueio arbitrário de contas legítimas |
| REC-08 | Staff acessa suporte e tenta remoção de MFA sem alçada | Negação; ação crítica gera trilha sob privacidade |
| REC-09 | Falha do e-mail, store ou relógio | Falha fechada; segredo não exposto |
| REC-10 | Backup/restauração anterior a revogação de fator | Fator antigo continua inválido após reconciliação |

Esses testes NÃO foram executados nem demonstrados pelo CI do PR #23. Exigem ambiente isolado e versões concretas de segurança, banco e sessão.

## 6. Pontos de deliberação

**REC-D01** — método principal: senha Argon2id com MFA forte e avaliação WebAuthn (A recomendada); passkeys-first após UX/risco (B).
**REC-D02** — recuperação de senha: token aleatório single-use com verificador e consumo atômico (A recomendada); fluxo assistido de alta garantia sem token de e-mail (B).
**REC-D03** — perda de MFA: processo de alta garantia com alçadas e trilha, sem bypass automático por suporte (A recomendada); recuperação automatizada após validações externas homologadas (B).
**REC-D04** — revogação: registro canônico server-side com invalidação entre abas/dispositivos e barreira de concorrência (A recomendada); esquema diferente somente com prova equivalente (B).
**REC-D05** — regras temporais: definir parâmetros separados para sessão, step-up, token, enrolment e rate limit após ameaça/UX; 15min reset e 30min/12h/5min sessão apenas como candidatos (A recomendada); parametrização por risco com matriz específica (B).

**Nada de REC-D01..05 foi aprovado pela ratificação SEC02-01..09.** A próxima decisão de Produto pode ratificar diretrizes sem fechar D02-SEC01/02, G-BB ou autorizar código. Necessária revisão técnica independente de ameaça, MFA, recuperação, identidade, privacidade, concorrência e operação.
