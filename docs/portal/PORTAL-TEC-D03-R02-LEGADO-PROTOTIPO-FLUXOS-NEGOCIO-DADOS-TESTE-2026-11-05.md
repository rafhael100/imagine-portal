# PORTAL-TEC-D03-R02 — Escopo real de reaproveitamento do protótipo anterior

**Data:** 05/11/2026  
**Status:** **DIRETRIZ EXPRESSAMENTE APROVADA PELO RESPONSÁVEL**, posterior à ratificação D03-MIG-B.
**Manifestação:** "Sobre o sistema anterior que estavamos desenvolvendo, ele ainda estva ame fase de desenvolvimento, Voce precisa aproveitar mais so a esturtura do negocio e as ideias de fluxo. O restante e somente dados de teste".
**Natureza:** orientação de planejamento; **não executa nem autoriza exclusão, limpeza, migração, produção ou testes**.

## 1. Interpretação normativa da nova informação

1. O sistema antigo no repositório `rafhael100/imagine-portal` era **projeto em desenvolvimento / protótipo**, não solução operacional definitiva. A versão antiga **não** deve ditar a arquitetura, o modelo físico, o checkout, os contratos definitivos, a política de autenticação ou as integrações financeiras do novo Portal.
2. **O principal insumo a reaproveitar é a visão de negócio e as ideias dos fluxos:** jornada do cliente; aquisição e contratação; catálogo/planos; orçamento e aceite; pedido/briefing; produção/revisão/entrega; atendimento; gestão administrativa; financeiro e acompanhamento; observando as aprovações mais recentes.
3. O responsável informa que **os demais dados do sistema antigo são dados de teste**. Por isso, **não planejar migração de cadastros, pedidos, cobranças, sessões, tokens, contratos e conteúdo do SQLite por padrão** e não reconstruir telas apenas para preservar compatibilidade técnica com o legado.
4. **D03-MIG-B continua aprovada**, com interpretação mais precisa: backend Laravel/PostgreSQL **novo**, desenho limpo, **reaproveitamento prioritariamente conceitual**, de processos/requisitos e experiência útil observada; **código/HTML/testes existentes são referências opcionais**, só reutilizar por justificativa técnica e alinhamento à documentação vigente, sem obrigação de portá-los.
5. **Segurança para descarte futuro:** a afirmação do responsável é o pressuposto do planejamento. Antes de qualquer exclusão irreversível, substituição de banco, limpeza de uploads ou descarte de backups, validar em inventário mínimo a inexistência de dados reais, documentos contratuais ou históricos sujeitos a retenção. Isso **não** reintroduz migração operacional como requisito automático.
6. Histórico do GitHub, documentação de decisões e outras evidências válidas não devem ser apagadas sob a justificativa de serem protótipo. Preservar rastreabilidade, precedência das ratificações e eventuais compromissos legítimos preexistentes.

## 2. Inventário de aproveitamento conceitual recomendado (não é implementação)

| Ideia/fluxo legado | Manter como referência de negócio | Não transportar automaticamente |
|---|---|---|
| Site institucional, apresentação e catálogo | Navegação e jornada comercial | HTML/JS antigo como frontend definitivo |
| Cadastro do cliente e minha conta | Empresas/usuários/contatos e permissões por organização | Usuários de teste, hashes/sessões e esquema SQLite |
| Pedido e checkout | Estados de compra, proposta, aceite e pagamentos | Precificação antiga, D0/D30/D60 e gateway ativo |
| Briefing e materiais | Solicitação guiada, uploads privados e validação | Arquivos e dados fictícios do protótipo |
| Produção, revisão e aprovação | Etapas, prévias, ajustes e consentimento expresso | Estado automático sem prova de publicação/entrega |
| Financeiro/fiscal | Visão de mensalidades, documentos e histórico | Faturas de teste, tokens e PSP antes de homologação |
| Suporte, tarefas e BI | Atendimento e processos internos úteis | Regras de equipe/permissões antigas não auditadas |

## 3. Consequência para F1-01 e decisões ainda abertas

- **F1-01 futura:** partir de documentação e novo projeto Laravel + PostgreSQL, com análise conceitual do protótipo; não adaptar literalmente o monólito Node/SQLite.
- **Não há obrigação de migrar dados de teste.** Novos testes futuros poderão utilizar **fixtures totalmente fictícias** criadas para a nova aplicação, quando houver autorização de programação/testes.
- **D04-FASE-A** poderá usar as ideias de catálogo e fluxo de checkout, nunca valores antigos por padrão.
- Os gates de segurança/multiempresa, fronteiras Portal×CRM/Sites, validação de fontes, aprovação F1-01 e autorização de código continuam abertos.
- **Nenhum descarte foi autorizado/executado.** `main`, VPS, banco, backups e ambientes existentes permanecem inalterados.

## 4. Precedência documental

Esta decisão **refina e prevalece no escopo de reaproveitamento** sobre a leitura mais ampla da [D03-MIG-B aprovada](PORTAL-TEC-D03-APROVACAO-D03-MIG-B-MODELO-LOGICO-2026-11-05.md) e sua [proposta histórica D03 v0.1](PORTAL-TEC-D03-MODELO-DADOS-LEGADO-MIGRACAO-PROPOSTA-v0.1-2026-11-05.md). Não altera D01 Laravel/PostgreSQL, D02 logins independentes ou regras comerciais posteriores.

**Status:** diretriz documentada; implementação e operações ainda bloqueadas.
