# PORTAL-F1-01-B-B-D05 — Pacote de pré-implementação e fechamento controlado de gates

**Data:** 06/11/2026 · **Estado:** PROPOSTA DOCUMENTAL; não homologada.  
**Precedência:** D01–D04 e [DEC-01](PORTAL-F1-01-B-B-DEC-01-RATIFICACAO-DIRETRIZES-BB01-BB08-2026-11-06.md), [DEC-02](PORTAL-F1-01-B-B-DEC-02-RATIFICACAO-DIRETRIZES-BBD02-01-A-06-A-2026-11-06.md), [DEC-03](PORTAL-F1-01-B-B-DEC-03-RATIFICACAO-BBD03-01-A-06-A-2026-11-06.md), [DEC-04](PORTAL-F1-01-B-B-DEC-04-RATIFICACAO-BBD04-01-A-06-A-2026-11-06.md).  
**Objetivo:** trocar sucessivas decisões genéricas por um **checklist único e verificável** que possibilite preparar, em momento posterior, o menor recorte isolado de código B-B. **D05 não autoriza nem executa esse código**.

## 1. Baseline decidida e não decidida

**Direções já ratificadas apenas documentalmente:** schema PostgreSQL compartilhado com chave de tenant/FKs compostas e RLS; vínculo canônico por par; concessões por papéis locais versionados; barreira transacional de revogação; avaliador interno mínimo sujeito a revisão independente; contexto local por transação; identidade exclusivamente Portal sem privilégios implícitos; auditoria/privacidade por finalidade; testes negativos em laboratório futuro descartável; aprovações de documentação, código, homologação, merge e deploy em gates separados.

**NÃO decidido ou NÃO homologado:** versão exata PostgreSQL; versão/estratégia UUID; ORM/driver/pooler efetivos e suporte; lista final de tabelas, FKs e roles SQL; fonte autenticada de identidade e bootstrap; catálogo real de capacidades; política do último gestor e reingresso; mecanismo físico de revogação/concorrência; função RLS concreta e revisão de privilégios; retenção LGPD; procedimento de backup/restauração; desenho do laboratório, custos e testes; plano de CI e aprovação independente. Nenhum desses itens pode ser preenchido pela suposição de que alternativas A já foram aprovadas.

## 2. Evidências documentais objetivas para fechar os oito gates candidatos

| Gate | Artefato documental a entregar ANTES de pedir autorização de laboratório | Critério mínimo para recomendar fechamento (não realizado) |
|---|---|---|
| **G-BB01 Ambiente** | ADR de versão PG específica com EOL, compatibilidade PHP 8.3/8.4 + `pdo_pgsql`, Laravel, extensão e pooler escolhidos; custo e isolamento | Inventário revisado e aceite Arquitetura/Plataforma |
| **G-BB02 Esquema** | ERD final, dicionário de tipos/UUID, constraints, FK composta, índices, regra de origem de IDs, vigência e rollout reversível | Cada tabela privada identificada, inclusive auditoria e exceções globais; revisão Dados/Segurança |
| **G-BB03 Autoridade** | Contrato de emissor Portal, estados/revisão de usuário, bootstrap próprio, vínculo canônico, organização e recurso; mapa de fronteiras CRM | Fonte verificável para cada entrada da decisão; negação de IDs CRM equivalentes; revisão Identidade/Segurança |
| **G-BB04 Papéis/revogação** | Catálogo versionado de poderes e matriz concedente→alvo, exceções de último gestor/reingresso, algoritmo candidato de barreira/retry | Sem autoelevação nem uso de grants obsoletos; revisão Produto/Segurança/Dados |
| **G-BB05 RLS** | Inventário dos usuários SQL e GRANTs; composição de policies por comando; desenho do avaliador mínimo e grafo de dependências | Ausência demonstrável de bypass genérico, recursão RLS e oracles; revisão independente DBA/Segurança |
| **G-BB06 Pool/concorrência** | Contrato de `SET LOCAL` por transação, afinidade de conexão, rollback/retry, isolamento/locks e limites de réplicas/filas | Matriz completa das falhas e responsabilidades; avaliação Backend/Plataforma/DBA |
| **G-BB07 Auditoria/LGPD** | Finalidades, minimização, retentativas, retenção por evento, acesso e restauração conciliada a revogação | Alçadas e privacidade revisadas; nenhuma retenção inventada |
| **G-BB08 Testes** | Plano rastreável H01–H12, C01–C10 e P01–P12; fixtures sem PII, verificações negativas, execução prevista sob role runtime real | Cada teste possui pré-condição, negação esperada, ausência de efeito/vazamento, coletor de prova e dono |

