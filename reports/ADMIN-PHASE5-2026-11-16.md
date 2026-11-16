# Imagine Performance AI — Fase 5 — candidata integrada

Data: 16/11/2026. DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.

## Escopo e versões

PR Portal #917, base #909. A integração é uma candidata em branch própria, não merge nas branches dos produtos nem em main/dev.

| Origem | SHA verificado |
|---|---|
| Portal #909 Performance | fce965f76b96647c86ba0985b49ac48a0fc03021 |
| Portal #908 Automações | 12d08d2e2af36b8011bc520d55c5e9cdbcccc108 |
| Portal #910 regressão sem MFA | 9eb947f455bd78805b66c8b94c064b48331bca9b |
| Portal #914 preparação descartável | c87f39c8c761c9a9c64d13d14bf45de889061a32 |
| Performance #419, pin do motor preservado | 0fec80aa81a81a103481664dd568f79bd85ce588 |
| Primeira árvore consolidada | 2a43bcbc93122cd3c6f370ecc6566ae2fc3954ae |
| Correções de autorização | ae6ca013541761875509d2291ed25bed92451c65 |
| Código final candidato | 0afe8be4c836ef0ccf652d991d5f61e210f7e181 |

Inventário integral dos arquivos desta integração relativo à #909: `ADMIN-PHASE5-INTEGRATION-INVENTORY.json`, com SHA-256 e tamanho. O inventário da base completa e do motor continua nos relatórios da Fase 4. Frentes comerciais/Sites paralelas não foram incorporadas sem análise de seus contratos.

## Consolidação

Os conflitos reais em AppServiceProvider.php, config/database.php e routes/web.php foram resolvidos preservando os dois produtos:

- Bindings e controles de disponibilidade próprios, sem conceder Performance por permissão de Automação/CRM/SEO.
- Conexões `performance_authority` e `automation_authority` separadas, sem fallback para senha de bootstrap e com verify-full fora do CI isolado.
- Rotas privadas, flags e controladores próprios; não habilitados operacionalmente.
- Migrations incrementais 000019 (Automação) e 000020 (Performance) coexistem, testadas no mesmo PostgreSQL descartável. Nenhuma migration na VPS.
- Follow-ups #910 e #914 preservados. O preparador local de cluster da #914 não foi aplicado indevidamente a Docker/VPS. O ensaio integrado usa um preparador específico do serviço CI, confrontando configuração Laravel efetiva, login SQL e system_identifier real do contêiner; duas negativas verificam ausência da tabela migrations antes de migrar.

## Achados e correções do implementador

1. O digest da autorização não vinculava a referência exata do contrato nem a identidade da concessão. Agora inclui contract_ref, source_kind e grant.id, além do digest anterior com versões, sessão e MFA. Regressão altera contrato sem mudar versão e exige recusa sem audit completed.
2. O registro de conclusão relia a autoridade sem comparar com a concessão original. Agora recebe expectedLease e recusa alteração antes de gravar completed. Regressão altera revision entre concessão e conclusão.
3. O primeiro ensaio integrado não habilitava os quatro testes HTTP de AutomationAdministrationTest. A flag específica do CI foi adicionada; resultados finais abaixo distinguem os testes contextuais de HTTPS executados em etapa própria.

Essas correções são revisão do próprio implementador, NÃO parecer independente.

## Evidências de execução

HEAD de código testada: `0afe8be4c836ef0ccf652d991d5f61e210f7e181`, motor pinado `0fec80aa81a81a103481664dd568f79bd85ce588`.

