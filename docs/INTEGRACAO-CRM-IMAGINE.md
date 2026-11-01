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


## IN-042 — Exclusão definitiva imediata de anexos sem lixeira (APROVADA em 01/11/2026)

- Funcionário com permissão efetiva para excluir um anexo poderá solicitar **exclusão definitiva imediata, mediante confirmação explícita**, sem passagem por lixeira e **sem restauração funcional** do anexo excluído.
- Após confirmação e verificação de permissões e obrigações de preservação, o arquivo deverá deixar de estar acessível imediatamente pelo CRM e a remoção definitiva no armazenamento principal deverá ser executada conforme os processos técnicos necessários, sem oferecer período de recuperação ao usuário.
- Quando houver **obrigação legal/regulatória, bloqueio de preservação ou retenção aplicável**, impedir a exclusão indevida ou preservar a cópia obrigatória sob acesso restrito, de acordo com a política legal; informar o usuário da restrição sem divulgar informações indevidas.
- **Backups técnicos, versionamento, replicação e propagação de exclusões** seguem política técnica a definir; não interpretar exclusão imediata da interface como eliminação instantânea de todas as cópias físicas. Não prometer irrecuperabilidade absoluta antes da definição desses prazos.
- Continuam válidas as verificações de autenticação/autorização IN-039, permissão única de visualização/download IN-040 e a decisão IN-041 de não auditar cada leitura/download. Manter trilha de auditoria para a **ação de exclusão** conforme regras comerciais já aprovadas.
- **Somente documentação; implementação não autorizada.**


## IN-043 — Retenção técnica de backups de anexos por 30 dias (APROVADA em 01/11/2026)

- Manter as cópias técnicas de backup dos anexos por **30 dias**, observando exceções de retenção legal, obrigações regulatórias e preservações aplicáveis.
- Os backups destinam-se à continuidade operacional e recuperação de desastre, **não são lixeira** e não autorizam recuperação funcional de anexos intencionalmente excluídos sob a IN-042.
- A exclusão legítima de um anexo deve continuar efetiva mesmo se for restaurado um backup antigo. A arquitetura deverá manter registro durável e confiável das exclusões (por exemplo, tombstones, journal ou mecanismo equivalente), reaplicá-las na restauração **antes de disponibilizar o acesso a arquivos**, e testar a prevenção de reativação indevida.
- Manter segregação de acesso e permissões, políticas de expurgo e preservação legal; evitar restaurar automaticamente versões deletadas a áreas acessíveis pelos usuários.
- **Pendentes técnicos:** desenho de replicação, agendamento, armazenamento protegido, tempo de expurgo, testes de restauração e tratamento de obrigações legais. Nenhuma programação iniciada.


## IN-044 — Replicação incremental assíncrona a cada 15 minutos (APROVADA em 01/11/2026)

- Executar **replicação incremental assíncrona a cada 15 minutos** em **servidor separado** da aplicação CRM, copiando somente objetos/versões elegíveis ainda não replicados, sem bloquear o usuário enquanto a cópia de backup é feita.
- Manter **fila persistente**, tratamento idempotente, controle de concorrência e limites de CPU, RAM e banda; **retentativas** de falhas e **verificação de integridade** nas cópias concluídas.
- Monitorar o **atraso real** (idade do arquivo ainda não protegido mais antigo, falhas e backlog), não apenas o número de execuções. **RPO-alvo de 15 minutos** para anexos é meta de engenharia **dependente de testes**, não SLA/garantia assegurada simplesmente pela periodicidade. Planejar alertas e capacidade de processamento de pico.
- Manter **retenção técnica de backups por 30 dias (IN-043)**; preservar trilha técnica de exclusões para que arquivos legitimamente apagados não reapareçam em restaurações (IN-042/043). Não usar replicação ingênua que restaure exclusões.
- A escolha definitiva de fornecedor de armazenamento e preços dos adicionais (IN-038) continua pendente. O par B2 principal + R2 backup segue somente candidato em análise, não contratação aprovada.
- **Somente planejamento e documentação; nenhuma programação autorizada.**


## IN-045 — Monitoramento completo da replicação de anexos (APROVADA em 01/11/2026)

- Disponibilizar **painel técnico de monitoramento** de replicação de anexos, restrito à equipe operacional autorizada, com status das execuções, atraso real do arquivo pendente mais antigo, backlog de filas, falhas e integridade.
- Gerar alertas quando o **atraso real de proteção ultrapassar o RPO-alvo de 15 minutos** (IN-044), em caso de **falhas persistentes**, crescimento/acúmulo de filas e **falhas de integridade** das cópias.
- Executar **retentativas e recuperação automáticas** quando seguras, com controle para evitar processamento duplicado. Evitar alertas repetitivos por falhas transitórias resolvidas automaticamente: agrupar eventos relacionados, deduplicar notificações e sinalizar resolução.
- A frequência de execução de 15 minutos não garante o RPO; aferir o atraso efetivo e reportar violações. Preservar retenção técnica de 30 dias (IN-043) e impedir reativação de arquivos excluídos (IN-042).
- **Pendências para decisões futuras:** canais e destinatários, gravidade/escalonamento, períodos de silêncio, retenção das métricas/logs e políticas de resposta a incidentes. Não aprovar notificações externas ao cliente por esta decisão.
- **Somente documentação e planejamento; nenhuma programação iniciada.**


## IN-046 — Canais de alertas da replicação (APROVADA em 01/11/2026)

- Apresentar os alertas operacionais de replicação no **painel administrativo interno da Imagine**, acessível apenas à equipe autorizada, nos termos da IN-045.
- Enviar **e-mails aos responsáveis técnicos** quando ocorrerem **falhas persistentes ou incidentes críticos**. Falhas transitórias recuperadas automaticamente não geram e-mails repetitivos; aplicar agrupamento/deduplicação e estado de resolução.
- **Não enviar aos clientes das empresas** mensagens automáticas de alerta de backups/replicação. O e-mail técnico deve conter somente informações operacionais mínimas, sem anexos, conteúdo dos documentos ou dados pessoais desnecessários.
- Preservar verificações e monitoramento de RPO-alvo de 15 minutos, filas, integridade e retentativas (IN-044/IN-045). A definição dos critérios objetivos de gravidade/persistência, lista de responsáveis, escalonamento e prazos será realizada separadamente.
- **Somente planejamento e documentação. Nenhuma programação autorizada.**


## IN-047 — Níveis de gravidade dos alertas de replicação (APROVADA em 01/11/2026)

- **Informativo:** falhas transitórias já recuperadas automaticamente; registrar no painel técnico, sem e-mail automático de incidente.
- **Atenção:** atraso real do anexo ainda não protegido **superior a 15 minutos** (RPO-alvo IN-044); exibir aviso no painel técnico, manter retentativas e acompanhamento.
- **Crítico:** atraso real de replicação **superior a 60 minutos**, **paralisação da replicação** ou **falha de integridade**. Exibir no painel técnico **e enviar e-mail aos responsáveis técnicos**, conforme IN-046. Paralisação detectada e falha de integridade são críticas sem precisar esperar 60 minutos.
- Usar o atraso **efetivamente medido**, não somente a periodicidade de agendamento. Aplicar deduplicação, agrupamento, registro de recuperação e prevenção de alertas repetitivos conforme IN-045/IN-046.
- **Pendências:** definição dos sinais objetivos de paralisação e integridade, tempos de detecção, destinatários e reenvios, escalonamento, plantão e encerramento de incidentes. Não enviar alertas de backup a clientes finais.
- Planejamento/documentação apenas; nenhuma programação autorizada.


## IN-048 — Recuperação automática controlada da replicação (APROVADA em 01/11/2026)

- Permitir **reinicialização segura de processos de replicação**, reconexão aos provedores, **retentativas controladas de transferências** e **reprocessamento idempotente das filas persistentes**, sem bloquear o uso normal do CRM.
- Impor **limites de tentativas, concorrência, CPU, RAM, banda e ciclos de reinício**; aplicar backoff e mecanismos contra tempestades de reprocessamento. Evitar duplicação, inconsistências e loops infinitos.
- Registrar ações automáticas em **auditoria técnica restrita**, preservando a decisão IN-041 de não auditar individualmente visualizações/downloads de anexos.
- Quando a falha persistir ou for crítica, **escalonar à equipe técnica** por painel e e-mail de acordo com IN-045, IN-046 e IN-047, evitando notificações repetidas.
- **Proibir ações destrutivas automáticas**: não excluir anexos, não sobrescrever backups íntegros, não enfraquecer retenções ou permissões, não restaurar arquivos legitimamente excluídos (IN-042/043).
- **Pendências:** critérios operacionais exatos de retentativa/reinício, procedimentos manuais, limites de segurança e testes de recuperação. Somente planejamento; nenhuma programação autorizada.


## IN-049 — Testes periódicos de recuperação de backups (APROVADA em 01/11/2026)

- Executar **verificação de integridade a cada replicação** de anexos, validando a correspondência entre a origem elegível e o backup após a cópia.
- Realizar **diariamente restauração de amostra** dos anexos ainda elegíveis, em ambiente isolado, com conferência de conteúdo, metadados e integridade.
- Realizar **semanalmente teste ampliado de restauração**, cobrindo maior amostra, tipos de arquivos, tamanhos, falhas e cenários de recuperação.
- Realizar **trimestralmente simulação completa de recuperação de desastre**, incluindo procedimentos de recuperação dos arquivos e dependências necessárias, mensurando tempos e validando objetivos RPO/RTO, sem alterar produção.
- **Preservar exclusões legítimas** conforme IN-042 e IN-043: utilizar registros de exclusão/tombstones e controles de autorização para impedir reativação de documentos excluídos em qualquer restauração; testes não podem disponibilizar cópias de arquivos excluídos aos usuários.
- Manter o **ambiente de testes isolado**, acesso técnico restrito, proteção da confidencialidade e eliminação segura de cópias temporárias de teste conforme política técnica futura. Falhas de integridade/restauração são tratadas e notificadas conforme IN-045, IN-046, IN-047 e IN-048.
- **Pendências:** amostragem, duração, critérios de aceitação, procedimentos automatizados, proteção de dados de teste e custeio. Apenas documentação/planejamento; nenhuma programação autorizada.


## IN-050 — Contingência controlada de acesso a anexos em incidentes graves (APROVADA em 01/11/2026)