**Todos os gates permanecem ABERTOS**, inclusive quando o artefato correspondente já possui proposta parcial. A matriz é critério documental futuro, não evidência de execução.

## 3. Menor recorte programável FUTURO — proposta sujeita a autorização separada

**Propósito:** demonstrar isolamento em ambiente descartável, não disponibilizar Portal com usuários reais.

**Candidato mínimo:** dados fictícios para duas empresas, dois usuários locais simulados e vínculos; um recurso privado fictício; modelo de papel e permissão restrito; migrações somente para laboratório autorizado; role runtime sem BYPASSRLS; no mínimo testes de FK tenant, RLS de leituras/escritas, revogação e vazamento de contexto de conexão. Isso é um **plano de escopo para outra decisão**, não autorização para criar arquivos ou tabelas agora.

**Fora do eventual recorte inicial:** login real, MFA, convites, alteração de contrato/preços, cobrança, integração Sites/CRM/Universidade/Performance, dados clientes, domínio público, deploy, migration na VPS, `main`, SSO ou migração do legado. O protótipo não deve receber permissões administrativas ou sessões de produção.

**Regras de parada propostas:** parar se a fonte de identidade/tenant não puder ser simulada com fidelidade controlada; se role runtime conseguir BYPASSRLS/DDL; se conexão vazar contexto; se a revogação não tiver ponto de linearização claro; se não houver testes reproduzíveis ou autorização de ambiente/custos; se o diff extrapolar o recorte autorizado.

## 4. Sequência executável de decisões — SEM executá-la agora

1. **D05 documental:** revisar esta matriz e listar lacunas objetivas, sem aprovar efeitos funcionais.
2. **Pacote de contratos**: submeter ADR PostgreSQL, ERD/FKs, catálogo e alçadas, RLS/roles/função, revogação/pooler, LGPD e plano de QA aos responsáveis com pareceres específicos. A mera apresentação não fecha gates.
3. **Revisão independente antes de código**: responsável de Produto/Dados/Segurança confirma se o conjunto de evidências permite **solicitar** laboratório limitado; registrar gates abertos restantes.
4. **Autorização separada do responsável**: designar branch, SHA, escopo, critérios de aceite, ambiente/custos, dados sintéticos e limites para um *experimento* de implementação. Sem esta ordem: não desenvolver SQL/migrations.
5. **Somente se autorizado depois:** Codex implementa em branch filha isolada, executa testes negativos e fornece logs/PR draft; nenhuma aprovação automática de merge.
6. **Revisão e aprovação de merge distintas**: dev somente mediante autorização posterior; `main`, VPS e produção exigem gates ainda separados.

## 5. Riscos que não podem ser descartados por ter CI verde

- RLS não autentica a pessoa; mesmo IDs Portal/CRM com texto igual exigem emissor Portal confiável.
- Variáveis locais de transação são contexto, não segredo; runtime comprometido ou injeção com capacidade de SQL pode modificar contexto. É necessário delimitar modelo de ameaça e privilégios.
- Isolamento por `organization_id` em aplicação não substitui FKs compostas, política negativa de todas as operações, privileges e bootstrap seguro.
- Cache/snapshot, réplica antiga e jobs podem permitir uso após revogação caso não haja barreira efetiva; testes PHP sintéticos da B-A não demonstram esse ponto.
- Retenção/auditoria/restore têm obrigações próprias e podem reativar privilégios antigos se não houver reconciliação.

## 6. Estado final e próxima decisão de produto

**DEC-01 a DEC-04 estão ratificadas apenas documentalmente; F1-01-B-B-D05 está EM AVALIAÇÃO; os oito G-BB, os gates D02-SEC/D03 físico/P0 e H/C/P estão ABERTOS.**

**Próxima deliberação útil:** autorizar **somente o trabalho documental de fechamento técnico dos oito G-BB**, com elaboração de ADR/ERD/matriz de permissões/contratos e revisões, sem SQL ou testes, em um próximo PR filho em rascunho. Isso deve gerar critérios concretos para avaliar uma autorização de laboratório futura — **não é um pedido de implementação automática**.

**Proibições mantidas:** nenhum SQL executável, migration, banco ligado, acesso de clientes, login/MFA, merge, alteração `main`, VPS ou deploy.
