# SEC-P02-DEC-02 — Ratificação documental preliminar IDN-D01 a D05 e REC-D01 a D05

**Data:** 06/11/2026 (America/Sao_Paulo)
**Decisão da Direção:** “aprovado”, em resposta à proposta expressa de ratificar as alternativas A de **IDN-D01 a IDN-D05** (SEC-P02-D03) e **REC-D01 a REC-D05** (SEC-P02-D04), exclusivamente como **diretrizes documentais preliminares**.
**Status:** RATIFICADO PARA PLANEJAMENTO DOCUMENTAL; NÃO HOMOLOGADO; SEM AUTORIZAÇÃO DE IMPLEMENTAÇÃO FUNCIONAL.

## 1. Conteúdo exato da ratificação

A aprovação alcança as alternativas A tal como descritas nos documentos SEC-P02-D03 e SEC-P02-D04. As alternativas B/C permanecem comparativos técnicos passíveis de reavaliação e não foram aprovadas.

| ID | Diretriz A ratificada documentalmente | Dependência/limite |
| --- | --- | --- |
| IDN-D01 | Identidade exclusiva do Portal por autoridade local e estados versionados; preferir módulo próprio Laravel na futura análise | Ainda falta emissor verificável, modelo físico, contas e operações |
| IDN-D02 | Sessão opaca revogável no servidor e rotacionada nos eventos relevantes | Store, prazos, concorrência, cookie, CSRF e revisão pendentes |
| IDN-D03 | Membership/grants provenientes da fonte canônica, com rechecagem em ação sensível | Contrato D02-SEC02, RLS, revogação e provas negativas pendentes |
| IDN-D04 | Login humano Portal separado de CRM e sem sessão automática Performance AI | Identidade de serviço a serviço e integração Performance ainda não homologadas |
| IDN-D05 | Impedir reativação e elevação automática da conta/último gestor enquanto recuperação e alçadas não forem homologadas | Recuperação de administrador e contingência permanecem bloqueadas |
| REC-D01 | Senha Argon2id com MFA forte candidato, avaliando WebAuthn/passkeys | Biblioteca, fator padrão, inscrição, phishing e UX não decididos |
| REC-D02 | Reset por token aleatório single-use, verificador e consumo atômico | TTL, emissor, armazenamento, tentativas e canal de entrega a homologar |
| REC-D03 | Perda de MFA por recuperação de alta garantia, com alçadas e trilha, sem bypass automático do suporte | Autoridade e processo de exceção não homologados |
| REC-D04 | Revogação em fonte canônica server-side entre abas/dispositivos e sob concorrência | Modelo transacional, store, invalidação de cache e jobs pendentes |
| REC-D05 | Definir tempos de sessão, step-up, recuperação e rate limit por política versionada após ameaça/UX | 30 min/12 h/5 min/15 min NÃO homologados e NÃO implementados |

## 2. Escopo da decisão

- **SEC-P02-DEC-01** continua em vigor: alternativas A de SEC02-01 a SEC02-09 são orientações documentais preliminares.
- Nenhuma decisão desta DEC-02 fecha D02-SEC01 a D02-SEC06, D03 físico/RLS, G-BB01 a G-BB08, P0-DOC01–14, nem qualquer bloqueio de segurança, dados, privacidade e infraestrutura.
- PR #19 (F1-01-B-B) continua documental em rascunho; nenhuma aprovação de SQL, migrations, tenant real, ensaio PostgreSQL ou RLS executável ocorreu.
- Permanece **proibido** nesta decisão: código funcional de login, registro, token, MFA, sessão, reset, middleware, rotas de clientes, API, banco, SQL, migrations, integração CRM/Performance, VPS, deploy, merge do PR #24 ou alterações na main.
- A assinatura de Direção de Produto em alternativa documental não substitui prova de fonte de identidade, revisão independente de Segurança/DBA, testes concorrentes ou autorização de ambiente.
- **O painel P02-D não foi publicado aos clientes:** a view Blade continua não roteada, sem login seguro.

## 3. Próximo trabalho documental

A DEC-02 permite aprofundar **somente em papel** o modelo de ameaça, a matriz de autoridades, os contratos de falha fechada, os cenários E2E e as evidências necessárias para avaliar autorização limitada de laboratório. O documento candidato SEC-P02-D05 é uma proposta nova, NÃO aprovada automaticamente por esta ratificação.

**Próximos portões separados:** (1) aprovação dos contratos técnicos e ameaça; (2) aceite de responsáveis de Segurança/Arquitetura/Dados; (3) autorização expressa de programação de recorte de laboratório; (4) testes negativos e revisão independente; (5) autorização própria de merge na dev; (6) homologação de main/produção. Sem ordem expressa para qualquer fase seguinte, nenhum trabalho operacional é liberado.