- Em **incidente grave de indisponibilidade do armazenamento principal**, permitir à **equipe técnica autorizada** ativar uma contingência temporária para servir **arquivos íntegros disponíveis no backup**, sem convertê-lo em armazenamento principal permanente e sem alternância automática não autorizada.
- Todo acesso aos arquivos durante a contingência deve permanecer condicionado a **sessão autenticada no CRM, organização correta e permissão efetiva por anexo**, com validação de cada requisição, sem links públicos ou URLs pré-assinadas compartilháveis (IN-039/040).
- Antes de permitir acesso, conferir a integridade da cópia e a vigência das exclusões legítimas (IN-042/043), aplicando o registro durável de exclusões para **não reativar anexos apagados** por causa de versões antigas do backup.
- Registrar tecnicamente a autorização, ativação, monitoramento e desativação da contingência, com controle restrito de responsáveis e segurança. Não criar auditoria funcional individual de visualizações/downloads (IN-041).
- Contingência é **temporária e controlada**, sem autorizar automaticamente novos uploads no backup, substituição de arquivos íntegros ou sincronização destrutiva. Regras de escrita, retorno ao principal, gatilhos, limites e testes de failover ainda serão especificadas.
- Permanecem válidas a replicação de 15 minutos (IN-044), monitoramento e alertas (IN-045–047), recuperação automática não destrutiva (IN-048) e testes isolados (IN-049).
- Apenas documentação e planejamento; nenhuma programação iniciada.


## IN-051 — Contingência somente leitura, sem novos uploads (APROVADA em 01/11/2026)

- Com a contingência controlada de IN-050 ativada em falha do armazenamento principal, o backup poderá atender **somente visualização, pré-visualização e download de anexos já existentes, íntegros e autorizados**; cumprir obrigatoriamente autenticação e permissões IN-039/040 e impedir reativação de arquivos excluídos (IN-042/043).
- **Bloquear temporariamente todos os novos uploads de anexos** enquanto o armazenamento principal estiver indisponível. Não gravar arquivos de clientes no destino de backup, nem receber novos anexos em buffer/armazenamento temporário como alternativa implícita.
- O **restante do CRM** (leads, contatos, observações, agenda e demais dados estruturados) permanece operacional **desde que os respectivos serviços estejam disponíveis** e suas próprias permissões/limites sejam respeitados.
- Informar de maneira clara aos usuários a indisponibilidade temporária de envio de anexos, sem afirmar que um upload bloqueado foi aceito.
- Contingência continua temporária, de ativação técnica humana e com backup em modo estritamente de leitura. Critérios de retorno ao principal, verificações e UX serão detalhados depois.
- Apenas planejamento e documentação; nenhuma programação autorizada.


## IN-052 — Retorno controlado ao armazenamento principal (APROVADA em 01/11/2026)

- Após a recuperação aparente do provedor principal, executar **verificações automáticas de disponibilidade, integridade dos objetos, isolamento e permissões por empresa/arquivo, exclusões legítimas e testes seguros de upload/download** no armazenamento principal.
- **Exigir autorização explícita de responsável técnico autorizado** para encerrar a contingência da IN-050/IN-051 e reabilitar uploads de anexos. A recuperação do provedor e o sucesso dos testes **não fazem a transição automática**.
- A validação não deve expor arquivos a links públicos, enfraquecer a IN-039/040 ou reativar anexos legitimamente excluídos (IN-042/043). Testes usarão objetos técnicos isolados e não sobrescreverão arquivos de clientes.
- Havendo qualquer falha na validação, **manter a contingência somente leitura**, permanecer com novos uploads bloqueados e registrar/escalonar o problema via logs e alertas técnicos IN-045–048.
- Registrar tecnicamente testes, decisão humana, data/hora e transição; preservar opção de reversão segura se a retomada falhar. Critérios exatos de aceitação, duração da estabilização e rollback ainda pendentes.
- Somente planejamento/documentação; não iniciar programação.


## IN-053 — Proteção automática contra nova falha grave após reativação (APROVADA em 01/11/2026)

- Se o armazenamento principal voltar a apresentar **falha grave após o retorno controlado da IN-052**, o CRM deve **suspender preventivamente novos uploads de anexos** para evitar inconsistência, confirmação indevida de envio ou perda de dados.
- Emitir alertas técnicos pelo painel e, para incidentes críticos, por e-mail aos responsáveis conforme IN-045, IN-046 e IN-047; manter deduplicação e registros técnicos.
- **Não alternar automaticamente ao backup:** a reativação da contingência de leitura da IN-050/051 exige **nova autorização explícita de membro autorizado da equipe técnica**, após checagens de segurança, integridade e exclusões legítimas.
- Proteger os arquivos existentes, permissões por empresa e por recurso, autenticação obrigatória e exclusões legítimas (IN-039–043); não criar mecanismos de upload temporário nem escrita no backup de contingência.
- O restante do CRM continua operacional sempre que seus serviços estiverem disponíveis. Não afirmar sucesso de uploads que foram bloqueados ou não concluídos.
- **Pendências:** sinais objetivos de falha grave, janela de estabilização, comportamento do acesso a arquivos enquanto aguarda contingência e roteiro de retomada. Apenas documentação, sem programação.


## IN-054 — Detecção inteligente e proporcional de falhas do armazenamento (APROVADA em 01/11/2026)

- Diferenciar **falhas isoladas/transitórias** de **incidentes sistêmicos** mediante verificações técnicas de disponibilidade, integridade, resposta do provedor e confirmação de gravação; retentar operações recuperáveis com limites e segurança.
- Em **risco confirmado de indisponibilidade, perda ou corrupção**, **suspender automaticamente novos uploads de anexos**, proporcionalmente ao escopo da falha e sem afetar indevidamente leads, contatos, agenda ou outros dados estruturados.
- **Nunca exibir sucesso em upload cuja gravação íntegra não tenha sido confirmada**. Erros e estados incertos devem ser tratados de modo seguro e transparente, sem perda silenciosa.
- Falhas isoladas não devem disparar bloqueio indiscriminado de todos os uploads ou do CRM. Falhas de integridade relevantes exigem investigação e proteção dos objetos afetados.
- Preservar **IN-050 a IN-053**: contingência somente leitura, retorno validado com aprovação humana e nova ativação da contingência somente por responsável autorizado. Alertas e recuperação técnica conforme IN-045 a IN-049.
- **Pendências:** sinais e limiares objetivos, janelas de detecção, escopo de suspensões e testes de aceitação. Apenas documentação; nenhuma implementação autorizada.


## IN-055 — Recuperação inteligente de uploads interrompidos (APROVADA em 01/11/2026)

- Diante de queda de conexão, timeout ou falha transitória no envio de anexo, aplicar **retentativas automáticas seguras e limitadas**, quando tecnicamente viáveis, com prevenção de duplicidades por operação idempotente (sem criar duas cópias/vínculos por reenvio).
- **Conferir gravação e integridade** do objeto no armazenamento antes de confirmar o upload como concluído ao usuário e antes de estabelecer o vínculo definitivo com o registro no CRM.
- Quando a recuperação automática não for possível, **informar claramente a falha e permitir o reenvio manual**. Não prometer retomada de bytes parcial sem suporte técnico; a estratégia de upload fracionado/retomável será detalhada separadamente.
- Não marcar como bem-sucedido um upload interrompido ou com estado incerto; não contornar quotas de anexos (IN-031/032), controles de segurança, indisponibilidade do armazenamento nem bloqueio durante contingência (IN-039/051/053/054).
- **Pendências:** políticas de timeout e novas tentativas, gestão/validade/limpeza de arquivos temporários, proteção contra uploads órfãos e detalhamento de UX. Apenas documentação; nenhuma programação autorizada.


## IN-056 — Uploads temporários por até 6 horas (APROVADA em 01/11/2026)

- **Reter por no máximo 6 horas** os arquivos ou fragmentos temporários de uploads interrompidos/incompletos, exclusivamente para permitir recuperação segura da operação quando viável (IN-055).
- Após esse prazo, efetuar **limpeza automática segura** de fragmentos, sessões e objetos temporários, com retentativas e monitoramento técnico da limpeza quando necessário; não oferecer recuperação do upload expirado.
- **Não contabilizar o arquivo incompleto/não confirmado na franquia comercial de anexos da empresa**; somente arquivos com gravação íntegra validada e efetivamente aceitos como anexos passam a ocupar franquia, respeitando IN-031/032.
- Os arquivos temporários podem gerar custos reais de infraestrutura, portanto aplicar quotas técnicas, isolamento por organização, autenticação, permissões, proteção contra abuso e controles de concorrência e armazenamento temporário. Não confundir ausência de cobrança de franquia com armazenamento ilimitado de fragmentos.
- Não declarar sucesso nem criar vínculo definitivo antes da confirmação de integridade, e não contornar bloqueios de uploads durante contingência (IN-051/053/054).
- A retenção temporária de 6 horas **não altera** os 30 dias de retenção técnica de backups (IN-043) nem a política de exclusão definitiva (IN-042).
- **Pendências:** contagem do prazo inicial, política de reserva de capacidade, limiares técnicos, gestão de uploads multipart/órfãos e rotinas de limpeza. Somente documentação, sem programação.


## IN-057 — Reserva temporária de espaço para uploads concorrentes (APROVADA em 01/11/2026)

- Antes do início do upload de anexo, verificar a **capacidade efetiva da empresa** (franquia base IN-031 + extras ativos IN-033–035) e criar **reserva temporária atômica** para o tamanho do arquivo, respeitando limite de 5 MB por arquivo (IN-030), autorizações e bloqueios de IN-032/051/053/054.
- Aplicar **controle de concorrência por organização**, impedindo que uploads simultâneos reservem mais bytes do que a disponibilidade calculada como capacidade total menos arquivos efetivamente ocupados menos reservas ativas. As reservas diminuem o espaço **disponível para novos uploads**, mas **não são contabilizadas como arquivos efetivamente utilizados** na franquia comercial.
- **Confirmar e contabilizar uso efetivo somente após gravação íntegra, verificação e vínculo válido do anexo** (IN-055); efetuar conversão idempotente da reserva em uso efetivo, sem dupla contagem nem anexos duplicados.
- **Liberar reserva** quando houver falha, cancelamento ou expiração; a retenção máxima dos uploads temporários incompletos permanece de **6 horas (IN-056)**. Conciliar reservas órfãs e fragmentos com limpeza segura e monitoramento técnico, sem liberar prematuramente reservas de uploads ainda válidos.
- Preservar isolamento entre empresas e impedir uso de metadados de tamanho manipulados para ultrapassar a franquia. Detalhes exatos de validação de tamanho, renovação da reserva, transações, expiração e reconciliação serão definidos tecnicamente.
- Planejamento/documentação somente; nenhuma programação autorizada.


## IN-058 — Até 5 uploads simultâneos por empresa (APROVADA em 01/11/2026)

