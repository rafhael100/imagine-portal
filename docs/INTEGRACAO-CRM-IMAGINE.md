# Imagine — Integração Onepage/Landing Page × CRM Imagine

> **Planejamento v0.2 · 2026-11-01 · IN-001 e IN-002 aprovadas; implementação e detalhes do benefício ainda pendentes**  
> Repositório de origem: `rafhael100/imagine-portal`. Documento em branch de planejamento. **Sem implementação, contratação de serviço, cobrança ou alteração em produção.**

## 1. Decisão IN-001 — integração entre produtos (APROVADA)

Quando um cliente contratar um **Site Onepage** ou, futuramente, uma **Landing Page** da Imagine, deverá contar com acesso à assinatura do **Plano Básico do CRM Imagine**, com integração automática do formulário de captação de contatos para a organização correta no CRM.

O site também continuará com botão para WhatsApp. **Formulário de leads e CRM são serviços diferentes de hospedagem de e-mail profissional**, que segue **excluída** da oferta conforme HD-001.

**IN-004 aprovada em 2026-11-01:** benefício CRM Básico inclui **Agenda e Agendamentos**, com detalhes, integrações e cotas a definir. **IN-008 aprovada em 2026-11-01:** no Básico, cada organização CRM terá **1 profissional com agenda própria**. **IN-009 aprovada em 2026-11-01:** até **10 serviços ativos** para esse profissional, por empresa. **IN-011 aprovada em 2026-11-01:** expediente flexível (dias, vários períodos e intervalos), bloqueios manuais de folgas/férias/feriados e agendamento manual excepcional fora do expediente mediante aviso e confirmação de usuário autorizado, sempre sem sobreposição. **IN-012 aprovada em 2026-11-01:** agendamentos criados **manualmente** dentro do CRM Básico não possuem teto comercial mensal rígido, sujeitos a uso justo e controles antiabuso; não autoriza página pública de reserva. Quantidade de usuários, canais de lembretes e regras técnicas de uso justo ainda pendentes. [Agenda Manual CRM Básico](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md). **IN-007 aprovada em 2026-11-01:** no Básico, compromissos são lançados e gerenciados **manualmente dentro do CRM**; **não oferecer botão/link público para o visitante agendar automaticamente no site**. Autoagendamento público pelo Onepage/Landing Page ou página do CRM fica exclusivo aos planos **pagos** do CRM, após verificação de direitos e autorização de configuração. O formulário padrão pode criar lead, **não cria reserva automaticamente**. **IN-005 aprovada em 2026-11-01:** modelos prontos com até **3 automações comerciais ativas simultaneamente por empresa CRM**. **IN-006 (2026-11-01):** biblioteca central será documentada integralmente antes da revisão das quantidades por plano; algumas automações mais avançadas deverão ficar disponíveis apenas nos planos pagos. Os números atuais continuam referências até nova aprovação expressa. [Catálogo central em desenvolvimento](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/10-biblioteca-automacoes-e-criterios-de-planos.md). O catálogo concreto e a agenda pública ainda dependem de aprovação; não significa liberar o construtor genérico de automações. Ver [agenda e automações do CRM Básico](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/09-revisao-agenda-e-automacoes-basico.md).

**IN-003 aprovada em 2026-11-01:** o CRM Básico vinculado ao site será uma **versão enxuta exclusiva**, diferente do plano Essencial do CRM; conjunto de recursos, limites e quotas ainda precisam de aprovação na documentação de produto do CRM. Proposta em [CRM Imagine — Plano Básico cortesia](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/08-plano-basico-cortesia.md).

**Decisão IN-002 aprovada em 2026-11-01:** o CRM Básico será disponibilizado como **benefício gratuito vinculado à contratação ativa de Onepage ou, futuramente, Landing Page**; não cria segunda mensalidade no CRM. O site continua com cobrança própria pelo Portal Imagine. **Quando o cliente contratar um plano superior diretamente no CRM**, a assinatura paga e o faturamento desse plano ocorrerão no CRM, mantendo a assinatura do site no Portal. A gratuidade não é um plano gratuito público aberto a qualquer usuário. **Ainda pendentes:** matriz de recursos/cotas do Básico, correspondência com planos Essencial/Profissional/Empresarial, preço da Landing Page, estados exatos da elegibilidade em cancelamento/inadimplência, e implementação/homologação. O catálogo implementado no portal atualmente possui o serviço `site` (Onepage), **não um produto Landing Page independente**. O CRM Imagine está em planejamento; não marcar como funcionalidade já ativa no checkout antes de integração testada/homologada e condições comerciais aprovadas.

### Decisão IN-022 — Administração da Empresa e Segurança no CRM Básico (APROVADA)

