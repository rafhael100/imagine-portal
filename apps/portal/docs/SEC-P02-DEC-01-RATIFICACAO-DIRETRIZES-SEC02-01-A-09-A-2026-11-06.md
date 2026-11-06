# SEC-P02-DEC-01 — Ratificação das diretrizes preliminares SEC02-01 a SEC02-09

**Data:** 06/11/2026 (America/Sao_Paulo)
**Origem:** manifestação expressa do responsável: "aprovado", em resposta à recomendação de ratificar **as nove diretrizes SEC02-01 a SEC02-09 como orientações documentais preliminares, preservando todos os gates**.
**Estado:** RATIFICADO EXCLUSIVAMENTE COMO DIRETRIZ DOCUMENTAL PRELIMINAR — NÃO HOMOLOGADO.

## 1. Alcance da aprovação

A ratificação alcança as alternativas A já apresentadas na matriz SEC-P02-D02, com as ressalvas daquele documento. Não torna parâmetros numéricos definitivos, não aprova fluxos operacionais, não executa provas e não concede autorização para login, MFA, persistência, RLS, rotas, deploy, merge ou produção.

| ID | Diretriz A preliminar ratificada | O que permanece em aberto |
| --- | --- | --- |
| SEC02-01 | Emissor e conta de usuário exclusivos do Portal, sem equivalência humana por ID ou e-mail com o CRM | Prova do emissor, cadastro, verificação, estados, suspensão, identidade de serviço |
| SEC02-02 | Senha com verificador robusto e MFA TOTP candidato; avaliar WebAuthn/passkeys; autenticação recente em ações sensíveis | Enrolment, WebAuthn, recuperação do fator, resistência a phishing, proteção ao abuso |
| SEC02-03 | Sessões opacas revogáveis no backend do Portal, cookies restritos, rotação e CSRF | Store e modelo de revogação durável, dispositivos, sessão e trilhas |
| SEC02-04 | Limites de inatividade, máximo absoluto e frescor de step-up definidos por política server-side | Números 30 min/12 h/5 min são apenas candidatos; UX e Segurança precisam decidir |
| SEC02-05 | Recuperação por token de uso único, sem enumeração, revogação e fluxo de alta garantia para perda de MFA | Prazo candidato 15 min, transições atômicas, aprovação de suporte e autoridade |
| SEC02-06 | Resolução server-side atual do vínculo, permissões e titularidade em toda ação | Fonte canônica, revogação concorrente, roles, delegações, último gestor |
| SEC02-07 | FKs compostas, menor privilégio e RLS como barreira secundária, com escopo transacional | Modelo físico, versão PG, role, bypass e prova de isolamento no PR #19 |
| SEC02-08 | Auditoria mínima com finalidade, acesso e retenção controlada | Dicionário final, prazos LGPD, restore, evidências de revisão |
| SEC02-09 | Rotas privadas somente após fonte confiável de identidade/tenant, testes negativos e revisão independente | Autorização de implementação, liberação de rotas, homologação e publicação |

**Importante:** a alternativa A de SEC02-04 aceita o PRINCÍPIO de prazo limitado, não homologa os intervalos concretos. A alternativa A de SEC02-02 não seleciona automaticamente um provedor de MFA, biblioteca ou arquitetura WebAuthn.

## 2. Fronteiras e dependências preservadas

- Portal e CRM mantêm identidade humana, credenciais, sessões e bancos separados. O Portal não faz SSO implícito para o CRM ou para Performance AI.
- As decisões do PR #19 (F1-01-B-B/D01–D05) continuam documentação candidata, com DEC-01 a DEC-04 somente preliminares; nenhum esquema físico/RLS foi aprovado para implementação por esta ratificação.
- Permanecem ABERTOS: D02-SEC01–06; D03 físico/RLS; G-BB01–08; P0-DOC01–14 e demais gates de segurança, dados, arquitetura, qualidade e produção.
- Permanecem SEM AUTORIZAÇÃO: migrations, SQL executável, banco real ou laboratório, Eloquent persistente, autenticação/login real, cookies, sessão real, MFA, API/rotas/controllers, conta de cliente, integração CRM/Performance, PSP, VPS, deploy, main e merge de PRs.
- A equipe de Segurança/Arquitetura/Dados precisa revisar e homologar contratos, ameaças e evidências. A ratificação de Produto não substitui essa revisão.

## 3. Próxima entrega em avaliação

Autoriza-se a preparação DOCUMENTAL de contratos mais executáveis em papel: D03 (proveniência da identidade, sessão e autorização por organização) e D04 (recuperação, MFA e revogação), incluindo matrizes de estados, negações, concorrência e critérios de prova. Os documentos propostos não encerram seus próprios gates e são submetidos separadamente à Direção.

A eventual implementação funcional deve ser objeto de AUTORIZAÇÃO EXPRESSA FUTURA com escopo técnico, base Git, ambiente isolado, evidências negativas e condições de parada. A decisão de merge na branch dev e as decisões de main/VPS/produção permanecem independentes.