- Cada organização/empresa poderá manter **até 5 uploads de anexos em processamento simultâneo**, considerados de forma agregada entre todos os seus usuários e sessões. **Demais uploads aguardam em fila** até a liberação de uma vaga.
- Este é um **controle técnico de concorrência, não uma quota comercial**: não fixa teto para a quantidade total de anexos legítimos armazenados dentro da franquia contratada e não gera exigência de upgrade de plano.
- Na futura implementação, o controle deverá ser **coordenado por organização no backend**, para que usuários, navegadores ou servidores diferentes não ultrapassem o limite compartilhado; a fila pode ter apresentação no navegador, mas não depender somente dele para a garantia.
- Respeitar reserva atômica de capacidade e prevenção de excesso (IN-057), limite atual de 5 MB por arquivo (IN-030), validação de integridade e idempotência (IN-055) e limpeza de uploads temporários incompletos em até 6 horas (IN-056). Não confirmar arquivo antes da gravação íntegra e não permitir uploads durante contingência bloqueada (IN-051/053/054).
- **A definir separadamente:** ordenação e política de prioridade da fila, cancelamento, limite de espera, tratamento de queda de sessão, momento de reserva de capacidade para itens aguardando e experiência do usuário. Não presumir contratação automática ou cobrança por esse controle.
- Exclusivamente planejamento/documentação; nenhuma programação autorizada.


## IN-059 — Fila FIFO de uploads por organização (APROVADA em 01/11/2026)

- Processar uploads de anexos **pela ordem de chegada (FIFO — First In, First Out) à fila de cada empresa/organização**, independentemente do funcionário que solicitou o envio. Não aplicar rodízio por usuário nem prioridade configurável sem aprovação futura.
- Respeitar **até 5 uploads em processamento simultâneo por empresa** (IN-058). Quando uma vaga é liberada, o próximo item **elegível** na ordem de chegada pode iniciar, com controle centralizado entre todos os usuários, sessões e instâncias.
- Garantir **ordenação consistente no backend**, inclusive sob concorrência; filas de diferentes empresas ficam isoladas. Uma tarefa bloqueada ou inválida não deve permitir estouro de limite, desrespeito a permissões ou confirmação indevida de upload.
- Preservar reserva atômica de capacidade (IN-057), gravação íntegra e idempotente (IN-055), retenção temporária por até 6 horas para uploads incompletos (IN-056), regras de quotas e bloqueio em contingência (IN-032/051/054).
- **Pendências:** como lidar com cancelamentos, expiração e desconexão de itens aguardando, retentativas/reentrada na fila, quando reservar espaço de itens em espera e tratamento de uploads que não podem iniciar. Não presumir prazo de fila ou direito de ultrapassagem.
- **Somente planejamento/documentação; nenhuma programação autorizada.**


## IN-060 — Cancelamento seguro de uploads em fila e em transmissão (APROVADA em 01/11/2026)

- Permitir ao usuário autorizado **cancelar uploads ainda aguardando na fila FIFO** (IN-059) ou **em transmissão**, antes da conclusão definitiva. Remover o item pendente ou interromper com segurança o envio em andamento.
- Liberar a **reserva de capacidade** do upload cancelado (IN-057) e a **vaga de concorrência** quando efetivamente desocupada (IN-058), permitindo iniciar o próximo item elegível pela ordem FIFO. Evitar liberações prematuras enquanto a operação não foi efetivamente interrompida.
- Executar **limpeza segura dos arquivos, fragmentos e sessões temporárias**, com controle de objetos órfãos, retentativas e respeito à política de temporários (IN-056). Não manter lixo técnico ou consumo comercial de um upload não confirmado.
- Tratar de maneira **idempotente a disputa entre cancelamento e conclusão**: se o arquivo tiver sido finalizado/confirmado antes da efetivação do cancelamento, informar isso ao usuário e encaminhar eventual remoção à **IN-042** (exclusão definitiva, sob autorização e confirmação), sem apagá-lo silenciosamente pelo processo de cancelamento.
- Preservar autenticação, permissão por empresa, prevenção de duplicidades, confirmação apenas após integridade verificada (IN-039/055) e o bloqueio de uploads em contingência (IN-051/053/054).
- **Pendências técnicas:** estado exato de cancelamento, UX, sincronização entre servidores, corrida cancelamento × conclusão, forma de interromper multipart upload e tempo operacional de limpeza. Somente planejamento/documentação; nenhuma programação iniciada.


## IN-061 — Recuperação segura de uploads interrompidos após saída do navegador (APROVADA em 01/11/2026)

- Ao fechar a aba/navegador, perder conectividade ou sair do CRM, a transmissão que depende da conexão do navegador pode ser interrompida, **sem considerar a operação automaticamente concluída**. Preservar dados temporários elegíveis para recuperação, conforme IN-055 e IN-056, por **até 6 horas**; o prazo não é renovado indefinidamente por novas tentativas.
- Qualquer tentativa de **retomada exige sessão autenticada válida, verificação renovada de permissões do usuário, organização, recurso, capacidade e bloqueios operacionais**, antes de ler ou completar os dados enviados; não retomar apenas com um identificador ou link compartilhável.
- Quando a retomada parcial não for tecnicamente possível ou o navegador perder acesso ao arquivo local, **permitir reenvio manual com nova seleção do arquivo**, usando prevenção de duplicidade e conferência de integridade. Não prometer que o navegador guarda acesso ao arquivo original após fechamento.
- **Cancelar com segurança itens pendentes não recuperáveis**, liberar vagas e reservas somente quando a tarefa correspondente deixar de estar ativa, reconciliar concorrência e fila FIFO (IN-057 a IN-060), e limpar automaticamente dados temporários expirados (IN-056).
- Arquivos já confirmados com gravação íntegra permanecem como anexos válidos; nenhuma ação de reconexão pode confirmar um envio incompleto, criar duplicidade ou contornar quotas e bloqueios de contingência (IN-032/051/054/055).
- **Detalhes pendentes:** token de sessão retomável, proteção de fragmentos, ordem de retorno à fila, estado e tempo de reserva, limites de tentativas, mensagens da interface e implementação de upload retomável no navegador.
- **Somente documentação de planejamento; nenhuma programação autorizada.**


## IN-062 — Upload recuperado retorna ao fim da fila FIFO (APROVADA em 01/11/2026)

- Se um upload interrompido por perda de conexão ou encerramento do navegador puder ser recuperado dentro do prazo máximo de **6 horas** (IN-056/061), ele **não preserva sua antiga posição** e **volta ao final da fila FIFO da própria organização** (IN-059), após as verificações exigidas.
- Enquanto a transmissão estiver efetivamente interrompida/desconectada, o upload **não reserva uma das 5 vagas de processamento simultâneo** da empresa (IN-058); a vaga desocupada pode ser destinada ao próximo upload elegível pela ordem FIFO. A vaga só é liberada quando o processamento anterior estiver efetivamente interrompido, sem contagem dupla em situações de corrida.
- Antes de permitir recuperação e reenfileiramento, **revalidar sessão autenticada, permissões por usuário/empresa/recurso, integridade e elegibilidade do upload, bloqueios de contingência e capacidade disponível** (IN-032/039/051/054/057/061). Nenhuma credencial antiga ou identificador de upload dispensa a autorização atual.
- Respeitar idempotência, prevenção de duplicidades e confirmação somente após gravação íntegra (IN-055); um upload expirado, cancelado ou já concluído não pode ser reintroduzido como envio novo por erro de concorrência.
- A **regra de retenção/liberação da reserva de capacidade de armazenamento durante a desconexão ainda precisa de definição específica**: a IN-062 define a liberação da vaga de processamento, mas não determina automaticamente a política dessa reserva de bytes, a ser tratada na IN-063.
- Apenas planejamento e documentação; nenhuma programação autorizada.


## IN-063 — Liberação da reserva após interrupção confirmada (APROVADA em 01/11/2026)

- Após a **interrupção efetivamente confirmada** de um upload (por queda de conexão, fechamento de aba/navegador ou outra interrupção recuperável), **liberar a reserva temporária de capacidade** do arquivo, sem reter espaço indisponível aos demais funcionários durante a desconexão. A liberação deve ser coordenada, segura e idempotente, sem agir sobre gravações ainda efetivamente ativas.
- Os **fragmentos e metadados temporários elegíveis à recuperação** podem ser preservados por **até 6 horas** desde o prazo aplicável da IN-056/061; ocupam recursos técnicos reais, mas **não são contabilizados como uso comercial efetivo de anexos confirmados**. Aplicar controles de consumo, isolamento, proteção e limpeza/expiração.
- Antes de qualquer retomada, exigir **nova autenticação, verificação de permissões do usuário/empresa/recurso, elegibilidade, bloqueios operacionais, tamanho e capacidade disponível**, com **nova reserva atômica** conforme IN-057. Se a capacidade estiver indisponível, não retomar nem confirmar upload por conta da reserva antiga.
- Após validações e nova reserva bem-sucedidas, o upload retomado **reentra no final da fila FIFO** conforme IN-059/062, respeitando até **5 uploads simultâneos por empresa** (IN-058); não reivindica a vaga nem a posição original.
- Evitar dupla contagem, reservas órfãs, liberações prematuras, anexos duplicados e confirmações sem gravação íntegra (IN-055/060). A política para apresentar insuficiência de espaço no retorno será especificada separadamente (IN-064).
- **Somente planejamento e documentação; nenhuma programação autorizada.**


## IN-064 — Insuficiência de espaço na recuperação de upload (APROVADA em 01/11/2026)

- Quando um upload interrompido for elegível à recuperação, mas **não houver capacidade disponível para nova reserva** (IN-057/063), comunicar claramente ao usuário a falta de espaço, sem iniciar transmissão, sem declarar upload concluído e sem utilizar a reserva antiga já liberada.
- **Preservar os fragmentos temporários recuperáveis somente até o fim do prazo original máximo de 6 horas** definido em IN-056/061, **sem renovar ou reiniciar esse período** por tentativas de recuperação, falta de espaço, fechamento de navegador ou reconexão.
- O funcionário poderá **solicitar manualmente nova tentativa** enquanto o prazo original estiver válido, após liberar espaço na franquia ou ativar capacidade adicional devidamente contratada. Em cada tentativa exigir **nova autenticação válida, autorização de usuário/organização/recurso, checagem de integridade e elegibilidade, limites técnicos, bloqueios operacionais e reserva atômica da capacidade necessária** (IN-032/039/051/054/057/063).
- Enquanto aguarda espaço ou ação do usuário, o upload **não ocupa nenhuma das cinco vagas de transmissão** (IN-058) e **não tem posição garantida na fila FIFO**. Após nova validação e reserva bem-sucedida, **reentra no fim da fila** (IN-059/062), sem ultrapassar uploads já aguardando.
- Se o prazo de recuperação expirar, **cancelar a recuperação e limpar com segurança os fragmentos e metadados temporários**, conforme IN-056/060/061, sem consumir franquia comercial como anexo confirmado.
- Não criar cobrança automática de armazenamento nem obrigar upgrade: capacidade adicional continua sujeita a consentimento comercial explícito (IN-033–037). Operações simultâneas podem esgotar novamente o espaço; reserva atômica evita sobrealocação.
- **Pendências:** mensagens de interface, exibição opcional de tempo restante, detalhes de corrida entre disponibilidade e reserva, tratamento técnico de fragmentos órfãos. Apenas planejamento/documentação; nenhuma programação autorizada.


