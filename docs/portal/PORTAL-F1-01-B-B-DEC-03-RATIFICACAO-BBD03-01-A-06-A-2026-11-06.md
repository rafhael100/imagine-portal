# PORTAL-F1-01-B-B-DEC-03 — Ratificação das alternativas A de BBD03-01 a BBD03-06

**Data:** 06/11/2026 (America/Sao_Paulo)  
**Manifestação:** o responsável respondeu **“sim”** à pergunta: “Você aprova as alternativas A de BBD03-01 a BBD03-06, somente como diretrizes documentais preliminares, para avançarmos ao próximo detalhamento de segurança e homologação?”  
**Estado da decisão:** **DIRETRIZES DOCUMENTAIS PRELIMINARES RATIFICADAS**; não constitui aprovação de esquema físico, código funcional, homologação de segurança ou autorização de merge.

## 1. Decisões ratificadas

Ratificam-se unicamente as alternativas A descritas em [F1-01-B-B-D03](PORTAL-F1-01-B-B-D03-CONTRATOS-FISICOS-IDENTIDADE-RLS-REVOGACAO-PROPOSTA-2026-11-06.md), preservando as anteriores [DEC-01](PORTAL-F1-01-B-B-DEC-01-RATIFICACAO-DIRETRIZES-BB01-BB08-2026-11-06.md) e [DEC-02](PORTAL-F1-01-B-B-DEC-02-RATIFICACAO-DIRETRIZES-BBD02-01-A-06-A-2026-11-06.md):

| ID | Alternativa A aprovada como orientação | Contratos e limites ainda abertos |
|---|---|---|
| **BBD03-01** | Fixar uma versão principal PostgreSQL suportada com matriz explícita de compatibilidade PHP, driver, pooler e CI, e política de upgrade | Nenhum número de versão foi aprovado; evidência de ambiente e manutenção é pendente |
| **BBD03-02** | UUIDs locais gerados no servidor, com escopo de organização obrigatório em FKs privadas e vigências temporais consistentes | Variante UUID, nomes, DDL, constraints, indexes e migrações não homologados |
| **BBD03-03** | Catálogo e matriz distintos para leitura, concessão, manutenção de políticas, auditoria e contratos, sem elevação implícita | Capacidades exatas, alçadas, trilhas e papéis iniciais pendentes |
| **BBD03-04** | Reingresso bloqueado até fluxo autorizado; proteger o último administrador e definir recuperação segregada | Procedimento de recuperação, dois responsáveis, evidência e alçada pendentes |
| **BBD03-05** | RLS obrigatória para dados privados com role runtime mínima e revisão independente de funções, views e consultas indiretas | Bootstrap de identidade, composição de policies, função interna e provas negativas pendentes |
| **BBD03-06** | Ensaios exclusivamente em laboratório isolado com fixtures fictícias e papéis de teste, antes de qualquer rollout | H01–H12, C01–C10, P01–P12 ainda não executados; laboratório e execução não autorizados |

**Não confundir orientação com implementação:** aprovação das alternativas A não escolhe versão PostgreSQL, tipo exato do UUID, SQL, migrações, função com privilégio ou política RLS concreta.

## 2. Bloqueios preservados

- **ABERTOS:** D02-SEC01..06 (identidade local, sessões/MFA, recuperação, separação CRM e abuso), D03 físico/RLS, P0-DOC01..14 e P0-DOC06/G-04, contratos operacionais, políticas LGPD, revisão independente e homologação de isolamento.
- **SEM AUTORIZAÇÃO:** programação de B-B, SQL executável, DDL, migrations, Eloquent persistente, RLS operacional, autenticação/login, conexão ao PostgreSQL, dados reais, acesso CRM, alterações na VPS, deploy ou merge em qualquer branch.
- **Git:** PR #19 permanece em rascunho/documental, sem merge; PR #11 e PR #10 continuam sob governança própria; `main` intocada.
- **Testes:** H01–H12, C01–C10 e P01–P12 são cenários futuros, não evidências de homologação. O CI Node pode passar sem validar PostgreSQL.

## 3. Próximo avanço documental autorizado

Preparar **F1-01-B-B-D04 — Matriz de homologação, evidências mínimas e gates de segurança antes da programação**, especificando uma sequência verificável de decisões, fronteiras de confiança, laboratório sintético futuro e critérios que bloqueiam um recorte programável. Submeter alternativas D04 para avaliação separada; não inferir sua aprovação nesta DEC-03.

**Situação B-B funcional:** BLOQUEADA / NÃO AUTORIZADA.
