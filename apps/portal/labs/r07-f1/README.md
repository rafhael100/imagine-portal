# PORTAL R07-F1 — Prova isolada Z1 hostil / Z2 autoridade / Z3 PostgreSQL

Direção autorizou a PoC R07-F1, Issue #50, com dados fictícios, containers descartáveis do GitHub Actions, PR draft, sem merge, VPS, login ou dados reais. Repositório público: não inserir segredos da Imagine.

## Componentes

- Z1 edge: container Python sem credenciais de banco, com somente handle fictício de ator A, na rede front_net. Sem acesso ao Docker socket.
- Z2 authority: container PHP 8.4/PDO PGSQL, conectado a front_net e data_net; fonte da identidade sintética, vínculo e propriedade de recurso. Expõe exclusivamente GET /v1/resource e POST /v1/effect, sem SQL livre e com sessão/epoch verificados no banco.
- Z3 pg: PostgreSQL 18 com credenciais efêmeras em data_net interna e sem porta publicada; empresas A/B fictícias, FK composta e RLS por comando como defesa adicional DENTRO da Z2.
- Runner CI: controle administrativo somente do ensaio, produz senhas e handles aleatórios por execução, testa revogação, lê contagem de efeitos e elimina volumes ao finalizar.

## Testes negativos e oráculos

| Caso | Evidência esperada |
| --- | --- |
| T00–T02 | Handle falso A correto, edge sem credenciais e sem resolução DNS de pg |
| T03–T04 | Z2 disponível; A acessa recurso de A |
| T05–T06 | B negado com cabeçalhos/actor/tenant/role falsos ou SQLi |
| T07–T09 | Cookie CRM e ausência de sessão negados; endpoint SQL genérico não existe |
| T10 | Efeito em B negado mesmo com claims forjados |
| T11–T12 | Efeito em A permitido uma vez; retry idempotente não duplica |
| T13 | Com sessão A válida, edge hostil ainda pode executar ações permitidas a A — risco T3 conhecido, NÃO resolvido |
| T14–T15 | Após corte por epoch, handle A antigo não pode ler nem gravar |
| Verificação DB | Exatamente um efeito na empresa A, zero na empresa B |

## Limitações deliberadas

- Isto NÃO é autenticador real Portal, prova de MFA, consentimento humano, frontend, servidor de produção, API de clientes ou integração com CRM.
- A ausência de acesso ao banco demonstrada por Docker networking/credenciais é um ensaio de capacidade T2 no contexto Z1. O host GitHub Runner tem poder administrativo; um atacante que comprometa Z2 ou o host foge do escopo.
- A role SQL Z2 ainda consegue alterar variáveis de RLS se Z2 for comprometida. RLS não se tornou imune a SQL arbitrário. LAB-T07 anterior continua válido para sua fronteira original.
- Sem teste de concorrência real de revogação, restauração, latência, canal lateral, captura de credencial Z2 ou T3 completo. O próprio T13 documenta risco remanescente.
- G-ID/G-BB, D02-SEC, D03/RLS, P0 R01-01/02/03/09/10, revisão humana AppSec/DBA e release permanecem ABERTOS. CI verde não aprova produção.
- Sem merge de PR #47/#49 nem desta PoC, sem VPS, sem deploy. A limpeza do laboratório é automática no workflow.