## IN-065 — Validação rigorosa do tamanho real dos arquivos (APROVADA em 01/11/2026)

- Para cada upload, comparar o **tamanho real dos bytes recebidos e verificados no servidor** com (a) a **reserva temporária atômica** concedida para a empresa conforme IN-057/063 e (b) o **limite máximo de 5 MB por arquivo** vigente na IN-030. Não confiar apenas no tamanho declarado pelo navegador, extensão ou cabeçalhos do pedido.
- **Rejeitar sem aumento automático de reserva** qualquer arquivo que ultrapasse o tamanho reservado ou o limite de 5 MB, mesmo se a empresa ainda possuir franquia disponível. Interromper o processamento de modo seguro quando o excesso for identificado; não confirmar, publicar ou vincular como anexo válido.
- Quando o tamanho real for **menor que a reserva**, reconciliar a diferença e **liberar de modo atômico a capacidade excedente**, após a validação adequada, sem causar dupla liberação. Se o arquivo estiver dentro do espaço reservado, só converter a quantidade efetivamente confirmada em **uso comercial** após gravação íntegra, validação e vínculo idempotente (IN-055/057).
- Em qualquer rejeição, **limpar com segurança fragmentos/objetos temporários e sessões incompletas e liberar reservas e vagas** quando a interrupção estiver efetivamente confirmada, respeitando IN-056/058/060. Registrar eventos técnicos essenciais, sem exibir arquivo inválido como concluído.
- Manter controles de autenticação, autorização, isolamento por empresa, quotas, bloqueios de contingência e fila FIFO (IN-032/039/051/054/059/062). Não permitir que dados de cliente tenham seu tamanho declarado manipulado para superar limite ou reserva.
- **Detalhes pendentes:** convenção exata de MB (decimal/binária), implementação de verificação de tamanho em fluxo e mensagens da interface. Somente planejamento/documentação; nenhuma programação autorizada.


## IN-066 — Verificação rigorosa do formato real dos anexos (APROVADA em 01/11/2026)

- Para uploads de anexos, **validar no servidor o tipo real e a consistência do conteúdo**, considerando conjuntamente **extensão**, **tipo MIME detectado do conteúdo** (não apenas o MIME informado pelo navegador), **assinatura/magic bytes** e **estrutura interna quando aplicável** ao formato.
- Manter estritamente o **conjunto de formatos permitidos já aprovado na IN-030** para todos os planos; esta decisão não amplia os tipos aceitos nem altera o limite de **5 MB por arquivo**.
- **Rejeitar com segurança** arquivos disfarçados por extensão renomeada, arquivos malformados, incompatíveis ou cuja combinação de indicadores/estrutura não seja aceita pelos validadores do formato. Em falha ou resultado inconclusivo, **não confirmar, vincular nem disponibilizar** o upload como anexo válido.
- Implementar verificadores adequados por classe de arquivo, com limites seguros de análise para conteúdo comprimido/incorporado; **uma assinatura compatível ou MIME aparentemente correto não comprovam, por si sós, ausência de malware**, de conteúdo ativo ou de arquivos poliglotas. A política de varredura antimalware será tratada em decisão separada (proposta IN-067).
- Ao rejeitar, **interromper e limpar fragmentos/objetos temporários com segurança, liberar a reserva de capacidade e a vaga de processamento somente após encerramento efetivo**, sem dupla liberação, seguindo IN-056–060 e IN-063–065. Registrar dados técnicos mínimos necessários à análise e incidentes, sem expor conteúdo sensível.
- Preservar autenticação, permissões e isolamento por empresa (IN-039/040), limites de franquia, suspensão em contingência, fila FIFO, idempotência e **confirmação somente após gravação íntegra e verificações concluídas** (IN-032/051/054/055/057/059/062).
- **Detalhes pendentes:** matriz exata de validadores por formato, critérios para assinaturas ambíguas e arquivos compostos, mensagens da interface, processos de testes e análise de ameaças. **Somente documentação de planejamento; nenhuma programação autorizada.**


## IN-067 — Análise antimalware obrigatória para todos os anexos (APROVADA em 01/11/2026)

- **Todos os arquivos de anexo**, em todos os planos, serão submetidos a **análise antimalware obrigatória** antes de serem disponibilizados a qualquer usuário, somada à validação de extensão, MIME, assinatura e estrutura da IN-066 e à conferência de tamanho real da IN-065.
- Receber e manter o arquivo **isolado em área técnica privada de quarentena** durante as verificações. A quarentena não equivale a um anexo válido; os arquivos não poderão ser visualizados, baixados, vinculados como concluídos ou acessados por links públicos enquanto a análise estiver pendente, falhar ou resultar inconclusiva. Os controles de login, permissão por empresa e arquivo da IN-039 continuam obrigatórios.
- **Somente promover o arquivo ao estado de anexo confirmado** após gravação íntegra e validação de tamanho, formato e análise antimalware com resultado aprovado. Não informar sucesso definitivo antes disso; exibir estado de processamento com linguagem precisa, sem confundir recebimento temporário com conclusão.
- **Ameaças detectadas:** rejeitar o arquivo e efetuar descarte/limpeza segura de dados temporários, impedir disponibilização e registrar evento técnico mínimo para apuração, sem incluir o conteúdo sensível do anexo no log. A ação de rejeição não é uma exclusão funcional de anexo já concluído (IN-042).
- **Falha, indisponibilidade ou resultado inconclusivo da análise:** adotar política de **falha segura (fail closed)**: bloquear disponibilização e confirmação, aplicar **retentativas controladas**, sem liberação por confiança no MIME, ausência de resultado ou autorização comercial. A especificação dos intervalos e das tentativas será decidida posteriormente.
- Preservar o **prazo máximo original de 6 horas para arquivos temporários incompletos ou ainda não confirmados**, de acordo com IN-056/061, sem reiniciar o relógio por novas análises. Ao esgotar o prazo, encerrar o processamento pendente, remover fragmentos/objetos temporários com segurança e liberar reservas/vagas de forma coordenada e idempotente (IN-057/058/060/063–065), conforme os estados efetivos da operação.
- Respeitar quotas, concorrência, FIFO, exclusões legítimas, acesso autenticado e proteção contra duplicação; a análise de arquivos não deve violar isolamento entre empresas. Registrar **auditoria técnica** com data, resultado/código de erro, versão do mecanismo, correlação técnica e providências, minimizando dados pessoais e sem criar log comercial individual de visualizações ou downloads (IN-041).
- **O fornecedor/mecanismo antimalware permanece em aberto**, assim como tratamento de falsos positivos, horários e limiares de retentativas, fila de análise, disponibilidade do motor, critérios de aceitação técnica e resposta operacional. Nenhum antivírus é considerado infalível.
- **Apenas planejamento e documentação; nenhuma programação autorizada.**


## IN-068 — Indisponibilidade sistêmica do antivírus e suspensão preventiva de novos uploads (APROVADA em 01/11/2026)

- Diante de **indisponibilidade sistêmica confirmada** ou incapacidade generalizada do serviço antimalware de concluir suas análises obrigatórias, **suspender preventivamente a aceitação de novos uploads de anexos**. Falhas isoladas e transitórias devem ser distinguidas de uma indisponibilidade sistêmica para não bloquear uploads indevidamente (em coerência com a IN-054).
- **Anexos já recebidos, mas ainda não aprovados**, permanecem **isolados em quarentena privada**, sem visualização/download nem vínculo como anexo definitivo; nenhuma falha ou resultado inconclusivo libera o arquivo (IN-039/066/067).
- Aplicar **retentativas controladas** de verificação antimalware enquanto o serviço volta a funcionar, respeitando rigorosamente o **prazo original máximo de 6 horas** para temporários não confirmados (IN-056/061/067). A falha do antivírus, as retentativas e a reconexão não renovam a janela. Uma vez expirado o prazo, encerrar com estado não concluído, remover temporários com segurança e liberar/conferir reservas e vagas sem dupla liberação (IN-057/058/060/063–065).
- **Anexos já aprovados anteriormente continuam acessíveis**, sujeitos a login e autorização atual por empresa e arquivo; indisponibilidade do motor de varredura **não revoga a aprovação anterior por si só**. Os demais módulos do CRM permanecem operacionais desde que seus serviços estejam disponíveis.
- **Retomar a aceitação de novos uploads somente após validação da recuperação funcional do antivírus**, incluindo capacidade de executar análises e emitir resultados confiáveis; uma mera resposta de conectividade não é suficiente. A operação respeitará validação de formato/tamanho, integridade, quotas, fila FIFO, limites e os demais bloqueios ativos, sem contorná-los (IN-030/032/051/054/055/057–059/065–067).
- Manter alertas técnicos, logs mínimos de indisponibilidade e recuperação, estados de quarentena e mensagens de interface claras; nunca informar sucesso definitivo para arquivo ainda não analisado. Evitar dados pessoais/conteúdo de anexos em logs e não criar auditoria comercial de visualização/download (IN-041/045–047/067).
- **A definir posteriormente:** limiares de confirmação de indisponibilidade sistêmica, frequência/limite de retentativas, verificações de recuperação e procedimento de liberação do bloqueio, tratamento de casos suspeitos e falsos positivos. O mecanismo antivírus segue **não escolhido**, conforme IN-067.
- Exclusivamente planejamento/documentação; nenhuma programação autorizada.


## IN-069 — Revisão técnica controlada de falsos positivos do antivírus (APROVADA em 01/11/2026)

