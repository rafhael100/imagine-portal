# Imagine P0 — consolidação e homologação operacional (sem publicação)

## Resultado desejado para conclusão

Na infraestrutura privada da Leaseweb, executar uma jornada **cliente real, autorizada e auditável**: site Comercial -> escolha do produto/valor -> login ou cadastro de empresa verificada no Portal -> contrato/consentimento -> pagamento autorizado pelo provedor -> pedido assinado -> Sites (somente Onepage e Landing Page) -> processamento idempotente -> briefing -> status no Portal -> emissão fiscal e monitoramento. O painel do administrador tem permissões específicas, sem compartilhar sessão do cliente.

**Esta revisão não entrega a operação acima.** Os testes compilados com Chromium/Laravel/PostgreSQL usam contas, aprovações e pagamentos fictícios e contêineres efêmeros do GitHub. A autorização real de criar e publicar exige evidência independente.

## Candidatas congeladas para revisão

| Frente | Candidato | O que está comprovado | O que não está |
|---|---|---|---|
| Comercial | [PR #374](https://github.com/rafhael100/imagine-comercial/pull/374) | menus desktop/mobile, quatro CTAs, build Astro, testes privados | DNS/HTTPS/login real, oferta contratável e checkout ativo |
| Portal | [PR #725](https://github.com/rafhael100/imagine-portal/pull/725) | contas individuais fictícias, pedidos/briefings no PG18, proteção RLS, pós-restart, 2 encaminhamentos simulados ao Sites | IAM/MFA, empresa real, PSP assinado, API S2S e migração da instalação atual |
| Sites (candidata reconciliada) | [PR #470](https://github.com/rafhael100/imagine-sites/pull/470) | 9/9 workflows na mesma SHA: HTTP Portal PG18→Sites Laravel/Nginx TLS verify-full, Ed25519 com testemunhas fictícias independentes, migrations RLS e rollback | ainda faltam adaptadores de PSP/revogação/IAM reais, publisher e migração na Leaseweb |
| Origem HTTP da reconciliação | [PR #463](https://github.com/rafhael100/imagine-sites/pull/463) | conteúdo funcional F1-381 restaurado e teste corrigido na #470 | **oito commits históricos ainda não são ancestrais** da #470; manter revisão de origem/patch antes de união de branches |

## Duas verificações antes de qualquer merge

1. A CI `portal-p0-candidate-readiness.yml` lê **ao vivo** as três PRs, verifica a SHA exata, os nomes dos workflows exigidos, o resultado da última execução, a relação com a base e a divergência das linhas Sites. Não confunde **PASS dos testes** com autorização de produção.
2. O inventário já existente `portal-f144-integration-inventory.yml` percorre o histórico **Git completo**, incluindo arquivos fora dos 300 itens limitados na API REST. Seus pacotes são evidência de revisão, não aprovação. A questão [#341](https://github.com/rafhael100/imagine-portal/issues/341) controla a dívida de integração.

A reconciliação funcional da linha Sites #463 com a candidata #470 passou nos workflows da mesma SHA, mas a **ancestralidade Git dos oito commits originais permanece divergente**. O relatório deve continuar exibindo `DIVERGED_RECONCILIATION_REQUIRED` até a revisão independente do histórico; CI verde não substitui rastreabilidade e autorização de promoção. Não fazer merge por número da PR nem usar árvore que descarte silenciosamente diferenças.

## Pré-homologação Leaseweb — sequência obrigatória

**Gate A — Inventário sem mudanças:** verificar estado de `/srv/imagine/purchase-preview`, disponibilidade dos contêineres, versão do Docker/Compose, espaço livre, memória, portas, DNS interno, TLS, certificados e backup existente. Não usar portas públicas novas nem revelar segredos em logs.

**Gate B — Backup e migração reversível:** exportar e verificar restore separado do PostgreSQL, volumes, configurações e dados existentes; congelar revisões e migrações; definir rollback testado com critérios de reversão. A `prepare.py` do checkout anterior recusa sobrescrever `.private`; respeitar esse bloqueio.

**Gate C — Staging paralelo:** criar domínio **privado** e rede isolada, identidade e credenciais únicas, novo PostgreSQL e contêineres independentes. Não reutilizar chaves HMAC fictícias nem copiar o banco da instância antiga sem plano de migração. Usar firewall, TLS válido e autenticação forte. Cloudflare e DNS públicos não são pré-requisitos para testar a rede privada.

**Gate D — End-to-end operacional:** validar catálogo/preços, empresa/CNPJ, consentimento, sessões e MFA, contratos, comprovante PSP assinado, idempotência e reconciliação de pagamento, integração Portal→Sites com autenticação mútua e revogação, emissão fiscal, outbox e worker, painel do cliente/admin e notificação. Testar falha, retry, ACK perdido, backup/restore, observabilidade e rollback. Requer revisão independente de segurança, DBA e requisitos da LGPD.

**Gate E — Liberação controlada:** após evidências e autorização específica, promover lotes de commits revisados à branch protegida; canário privado; aprovar janela de publicação; observação; rollback disponível. **Sem auto-merge, auto-deploy, compra real ou publicação.**

## Não confundir "pronto" com CI verde

O relatório do workflow pode aprovar a **integridade dos testes e dos pins** enquanto sua decisão continua `NO_GO`. Os seguintes são bloqueadores reais, não sugestões cosméticas: identidade do cliente e empresa, PSP oficial com webhook verificado, aceite contratual, autorização S2S assinada e revogável, separação de papéis, revisão de privacidade, backup e migração recuperáveis, teste HTTPS autenticado na Leaseweb e publicação segura do Sites.

**Estado atual: candidata de revisão, não produção.** A conexão GitHub não executa comandos SSH e não fornece acesso à configuração da VPS. Nenhum dado ou segredo de cliente deve ser colocado em issue/PR.
