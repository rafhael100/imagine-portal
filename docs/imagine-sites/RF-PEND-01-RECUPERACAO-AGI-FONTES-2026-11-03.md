# RF-PEND-01 — Recuperação documental das séries AGI e fontes históricas

**Estado:** investigação DOCUMENTAL PARCIAL; não declarar RF-PEND-01 concluída.  
**Escopo desta rodada:** AGI-RF01-R01 a R06; AGI-P09 a P18; identificação de fontes históricas de Produção Inteligente.  
**Autorização:** exclusivamente criar/atualizar documentação aprovada no GitHub; nenhuma programação, testes, deploy, contratação ou homologação.  
**AGI-11-RB01:** rollback automático permanece **DESABILITADO**.  
**Data das aprovações referidas:** 03/11/2026. Este registro não equivale a transcrição integral dos diálogos.

## 1. Fonte histórica adicional localizada no GitHub

| Dado | Evidência |
|---|---|
| Repositório | `rafhael100/imagine-portal` |
| Branch | `docs/producao-inteligente-v01` |
| Documento | [docs/PRODUCAO-INTELIGENTE.md](https://github.com/rafhael100/imagine-portal/blob/docs/producao-inteligente-v01/docs/PRODUCAO-INTELIGENTE.md) |
| Blob SHA consultado | `e8f6aeb4b47360650e187727f6b1f9331ea8abf6` |
| Extensão aproximada | 616 linhas |
| Estado declarado no próprio arquivo | Planejamento v0.2 — **rascunho para revisão**, com **AD-001 aprovada** |
| Identificadores AGI-RF01-R01..R06 | **Não localizados nesse documento** |
| Identificadores AGI-P09..P18 | **Não localizados nesse documento** |
| Relação com o planejamento atual | Fonte histórica com controles de autonomia e publicação; não é documentação canônica das séries AGI posteriores |

### Conteúdo da AD-001 explicitamente identificado

O texto histórico do bloco AD-001 estabelece preferência por **alta automação da produção de sites**, com agentes realizando as etapas rotineiras e equipe Imagine tratando exceções. A publicação depende de **aceite expresso/autenticado do cliente à versão exata** e controles determinísticos de segurança. A IA não assume autoridade para gastos, cobranças, emissão fiscal, contratações nem concessão de permissões ou publicações fora dos gates aprovados.

**Cuidado de precedência:** outras partes do documento são propostas e hipóteses de 01/11/2026. Devem ser confrontadas com as aprovações posteriores de Cloudflare, CRM, IN-127, AGI e políticas comerciais. Não importar literalmente valores, topologias ou gates antigos para a versão vigente.

## 2. Branches encontradas

| Repositório | Branch | Evidência examinada | Cobertura |
|---|---|---|---|
| imagine-portal | `main` | Árvore completa consultada; arquivos na pasta `docs/imagine-sites/` identificados | Não contém, nos nomes examinados, documentos integrais separados R01–R06 e P09–P18 |
| imagine-portal | `docs/producao-inteligente-v01` | `README.md`, `docs/PROJETO.md`, `docs/PRODUCAO-INTELIGENTE.md` recuperados | AD-001 localizada; séries AGI posteriores não localizadas no documento |
| imagine-crm | `main` | Árvore completa consultada; IN-124..149, IN-168 e fontes CRM/PORTAL identificadas | Contém dependências e referências de integração, mas não a série AGI integral por nome de arquivo |
| imagine-crm | `docs/integracao-site-crm-basico` | `docs/decisoes.md` recuperado, blob `0e05c23551b46da4b9b93d73028ab55d549b3374` | Fonte histórica; conferência integral pendente |
| imagine-crm | `docs/especificacao-inicial-2026-10-31` | `docs/decisoes.md` recuperado, blob `e87097727256c61adae98818aba5a0f69ff75f2f` | Fonte histórica de versão diferente; conferência integral pendente |

As verificações de nomes de arquivo, termos e documentos acima **não provam inexistência** dos requisitos nos diálogos anteriores ou em arquivos não examinados.

## 3. Cobertura das séries AGI solicitadas

| Série | Evidência de aprovação recuperada | Texto individual integral recuperado | Status correto |
|---|---|---|---|
| AGI-RF01-R01 | Não foi recuperada transcrição suficiente nesta rodada | Não | **PENDENTE DE FONTE** |
| AGI-RF01-R02 | Não foi recuperada transcrição suficiente nesta rodada | Não | **PENDENTE DE FONTE** |
| AGI-RF01-R03 | Não foi recuperada transcrição suficiente nesta rodada | Não | **PENDENTE DE FONTE** |
| AGI-RF01-R04 | Há referência à aprovação documental do grupo R04.01–.48 | Não | **APROVAÇÃO DE BLOCO REFERIDA; CORPO A RECUPERAR** |
| AGI-RF01-R05 | Há referência à aprovação documental do grupo R05.01–.48 | Não | **APROVAÇÃO DE BLOCO REFERIDA; CORPO A RECUPERAR** |
| AGI-RF01-R06 | Há referência à aprovação documental do grupo R06.01–.48 | Não | **APROVAÇÃO DE BLOCO REFERIDA; CORPO A RECUPERAR** |
| AGI-RF01-R07 | Aprovação documental de R07.01–.48 e ratificações IN127-R01..R06 | Itens/títulos em documento resumido; redação canônica integral não validada | **REGISTRADA PARCIALMENTE** |
| AGI-P09 a AGI-P17 | Referência consolidada a blocos aprovados em diálogo | Não, para cada série | **APROVAÇÕES REFERIDAS; TEXTO A RECUPERAR** |
| AGI-P18 | Aprovação documental de P18.01–.48 referida | Não | **APROVAÇÃO REFERIDA; CORPO A RECUPERAR** |

"Não recuperado" significa **não há texto integral suficientemente verificado nas fontes alcançadas nesta rodada**, não que o usuário não tenha aprovado o item.

O arquivo [AGI-RF01-R07 — índice de requisitos](AGI-RF01-R07-requisitos-aprovados-2026-11-03.md) deve ser usado **como catálogo temático provisório**, não como reprodução comprovada dos 48 parágrafos canônicos.

## 4. Fontes que NÃO devem ser usadas indevidamente

- Arquivos **UNI** pertencem ao planejamento da Universidade Imagine e **não** são prova textual das séries AGI de Sites.
- `docs/PROJETO.md` e `docs/OFERTA-ONEPAGE.md` na branch histórica refletem implementação/oferta anteriores, com tecnologias e parcelas que podem divergir de decisões posteriores.
- IN-124..149 do CRM cobrem integração de leads e controles, mas **não substituem** os requisitos individuais de agentes e produção.
- A existência de uma aprovação genérica de bloco não comprova a redação de cada requisito integrante.

## 5. Ações documentais seguintes

1. Localizar os textos aprovados em conversas originais ou fontes exportadas que contenham a redação de cada item, preservando identificação, sequência, data e aprovação.
2. Confrontar AGI-RF01-R01..R06 e AGI-P09..P18 com os controles da AD-001 e IN-095..114, sem preencher lacunas por inferência.
3. Elaborar uma matriz por ID com status: `ORIGINAL_CONFERIDO`, `APROVACAO_REFERIDA_SEM_TEXTO`, `PROPOSTA_HISTORICA` ou `NAO_LOCALIZADO`.
4. Somente então criar os documentos canônicos **integrais** das séries AGI e propor correções materiais à aprovação do responsável.
5. Atualizar RF-PEND-01 e parecer de prontidão, mantendo P0 abertos onde a redação ou autoridade não esteja confirmada.

## 6. Situação do fechamento

- **Concluído nesta rodada:** identificação de uma branch histórica relevante; localização do texto v0.2; identificação do escopo aprovado AD-001; comparação de presença dos identificadores AGI nos documentos consultados; quadro de cobertura e lacunas.
- **Não concluído:** recuperação textual integral R01..R06/P09..P18; validação de cada ratificação contra seu documento original; verificação exaustiva de todas as fontes e branches; fechamento RF-PEND-01; homologação RF-PEND-04.
- **Nenhuma execução:** código, testes, servidores, prestadores, deploy, migração, aprovação de despesas ou ativação automática de rollback.

**Este registro é evidência documental parcial e não autoriza início de desenvolvimento.**