- Quando um arquivo enviado for **rejeitado por detecção antimalware**, permitir que um funcionário autorizado da empresa **solicite revisão técnica de possível falso positivo**. Registrar a solicitação e seus dados mínimos (empresa, usuário, data/hora, identificação técnica do evento, status e motivo declarado) sem armazenar conteúdo malicioso em logs ou expor dados sensíveis.
- A análise da contestação será responsabilidade da **equipe técnica Imagine com atribuições autorizadas**, com registro das etapas relevantes, resultado, responsável e justificativa em **auditoria técnica protegida**. Preservar isolamento entre empresas, acesso mínimo necessário e rastreabilidade.
- **Não restaurar nem disponibilizar arquivos já rejeitados**; a contestação **não constitui exceção** à quarentena, às exclusões legítimas, à proibição de acesso não autenticado nem às regras de análise antimalware. **Nenhum funcionário ou administrador, inclusive da Imagine, poderá liberar manualmente um anexo não aprovado** (IN-039/042/066–068).
- Se a avaliação indicar possível falso positivo e houver caminho seguro, orientar o funcionário a realizar **um novo upload**, após eventual correção legítima do problema de detecção/configuração. O novo arquivo deve cumprir **todas as etapas normalmente exigidas**, inclusive autorização, tamanho de até 5 MB, validação real de formato, integridade, reserva/capacidade, análise antimalware positiva e confirmação após gravação íntegra (IN-030/055/057/065–068).
- O processo de revisão **não altera nem renova** o prazo de retenção de temporários incompletos de **até 6 horas** (IN-056/061) e não autoriza resgate de arquivos que já foram eliminados.
- **Pendências:** canal de solicitação, perfis técnicos, níveis de urgência e prazo de resposta, orientações ao usuário e retenção da auditoria técnica. Apenas planejamento e documentação; nenhuma programação autorizada.


## IN-070 — Reanálise inteligente de segurança de anexos armazenados (APROVADA em 01/11/2026)

- Anexos anteriormente aprovados e armazenados poderão passar por **reanálise antimalware direcionada e baseada em risco**, quando houver atualizações relevantes de segurança, indicadores de novas ameaças, novas informações de detecção ou outros riscos concretos identificados.
- **Identificar e selecionar os arquivos potencialmente afetados** conforme as evidências técnicas disponíveis, em vez de impor indiscriminadamente uma nova varredura completa de todo o acervo a cada atualização. A reanálise será executada de modo seguro, com respeito a isolamento por empresa, recursos técnicos e integridade de objetos.
- Se uma **ameaça for confirmada em arquivo já armazenado**, **suspender o acesso ao arquivo afetado** (visualização, pré-visualização e download, inclusive por fluxos internos), sem expor seu conteúdo a funcionários; gerar alerta técnico, registrar evento e justificativa em **auditoria técnica protegida**, e encaminhar o caso para análise/remediação.
- A reanálise e o bloqueio **não autorizam exclusão automática** de um anexo já concluído. Qualquer exclusão deverá seguir a IN-042 e demais exigências legais/contratuais. Não restaurar objetos excluídos legitimamente (IN-043) nem criar exceções de disponibilização para arquivos identificados como perigosos (IN-067/069).
- **Preservar o acesso normal dos demais arquivos não afetados**, sempre sob sessão autenticada, autorização atual por organização/arquivo e controles de IN-039/040; não revogar em massa anexos sem indicação de risco.
- Minimizar dados pessoais nos alertas e registros técnicos, preservando evidências relevantes, identificação do mecanismo e resultado, responsáveis e momentos das mudanças de estado; não registrar visualizações/downloads no log de auditoria comercial (IN-041).
- **Pendências para próximas IN:** critérios detalhados de gatilho e seleção, prioridade e ritmo da reanálise, decisões sobre suspeita ainda não confirmada, triagem e desbloqueio de arquivos, controles de custo e desempenho. O mecanismo antivírus continua em aberto.
- Somente planejamento/documentação. **Nenhuma programação autorizada.**


## IN-071 — Bloqueio preventivo de anexos proporcional ao risco (APROVADA em 01/11/2026)

- Na **reanálise de segurança de anexos já armazenados** (IN-070), manter disponíveis para usuários devidamente autorizados os arquivos submetidos a **reanálise rotineira sem indícios concretos de ameaça elevada**; não bloquear todo o acervo nem todos os arquivos em análise.
- Se surgirem **indícios técnicos concretos de risco elevado**, aplicar **bloqueio preventivo temporário ao arquivo especificamente afetado**, abrangendo visualização, pré-visualização e download, inclusive em fluxos internos e eventuais acessos por backup/contingência. Bloquear acesso antes da conclusão da reanálise quando o risco justificar essa proteção.
- Quando uma reanálise com resultado **confiável e verificações de segurança concluídas descartar a suspeita**, **restabelecer o acesso normal** sujeito a autenticação e permissões atuais (IN-039/040). A reanálise isoladamente inconclusiva não autoriza desbloqueio de arquivo previamente bloqueado por risco elevado.
- Se houver **ameaça confirmada ou suspeita grave ainda não resolvida**, manter o bloqueio, notificar os responsáveis técnicos, registrar os resultados e submeter a investigação a fluxo técnico controlado; não criar exceção manual para liberar arquivo reconhecidamente perigoso.
- Preservar **auditoria técnica protegida** de indícios, gatilhos, estados, resultados, responsável/serviço, horários e decisões, minimizando dados sensíveis e sem criar registro comercial de visualização/download (IN-041).
- **Não excluir arquivos automaticamente** em razão de suspeita, reanálise ou confirmação; respeitar IN-042, retenções aplicáveis e exclusões legítimas, sem restaurar objetos excluídos. Arquivos não afetados continuam disponíveis conforme permissões vigentes.
- **A detalhar:** critérios técnicos objetivos de risco elevado, validação do resultado seguro, aprovação humana ou automática para desbloqueio e tratamento da análise inconclusiva. Apenas planejamento e documentação; nenhuma programação autorizada.


## IN-072 — Desbloqueio híbrido e seguro após reanálise de anexos (APROVADA em 01/11/2026)

- **Bloqueio preventivo sem ameaça confirmada (IN-071):** se novas verificações completas e confiáveis **descartarem a suspeita de risco elevado**, o sistema pode **restabelecer automaticamente o acesso ao arquivo**, sem aprovação humana adicional. O desbloqueio não dispensa a autenticação atual, as permissões por empresa/arquivo e as demais condições de acesso da IN-039/040.
- **Arquivo que já teve ameaça confirmada (IN-070):** só poderá voltar a ficar acessível se uma **nova análise completa tiver resultado aprovado, sem detecção pendente ou inconclusiva, E houver autorização explícita de integrante habilitado da equipe técnica Imagine**. Nenhuma dessas condições isoladamente basta.
- **Proibida a liberação manual por exceção** de arquivo cuja detecção de risco permaneça positiva, não concluída ou inconclusiva. A equipe não pode ignorar controles antimalware, substituir resultados técnicos por julgamento informal ou disponibilizar arquivos perigosos; preservar IN-067/069/070/071.
- Registrar **auditoria técnica protegida** de causa do bloqueio, versões/resultados das análises, transições de estado, identidade e data/hora do autorizador quando exigido, e justificativas. Minimizar dados sensíveis, sem auditoria comercial de eventos individuais de visualização/download (IN-041).
- Aplicar transições **atômicas, seguras e rastreáveis**, impedindo acesso entre uma reanálise positiva e a confirmação do desbloqueio. Qualquer nova indicação concreta de ameaça deverá reativar os controles de proteção de IN-070/071.
- **Não restaurar, reabrir ou reintroduzir anexos legitimamente excluídos**, inclusive a partir de backup (IN-042/043); o desbloqueio limita-se a arquivos ainda existentes de forma legítima.
- **A detalhar posteriormente:** critérios técnicos específicos de confirmação de resultado seguro, perfil e dupla checagem de autorização, prazos, mensagens de estado e comunicação de bloqueio/desbloqueio à empresa.
- **Somente documentação de planejamento; nenhuma programação autorizada.**


## IN-073 — Notificações direcionadas sobre bloqueio e desbloqueio de anexos (APROVADA em 01/11/2026)

- Em **bloqueio preventivo, confirmação de ameaça ou desbloqueio de segurança** de um anexo já armazenado (IN-070–072), produzir **notificação interna direcionada** ao **administrador autorizado da organização** e ao **funcionário responsável pelo anexo**, caso ainda tenha permissão atual para conhecer o arquivo e seu estado. Evitar notificação em massa aos demais funcionários.
- A notificação deve informar de maneira clara o **estado do anexo (acesso suspenso por segurança ou acesso restabelecido)** e orientar o usuário sobre a indisponibilidade, **sem revelar assinatura de malware, dados de investigação, indicadores técnicos sensíveis ou conteúdo do anexo**. Exibir o próprio arquivo somente quando a autorização atual e o estado de segurança permitirem.
- **Checar permissões no momento de direcionar e de consultar a notificação**, preservando fronteiras entre empresas; retirar ou ocultar detalhes de notificações quando a pessoa perder acesso ao registro. O papel de administrador não deve suprimir restrições específicas de segurança do arquivo.
- Alertas sobre ameaças, falhas de varredura e decisões de bloqueio/desbloqueio permanecem no **painel e nos canais técnicos da Imagine**, de acordo com os controles já aprovados (IN-045–047/067–072), sem exposição de dados desnecessários ao cliente.
- Registrar transições e comunicações essenciais em **auditoria técnica protegida**, sem criar auditoria comercial individual de visualizações e downloads (IN-041). Integrar notificações à infraestrutura existente do CRM e preservar regras já aprovadas de retenção e arquivamento de notificações, sem inventar novos prazos nesta decisão.
- **Pendências:** identificação e transferência de responsabilidade do anexo, deduplicação e agregação de eventos, mudanças de permissão/conta desativada, mensagens e experiência da interface, critérios de status da notificação.
- **Somente documentação de planejamento; nenhuma programação autorizada.**


## IN-074 — Referência visível e conteúdo inacessível para anexos bloqueados (APROVADA em 01/11/2026)

- Quando um anexo já armazenado estiver **bloqueado por segurança** conforme IN-070–073, **preservar a referência e os vínculos do anexo no histórico do CRM**, incluindo seus registros de lead, contato, oportunidade e demais entidades relacionadas. Não apagar ou ocultar indiscriminadamente o histórico empresarial.
- Somente **usuários atualmente autorizados** a conhecer o registro e o anexo poderão ver uma representação informativa com **metadados permitidos** (por exemplo, nome do arquivo, se permitido) e o status **acesso suspenso por segurança**. Não revelar conteúdo, ameaças técnicas detectadas, indicadores sigilosos ou outros metadados protegidos. Usuários sem permissão não poderão inferir informações do anexo por busca, notificações ou referências.
- **Impedir acesso ao conteúdo do arquivo bloqueado** por todos os caminhos, incluindo visualização, pré-visualização, download, miniaturas que exponham conteúdo, processamento por IA, extração/consulta automatizada, automações, exportações ou integrações; impor verificação de estado de bloqueio e permissões também no serviço de entrega, sem depender somente da interface. Manter a vedação a URLs de objeto reutilizáveis da IN-039.
- **Preservar o objeto original e os vínculos comerciais**, sem exclusão automática por bloqueio, conforme IN-042/070/071. O tratamento de cópias derivadas, conteúdo já extraído, caches e tarefas iniciadas anteriormente será especificado separadamente para impedir rotas indiretas de exposição.
- **Restabelecer acesso somente após as condições de desbloqueio seguro da IN-072** serem cumpridas; em toda tentativa de acesso, validar novamente autenticação, permissões por empresa/arquivo e estado atual de segurança.
- Os avisos direcionados seguem IN-073 e a auditoria técnica de segurança segue IN-070–072, sem criar auditoria comercial de cada visualização/download (IN-041).
- **A detalhar:** apresentação da referência e metadados por perfil, aplicação da política a cache/índices/derivados, cancelamento de trabalhos pendentes, mensagens de UX e testes de autorização. Somente planejamento e documentação; nenhuma programação autorizada.


