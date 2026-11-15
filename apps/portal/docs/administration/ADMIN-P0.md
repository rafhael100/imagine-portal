# Central Administrativa Imagine Performance AI — ADMIN-P0-01 a 10

## Diagnóstico e bases fixadas

Portal RC49 / PR887 `82624818832ee04b64111154fd551e2cca34c27e`: login administrativo real Laravel senha+TOTP, epoch de sessão, revogação e trilha de segurança. O shell autenticado anterior não tinha operação de Performance. O demo-dashboard era uma prévia de cliente, com navegação ilustrativa e sem mutações. PR836 `df50af02ddb28edb85fb33aacb1dcb4adf7edfb2`: recibo de apresentação/aceite V7 é laboratório PG18, não UI legal ou autorização operacional.

Performance PR410 `3b34173ea3aa368b7db3b748bdae099f2540102e`: motor e contratos V7/PG18, sem IAM humano ou API publicitária homologada. Nova dependência ADMIN-P0-01 / PR412 `238bcb026aca0a02d90c0ebff38fb2d4f35d8e1f`: contrato `imagine.performance.admin.v1`, funções puras de comando e referência financeira V7. Nenhuma PR foi confundida com produção ou alterada para incorporar esta frente.

| Reutilização | Lacuna atendida / limite |
| --- | --- |
| PortalAdminMfaGuard + FeatureGate + guard portal-admin | Mesmo login humano, MFA, sessão revogável; nenhuma identidade Performance duplicada |
| Marca assets/logo-imagine.png | Logotipo original copiado ao public Laravel; nova shell laranja/cinza, sidebar e tabelas móveis |
| PERF-08 / 10 / 11 | Aprovação por versão/digest/tempo, estado+fonte explícitos e incidente sem apagamento |
| V7 MonthlyManagementFeeFloorV7Candidate do motor | Referência por canal; não é fatura ou receita recebida; parcial pendente |
| Contratos Portal/Performance existentes | Adaptador local de código pinado para testes; S2S operacional não implementado nem declarado |

## Funcionalidade

Dez módulos em `/portal-admin/performance/{module}`: dashboard, clients, channels, campaigns, agents, approvals, finance, reports, incidents e settings. Seletor de empresas contém somente vínculos ativos/não expirados do administrador. Detalhes do cliente com oito abas e links para as consultas por contexto. Campanhas, canais, agentes, aprovações e incidentes com busca, canal, situação, ordem e paginação de dez registros; objetivos nas campanhas. Histórico dos cinquenta eventos mais recentes do tenant. CSV das campanhas do contexto com os mesmos filtros de busca, canal, situação, objetivo e período; PDF indisponível.

Dashboard diferencia contagem do contexto atual de empresas autorizadas, dados sintéticos de ausência de conexão, gestão estimada de receita recebida. Não há ROI/ROAS/CPA inventado. ChatGPT Ads aparece indisponível e não recebe autorização de leitura/escrita nem cobrança. Informações de contrato e finanças são fictícias; extratos, gateways e métricas reais indisponíveis.

Comandos POST de laboratório: pausa/retomada de agente interno ativo/pausado, aprovar/rejeitar/revogar proposta e consumir exclusivamente recibo interno por outro operador, reconhecer incidente e resolver somente se a evidência do cenário for verificada. Aprovação de orçamento ou financeiro **nunca** habilita execução. N2/N3 são descritos, sem elevação de autonomia ou execução externa.

## Permissões e persistência

| Papel | Consulta | Financeiro | Agentes / aprovações | Incidentes |
| --- | --- | --- | --- | --- |
| Superadmin | Sim | Sim | Internos, sem bypass | Reconhecer / resolver com evidência |
| Operações | Sim | Não | Internos | Reconhecer / resolver com evidência |
| Analista | Sim | Não | Não | Reconhecer |
| Supervisor IA | Sim | Não | Internos | Somente leitura |
| Financeiro | Sim | Sim | Não | Somente leitura |
| Auditor | Sim | Sim | Não | Somente leitura |

Novas tabelas exclusivamente `performance_admin_lab_*`; tenants restritos no PostgreSQL a `lab-alfa/lab-beta`. Associações são persistidas por operador/empresa, nunca de cookie ou query. Cada comando bloqueia linha do administrador, vínculo e estado; revalida ativação/epoch/MFA/papel/empresa, passa ao motor, atualiza revisão e insere evento na mesma transação. Chave idempotente única por ator+empresa com hash da requisição; repetição idêntica devolve recibo, divergente retorna 409. Eventos rejeitam UPDATE/DELETE por trigger. Não há formulário de grants ou credenciais. Auth antiga e bancos de outros produtos preservados.

## Execução de demonstração segura

A feature está OFF por padrão e rotas só são registradas em `local/testing` com **ambas** as flags `PORTAL_ADMIN_AUTH_ENABLED=true` e `PERFORMANCE_ADMIN_LAB_ENABLED=true`. Middleware revalida o bloqueio mesmo após registro. Não há rota em produção. O operador usa apenas banco descartável dedicado. Não executar migrations ou fixtures sobre a VPS existente.

Workflow `.github/workflows/performance-admin-central.yml` instala Laravel com lock, fixa código do motor por SHA e hash de dois arquivos, cria PostgreSQL18 descartável, executa testes de isolamento/perfis/replay/revogação, compila Blade, cria identidade CI única com segredo aleatório em arquivo privado temporário, inicia apenas loopback e navega via Chromium após senha+TOTP reais. Os artefatos contêm apenas HTML/PNGs sem credenciais; a identidade é destruída com o runner e o vault removido. Nenhuma instalação Node é exigida do proprietário para conferir evidências.

Para uma sessão web fora do CI ainda é necessária **autorização específica de homologação/publicação**, armazenamento de sessão adequado, provisionamento privado nominal, revisão de permissões, TLS/BasicAuth e aprovação dos gates. Não foi publicada URL operacional nova nesta entrega.

## Contrato e fronteiras

Fonte de identidade/vínculos: Portal. Estado operacional: contrato v1 do motor. Financeiro/cobrança: Portal; provedor é fonte de métricas somente quando houver leitura homologada. O adaptador local verifica hashes e não encaminha tokens humanos. Não contém HTTP, segredos ou conexão de banco Performance. Teste local não é identidade técnica S2S: mTLS/assinatura, idempotência durável distribuída, políticas independentes de revogação e escopo ainda precisam de homologação.

Pendências: IAM/grants operacionais, RLS de produção, revisão independente SEG/DBA/LGPD/Financeiro, contas Ads e ChatGPT autorizadas, conciliação oficial, regra contratual de mês parcial, gateways, PDF, filtros temporais além do único cenário, retenção/exportação de auditoria e prova concorrente multicluster. Nenhuma alçada real inferida. Dados reais, custos e ações externas continuam bloqueados.

DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.