- [Ensaio integrado 43959034148](https://github.com/rafhael100/imagine-portal/actions/runs/43959034148): aprovado, job 133621161802. Suíte conjunta: 72 testes aprovados, 362 assertions, um teste contextual HTTPS omitido nesta etapa e executado separadamente em seguida.
- Duas execuções do teste HTTPS integrado: uma com processos separados e outra com receiver real FPM/Nginx e PostgreSQL TLS; cada execução aprovada com 1 teste/16 assertions. Não contar o mesmo cenário repetido como cobertura distinta.
- Navegador: 14 verificações aprovadas, login nominal com MFA, seleção de empresa, resposta do receiver separado, desktop/mobile e ausência de achados serious/critical da ferramenta de acessibilidade. Não equivale a parecer humano independente de UX.
- [P20 PostgreSQL 43959050154](https://github.com/rafhael100/imagine-portal/actions/runs/43959050154), [autenticação F132 43959050274](https://github.com/rafhael100/imagine-portal/actions/runs/43959050274), [central administrativa 43959050164](https://github.com/rafhael100/imagine-portal/actions/runs/43959050164), Laravel F1 e as duas execuções Node: aprovados na mesma HEAD. F138 Docker staging ainda em acompanhamento na elaboração deste relatório; não é apresentado como aprovado antes da conclusão.
- Artefato `performance-phase5-integrated-evidence`, id 14273650534, digest `sha256:bd51a9f8827e96acb1697252dcf6405c00fc55f0cffb89189e114707a1691559`, disponível na execução integrada, expiração informada 23/11/2026. Inclui evidências geradas pelo ensaio; não divulgar certificados/segredos de laboratório como credenciais de uso.
- Diff local sem erros de whitespace. Não havia PHP/Docker local adequado para executar essa jornada; execução comprovada ocorreu no GitHub Actions.

Os erros SQL de permission denied nos testes negativos são esperados: os usuários runtime não podem ler tabelas do outro produto nem consultar/alterar auditorias fora de suas concessões. Não são ignorados para obter verde.

O fluxo de navegador utiliza senha e TOTP nominais de laboratório, seleção de empresa autorizada, autoridade persistida do Portal, comunicação privada para aplicação Performance separada, resposta e correlação. Não usa mocks para representar o servidor Performance no ensaio FPM/Nginx/PostgreSQL TLS. Identidades, certificados e dados continuam exclusivos do laboratório, não credenciais operacionais.

## Identidade, contratos e fontes

A estrutura nativa persistida relaciona administrador, epoch de sessão, MFA recente, empresa, serviço, contrato, papel, operação, validade e revogação. O vínculo de contrato exato foi reforçado nesta entrega. Duas concessões de produtos diferentes não são intercambiáveis; credenciais SQL distintas são testadas por acesso negado cruzado.

A fonte habilitada para demonstração continua `synthetic_persisted`. `portal_contract_authority` não é promovida a autorização real por flag. Falta homologar a autoridade oficial de empresas/contratos/serviços, os responsáveis pelas concessões e a custódia dos certificados.

Google/Meta: reutilizados os contratos e leitores de laboratório da #419, com consentimento por conta, limites de paginação, moeda/período/fuso e decimais seguros. Não existe nova consulta autenticada às APIs oficiais nem OAuth homologado. Métricas indisponíveis continuam indisponíveis. ChatGPT Ads permanece bloqueado. Nenhuma escrita em anúncios.

Financeiro: preservada a matriz versionada `reports/ADMIN-PHASE4-FINANCIAL-DECISIONS.md` do motor #419, sem duplicá-la nem escolher alternativa. Baseline R$490/canal, 20% sobre mídia verificada e mínimo R$170 quando contratualmente aplicável. Direção precisa decidir mínimo integral/proporcional, datas elegíveis, suspensão/reativação, reimplantação e ajustes de extrato. Exemplos já registrados: 10/30 dias e R$300 de mídia resultam em R$170 (mínimo integral) ou R$60 (mínimo proporcional). Asaas/C6 sem novas credenciais homologadas/consultas/transações nesta fase.

## Leaseweb — observação real somente leitura

Nova consulta no console autenticado em 16/11/2026, após o inventário anterior; executados apenas date, free -h, df -h, docker ps, docker compose ls, docker network ls, docker volume ls, ss -ltn e nproc. Sem leitura de segredos, mudanças, instalação ou restart.

- 8 CPUs; RAM total 23 GiB, cerca de 22 GiB disponíveis; sem swap. Disco raiz 296 GiB, 11 GiB usados, 273 GiB disponíveis (saídas arredondadas).
- Oito projetos Compose, 18 contêineres em execução: CRM f64, CRM private journey, Portal development DB, Portal f64, Portal private auth, purchase preview, Sites p0 staging e customer simulation.
- Listeners loopback observados: 18080, 18081, 18082, 18083, 18084, 18085, 18086 e 18090. CRM 18081 e Sites 18090 preservados. Listeners públicos preexistentes 22/80/443 e listener loopback 36713 observados, sem atribuir novos serviços a eles.
- Redes dos produtos continuam distintas no inventário nominal, inclusive Sites db_private/ingress/s2s_private/web_private. Cinco volumes nomeados listados: CRM PG/Redis, Portal development DB, purchase data e Sites database. A listagem nominal não prova políticas de firewall, ausência de bind mounts ou backup externo.
- Nenhum projeto/volume/rede Performance criado. Nenhuma porta escolhida ou reservada; revalidar no momento da instalação autorizada. A stack candidata do motor não publica portas de host.

Pacote `deploy/private-admin/compose.yaml` da #419 reaproveitado: PostgreSQL próprio, duas redes internas, volume próprio PG18, sem rede externa compartilhada/soquete Docker/auto-migrations. Tetos receiver 0,5 CPU/256 MiB; proxy 0,25 CPU/128 MiB; PG 1 CPU/768 MiB; total 1,75 CPU/1152 MiB, sem incluir Portal. São limites candidatos, não dimensionamento homologado por carga. A capacidade atual não autoriza implantação.

## Pacote para revisão independente e GO/NO-GO

Não há parecer independente emitido por esta entrega; as consultas de reviews #909/#908 retornaram vazias. A revisão separada dos follow-ups P20 não substitui revisão da árvore integrada #917 ou do motor #419.

| Gate | Estado / ação requerida |
|---|---|
| Integração e regressão em CI | Ver resultados acima; revisar a árvore exata e inventário |
| Revisão independente | Responsável distinto do autor deve revisar #917 e #419: autorização, banco, S2S, infraestrutura e UX |
| Auditoria de recusas precoces | Ainda exige decisão/revisão: recusas antes de alcançar a consulta de concessões não produzem necessariamente registro na auditoria do produto. Não inserir eventos genéricos em tabela restrita de segurança sem desenho aprovado |
| Revogação concorrente | Revalidações e regressões implementadas; não afirmar atomicidade global entre autoridade, rede e gravação de auditoria em transações separadas. Revisar janela residual e política de serialização antes de operar |
| Empresas/contratos reais | Autoridade operacional, concessões e administradores nominais ainda não homologados |
| TLS/mTLS operacional | CA, SANs, CRL, rotação, custódia, certificados próprios e DSN verify-full precisam ser provisionados pelo mecanismo seguro; certificados CI não podem ser reutilizados |
| Rede/HTTPS privado | Definir acesso autorizado Portal→Performance e restrição administrativa sem anexar redes dos outros produtos indiscriminadamente; domínio privado e MFA nominal pendentes |
| Banco/runtime | Bootstrap migrador separado, RLS e SQL runtime mínimos; revisar grants efetivos, images imutáveis e migrations na candidata antes da aplicação autorizada |
| Backup externo | Destino externo autorizado, criptografia e custódia não comprovados nesta rodada; não presumir que volume local seja backup |
| Restauração e rollback | Teste isolado de PG, chaves, configurações e inbox/outbox com RPO/RTO; pin de imagem/código e procedimento de reversão precisam ser aprovados e evidenciados; não apagar volumes |
| Monitoramento | Logs limitados/sanitizados no Compose; falta destino operacional e alarmes/retencão/operador responsável |
| APIs oficiais/financeiro | Credenciais homologadas e decisões comerciais pendentes; não bloqueiam demonstração sintética somente leitura se demais gates aprovados |
| Instalação | NO-GO: autorização específica de deploy não concedida; não há nova URL implantada |

Próxima ação executável: revisão por responsável independente sobre #917/#419, tratamento dos achados e preparação dos materiais operacionais sob custódia autorizada. Trabalho técnico de integração concluído nesta rodada não elimina esses gates externos. Nenhuma aprovação humana foi simulada.