## IN-075 — Proteção completa de conteúdos derivados de anexos bloqueados (APROVADA em 01/11/2026)

- Ao bloquear um anexo por segurança (IN-070–074), **propagar o bloqueio a todas as representações e conteúdos derivados gerenciados pelo CRM**, incluindo prévias, miniaturas, textos extraídos, metadados enriquecidos que exponham o conteúdo, índices de busca e vetoriais, respostas e consultas de agentes de IA, automações, integrações e rotas de exportação. Não depender exclusivamente de ocultação na interface: verificar estado de segurança e permissões também nos serviços de entrega/consulta.
- **Interromper ou cancelar os processamentos pendentes relacionados ao arquivo bloqueado**, impedindo geração, persistência, disponibilização ou distribuição de novos derivados após o bloqueio. Tratar eventos concorrentes de processamento × bloqueio por mecanismos seguros e idempotentes.
- **Invalidar caches gerenciados pelo CRM**, inclusive cópias temporárias e resultados de processamento sob seu controle, e impedir que índices e camadas de busca continuem servindo material derivado do anexo. Aplicar controles de revogação em serviços distribuídos e minimizar janelas de inconsistência; não afirmar revogação de cópias previamente baixadas por terceiros/dispositivos externos.
- **Preservar o arquivo original bloqueado, sua referência, seus vínculos com registros comerciais e os históricos legítimos** (IN-042/074), **sem exclusão automática** por detecção de ameaça. Quando resumos e outros registros históricos contiverem conteúdo extraído do arquivo bloqueado, **restringir o acesso aos segmentos associados** de maneira adequada, até decisão específica de rastreabilidade e apresentação dos trechos; não eliminar indiscriminadamente informações empresariais legítimas.
- **Revalidar autenticação, autorização por organização/arquivo e estado do bloqueio em cada acesso**, evitando vazamento por caminhos indiretos e respeitando a política de acesso autenticado (IN-039/040), sem links públicos reutilizáveis. Propagação a derivados deve preservar isolamento por empresa.
- **Desbloqueio dos conteúdos derivados somente quando o anexo puder ser desbloqueado com segurança conforme IN-072**, com revalidação dos derivados antes de reutilizá-los e sem restaurar objetos legitimamente excluídos (IN-042/043). Registrar ocorrências e ações de contenção em auditoria técnica mínima, sem logar conteúdo sensível ou criar auditoria comercial de visualizações/downloads (IN-041).
- **A detalhar:** rastreabilidade e classificação de trechos derivados em resumos de IA e históricos, mecanismos de invalidação de cache distribuído, reconstrução segura de índices, cancelamento de tarefas, tempos máximos de propagação e testes de ausência de acesso indireto.
- **Somente planejamento e documentação; nenhuma programação autorizada.**


## IN-076 — Rastreabilidade das fontes e restrição seletiva de conteúdo derivado por IA (APROVADA em 01/11/2026)

- Registrar, nas saídas de **agentes de IA** que utilizem anexos e outras fontes, **proveniência rastreável** entre o resumo, trecho ou unidade de conteúdo gerado e suas fontes identificáveis: organização, recurso de origem, anexo e versão/identificador de conteúdo quando aplicável, sem inserir cópias desnecessárias de dados confidenciais nos metadados. Aplicar segregação por empresa, permissões e acesso mínimo necessário.
- Se uma **fonte/anexo for bloqueado por suspeita ou ameaça de segurança**, propagar a restrição de IN-070–075 aos **trechos de resumos, históricos e demais produções de IA derivados dessa fonte**, de forma seletiva, impedindo visualização, exportação, busca, consulta por IA, integrações e reutilização do material protegido. A restrição deve ser aplicada nos serviços de acesso e não apenas como ocultação visual.
- **Preservar e manter acessíveis as informações realmente independentes do anexo afetado**, desde que os usuários tenham autorização atual. Quando a proveniência do material misto for insuficiente para distinguir com confiança as partes derivadas da fonte bloqueada, **bloquear a seção ou todo o resumo envolvido**, sem fingir precisão que não existe.
- **Preservar históricos empresariais legítimos, resumos, metadados de proveniência e versionamento**, com as restrições necessárias, **sem exclusão automática** de registros em razão do bloqueio. Respeitar a política de exclusão e retenção aplicável, incluindo IN-042/043, sem restaurar anexos legitimamente excluídos; a preservação não autoriza retenção ilimitada além de regras aplicáveis.
- Manter trilha de **auditoria técnica** de fontes relacionadas, alterações de estado, bloqueios seletivos, causa e resultados, com proteção contra acesso indevido, minimização de dados e sem auditoria comercial individual de visualizações/downloads (IN-041).
- **Bloquear o acesso indireto** via caches, índices, embeddings, extração, automações, integrações ou agentes de IA; interromper tarefas que tentem incorporar ou republicar os trechos bloqueados, em alinhamento à IN-075.
- **Pendências de especificação:** granularidade e estrutura da proveniência, retroatividade para históricos antigos sem fontes registradas, reconstrução e revalidação após desbloqueio seguro, tratamento de fontes alteradas/deletadas, testes de autorização, custos de índices/metadados e possíveis riscos de texto misto impossível de separar.
- **Somente documentação de planejamento; nenhuma programação autorizada.**


## IN-077 — Recuperação controlada de derivados de IA após desbloqueio seguro (APROVADA em 01/11/2026)

- Quando o anexo de origem for **desbloqueado pelos critérios aprovados na IN-072**, considerar elegíveis à recuperação os conteúdos de IA antes restritos por IN-075/076, **sem liberar automaticamente tudo apenas porque a fonte voltou a estar acessível**.
- Antes de cada restabelecimento, **revalidar o estado atual de segurança da fonte, sua identidade e versão, proveniência dos trechos, integridade dos artefatos derivados e permissões atuais do usuário/organização/recurso**, inclusive no serviço de consulta/entrega. A validação da fonte deve respeitar as verificações de segurança e a autorização humana quando requerida na IN-072.
- **Restabelecer o acesso apenas aos trechos e representações efetivamente verificados como seguros**, preservando partes independentes e proibindo acesso indireto a derivados ainda restritos por busca, índices, cache, integrações, exportação, automações ou agentes de IA (IN-074–076).
- Se o conteúdo antigo estiver desatualizado, inconsistente com a versão atual da fonte ou não puder ser recuperado com segurança, **regenerar os trechos quando necessário e tecnicamente possível** a partir de fontes ainda existentes, aprovadas e autorizadas, sem sobrescrever silenciosamente versões anteriores; registrar nova versão e sua proveniência. A regeneração não pode ignorar limites, permissões, aprovações e controles de consumo de IA já definidos.
- **Manter as versões históricas antigas com acesso restrito** quando sua segurança, correspondência de fonte ou integridade não puder ser confirmada. Preservar histórico legítimo e rastreabilidade, sem exclusão automática por falha de validação e **sem reintroduzir anexos legitimamente excluídos** (IN-042/043).
- Registrar **auditoria técnica protegida** de bloqueio, condições de revalidação, fontes e versões utilizadas, regeneração, liberação de trechos e motivos de restrição, minimizando dados confidenciais e sem gerar logs comerciais de visualização/download (IN-041).
- **Pendências:** políticas detalhadas de reconciliação, critérios objetivos de integridade de derivados, custo/aprovação de regenerações com IA, avisos aos usuários e política de tratamento de registros antigos sem proveniência suficiente (proposta IN-078).
- **Apenas documentação de planejamento; nenhuma programação autorizada.**


## IN-078 — Reconstrução gradual da rastreabilidade de resumos antigos ou importados (APROVADA em 01/11/2026)

- **Resumos, conteúdos gerados por IA e históricos antigos ou importados**, criados antes dos mecanismos de proveniência da IN-076, poderão passar por **reconstrução gradual da rastreabilidade das fontes**, sem exigir regeneração geral indiscriminada nem alterar silenciosamente o texto original ou seu histórico de versões.
- **Registrar uma fonte como verificada somente quando houver evidências confiáveis e auditáveis** que associem a informação gerada ao anexo/documento/registro de origem, inclusive à versão correta quando possível. Correlações aproximadas, sem prova suficiente, devem ser explicitamente **marcadas como hipótese ou origem desconhecida**, nunca promovidas automaticamente a proveniência confirmada. Não inventar vínculos ou declarar segurança com base em confiança do modelo.
- **Sinalizar origens desconhecidas ou não verificadas** em metadados protegidos; preservar o isolamento por organização, a autenticação e a autorização por usuário/registro/anexo. Proteger esses metadados para que a rastreabilidade não vaze informações restritas de outros recursos.
- Quando um **anexo/fonte potencialmente relacionada** for bloqueado por motivo de segurança (IN-070–075), aplicar **restrição preventiva proporcional** ao conteúdo antigo que possa depender dessa fonte: restringir seletivamente trechos separáveis de modo confiável ou, se não houver separação segura, **bloquear a seção ou todo o resumo potencialmente afetado**, sem liberar conteúdo incerto por IA, busca, índices, exportações, cache, automações ou integrações (IN-075/076).
- **Preservar resumos, versões e históricos comerciais legítimos sem exclusão automática** por falta de proveniência ou por bloqueio de fonte, respeitando regras de retenção, exclusão legítima (IN-042/043) e desbloqueio seguro (IN-072/077). Preservar conteúdos efetivamente independentes da fonte bloqueada quando essa independência puder ser demonstrada com segurança.
- Registrar **auditoria técnica** sobre evidências utilizadas, alterações de status de proveniência, restrições e decisões, minimizando dados sensíveis. O procedimento deve ter limites de recursos e consumo de IA, sem presumir processamento ilimitado, cobrança extra ou uso de créditos sem política aprovada.
- **Pendências:** padrões de evidência e níveis de confiança, ordem de priorização dos legados, gatilhos de reconstrução, necessidade de revisão humana em casos inconclusivos, custo/consumo de IA, política para fontes alteradas ou excluídas, testes de segregação.
- **Somente documentação de planejamento; nenhuma programação autorizada.**


## IN-079 — Reconstrução gradual de proveniência com prioridade de segurança (APROVADA em 01/11/2026)

