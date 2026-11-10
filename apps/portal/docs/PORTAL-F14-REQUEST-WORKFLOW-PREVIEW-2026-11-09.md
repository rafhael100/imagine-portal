# PORTAL-F14 — Solicitações do cliente: fluxo de estados isolado

Data: 09/11/2026. Estado: implementação em branch de desenvolvimento, **não operacional**.

## Propósito

Preparar o fluxo de solicitações/atendimento do Imagine Portal de forma independente
do motor Imagine Sites, do CRM e do Performance AI. Os serviços são referenciados
por identificadores opacos; este módulo **não implementa** criação, entrega,
edição ou publicação de sites e não acessa códigos nem bases de outros motores.

## Entregue neste recorte

- Estados tipados: rascunho, enviada, em análise, aguardando cliente, resolvida e encerrada.
- Papéis de fluxo (cliente e equipe Imagine) e ações explicitamente enumeradas.
- Máquina de estados de *pré-visualização*, sem efeito colateral.
- Registro de revisão esperada para identificar tentativas de transição sobre
  snapshots desatualizados **no preview**, sem garantia de concorrência no banco.
- Escopo por identificador da organização do Portal, comparado no preview.
- Snapshot e resultado imutáveis; nenhum método executa ou autoriza gravações.
- Testes para fluxo nominal, revisões divergentes, empresa divergente,
  estados finais, transições proibidas, referências inválidas e bloqueio de escrita.

## Matriz de fluxo proposta

| Estado atual | Quem atua no fluxo | Ação | Próximo estado |
| --- | --- | --- | --- |
| Rascunho | Cliente | Enviar | Enviada |
| Enviada | Equipe Imagine | Iniciar análise | Em análise |
| Em análise | Equipe Imagine | Pedir informações | Aguardando cliente |
| Aguardando cliente | Cliente | Complementar informações | Em análise |
| Em análise | Equipe Imagine | Resolver | Resolvida |
| Resolvida | Equipe Imagine | Reabrir | Em análise |
| Resolvida | Equipe Imagine | Encerrar | Encerrada |

Estado encerrado não aceita novas transições neste recorte.

## O que **não** está homologado

A implementação é apenas uma **proposta de transição de negócio**; passar
neste teste **não prova a identidade, o vínculo de empresa, os direitos de
operar o serviço, a titularidade do chamado nem autorização para gravar**.

Antes de abrir rotas reais, ainda precisam de decisões e provas independentes:

1. Identidade autenticada em sessão própria do Portal, MFA quando aplicável
   e autorização derivada de autoridade confiável **no backend**.
2. Membership, titularidade do recurso e serviço contratado verificados em
   PostgreSQL do próprio Portal, com RLS e barreiras contra contexto forjável.
3. Idempotência durável, gravação transacional, controle de concorrência real,
   auditoria, limites de volume e política de retenção/LGPD.
4. Antirreplay, revogação, CSRF, tratamento de anexos, antispam, rate limit,
   observabilidade, backup/restauração e respostas sem vazamento entre empresas.
5. Contratos claros com motor Sites para eventual encaminhamento de chamados
   sem compartilhar banco, autenticação ou autoridade de pagamento.
6. Homologação de segurança, UX e processo de atendimento humano.

**Gates que continuam abertos:** CORE/SEC/P0, autenticação real, contratos
Portal–Sites, operações financeiras e publicação. Esta branch não altera
`main`, rotas, migrations, controllers, filas, DNS, VPS, Cloudflare,
cobranças ou dados de clientes. Sem autorização para merge ou deploy.

## Verificação

O PR executa os workflows de validação Laravel PHP 8.3/8.4 e Node legado
quando o GitHub Actions estiver disponível. Não declarar PASS sem observar
resultados na HEAD exata. Testes existentes de rota fechada devem permanecer
verdes.