**Aprovação em 2026-11-01:** o Administrador da Empresa único (IN-020/IN-021) pode consultar/atualizar dados cadastrais comuns da própria organização; alterar CNPJ/identificador fiscal somente por validação específica; alterar senha/e-mail e gerenciar MFA sob verificação de identidade (**MFA obrigatório**); consultar/encerrar sessões de seus outros dispositivos; ver plano e limites; consultar e contratar upgrade CRM com consentimento financeiro explícito. Não pode adicionar segundo usuário, acessar outras empresas ou usar superadmin Imagine. **O Portal não deverá alterar CNPJ ou credenciais do CRM por atalho, nem contratar upgrade CRM ou mudar a fatura do site automaticamente.** Identidade fiscal e recuperação requerem fluxos seguros a especificar. [Matriz de permissões Básico — IN-022](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

### Decisão IN-032 — Utilização, avisos internos e bloqueio de novos anexos (APROVADA)

**Aprovação em 2026-11-01:** nos quatro planos CRM, o administrador autenticado visualiza o **espaço de arquivos/anexos utilizado e disponível** (quotas IN-031: Básico 1 GB, Essencial 5 GB, Profissional 20 GB, Empresarial 50 GB). Emitir **avisos internos no CRM quando o consumo alcançar 80% e 95%** da franquia. **Ao atingir 100%, impedir somente novos uploads de anexos**, mantendo arquivos previamente aceitos acessíveis e **sem bloquear recebimento de leads pelo formulário Imagine, cadastro de contatos e demais dados estruturados**. A tentativa de upload que excederia o saldo disponível não pode ser confirmada. Não criar e-mails/WhatsApp/push externos de aviso, cobrança automática, aumento compulsório de plano ou acesso do Portal Imagine aos arquivos privados dos clientes CRM. Dados estruturados ficam fora da quota nos quatro planos, segundo IN-031. Detalhes da política de notificações/reaviso, capacidade adicional, temporários e retenção serão definidos separadamente. [Matriz IN-032](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

### Decisão IN-031 — Franquias de arquivos/anexos dos quatro planos CRM (APROVADA)

**Aprovação em 2026-11-01**, após revisão do cenário Amplo e confirmação de exclusão dos dados estruturados da franquia: **Básico Cortesia 1 GB; Essencial 5 GB; Profissional 20 GB; Empresarial 50 GB**, **por empresa**, compartilhados e contabilizando **somente arquivos e anexos efetivamente armazenados**. **Dados estruturados — leads, contatos, textos, observações, históricos, agendamentos e oportunidades — não consomem franquia em nenhum dos planos**, estendendo a separação de IN-029 aos pagos. Quotas anteriores pagas 1/5/10 GB são históricas e foram substituídas. IN-030 mantém iguais os tipos de anexos entre planos e o limite geral anterior de **5 MB por arquivo** continua, até revisão distinta. Franquia atingida não permite descartar arquivos já aceitos nem bloquear leads legítimos sem anexos; avisos, pacotes extras, políticas temporárias de importação/exportação, backup e retenção seguem pendentes. **Não alterar cobranças ou assinaturas do Portal Imagine nem conceder acesso aos documentos privados dos clientes CRM.** [Matriz IN-031](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

### Decisão IN-030 — Tipos de anexos iguais entre CRM Básico e planos pagos (APROVADA)

**Aprovação em 2026-11-01:** o **CRM Básico Cortesia** aceitará **os mesmos tipos de anexos em leads/contatos** disponíveis aos planos Essencial, Profissional e Empresarial (imagens, PDFs e documentos comuns da lista de formatos permitidos do CRM), com verificação de tipo, segurança/antivírus, autorização, isolamento entre organizações e histórico/auditoria. **Não criar diferenciação comercial de tipos de arquivo entre planos.** O **limite geral de upload já aprovado é 5 MB por arquivo** para todos os planos, sem alteração por IN-030. **A IN-031 aprovou posteriormente a revisão:** Básico 1 GB, Essencial 5 GB, Profissional 20 GB, Empresarial 50 GB, somente arquivos/anexos e sem contabilizar dados estruturados em qualquer plano; valores pagos anteriores foram substituídos. **IN-029 preservada:** dados estruturados do Básico não consomem franquia comercial fixa de anexos. Importação/exportação temporária e políticas de retenção continuam decisões separadas. O Portal Imagine não passa a ter acesso aos anexos dos clientes CRM. [Matriz IN-030](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

### Decisão IN-029 — Dados estruturados separados da franquia comercial de arquivos (APROVADA)

**Aprovação em 2026-11-01:** o **CRM Básico Cortesia** mantém **dados estruturados de leads, contatos e seus históricos fora de qualquer franquia comercial fixa de armazenamento de arquivos/anexos**, preservando a regra IN-024 de **quantidade de registros sem teto comercial rígido** e recebimento durável de leads dos formulários do Onepage/Landing Page. O Portal Imagine não deverá bloquear ou rejeitar silenciosamente uma captação confirmada porque a futura franquia de anexos estiver esgotada; controles técnicos de uso justo e segurança continuam necessários. **IN-030 aprovou os mesmos tipos de anexos e IN-031 aprovou posteriormente franquias 1/5/20/50 GB apenas para arquivos/anexos nos quatro planos; dados estruturados excluídos em todos eles** (limite geral de 5 MB/arquivo permanece). Uploads temporários de importação CSV/XLSX (IN-025), arquivos de exportação (IN-026), descarte, retenção e recuperação terão políticas separadas. **Quotas dos planos CRM pagos permanecem independentes** e o Portal não ganha acesso aos dados/arquivos do CRM. [Matriz IN-029 do CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

### Decisão IN-028 — Mesclagem manual de leads e contatos duplicados no CRM Básico (APROVADA)

**Aprovação em 2026-11-01:** o **Administrador da Empresa** do CRM Básico Cortesia poderá **mesclar manualmente registros de leads/contatos duplicados**, escolhendo o **registro principal**, comparando e resolvendo **conflitos campo a campo** e efetuando **confirmação explícita** antes de executar. Preservar vínculos com **agendamentos, oportunidades, origens de formulários/site, observações, históricos e auditoria**, obedecendo à LGPD, identidade e isolamento multiempresa. **A mesclagem automática continua proibida**, inclusive na recepção do formulário Imagine, importação CSV/XLSX e alerta de duplicidade. O **Portal Imagine não possui autorização para mesclar registros de clientes em nome do administrador do CRM** por prestar o serviço do site. **Não equiparar mesclagem a exclusão definitiva** da IN-027. **Pendente:** gestão do cadastro secundário, consentimentos divergentes, possibilidade de desfazer, transação e retenção. [Matriz IN-028 no CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

### Decisão IN-027 — Exclusão definitiva controlada de Leads e Contatos no CRM Básico (APROVADA)

**Aprovação em 2026-11-01:** o único Administrador da Empresa no **CRM Básico Cortesia** poderá **excluir definitivamente registros elegíveis de leads e contatos mediante confirmação explícita**. Quando houver **agendamentos, oportunidades, históricos vinculados ou obrigações legais/contratuais de guarda**, será obrigatório **fluxo protegido**, sem exclusão simples em cascata ou perda indevida de integridade, respeitando **LGPD, direitos dos titulares e auditoria**. O Portal Imagine não está autorizado a excluir dados de CRM de seus clientes finais por consequência do cancelamento do site, nem a executar essa ação como administrador do Portal. Não é o arquivamento (IN-023), que preserva o registro. **Pendentes:** critérios exatos de elegibilidade, anonimização, tratamento de vínculos, retenção e eventual recuperação. **A IN-028 aprovou posteriormente mesclagem manual completa e controlada no Básico**; a automática permanece proibida. Detalhes técnicos de mesclagem ainda pendentes. [Matriz IN-027 no CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

### Decisão IN-026 — Exportação CSV e XLSX completa ou filtrada de Leads e Contatos (APROVADA)

**Aprovação em 2026-11-01:** no **CRM Básico Cortesia**, o Administrador da Empresa poderá **exportar leads e contatos em CSV e XLSX**, selecionando **todos os registros autorizados da sua organização ou somente os filtrados**. A exportação exige autenticação, autorização por tenant, **proteção dos arquivos e links de download, auditoria** e **controles técnicos proporcionais**, sem discriminar acesso à portabilidade legal por ausência de upgrade. O Portal Imagine não obtém automaticamente acesso à base de leads do cliente por prestar o serviço do site; continua operando apenas integração do formulário e contrato Onepage. **Pendente:** expiração de downloads, escopo de campos sensíveis, retenção de arquivos temporários, tamanho do lote e tratamento de exportações de grande volume. Não conceder relatórios avançados, API pública ou exportação de outros módulos. A IN-024 (quantidade sem teto comercial rígido) permanece. [Matriz IN-026 no CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

### Decisão IN-025 — Importação básica de leads e contatos por CSV e XLSX (APROVADA)

**Aprovação em 2026-11-01:** dentro do **CRM Básico Cortesia**, o Administrador da Empresa poderá **importar leads e contatos via arquivos CSV e XLSX**, com **mapeamento de colunas, prévia dos registros, validação e alertas de possíveis duplicidades**, sem mesclagem automática. O sistema deverá preservar dados pré-existentes e separar as organizações; executar importação apenas por ação autorizada do administrador e registrar resultado fiel, sem sobrescrever ou apagar informações silenciosamente. A operação é recurso próprio do CRM, não autoriza upload por agentes do Portal, API pública de criação, bots ou integração externa de importação. **Pendentes:** tamanho/linhas por arquivo, regras de linhas inválidas, tratamento de duplicados e relatório final, retenção temporária dos arquivos. IN-024 continua sem teto comercial rígido no total de leads/contatos. **IN-026 aprovou posteriormente exportação CSV/XLSX; IN-027 aprovou exclusão definitiva controlada; IN-028 aprovou mesclagem manual**, sem mesclagem automática. Detalhes técnicos, armazenamento e retenção continuam pendentes. [Matriz IN-025](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

### Decisão IN-024 — Quantidade de leads e contatos sem teto comercial no CRM Básico (APROVADA)

**Aprovação em 2026-11-01:** o CRM Básico Cortesia **não limita comercialmente a quantidade de leads/contatos** que ingressam por **formulários Onepage/Landing Page Imagine** ou são **cadastrados manualmente** pelo administrador da empresa. Aplicar uso justo, antispam, antifraude e controles técnicos proporcionais, **sem quota escondida ou upgrade forçado por quantidade legítima**. **Não confirmar recebimento sem persistência durável** nem descartar silenciosamente leads aceitos. Bloqueios de segurança não são quota comercial (IN-010). **Permanecem pendentes:** capacidade de armazenamento, retenção, importação em massa, exportação comercial e exclusão; a decisão não libera API pública de criação de leads ou outros recursos pagos. [Matriz IN-024](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

### Decisão IN-023 — Operações de Leads e Contatos no CRM Básico (APROVADA)

**Aprovação em 2026-11-01:** o Administrador da Empresa do Básico pode visualizar leads/contatos da empresa, cadastrar/editar manualmente, pesquisar/filtrar por informações básicas, registrar observações e histórico manual, identificar possíveis duplicidades com **alerta sem mesclagem automática** e **arquivar sem perder dados, origem, vínculos ou histórico**. A captação de leads pelo formulário do Onepage/Landing Page (IN-001) permanece integrada, com identificação da organização e origem; nunca confirmar envio sem persistir e depois descartar silenciosamente. Formulário não gera reserva automaticamente.

**Não aprovado pela IN-023:** quantidade de leads/contatos, importação em lote, exportação comercial, mesclagem, exclusão definitiva, quotas de armazenamento/retenção, API pública, scoring avançado ou concessão de funcionalidades pagas. Atender acesso/portabilidade/eliminação de dados pela LGPD sem exigir upgrade comercial indevido. Detalhar em decisões próprias. [Matriz por módulo IN-023](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).



### Decisão IN-020 — 1 usuário autenticado por empresa no CRM Básico (APROVADA)

**Aprovação em 2026-11-01:** cada organização CRM Básico Cortesia admite até **1 usuário autenticado para acesso ao CRM**, distinto de **1 profissional agendável** (IN-008). **IN-021 aprovada:** o único usuário com login terá perfil **Administrador da Empresa**, **MFA obrigatório** e direitos apenas sobre funcionalidades efetivamente incluídas no Básico, sem privilégios de superadministrador SaaS; poderá ou não coincidir com o profissional agendável. A matriz de ações por módulo ainda precisa de definição. Vários sites contratados por uma organização não geram usuários adicionais. O Portal não cria contas extras pelo vínculo de novos sites nem calcula quota por site; o CRM valida a quota por organização. Tentativa de segundo usuário comunica **1/1** e oferece upgrade/assento adicional elegível no CRM sem contratação automática (IN-010). [Especificação CRM — IN-020](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

### Decisão IN-013 — Avisos da agenda apenas dentro do CRM Básico (APROVADA)

O CRM Básico Cortesia disponibiliza **notificações e lembretes operacionais internos na própria interface do CRM**, **sem consumir as vagas de automações comerciais ativas**. Não enviar e-mail automático ao cliente agendado nem mensagem pela API oficial do WhatsApp como cortesia do Onepage/Landing Page. Esses canais externos são exclusivos de **planos pagos CRM ou adicionais elegíveis**, mediante contratação, autorização e custos aplicáveis. Essa decisão não afeta avisos de pagamento/produção do **Portal Imagine**, que são processos separados dos lembretes da agenda CRM. **IN-014 aprovada:** lembretes internos do CRM Básico em 24h e 1h antes, ajustáveis/desativáveis individualmente e sem consumir automações. **IN-015 aprovada:** central de notificações do CRM Básico é persistente, com estados lido/não lido e consulta aos lembretes vencidos enquanto o usuário estava ausente; **IN-016 aprovada:** abrir detalhes de notificação marca como lida e usuário pode restaurar não lida; ver a lista não muda estado. **IN-017 aprovada:** filtros por estado (Todas/Não lidas/Lidas), tipo de evento e período, combináveis, com notificações mais recentes primeiro. **IN-018 (2026-11-01) aprovada:** avisos internos da agenda CRM Básico têm retenção de **90 dias**, sem alterar a retenção do histórico de agendamentos, dados do cliente, registros do Portal Imagine ou trilhas de auditoria. **IN-019 aprovada (2026-11-01):** arquivar e restaurar notificações lidas e não lidas antes de 90 dias, sem alterar leitura; todas deixam a central aos 90 dias, sem tocar em agendamentos ou auditoria. **Pendentes:** descarte técnico, pesquisa textual e ações em lote. Continua sendo aviso interno, sem push, WhatsApp ou e-mail automático. [CRM — Agenda e IN-013](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

### Decisão IN-010 — Avisos contextuais de recursos e quotas do CRM (APROVADA)

No **CRM Imagine**, quando uma organização tentar recurso indisponível no plano ou exceder quota efetiva, mostrar explicação do limite e oferecer upgrade/extra elegível quando resolver o problema. Isso inclui, para o Básico cortesia, a tentativa de autoagendamento público, segundo profissional, 11º serviço ativo ou automação além das vagas atuais, sujeitas à futura revisão. **Oferecer opções de gerenciamento gratuito da quota quando existirem** (inativar serviço, pausar automação). Comprar plano pago ocorre **somente dentro do CRM**, com autorização e consentimento financeiros próprios; a assinatura do Onepage permanece no **Portal Imagine** inalterada. Não confundir erro de permissão, segurança ou infraestrutura com necessidade de upgrade. O Portal não deve inventar quotas ou encaminhar cobrança do CRM sem consultar direitos efetivos pelo backend. [Especificação CRM IN-010](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/12-limites-e-upgrade-contextual.md).

## 2. Objetivos

- Cliente conclui contratação do site sem duplicar cadastro para usar CRM.
- Backend da Imagine cria ou vincula **organização CRM** de forma idempotente e segura quando contratação elegível é confirmada.
- O site publicado traz formulário associado a um `site_id` e `form_id` do pedido e à organização CRM; a associação é feita pelo **backend**, não por dados arbitrários enviados pelo visitante.
- Cada envio validado produz um registro de entrada de lead associado à organização, à página/campanha e à data/hora, com controles antifraude e rastreabilidade.
- Portal e CRM mantêm responsabilidades de negócio separadas, sem exigir que o código do site se conecte diretamente ao banco de dados ou à API administrativa do CRM.
- O cliente acompanha leads no CRM, com possibilidade futura de funil, automações e serviços adicionais conforme o plano contratado.

## 3. Relação entre dois produtos e estado de cada projeto

**Imagine Portal:** Node.js, banco SQLite e pagamentos por Asaas em homologação; pedidos/contratos e produção do Onepage já têm modelos implementados. A emissão fiscal e cobrança real ainda não estão ativadas.

**CRM Imagine:** produto SaaS multiempresa independente, em fase de **planejamento antes da programação**. Diretrizes anteriores: Laravel, PostgreSQL, API REST versionada, autenticação, filas/Redis, isolamento por organização; usuário pode participar de várias organizações. Os módulos/planos e a integração comercial ainda precisam ser documentados e aprovados no planejamento próprio do CRM. O repositório `rafhael100/imagine-crm` contém atualmente apenas um README inicial; não presumir que endpoints, tabelas ou plano básico já estejam implementados.

### Responsabilidades

| Sistema | Responsabilidade |
| --- | --- |
| Portal Imagine | Contratos do site, cliente, pagamento, produção por IA, domínio, hospedagem, suporte; evento de elegibilidade e vínculo do pacote CRM |
| CRM Imagine | Organização/empresa, usuários/permissões, plano/entitlement de CRM, lead, origem, oportunidades/funil e regras comerciais específicas |
| Integração de produtos | Provisionamento, vínculo, reconciliador de estados, IDs externos/idempotência, auditoria e mapeamento de contratos |
| Serviço de captura de leads | Recepção segura do formulário público, validação/antispam, persistência durável, entrega à API CRM, tentativas e conciliação |

## 4. Jornada proposta — contratação do site até receber leads

1. **Pedido e contrato:** cliente contrata Onepage (ou futura Landing Page). O checkout mostra claramente os termos do pacote CRM **quando forem definidos/aprovados**, sem criar cobrança CRM separada não autorizada.
2. **Pagamento:** somente confirmação conciliada do pagamento elegível gera evento `crm.entitlement.provision_requested`, idempotente por pedido + regra comercial vigente.
3. **Localizar identidade:** serviço busca vínculo pré-existente do titular e sua(s) organização(ões) autorizada(s). **Não** cria organização duplicada para empresa já vinculada. Se houver várias, exigir escolha/autorização verificada de qual será ligada ao site.
4. **Criar/vincular organização:** CRM provisiona tenant/organização e entitlement ao Plano Básico com referência externa do pedido; caso já existente, vincula a organização correta e atualiza direitos segundo regras aprovadas.
5. **Acesso:** cliente entra por convite seguro/fluxo de vinculação a conta existente. Não compartilhar senhas nem transportar sessão do portal para CRM. SSO futuro é decisão separada.
6. **Construção do site:** agente de IA recebe esquema e componentes oficiais do formulário; o build contém apenas `form_public_id` e campos permitidos; **nenhum segredo ou token da API do CRM é exposto no HTML/JS**.
7. **Publicação:** backend registra mapeamento `order_id/site_id/form_id -> crm_organization_id` e ativa o formulário apenas após domínio, versão e ambiente verificados.
8. **Envio visitante:** browser envia formulário a endpoint público de ingestão Imagine; backend verifica origem e configurações do formulário, valida payload, aplica antispam e grava aceitação durável.
9. **Entrega de lead:** worker autenticado entrega via API privada versionada do CRM, com chave idempotente e identificador externo imutável. CRM responde ID de lead criado ou evento já processado; falhas transitórias usam retry com backoff e alerta.
10. **Operação:** cliente vê lead, origem e dados autorizados no CRM. Imagine vê status técnico (aceito/pendente/entregue/erro), sem exposição indiscriminada dos dados pessoais.

## 5. Arquitetura e segurança de formulário

```text
Site estático publicado (Cloudflare Pages / VPS / cPanel)
  formulário sem secrets
      ↓ HTTPS
Ingestão de Leads Imagine (endpoint público com proteção antiabuso)
      ↓ validação por form_public_id → organização/site no backend
Fila persistente / Outbox + status por evento
      ↓ canal servidor-servidor com autenticação, escopo e idempotência
CRM Imagine API v1 (isolamento por empresa, plano, origem)
      ↓
Lead pertencente à organização correta
```

- O backend escolhe o tenant com base no registro do formulário em banco: **nunca confiar no `crm_organization_id`, `company_id` ou `plan_id` enviados pelo navegador**.
- Cada site pode ter múltiplos formulários no futuro, com ID público aleatório, configuração por versão e trilha de auditoria.
- Aplicar limites de tamanho/campos, validação de telefone/e-mail, antifraude (rate limit, honeypot e desafio adaptativo), logs mínimos e restrição de conteúdo. CORS/Referer sozinhos não constituem autenticação.
- Consentimento e aviso de privacidade quando aplicável, finalidade da coleta, retenção, direitos dos titulares e acordo de tratamento de dados entre Imagine e a empresa cliente. Evitar capturar dados sensíveis desnecessários.
- Proteção contra conteúdo malicioso, HTML/script no campo livre, uso de formulários para spam, enumeração de IDs, replay e injeção em ferramentas de IA.
- Disponibilidade: gravar na fila/outbox **antes** de responder sucesso. Se CRM estiver indisponível, manter pendente e tentar novamente, com alertas operacionais; falha de persistência não deve afirmar recebimento.
- Deduplicação técnica do **evento** com `submission_id` e idempotency key; deduplicar leads por e-mail/telefone é uma **regra comercial separada** e não deve apagar contatos recorrentes ou sobrescrever estágios automaticamente.
- Dados publicitários como `utm_source`, `utm_medium`, `utm_campaign`, landing page e URL de origem podem ser incluídos com validação e regras de privacidade; não prometer rastreamento sem consentimento/implementação.

## 6. Entidades e contratos propostos (não implementados)

- `site_crm_links`: `order_id`, `site_id`, `crm_org_id`, `crm_subscription_entitlement_id`, estado, origem do vínculo, datas e auditoria.
- `lead_forms`: `form_public_id`, `site_id`, `order_id`, `config_version`, campos habilitados, destino vinculado no servidor, rate-limits e estado ativo/suspenso.
- `lead_submissions`: `submission_id`, `form_public_id`, `received_at`, payload validado/protegido, versão, status de entrega, tentativas, ID de lead CRM e rastreamento da origem.
- `crm_provisioning_jobs`: `order_id`, motivo, idempotency key, estado, resultado, tentativas, erro sanitizado.
- `contract_entitlements` (ou equivalente no CRM): vínculo contratual entre assinatura do site e direito de usar o Plano Básico; preços, ciclo e limites aguardam aprovação.

Contratos de API futuros: `POST /integrations/v1/organizations/provision`, `POST /integrations/v1/entitlements/sync`, `POST /integrations/v1/leads/ingest` são **nomes ilustrativos** a revisar na documentação de APIs do CRM, não endpoints implementados nem aprovados. Proteger com service identities, tokens restritos por finalidade e rotação de secrets. Separar endpoint público de captura do endpoint servidor-servidor do CRM.

## 7. Interações do cliente e do agente de IA

- Cliente terá um acesso explícito **“Abrir meu CRM”** no portal quando o provisionamento estiver confirmado; durante atraso, mostrar `Ativando seu CRM` com status verdadeiro.
- Se já for usuário do CRM, o fluxo vincula a conta/organização autorizada, sem criar e-mails/senhas duplicados. O CRM suporta conceitualmente várias organizações por usuário; não escolher uma empresa arbitrariamente.
- Agente da Imagine pode orientar o cliente sobre formulário e acesso, mas não ler leads confidenciais nem dados CRM de outras organizações por padrão.
- No painel administrativo, acompanhar `formulário ativo`, `último envio`, `fila pendente`, `erros`, `organização vinculada` e `plano autorizado`; acesso a dados de leads deve seguir papéis e necessidade.
- Botão WhatsApp permanece no site. Clique em WhatsApp **não cria lead automaticamente** salvo decisão futura sobre consentimento/rastreamento.
- Se o cliente alterar domínio, editar conteúdo ou mudar de plano, revalidar o vínculo e a configuração do formulário antes do deploy.

## 8. Política financeira e vínculo entre assinaturas — IN-002 APROVADA (com pendências operacionais)

### 8.1 Regras comerciais aprovadas

- A contratação de **Onepage** (ou da futura Landing Page) concede acesso ao **CRM Básico sem mensalidade adicional**, condicionado ao vínculo contratual com o site; não criar cobrança recorrente do Básico no CRM.
- **Portal Imagine** continua como fonte de cobrança e contrato do site. Para o Onepage atual: implantação R$ 990 em D0/D30/D60, assinatura mensal do site R$ 79,90 a partir de D90, independentemente da publicação. Esses valores e ciclos não são alterados pela integração.
- Se o cliente fizer **upgrade para plano CRM pago**, ele contrata no **CRM Imagine**, sob termos e preço do plano escolhido, e passa a pagar **duas mensalidades separadas**: uma pelo site no Portal Imagine e outra pelo plano avançado no CRM Imagine.
- Upgrade de CRM **não cancela, substitui, desconta nem transfere** a assinatura do site.
- O benefício de CRM Básico não é o **trial público de 14 dias** nem cria um plano gratuito permanente aberto a usuários que não contratam site. O teste gratuito existente do CRM permanece com regras próprias.
- O CRM já possui três planos comerciais aprovados em conceito: **Essencial, Profissional e Empresarial**. A expressão **CRM Básico vinculado ao site** designa inicialmente um **benefício/entitlement gratuito por vínculo**, e não a aprovação de um quarto plano comercial público ou a renomeação automática do Essencial. Definir, na especificação do CRM, se o benefício usa o conjunto de recursos do Essencial ou um perfil próprio, com limites explicitamente aprovados.
- O usuário pode participar de mais de uma empresa no CRM. O benefício e o plano pago pertencem à **organização/empresa elegível** vinculada ao pedido, não automaticamente a todas as organizações do login.

### 8.2 Regras de operação recomendadas para detalhamento (não aprovadas além da diretriz)

1. Criar `site_bundle_basic_entitlement` por `site_order_id + crm_org_id`, associado à versão dos termos comerciais e aos eventos de contrato.
2. Após confirmação conciliada do primeiro pagamento, provisionar ou vincular a organização CRM sem cobrar o Básico. É necessária verificação de vínculo e escolha quando titular participa de múltiplas empresas; não presumir pelo e-mail.
3. O **plano efetivo** do CRM é o plano pago vigente quando existir; caso contrário, pode ser o Básico por benefício do site enquanto o vínculo estiver elegível. Nunca somar cotas automaticamente sem regra comercial.
4. Quando o upgrade pago é confirmado, trocar recursos efetivos para o plano contratado. O benefício gratuito permanece registrado como direito de retorno, sem cobrança paralela.
5. O CRM já prevê **upgrade self-service com pró-rata e ativação após confirmação do pagamento**, e **downgrade no próximo ciclo** com regularização dos recursos excedentes. A implementação do benefício deve respeitar essas regras; cancelamento de plano pago não deve apagar registros.
6. Se o plano avançado terminar e o site continuar elegível, **retornar ao Básico na data contratual apropriada**, respeitando o ciclo e os limites do destino. Recursos excedentes devem ser preservados com bloqueio/restrição de criação/uso, não apagados automaticamente.
7. Se o site for cancelado, suspenso ou inadimplente, **não encerrar automaticamente a assinatura avançada paga do CRM**. Política exata de perda/suspensão do benefício Básico, prazos, avisos e retenção dos dados depende de aprovação; nunca apagar leads de forma silenciosa.
8. Se uma organização possui múltiplos sites qualificados, registrar múltiplas origens do benefício sem gerar duplicidade de tenant, assinatura Básico ou cobrança; após término de um site, continuar benefício se outro vínculo elegível existir.
9. Conciliação **servidor-servidor autenticada** entre Portal e CRM, com IDs de contratos, idempotência, auditoria, retentativa e ações de suporte para incidentes. Nenhum sistema deve atualizar diretamente o banco do outro.
10. Faturas do site no Portal e do plano avançado no CRM possuem provedores/identificadores separados e histórico explícito, evitando cobrança dupla e impedindo autorização tácita da assinatura paga.

### 8.3 Pendências comerciais e de ciclo de vida

- Recursos e quotas do **Básico cortesia**: usuários incluídos, leads, automações, armazenamento, API, agentes e limites do formulário; não copiar quotas do trial de 14 dias sem decisão.
- Mapear formalmente Básico × Essencial × Profissional × Empresarial e preços finais dos planos pagos (ainda não aprovados).
- Condições exatas de elegibilidade durante inadimplência do site; datas de início, fim e comunicação da cortesia.
- Cancelamento do site com assinatura CRM avançada ativa; cancelamento do CRM pago com benefício do site elegível; recontratação; portabilidade e retenção, respeitando a LGPD e os prazos de assinatura do CRM aprovados.
- Contrato/preço da futura Landing Page e extensão das regras de elegibilidade.
- Regras fiscais e faturamento de benefícios gratuitos e planos pagos quando a documentação tributária for aprovada.


## 9. Testes de aceitação propostos

1. Mesma confirmação financeira entregue duas vezes cria no máximo um entitlement e um vínculo do site.
2. Cliente com conta CRM pré-existente acessa sua organização autorizada sem organizações duplicadas.
3. Um usuário com várias organizações pode selecionar/vincular a organização correta; o formulário não decide sozinho.
4. Formulário de cliente A não consegue criar lead na organização B, mesmo alterando parâmetros do navegador.
5. O HTML/JS publicado não inclui token privado de API ou credenciais CRM.
6. Sites ainda não publicados ou formulário suspenso não recebem novos envios aceitos.
7. CRM fora do ar não perde submissões aceitas duravelmente; retries não duplicam evento.
8. Payload malicioso, spam repetido e arquivos não permitidos são bloqueados/registrados.
9. URL/UTM de origem respeitam validação, finalidades e retenção.
10. Alteração de domínio/site/tenant exige revalidação e não redireciona leads antigos indevidamente.
11. Cancelamento ou inadimplência seguem política contratual aprovada, sem apagar leads.
12. Regressão financeira: compra do Onepage mantém D0/D30/D60 e D90 até revisão comercial aprovada.
13. Formulário e integração funcionam sem serviço de hospedagem de e-mail para o cliente.
14. O site continua útil e exibindo contato WhatsApp se a captação via CRM estiver temporariamente indisponível, com tratamento transparente do formulário.

## 10. Próximas decisões para aprovação

1. **IN-002 já aprovou:** CRM Básico gratuito vinculado ao site, cobrança do site apenas no Portal Imagine e upgrade pago apenas no CRM. **IN-003 já definiu:** Básico mais enxuto e exclusivo do contratante de site/landing page, não o Essencial gratuito. **Ainda definir:** elegibilidade de cancelamento/inadimplência, quotas/recursos concretos e detalhes de ativação.
2. **Provisionamento multiempresa**: criar organização nova, vincular existente ou permitir seleção pelo cliente, e quando disparar convite/acesso.
3. **Funcionalidades mínimas do Plano Básico**: leads, funil, contatos, atividades, automações e limites; agentes de IA do CRM apenas se contratados conforme plano.
4. **Formulário-padrão**: campos obrigatórios/opcionais, antispam, registro de consentimento/aviso de privacidade, configuração por site.
5. **Landing Page**: se será novo produto/catálogo e como funcionará valor, contrato, prazo e assinatura.
6. **Integração e resiliência**: contrato API v1, autenticação servidor-servidor, fila, RPO/RTO e retenção de dados pessoais.
7. **Ciclo de vida**: cancelamento, inadimplência, solicitação de exclusão e exportação dos dados do CRM sem perda indevida.

**Status:** IN-001 e IN-002 aprovadas; benefício comercial do Básico gratuito e faturamento separado para upgrade aprovados. Limites/recursos do Básico, exceções e implementação aguardam aprovação. O CRM continua em planejamento; nenhuma API, assinatura ou formulário foi implementado.


## IN-033 — Armazenamento adicional opcional (APROVADA em 01/11/2026)

- Organizações dos quatro planos — **Básico Cortesia, Essencial, Profissional e Empresarial** — podem **contratar capacidade extra para arquivos e anexos**, sem trocar de plano.
- O adicional aumenta somente a capacidade disponível de arquivos/anexos da organização, mantendo inalterados os direitos e limites do plano (usuários, agenda, automações e demais funcionalidades).
- Exemplo: o Básico Cortesia conserva sua franquia de **1 GB** (IN-031) e pode contratar armazenamento adicional sem adquirir usuários ou funcionalidades dos planos superiores.
- A contratação será **opcional e condicionada a aceite explícito**; não autoriza cobrança automática, compra implícita nem alteração unilateral de plano.
- Permanecem válidas as regras da IN-032 (80% e 95%: avisos internos; 100%: bloqueio somente de novos uploads de anexos, preservando arquivos e dados estruturados).
- **Pendências independentes:** tamanhos de pacotes, preços, forma/ciclo de cobrança, renovação/cancelamento, tratamento da capacidade excedida após término do adicional e detalhes de armazenamento temporário. Não presumir valores ou condições.
- **Somente documentação e planejamento:** nenhuma programação iniciada por esta decisão.


## IN-034 — Cinco pacotes de armazenamento adicional (APROVADA em 01/11/2026)

Os seguintes **cinco pacotes opcionais de espaço adicional para arquivos/anexos** estão disponíveis para contratação por organizações de **qualquer um dos quatro planos**: **Extra P +1 GB, Extra M +5 GB, Extra G +10 GB, Extra GG +25 GB e Extra Empresarial +50 GB**. O nome Extra Empresarial não o restringe ao plano Empresarial: também poderá ser contratado por Básico Cortesia, Essencial e Profissional. O espaço contratado acrescenta capacidade à franquia base (IN-031), sem upgrade, sem alterar direitos funcionais ou quantidades de usuários, profissionais, agendamentos e automações. Aplicam-se IN-032 e IN-033 (avisos e bloqueio somente de novos uploads; contratação opcional com aceite explícito). **Não foram aprovados ainda** preços, método e recorrência de cobrança, combinação de pacotes, possibilidade de múltiplas unidades, ciclo de vigência, cancelamento, tratamento de uso excedente após redução nem requisitos operacionais. Nenhuma programação autorizada.


## IN-035 — Pacotes extras cumulativos, inclusive repetidos (APROVADA em 01/11/2026)

- Uma organização de **qualquer dos quatro planos** pode contratar **vários pacotes extras simultâneos**, inclusive repetir o mesmo pacote IN-034, **sem teto comercial fixo** para a capacidade extra contratada.
- A capacidade total de anexos da organização corresponde à **franquia base do plano (IN-031) acrescida da soma das capacidades extras contratadas e ativas**. Exemplo ilustrativo: Básico Cortesia 1 GB + Extra M 5 GB + Extra G 10 GB = 16 GB.
- Cada contratação é opcional e exige **aceite explícito**, sem compra/cobrança automática. Acúmulo de armazenamento **não concede** novos usuários, permissões ou funcionalidades.
- Aplicar controles técnicos de segurança, prevenção de abuso, proteção contra falhas e capacidade operacional. Esses controles não constituem teto comercial oculto.
- Regras IN-032 de visualização, avisos em 80%/95% e bloqueio **apenas de novos uploads de anexos** ao atingir 100% seguem válidas sobre a capacidade efetivamente disponível.
- **Pendentes, sem aprovação implícita:** preços, ciclos e meios de cobrança, faturamento, renovação, cancelamento, redução da capacidade e tratamento de arquivos quando o uso superar a capacidade remanescente.
- **Planejamento/documentação somente; nenhuma implementação autorizada.**


## IN-036 — Cancelamento de capacidade adicional com preservação de arquivos (APROVADA em 01/11/2026)

- Após o **término efetivo** de um pacote adicional de armazenamento, recalcular a capacidade como franquia base (IN-031) mais extras ainda ativos (IN-034/IN-035).
- **Preservar integralmente todos os arquivos e anexos já existentes**, mesmo quando a utilização ultrapassar a capacidade remanescente. Arquivos continuam acessíveis conforme permissões; não apagar, ocultar ou tornar inacessíveis automaticamente por excesso de uso.
- Se o uso exceder a nova capacidade, **bloquear exclusivamente novos uploads de anexos** enquanto não houver regularização. Continuam possíveis o cadastro e a operação de leads, contatos, observações, agendamentos e demais dados estruturados, observadas as demais permissões e regras do plano (IN-029/IN-031/IN-032).
- Permitir regularização pela **liberação voluntária de espaço** conforme regras de exclusão e retenção aplicáveis, ou pela **contratação explícita de novo armazenamento adicional**, sem compra, cobrança ou upgrade automático.
- Permanecem em aberto o momento efetivo do cancelamento, questões de cobrança e reembolso, detalhes da exclusão voluntária, retenção, recuperação e notificações operacionais. Nenhuma implementação de software foi autorizada.


## IN-037 — Recorrência mensal de armazenamento adicional (regra confirmada em 01/11/2026)

- Cada pacote extra contratado é uma **assinatura com pagamento recorrente mensal, por prazo indeterminado**. Não há fim de período contratual predeterminado; a assinatura permanece vigente enquanto o cliente não a cancelar.
- A adesão à recorrência exige **contratação e aceite explícitos**; a renovação recorrente do pacote já contratado não equivale a contratar automaticamente novos pacotes extras. Aplica-se a todos os quatro planos e aos pacotes cumulativos IN-033 a IN-035.
- Após o cancelamento **efetivo** e a redução da capacidade, observar integralmente a preservação e o bloqueio seletivo definidos em IN-036.
- **Pendente de decisão:** quando a redução de capacidade ocorre depois do pedido de cancelamento (na hora ou ao encerrar o ciclo mensal já pago), além de preço, detalhes de cobrança, reembolso e rotinas financeiras. Não inferir prazo fixo nem tratar o mero fim de um mês como cancelamento automático.
- Atualização somente documental, sem iniciar implementação.


### Complemento IN-037 — Cancelamento no ciclo mensal já pago (APROVADO em 01/11/2026)

**Complemento aprovado em 01/11/2026:** ao solicitar o cancelamento, a renovação e as próximas cobranças recorrentes do pacote cancelado são interrompidas; a capacidade extra permanece ativa até o encerramento do ciclo mensal já pago. Ao terminar esse ciclo, a capacidade é recalculada e aplica-se a IN-036: arquivos existentes preservados e, havendo excesso, bloqueio apenas de novos uploads. Não se presume reembolso ou nova cobrança.


## IN-039 — Anexos acessíveis somente em sessão autenticada e autorizada (APROVADA em 01/11/2026)

- Visualizar, pré-visualizar ou baixar documentos e demais anexos do CRM requer **usuário logado na plataforma**, com sessão válida, além de **permissão efetiva para o recurso e organização**. Não basta conhecer o endereço do arquivo.
- Impedir links públicos ou URLs pré-assinadas de acesso direto ao provedor que continuem funcionando para terceiros sem login, mesmo por poucos minutos. Uma URL pré-assinada é um token portador e, isoladamente, **não comprova que quem a utiliza é o usuário autenticado**.
- A arquitetura deve validar autenticação, escopo por organização e autorização **no backend em cada requisição de acesso**, inclusive prévias, miniaturas e solicitações de download; pode fazer streaming por gateway/proxy autenticado ou solução técnica equivalente que preserve esta garantia.
- Não expor nomes de buckets, chaves, credenciais nem URLs públicas de objetos como mecanismo de acesso; manter objetos privados e registrar trilha de auditoria pertinente, respeitando a política de privacidade.
- Compartilhar um endereço interno não deve permitir acesso a quem não esteja autenticado e autorizado. Medidas técnicas não conseguem impedir capturas de tela ou redistribuição de arquivo já baixado legitimamente; esse risco requer controles adicionais organizacionais.
- **Detalhes técnicos pendentes:** política de cache, processamento de previews, streaming eficiente, escala, controle de sessões, expiração e nível de auditoria. Somente documentação; não programar nesta etapa.


## IN-040 — Permissão única de visualização e download de anexos (APROVADA em 01/11/2026)

- A autorização de **acesso ao anexo** é uma **permissão única** que abrange **visualização, pré-visualização e download**; não criar direitos distintos de 'somente visualizar' e 'baixar'.
- A permissão continua sujeita ao escopo efetivo do usuário, organização/empresa e registro ao qual o arquivo está vinculado. Não concede acesso irrestrito a outros anexos nem a outras organizações.
- Mantém integralmente a **IN-039**: acesso apenas com sessão autenticada e autorização validada em cada requisição; nenhuma URL pública ou pré-assinada transferível pode permitir acesso fora dessa sessão.
- Pré-visualizações, miniaturas e downloads devem aplicar a mesma regra de autorização. Auditoria e controles técnicos serão detalhados na arquitetura.
- Essa decisão não impede cópia de conteúdo já disponibilizado legitimamente; não autoriza compartilhamento público.
- **Somente documentação; não iniciar programação.**


## IN-041 — Somente logs técnicos e de segurança para acessos a anexos (APROVADA em 01/11/2026)

- **Não implementar auditoria funcional individual de cada visualização, pré-visualização ou download** de documentos/anexos, nem apresentar histórico de quem abriu ou baixou cada arquivo.
- **Manter apenas logs técnicos e de segurança estritamente necessários** para diagnóstico, desempenho, controle de abusos, investigação de incidentes e cumprimento de obrigações aplicáveis, com minimização de dados, proteção e acesso restrito; detalhar futuramente quais eventos são registrados e os prazos de retenção.
- Os logs técnicos eventualmente podem conter metadados de requisições e identificadores necessários à segurança, mas **não devem se transformar em rastreamento funcional de leituras e downloads**.
- A IN-041 **não altera** IN-039 (login, autorização por empresa/arquivo em cada acesso, sem links públicos transferíveis) nem IN-040 (permissão única para visualizar e baixar). Também não elimina trilhas de auditoria de mudanças, exclusões, permissões ou outras ações que já tenham regras específicas.
- **Planejamento/documentação apenas**, nenhuma implementação autorizada.