- A reconstrução de proveniência de resumos antigos e importados definida na IN-078 será **gradual**, sem exigir processamento integral e imediato de todo o histórico. A prioridade será determinada sobretudo por **risco concreto de segurança e necessidade autorizada**.
- **Prioridade elevada:** conteúdos potencialmente associados a anexos bloqueados, incidentes de segurança, suspeitas relevantes de exposição ou outras necessidades de contenção. Aplicar imediatamente restrições necessárias de IN-070–078, **independentemente** de a reconstrução detalhada das fontes ainda estar pendente.
- **Prioridade intermediária:** solicitações de reconstrução por usuários e responsáveis técnicos **devidamente autorizados**, após validação de permissões por organização, resumo e fontes. Sem tratamento privilegiado que comprometa contenção de riscos elevados.
- **Prioridade baixa:** resumos legados sem risco conhecido e sem solicitação específica, processados progressivamente conforme capacidade técnica disponível. Na ausência de evidência confiável, classificar a proveniência como desconhecida/não verificada; não atribuir origens inventadas nem liberar dados restritos.
- Aplicar **limites técnicos de carga, CPU, memória, concorrência, orçamento e processamento** para evitar saturação do CRM, prejuízo às operações e consumo descontrolado. Mecanismos e quotas exatos ficam pendentes de especificação.
- **Não debitar automaticamente créditos comerciais de IA do cliente por verificações, contenções e rastreabilidade indispensáveis à segurança da plataforma.** Manter rastreabilidade de custos operacionais internos; eventuais análises ou regenerações opcionais que gerem cobrança/créditos exigirão política comercial e autorização explícitas, sem alteração implícita dos contratos. Não presumir processamento de IA irrestrito ou gratuito para solicitações opcionais.
- Preservar **auditoria técnica protegida**, controles de acesso e isolamento entre empresas, versões e históricos legítimos, restrições proporcionais e exclusão somente sob a política aplicável (IN-039–043/070–078). Não permitir acesso por IA, busca, exportação ou integrações a conteúdo bloqueado.
- **Pendências:** dimensionamento de filas/prioridades, limites e orçamentos, responsabilidade por custos de reconstruções opcionais, critérios de autorização e transparência de status ao usuário.
- **Somente documentação de planejamento; nenhuma programação autorizada.**


## IN-080 — Reconstruções de proveniência por IA exclusivamente para segurança (APROVADA em 01/11/2026)

- **Não disponibilizar reconstruções opcionais de proveniência por IA para clientes** como funcionalidade de enriquecimento, melhoria comercial ou reprocessamento voluntário de resumos antigos/importados, **mesmo se o cliente possuir créditos comerciais ou plano pago**. Esta decisão encerra a hipótese de cobrança ou contratação de reconstruções opcionais por IA aventada na IN-079, sem alterar outros recursos de IA do CRM.
- Permitir reconstrução de proveniência com IA **exclusivamente quando necessária a controles de segurança** da plataforma (IN-075–079), como investigar fontes potencialmente contaminadas, conter exposição por anexos bloqueados, comprovar independência de trechos e reconstruir gradualmente a rastreabilidade dos legados enquanto controle de segurança. A prioridade continua baseada em risco, com processamento de baixa prioridade do passivo que integra o programa de segurança; solicitações de usuários autorizados só orientam priorização quando houver finalidade legítima de segurança, não criam uma opção comercial nova.
- **Não debitar créditos comerciais de IA do cliente** por essas verificações ou reconstruções obrigatórias e não gerar compras, cobranças ou consumo automático de franquias de IA em consequência delas. O custo operacional é técnico/interno da plataforma e sujeito a monitoramento, limites de CPU, memória, processamento, concorrência, taxa de utilização e orçamento, a detalhar.
- **Falhas e limites de processamento não podem causar liberação insegura:** manter restrições a fontes ou trechos incertos, auditoria técnica e rastreabilidade; não usar sem evidência confiável uma associação como fonte verificada, conforme IN-076–079.
- Preservar autenticação e permissões por empresa, versões e históricos legítimos, dados estruturados e medidas de bloqueio de conteúdo, **sem exclusão automática**; não restaurar anexos legitimamente excluídos (IN-039/042/043/070–079).
- **A definir posteriormente:** orçamento interno e limites por prioridade, critérios objetivos de necessidade de segurança, mecanismos de fila, recuperação de falhas do processamento de proveniência e acompanhamento técnico. Nenhuma reconstrução opcional de proveniência por IA está aprovada.
- **Somente documentação de planejamento; nenhuma programação autorizada.**


## IN-081 — Recuperação automática controlada das reconstruções obrigatórias de proveniência por IA (APROVADA em 01/11/2026)

- Para reconstruções de proveniência de resumos antigos/importados **necessárias à segurança** (IN-076–080), manter **fila técnica persistente e segura**, isolada por empresa, com estados rastreáveis e operações idempotentes para evitar reprocessamento ou associação duplicada de fontes.
- Em **falha temporária do modelo, serviço de IA, infraestrutura ou processamento**, aplicar **retentativas automáticas controladas e limitadas**, com intervalos adequados e limites técnicos de concorrência, recursos e orçamento operacional interno. Não executar ciclos indefinidos de repetição nem promover resultado inconclusivo a proveniência verificada.
- **Priorizar incidentes críticos e tarefas ligadas a bloqueios ativos**, em detrimento de reconstruções de baixa prioridade, respeitando a política da IN-079. Quando houver indisponibilidade sistêmica ou limite técnico atingido, preservar tarefas pendentes na fila e pausar/reduzir processamento de prioridade inferior; alertar equipe técnica Imagine diante de falhas persistentes, atrasos ou incidentes críticos.
- **Retomar processamento de maneira gradual** após verificar a recuperação funcional do serviço e a capacidade disponível, com revalidação de autorizações e estado atual das fontes. A retomada **não desbloqueia automaticamente** conteúdos de fonte suspeita, não verificados ou com risco não resolvido: manter as restrições de IN-070–078 até as condições de desbloqueio seguro da IN-072/077.
- **Preservar resumos, versões, históricos legítimos, trilhas de auditoria e isolamento empresarial**, sem exclusão automática por falha do processamento, nem restauração de anexos excluídos legitimamente. Fontes sem prova confiável permanecem marcadas como desconhecidas/não verificadas (IN-078).
- **Não debitar créditos comerciais de IA dos clientes** nem gerar cobrança implícita pelo processamento obrigatório de segurança, conforme IN-079/080. Contabilizar custos técnicos internos, com orçamento/limites seguros e sem comprometer os controles de contenção.
- Registrar **auditoria técnica protegida** de tentativas, falhas, retomadas, versões de modelo/procedimento quando aplicável e resultados, sem exposição do conteúdo protegido nos logs. Alertas técnicos seguem IN-045–047 no que for compatível com essa rotina.
- **Pendências:** número máximo de tentativas, estratégia de retentativa, critérios de recuperação funcional, limites operacionais e tratamento dos itens que esgotarem as tentativas (proposta IN-082).
- **Somente documentação de planejamento; nenhuma programação autorizada.**


## IN-082 — Fila técnica para falhas persistentes da reconstrução obrigatória de proveniência por IA (APROVADA em 01/11/2026)

- Após **esgotar o número limitado de retentativas automáticas** permitido pela IN-081, **suspender automaticamente somente a tarefa de reconstrução de proveniência afetada**, evitando repetições indefinidas, loops de custo ou bloqueio de outras tarefas independentes.
- **Encaminhar a tarefa a uma fila técnica de falhas persistentes**, com estado explícito, prioridade de segurança, registro do erro/causa conhecida, contagem de tentativas, identificadores técnicos mínimos e horários relevantes; proteger a fila por autenticação e autorização da equipe técnica Imagine e por isolamento entre empresas, minimizando conteúdo sensível.
- **Alertar a equipe técnica Imagine** de acordo com a gravidade e regras de monitoramento, evitando alertas duplicados; permitir diagnóstico e tratamento das causas com trilha de **auditoria técnica** de alterações, responsáveis, justificativas e resultados.
- **Preservar imediatamente os bloqueios e restrições já aplicados** a anexos, trechos e resumos com risco não resolvido (IN-070–078). Falha persistente não transforma origem desconhecida em verificada, **não autoriza desbloqueio** e não exclui automaticamente arquivos, resumos, versões ou históricos legítimos (IN-042/043/075–079).
- Permitir **reprocessamento controlado da tarefa após correção comprovada da causa ou intervenção/autorização da equipe técnica habilitada**, sempre com novas verificações de permissões, estado de segurança das fontes e idempotência. Autorização técnica é para reiniciar o processamento, **não para contornar detecções, ignorar falhas sem solução ou liberar conteúdos sem verificação**; preservar fila e prioridades da IN-079/081.
- **Não consumir créditos comerciais de IA do cliente** por diagnósticos, retentativas e reconstruções obrigatórias de segurança (IN-079/080). Aplicar controles internos de infraestrutura, concorrência e custo, sem provocar cobrança automática.
- **Pendências:** classificação das causas e severidades, prazos de triagem, critérios exatos de correção e reentrada em fila, limites de novas tentativas após reprocessamento e notificações técnicas. Apenas documentação e planejamento; nenhuma programação autorizada.


## IN-083 — Classificação automática das falhas persistentes com supervisão humana (APROVADA em 01/11/2026)

- Nas tarefas de reconstrução obrigatória de proveniência por IA encaminhadas à fila técnica da **IN-082**, classificar automaticamente as falhas **por causa e gravidade/prioridade**, com base em sinais verificáveis, registrando evidências e justificativas sem inventar diagnóstico ou atribuição de causa.
- Utilizar categorias iniciais: **(1) falha técnica recuperável** (ex.: serviço temporariamente indisponível); **(2) limitação de recursos/capacidade**; **(3) evidência insuficiente para identificar a fonte**; **(4) risco elevado ou incidente crítico de segurança**; **(5) falha de integridade/inconsistência da origem, do derivado ou de suas versões**. Admitir estado **inconclusivo/não classificado** quando os dados não sustentarem uma categoria.
- Quando uma **falha estritamente técnica tiver causa efetivamente corrigida e validações suficientes**, permitir **reprocessamento controlado**, respeitando filas, prioridades e limites técnicos das IN-079/081/082, com novas checagens de autenticação/autorização, integridade, versão/estado das fontes e idempotência. Classificação como recuperável, isoladamente, **não comprova que a causa foi corrigida**.
- **Exigir supervisão e avaliação de integrante autorizado da equipe técnica Imagine** para incidentes ou riscos críticos, falhas de integridade e casos inconclusivos, antes de definir o tratamento, reprocessamento excepcional ou encerramento técnico. Não confundir avaliação humana da tarefa com autorização para ignorar ameaças ou desbloquear material restrito, o que continua regido pelas IN-070–077.
- **Preservar bloqueios e restrições de segurança**, históricos, versões e metadados legítimos; não promover origens desconhecidas a verificadas, não excluir registros automaticamente e não permitir que uma tarefa com falha impeça outras operações independentes.
- Registrar **auditoria técnica protegida** com classe, prioridade, sinais disponíveis, status, tentativas, responsável pela avaliação, autorizações, reprocessamentos e resultado; minimizar dados sensíveis e alertar a equipe Imagine proporcionalmente à gravidade, evitando notificações redundantes.
- Essas tarefas são controles obrigatórios de segurança e **não consomem créditos comerciais de IA dos clientes** (IN-079/080). Aplicar limites internos de custo e capacidade.
- **Pendências:** regras objetivas de severidade e prioridade, catálogo versionado de códigos de falha, limiares de encaminhamento, critérios de correção verificada e prazos de triagem (próxima proposta IN-084).
- **Somente documentação de planejamento; nenhuma programação autorizada.**


