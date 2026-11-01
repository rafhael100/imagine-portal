# Imagine — Integração Onepage/Landing Page × CRM Imagine

> **Planejamento v0.2 · 2026-11-01 · IN-001 e IN-002 aprovadas; implementação e detalhes do benefício ainda pendentes**  
> Repositório de origem: `rafhael100/imagine-portal`. Documento em branch de planejamento. **Sem implementação, contratação de serviço, cobrança ou alteração em produção.**

## 1. Decisão IN-001 — integração entre produtos (APROVADA)

Quando um cliente contratar um **Site Onepage** ou, futuramente, uma **Landing Page** da Imagine, deverá contar com acesso à assinatura do **Plano Básico do CRM Imagine**, com integração automática do formulário de captação de contatos para a organização correta no CRM.

O site também continuará com botão para WhatsApp. **Formulário de leads e CRM são serviços diferentes de hospedagem de e-mail profissional**, que segue **excluída** da oferta conforme HD-001.

**IN-004 aprovada em 2026-11-01:** benefício CRM Básico inclui **Agenda e Agendamentos**, com detalhes, integrações e cotas a definir. **IN-008 aprovada em 2026-11-01:** no Básico, cada organização CRM terá **1 profissional com agenda própria**. **IN-009 aprovada em 2026-11-01:** até **10 serviços ativos** para esse profissional, por empresa. **IN-011 aprovada em 2026-11-01:** expediente flexível (dias, vários períodos e intervalos), bloqueios manuais de folgas/férias/feriados e agendamento manual excepcional fora do expediente mediante aviso e confirmação de usuário autorizado, sempre sem sobreposição. **IN-012 aprovada em 2026-11-01:** agendamentos criados **manualmente** dentro do CRM Básico não possuem teto comercial mensal rígido, sujeitos a uso justo e controles antiabuso; não autoriza página pública de reserva. Quantidade de usuários, canais de lembretes e regras técnicas de uso justo ainda pendentes. [Agenda Manual CRM Básico](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md). **IN-007 aprovada em 2026-11-01:** no Básico, compromissos são lançados e gerenciados **manualmente dentro do CRM**; **não oferecer botão/link público para o visitante agendar automaticamente no site**. Autoagendamento público pelo Onepage/Landing Page ou página do CRM fica exclusivo aos planos **pagos** do CRM, após verificação de direitos e autorização de configuração. O formulário padrão pode criar lead, **não cria reserva automaticamente**. **IN-005 aprovada em 2026-11-01:** modelos prontos com até **3 automações comerciais ativas simultaneamente por empresa CRM**. **IN-006 (2026-11-01):** biblioteca central será documentada integralmente antes da revisão das quantidades por plano; algumas automações mais avançadas deverão ficar disponíveis apenas nos planos pagos. Os números atuais continuam referências até nova aprovação expressa. [Catálogo central em desenvolvimento](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/10-biblioteca-automacoes-e-criterios-de-planos.md). O catálogo concreto e a agenda pública ainda dependem de aprovação; não significa liberar o construtor genérico de automações. Ver [agenda e automações do CRM Básico](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/09-revisao-agenda-e-automacoes-basico.md).

**IN-003 aprovada em 2026-11-01:** o CRM Básico vinculado ao site será uma **versão enxuta exclusiva**, diferente do plano Essencial do CRM; conjunto de recursos, limites e quotas ainda precisam de aprovação na documentação de produto do CRM. Proposta em [CRM Imagine — Plano Básico cortesia](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/08-plano-basico-cortesia.md).

**Decisão IN-002 aprovada em 2026-11-01:** o CRM Básico será disponibilizado como **benefício gratuito vinculado à contratação ativa de Onepage ou, futuramente, Landing Page**; não cria segunda mensalidade no CRM. O site continua com cobrança própria pelo Portal Imagine. **Quando o cliente contratar um plano superior diretamente no CRM**, a assinatura paga e o faturamento desse plano ocorrerão no CRM, mantendo a assinatura do site no Portal. A gratuidade não é um plano gratuito público aberto a qualquer usuário. **Ainda pendentes:** matriz de recursos/cotas do Básico, correspondência com planos Essencial/Profissional/Empresarial, preço da Landing Page, estados exatos da elegibilidade em cancelamento/inadimplência, e implementação/homologação. O catálogo implementado no portal atualmente possui o serviço `site` (Onepage), **não um produto Landing Page independente**. O CRM Imagine está em planejamento; não marcar como funcionalidade já ativa no checkout antes de integração testada/homologada e condições comerciais aprovadas.

### Decisão IN-013 — Avisos da agenda apenas dentro do CRM Básico (APROVADA)

O CRM Básico Cortesia disponibiliza **notificações e lembretes operacionais internos na própria interface do CRM**, **sem consumir as vagas de automações comerciais ativas**. Não enviar e-mail automático ao cliente agendado nem mensagem pela API oficial do WhatsApp como cortesia do Onepage/Landing Page. Esses canais externos são exclusivos de **planos pagos CRM ou adicionais elegíveis**, mediante contratação, autorização e custos aplicáveis. Essa decisão não afeta avisos de pagamento/produção do **Portal Imagine**, que são processos separados dos lembretes da agenda CRM. **IN-014 aprovada:** lembretes internos do CRM Básico em 24h e 1h antes, ajustáveis/desativáveis individualmente e sem consumir automações. **IN-015 aprovada:** central de notificações do CRM Básico é persistente, com estados lido/não lido e consulta aos lembretes vencidos enquanto o usuário estava ausente; definir posteriormente como marcar lido, retenção e demais preferências. Continua sendo aviso interno, sem push, WhatsApp ou e-mail automático. [CRM — Agenda e IN-013](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

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