## IN-084 — Severidade e escalonamento automáticos baseados em risco (APROVADA em 01/11/2026)

- Classificar as falhas persistentes da reconstrução obrigatória de proveniência por IA (IN-081–083) em **quatro níveis: CRÍTICA, ALTA, MÉDIA e BAIXA**. Considerar **evidências verificáveis, risco real ou potencial de exposição, impacto sobre integridade e proteção dos conteúdos, urgência e persistência**, com justificativa compreensível e histórico da classificação. Não inventar causa, risco ou impacto.
- **CRÍTICA:** sinais concretos de risco ativo ou grave de exposição de conteúdos protegidos, falha grave dos controles de segurança ou incidente de elevada urgência; **alerta técnico imediato**, triagem prioritária e escalonamento à equipe Imagine autorizada, sem aguardar a rotina comum.
- **ALTA:** falha significativa de integridade, proteção importante comprometida ou incidente relevante ainda não resolvido que demande atenção prioritária; encaminhamento e escalonamento técnico proporcionais.
- **MÉDIA:** falha persistente que exija intervenção, sem sinais que justifiquem urgência maior; atendimento na fila técnica conforme prioridade.
- **BAIXA:** reconstrução legada ou outra tarefa sem impacto imediato ou risco identificado, sujeita a processamento/tratamento gradual segundo a capacidade técnica.
- **Inconclusão não equivale automaticamente a severidade crítica**: classificar provisoriamente conforme sinais disponíveis, solicitar revisão humana quando necessário e reavaliar à luz de novas evidências. A classificação baixa/média ou inconclusiva **jamais remove bloqueios de segurança** já aplicados nem transforma proveniência desconhecida em fonte verificada.
- Permitir à equipe técnica Imagine autorizada **revisar a severidade mediante justificativa e trilha de auditoria**, sem alterar ou contornar as condições independentes de bloqueio/desbloqueio definidas nas IN-070–077. Registrar alterações de prioridade, evidências, notificações, responsáveis e transições, com proteção e minimização de dados sensíveis.
- **Alertar imediatamente incidentes críticos** via canais internos técnicos da Imagine; escalonar ocorrências altas, médias e baixas de forma proporcional à gravidade e persistência, com deduplicação e sem envio indiscriminado de informações ao cliente. Preservar isolamento por organização, históricos legítimos, recursos técnicos e idempotência (IN-079–083).
- Estes controles permanecem **sem consumo de créditos comerciais de IA dos clientes** (IN-079/080). **Pendentes:** limiares quantitativos, prazos de primeira resposta e resolução, gatilhos de reescalonamento e rota de plantão (proposta IN-085).
- **Somente planejamento/documentação; nenhuma programação autorizada.**


## IN-085 — Metas internas de primeira resposta e escalonamento por severidade (APROVADA em 01/11/2026)

- Definir **metas internas de primeira resposta humana (triagem inicial)** para falhas persistentes de reconstrução de proveniência por IA (IN-081–084), distintas de resolução do incidente e de garantias contratuais:
  - **CRÍTICA:** meta de **até 30 minutos**, **somente quando estiver estruturada e efetivamente ativa uma cobertura técnica 24 horas por dia, 7 dias por semana**. Até lá, registrar a meta planejada, lacuna de cobertura e necessidade de implantação, **sem afirmar um plantão existente ou resposta garantida em 30 minutos**.
  - **ALTA:** meta de **até 4 horas úteis**.
  - **MÉDIA:** meta de **até 1 dia útil**.
  - **BAIXA:** meta de **até 3 dias úteis**.
- Contabilizar a primeira resposta humana a partir de um marco auditável de registro e classificação acionável, sujeito a especificação dos detalhes de contagem, calendário de horas úteis, mudanças de severidade, feriados, contatos e responsabilidades de plantão.
- Utilizar **avisos internos e escalonamento técnico proporcionais** à gravidade, prioridade e extrapolação de metas, com deduplicação e histórico protegido do momento da detecção, classificação, acionamentos, primeira resposta e eventuais atrasos. Manter alertas **imediatos** para incidentes críticos conforme IN-084, independentemente da cobertura humana já ter sido implantada.
- **Bloqueios, contenção e regras de não liberação de conteúdo inseguro permanecem automáticos** e independentes da resposta humana (IN-070–084). A ausência de plantão ou atraso de triagem não autoriza desbloqueios, exclusões ou alteração de proveniência não comprovada.
- Metas são **objetivos operacionais internos**, **não prazos de resolução nem SLA contratual** e não autorizam comunicar disponibilidade 24/7 sem equipe, escala e procedimento efetivamente definidos.
- **Não consumir créditos comerciais de IA dos clientes** por tratamento obrigatório de segurança (IN-079/080). Preservar auditoria, autorizações, isolamento empresarial e históricos.
- **Pendências:** dimensionamento de plantão 24/7, calendário oficial de expediente técnico, papéis de acionamento e substituição, mecanismo de medição e escalonamento, revisões e validação operacional. **Somente planejamento/documentação, nenhuma programação autorizada.**


## IN-086 — Implantação futura de plantão técnico 24/7 (APROVADA em 01/11/2026)

- **Não implantar nem considerar operacional neste momento plantão humano 24 horas por dia, 7 dias por semana**, seja por sobreaviso, seja por turnos dedicados. Essa cobertura fica para uma etapa futura de planejamento e validação operacional.
- A **meta interna de primeira resposta humana de até 30 minutos para incidentes CRÍTICOS** da IN-085 permanece **condicionada** à efetiva implantação de cobertura técnica 24/7; **não está vigente**, não pode ser comunicada como garantia de atendimento, SLA contratual ou compromisso humano existente até a estrutura estar pronta e formalmente validada.
- **Preservar monitoramento contínuo, geração de alertas técnicos, classificação automática de severidade e contenção/bloqueios automáticos de riscos**, independentemente do expediente ou da disponibilidade de uma pessoa, conforme IN-045–048 e IN-070–085. A ausência de plantão não pode remover restrições, desbloquear conteúdos não verificados, permitir exclusões ou comprometer históricos e permissões.
- **Incidentes CRÍTICOS continuam gerando alertas técnicos imediatos** pelos canais já aprovados (painel Imagine e e-mail técnico em situações críticas/persistentes, IN-046/047). O recebimento de alerta não deve ser confundido com confirmação de resposta humana fora do horário coberto.
- **Metas ALTA/MÉDIA/BAIXA de 4 horas úteis, 1 dia útil e 3 dias úteis** permanecem como metas internas condicionadas ao calendário técnico de expediente a detalhar; não prometer primeira resposta humana de 30 minutos fora dele.
- A **ativação futura** do plantão 24/7 exigirá aprovação e documentação de equipe responsável e substituta, escala e disponibilidade real, canais de acionamento, escalonamento, treinamentos, dimensionamento, conformidade trabalhista aplicável e testes periódicos que comprovem a operação e a medição de resposta. Somente após essa validação a meta humana de 30 minutos poderá ser considerada em vigor.
- Tratamento provisório de incidentes críticos detectados fora do expediente, critérios de comunicação e escalonamento até o próximo atendimento humano serão detalhados na proposta IN-087, respeitando os canais existentes e a ausência de garantia de resposta imediata.
- **Somente documentação de planejamento; nenhuma programação autorizada.**


## IN-087 — Incidentes críticos fora do expediente: contenção imediata, triagem humana no próximo expediente (APROVADA em 01/11/2026)

- Para incidentes **CRÍTICOS** relacionados à segurança e reconstrução obrigatória de proveniência por IA, **detectados de madrugada, fins de semana, feriados ou fora do expediente técnico**, executar **imediatamente contenções e bloqueios automáticos apropriados**, inclusive restrição de anexos/trechos suspeitos, preservação da integridade e impedimento de liberação de conteúdos não verificados. As proteções de IN-070–086 não podem depender da presença de funcionários.
- **Gerar alertas técnicos imediatos** pelos canais previamente aprovados (painel administrativo Imagine e **e-mail técnico para incidentes críticos**, conforme IN-046/047), com deduplicação, dados mínimos necessários e preservação de confidencialidade; **alerta enviado não significa atendimento humano iniciado**.
- **Registrar automaticamente a ocorrência como prioridade crítica na fila técnica**, com estado, indícios, horário de detecção, providências de contenção, alertas emitidos e trilha de **auditoria técnica**; preservar sua prioridade para **triagem humana no primeiro período de atendimento técnico disponível**, conforme calendário a ser definido. Tratar outras ocorrências segundo as respectivas regras de severidade (IN-084/085).
- **Não oferecer nem anunciar resposta humana imediata fora do expediente**, pois o plantão humano 24/7 foi adiado na IN-086. A meta de **primeira resposta em 30 minutos** para críticos na IN-085 permanece condicionada à implantação e validação futura da cobertura e **não está vigente**.
- **Preservar bloqueios, permissões atuais, isolamento entre empresas, arquivos e históricos legítimos**, sem exclusão automática, restauração de anexos legitimamente excluídos nem desbloqueios por ausência de técnicos, falhas de IA ou expiração de alertas. A intervenção posterior seguirá as validações e aprovações de segurança aplicáveis (IN-042/043/070–083).
- A triagem do próximo expediente deve identificar o incidente como crítico e registrar início e responsáveis, mas **não constitui prazo contratual de resolução**, nem pressupõe que o horário/calendário técnico já esteja formalmente definido.
- **Pendências:** calendário oficial do expediente técnico (dias, horas, fuso e feriados), início de contagem de metas em horas úteis, regra de primeiro atendimento após feriados e comunicação interna de atrasos, a tratar na IN-088. Sem débito de créditos comerciais de IA por controles obrigatórios (IN-079/080).
- **Apenas planejamento/documentação; nenhuma programação autorizada.**
