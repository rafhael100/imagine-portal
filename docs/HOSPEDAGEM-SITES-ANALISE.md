# Imagine — Análise comparativa de hospedagem dos Onepages

> **Análise técnica preliminar · 2026-11-01 · HD-001 e IN-001 APROVADAS; PROVEDOR DE HOSPEDAGEM AINDA PENDENTE**
>
> Este documento compara cPanel/WHM, VPS própria com deploy automatizado e plataforma estática gerenciada. Não autoriza migração, contratação, deploy ou alteração de DNS. Vinculado a `docs/PRODUCAO-INTELIGENTE.md`, decisão AD-001 de alta automação.

## 1. Premissas do negócio e da operação

- Produto inicial: **Onepage responsivo**, com identidade visual, serviços, contatos e integração de WhatsApp; domínio fornecido pelo cliente.
- **IN-001 APROVADA como diretriz de produto (2026-11-01):** Onepage e futura Landing Page deverão ter formulário de captação de leads integrado ao **CRM Imagine**, com provisionamento/vínculo do Plano Básico para o comprador. Recursos/preço/condições do pacote e produto Landing Page ainda pendentes. Não é hospedagem de caixa postal.
- **HD-001 APROVADA em 2026-11-01:** a assinatura do Onepage inclui hospedagem do **site**, SSL, backup do projeto/site e suporte técnico conforme contrato; **não inclui hospedagem de e-mail profissional nem caixas de correio**. O cliente mantém seus e-mails onde preferir. Não confundir hospedagem de caixa postal com envios transacionais feitos pelo portal Imagine.
- Implantação: R$ 990 em três cobranças de R$ 330 (D0/D30/D60); assinatura R$ 79,90/mês a partir do D90, independentemente de publicação. Site, hospedagem, SSL, backups e suporte conforme oferta; projetos legados preservados.
- Agente de IA desenvolve e testa em ambiente **isolado**, sem executar código do cliente no servidor de produção.
- Cliente aprova versão/artefato exato, backend verifica gates e serviço técnico efetua deploy; apenas depois de teste real da URL registra `published`.
- O portal Imagine permanece separado logicamente do código/artefatos de clientes e de credenciais de deploy.
- Inventário prévio informado de dedicado atual: WHM/cPanel 110, CentOS 7, cerca de 125 GB RAM, 48 processadores lógicos, 10 TB totais (7,2 TB livres), orçamento global até R$ 1.000/mês incluindo cPanel, compartilhado com sites existentes e projetos Talci, CRM e n8n. Esse inventário é contextual e requer conferência antes de decidir.
- **Restrições técnicas atuais**: o ambiente CentOS 7/cPanel 110 permanece em suporte estendido, com atualização/migração recomendada. Não instalar containers/execução arbitrária de agentes diretamente nesse host legado.

## 2. Modelos avaliados

### A — WHM/cPanel em dedicado modernizado

Como funcionaria: backend Imagine registra pedido → orquestrador valida → sandbox IA cria artefato estático → WHM API cria conta isolada (ou conta já provisionada) → publicador restrito envia build para diretório por cliente → configura domínio/SSL, valida entrega. WHM API pode criar contas via `createacct`; tokens com ACLs devem seguir privilégios mínimos. O AutoSSL do cPanel pode usar Let's Encrypt (versões suportadas). Um cliente = uma conta cPanel é preferível a múltiplos clientes na mesma conta para isolar arquivos.

Vantagens: reaproveita hardware e licenciamento já pagos; experiência administrativa, FTP, hospedagem PHP/MySQL, e-mail e domínios no mesmo ecossistema; DNS A/AAAA pode apontar domínio raiz de outro DNS sem migrar nameservers; conta separada por cliente.

Desvantagens: a execução dos agentes não deve estar dentro do WHM; nível de isolamento entre contas e plugins exige auditoria; um dedicado continua sendo ponto de falha de muitos sites; upgrades e backups ficam sob responsabilidade da Imagine; cada conta consome licença; mais complexidade para testes de preview e rollback imutável.

Preços de referência cPanel Store **2026** (USD, contratação direta, antes de impostos/variação cambial): Premier Metal **US$ 69,99/mês** para até 100 contas e **US$ 0,49/mês por conta adicional**. Exemplo de 500 contas totais: US$ 265,99/mês de licença, antes do custo do dedicado. Licenciamento via provedor pode diferir.

### B — VPS própria moderna para hosting com deploy automatizado

Como funcionaria: agentes em workers separados produzem estáticos; publicador entrega um pacote assinado/imutável para host Linux moderno (Nginx/Caddy, diretórios/usuários segregados) ou um PaaS self-hosted (Coolify/Dokploy) em servidor separado da aplicação Imagine. Se houver necessidades PHP/DB, podem-se usar ambientes isolados mais robustos.

Vantagens: controle da pilha, contratos e DNS; sem taxa de licença cPanel por cliente; A/AAAA no domínio raiz sem obrigar mudar nameservers; deploy scriptável e rollback planejável; mover site para outro servidor é relativamente direto.

Desvantagens: Imagine cuida de SO, patches, firewall, backups offsite, monitoramento, TLS, isolamento, resposta a incidentes e failover. Não confundir uso de Docker/Coolify com segurança automática: reduzir privilégios de API e evitar que workers IA tenham SSH privilegiado. Em VPS pequena, dezenas de containers com bancos/serviços são mais pesados que servir centenas de sites HTML estáticos por um servidor web.

Referência DigitalOcean 2026: Basic Droplet 2 vCPU/4 GiB **US$ 24/mês**; 4 vCPU/8 GiB **US$ 48/mês**, sem extras. Backups básicos da DO custam adicional de 20% (semanais) ou 30% (diários). São pontos de partida, **não dimensionamentos certificados nem custo total**. Coolify self-hosted não cobra licença, mas recomenda host novo, com mínimo de 2 CPU/2 GiB/10 GiB, e a Imagine continua responsável pela infraestrutura.

### C — Plataforma gerenciada de sites estáticos / edge

**Inicial (até 100 projetos/account, conforme política atual): Cloudflare Pages por cliente**, com Direct Upload de build pré-compilado pelo serviço Imagine/CI e publicação de prévia distinta da produção. Operação dos servidores e CDN é gerenciada. Requisições de assets estáticos não têm cobrança de requests/transferência como Functions; uso dinâmico é separado.

Vantagens: operação de sites estáticos, HTTPS, CDN global, prévias e APIs de deploy; sem manter VPS de front-end para os Onepages; escala de tráfego da borda é simplificada. Portabilidade preservada caso template gere HTML/CSS/JS sem acoplamento excessivo.

Limitações reais: **Cloudflare Pages limita 100 projetos por conta** (documentação atual, limite não rotineiramente ampliado). Limites de builds no plano Free: 500/mês, com apenas um build concorrente quando integração nativa é usada; também existem limites de tamanho/número de arquivos. Prévia de Pages é pública por padrão: exigir autenticação via Cloudflare Access ou proxy de visualização controlado pelo portal. Atenção à impossibilidade de alternar depois o mesmo projeto Direct Upload para integração Git sem recriá-lo.

**Domínios:** Cloudflare Pages exige zona/nameservers Cloudflare para configurar o domínio raiz (`empresa.com.br`) diretamente no Pages. Um subdomínio como `www.empresa.com.br` pode ser configurado por CNAME em provedor externo. Para clientes que não queiram mudar nameservers, resolver e documentar redirecionamento do domínio raiz ou usar outro modelo de hospedagem. Não alterar MX, SPF, DKIM ou demais configurações de e-mail sem inventário; migração de DNS pode interromper e-mails.

**Crescimento além de 100 sites:** não supor 1 projeto Pages/cliente indefinidamente. Alternativas: Cloudflare Workers for Platforms + Cloudflare for SaaS (roteamento por hostname, isolamento por worker e SSL para hostnames personalizados), ou VPS própria com publicador multitenant de estáticos. Workers for Platforms custa **US$ 25/mês** de base, inclui 1.000 scripts e possui métricas de requests/CPU; Cloudflare for SaaS inclui 100 custom hostnames e cobra **US$ 0,10 por hostname extra**, sujeito a limites/regras. É outro produto, com implementação e verificação de DNS/segurança adicionais, não uma migração automática e gratuita de Pages.

## 3. Comparativo operacional

| Critério | A: cPanel modernizado | B: VPS própria | C: estático gerenciado |
| --- | --- | --- | --- |
| Investimento incremental (existe dedicado) | potencialmente baixo; licença por conta extra | VPS/backup/monitoramento externos | muito baixo em estáticos até limite; custos de funções/custom domains/escala |
| Atende PHP, MySQL e e-mail | sim | sim, com implantação/administração própria | não diretamente, exigiria serviços externos |
| Onepage HTML/CSS/JS | sim | sim | especialmente adequado |
| Autonomia de deploy | via WHM/UAPI + publicador | via CI/CD + API própria | via Pages API/Wrangler/CI |
| Certificado SSL | AutoSSL/Let's Encrypt | ACME/Let's Encrypt operado pela Imagine | gerenciado, condicionado a DNS |
| Domínio raiz sem mudar nameservers | normalmente sim, por A/AAAA | sim, por A/AAAA | Pages: normalmente exige nameservers Cloudflare para apex |
| Preview e rollback | implementar controle de artefato, versão e troca atômica | implementar orquestração | previsões de deploy integradas; ainda validar consistência da publicação |
| Isolamento | conta por cliente + hardening | filesystem/conta/container por cliente, configuração própria | isolamento por projeto/plataforma; acesso da prévia precisa proteção |
| Operação/patch do host | responsabilidade Imagine | responsabilidade Imagine | responsabilidade do provedor no hosting, mas orquestrador continua Imagine |
| Teto técnico conhecido | hardware/licença/carga/admin | tamanho da VPS, armazenamento e concorrência | Pages 100 projetos/account; planejar Workers for Platforms para escala |
| Ponto único de falha | dedicado | VPS única | plataforma externa + dependência do provedor |

## 4. Exemplos de orçamento (valores públicos e aproximações)

**Não são orçamento fechado nem conversão para BRL**; verificar preços, moeda, tributos, câmbio, quantidade de contas atuais, egress, backups e uso antes de contratar.

- cPanel Premier Metal: 25, 100 ou 500 contas **totais** → US$ 69,99; US$ 69,99; US$ 265,99/mês, respectivamente, somente licença (preços públicos, não necessariamente os já pagos pelo provedor).
- VPS DigitalOcean 2 vCPU/4 GiB: US$ 24/mês + US$ 4,80/semanais ou US$ 7,20/diários; backups externos, edge, gestão e observabilidade não incluídos. 4 vCPU/8 GiB: US$ 48/mês + backup.
- Cloudflare Pages: hosting de assets estáticos e requisições de assets sem cobrança de requests; projetos/conta até 100. Operações da plataforma e uso de funções podem ter custos/limites. **Não extrapolar esse valor para 500 projetos Pages.**
- Workers for Platforms em arquitetura própria para escala: base US$ 25/mês, com uso adicional; Cloudflare for SaaS cobra custom hostnames acima de 100 a US$ 0,10 por mês cada. Exemplo **apenas de componente de hostname**, 500 clientes com 1 hostname cada = 400 excedentes = US$ 40/mês, além do Workers e outros serviços. Com `www` + apex, a contagem e complexidade podem aumentar.
- Custos **não incluídos**: execução Codex/IA, criação de templates, revisões, armazenamento dos originais, backup de código e arquivos, notificações, suporte, antifraude/abuso, observabilidade, migrações, serviços extras, tributos e mão de obra.

## 5. Modelo de domínio e e-mail — risco crítico (HD-001 aprovado)

**IN-001:** o formulário será incorporado ao site estático (HTML/JS) e enviará dados a um endpoint seguro de ingestão da Imagine; serviço de integração armazena/encaminha os leads à organização correta por API servidor-servidor do CRM Imagine. **Não é necessário hospedar e-mail para receber leads.** A captação exige backend/API separado e proteção antispam, fila persistente e LGPD. Detalhes em [INTEGRACAO-CRM-IMAGINE.md](INTEGRACAO-CRM-IMAGINE.md).

**HD-001:** o serviço contratado não disponibiliza caixas postais, MX, webmail, IMAP/SMTP para os clientes. A presença de DNS/e-mails externos não impede a contratação nem a publicação do site. **O portal da Imagine pode utilizar o próprio serviço de e-mail transacional para informar status de pedidos**; esse envio não equivale a e-mail profissional do cliente.

Separar **registrador**, **zona DNS**, **hosting do site**, **servidor de e-mail** e **portal Imagine**. O cliente fornece domínio; a Imagine apenas solicita alterações necessárias. Nunca alterar nameservers automaticamente sem autorização e inventário de MX/TXT/CAA; nem apagar configurações de serviços existentes. Para uma VPS/cPanel, `A`/ `AAAA` no domínio raiz e `CNAME` para `www` são caminhos típicos. Pages: apex exige zona/nameservers Cloudflare; subdomínio permite DNS externo por CNAME. Para domínio sem acesso do cliente, publicar só prévia e suspender a entrega no domínio definitivo.

Fluxo de configuração: identificar domínio e titular → conferir DNS existente → enviar instruções ou solicitar autorização específica → verificar propriedade e resolução → provisionar HTTPS → executar deploy de artefato aprovado → validar `www` e raiz, redirecionamentos e e-mail sem alterações indevidas → só então registrar entrega.

## 6. Segregação dos quatro ambientes

1. **Portal Imagine:** negócio, pedidos, mensagens e aprovações.
2. **Workers de desenvolvimento IA:** geram código em sandbox sem segredos de produção.
3. **Preview:** versão/artefato fixo, acesso controlado, sem indexação, registro de URL e expiração.
4. **Produção:** publicador recebe somente artefato aprovado, com credenciais mínimas por cliente e logs.

O custo mensal de hosting do site não substitui o custo do ambiente de build. Cada execução Codex deve ser medida por projeto, versão, tempo e tokens.

## 7. Estratégia técnica sugerida, ainda sem aprovação

- **Curto prazo:** testar Onepage estritamente estático e prototipar deploy em plataforma gerenciada (Cloudflare Pages) **e** em servidor moderno próprio, comparando DNS, SSL, rollback, acesso privado à prévia, gasto e integração. Testar três domínios distintos: DNS Cloudflare, DNS externo com `www`, domínio sem autorização.
- **Médio prazo:** escolher por critérios mensuráveis, não apenas menor preço de hosting. Não colocar agentes no cPanel 110/CentOS 7. Se o cPanel continuar recebendo novos sites, migrar o host para SO/cPanel suportados antes da operação autônoma.
- **Longo prazo:** para mais de 100 sites, usar arquitetura escalável real (Workers for Platforms/Cloudflare for SaaS ou publicação multitenant em servidores modernizados), sem ficar dependente de contas Pages adicionais não garantidas.
- **Preservar portabilidade:** código-fonte privado, builds reproduzíveis, exportação completa, versionamento e rotina de rollback independentes do host.

## 8. Matriz de decisão ainda pendente

1. **IN-001 aprovou WhatsApp + formulário de leads ligado ao CRM Imagine**: site pode continuar estático, mas o formulário depende de API externa segura e da plataforma CRM separada. Permanecem pendentes: campos, recursos do plano e condições comerciais. Login/CMS/banco no Onepage não estão incluídos nesta decisão.
2. Domínio raiz (`empresa.com.br`) deve funcionar com **DNS externo** sem trocar nameservers? **E-mails existentes permanecem externos por decisão HD-001**; preservar MX/TXT é obrigatório.
3. Os clientes terão acesso individual ao cPanel/FTP, ou administração/hospedagem fica exclusivamente com a Imagine?
4. Quantos Onepages ativos são a meta em 12–24 meses? (25, 100, 500+). Capacidade e custo devem considerar total de contas cPanel já existentes.
5. É aceitável usar serviços gerenciados externos para sites estáticos, preservando dedicado para Talci, CRM, n8n e sistemas existentes?
6. Qual limite operacional para atividades humanas de manutenção e resposta a incidentes da hospedagem?

**Status:** HD-001 aprovado (sem hospedagem de e-mail profissional) e IN-001 aprovado como diretriz (formulário integrado ao CRM Básico). **Provedor, stack de publicação, regras de domínio e condições comerciais do CRM continuam pendentes**. Nenhuma implantação, compra, domínio ou ambiente alterado.

## 8A. Requisitos decorrentes da decisão HD-001 (aprovados)

- A Imagine é responsável por hospedar o site do Onepage, disponibilizar HTTPS/SSL, manter cópias restauráveis do projeto/site e prestar suporte técnico conforme condições da assinatura.
- E-mail profissional não integra o Onepage. O site não recebe contas postais, webmail, SMTP/IMAP do cliente ou transferência de e-mails.
- O cliente poderá manter e-mail em Microsoft 365, Google Workspace, serviço próprio ou outro prestador; DNS MX, SPF, DKIM e DMARC existentes devem ser preservados quando houver mudanças autorizadas no DNS do site.
- Avisos de contratação, briefing e aprovação são mensagens transacionais do **portal Imagine**; não caracterizam serviço de correio eletrônico do domínio do cliente.
- O modo de captação de contatos do Onepage (**apenas botão WhatsApp ou também formulário**) permanece em aberto: receber um formulário de contato não significa hospedar caixa postal e pode exigir endpoint/API e serviço de entrega seguros.
- Política operacional de backup ainda requer definição de periodicidade, retenção, local offsite, procedimento/testes de restauração e eventual RPO/RTO. Não afirmar garantias de recuperação sem validação técnica.
- A decisão HD-001 **não seleciona Cloudflare, VPS nem cPanel** e não autoriza migração ou delegação dos nameservers de terceiros.

## 9. Fontes de preços e limites

- cPanel Store 2026: https://support.cpanel.net/hc/en-us/articles/30117774089879-2026-cPanel-Store-License-Pricing
- cPanel depreciação: https://docs.cpanel.net/knowledge-base/cpanel-product/cpanel-deprecation-plan/
- WHM criação de conta: https://api.docs.cpanel.net/specifications/whm.openapi/account-creation
- WHM API tokens ACL: https://docs.cpanel.net/whm/development/manage-api-tokens-in-whm/
- Cloudflare Pages limites: https://developers.cloudflare.com/pages/platform/limits/
- Cloudflare Pages direct upload: https://developers.cloudflare.com/pages/get-started/direct-upload/
- Cloudflare Pages domínios: https://developers.cloudflare.com/pages/configuration/custom-domains/
- Cloudflare Pages preview: https://developers.cloudflare.com/pages/configuration/preview-deployments/
- Cloudflare Workers static assets: https://developers.cloudflare.com/workers/static-assets/billing-and-limitations/
- Cloudflare Workers for Platforms preços: https://developers.cloudflare.com/cloudflare-for-platforms/workers-for-platforms/reference/pricing/
- Cloudflare for SaaS preços: https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/plans/
- DigitalOcean Droplets: https://www.digitalocean.com/pricing/droplets
- DigitalOcean backup: https://docs.digitalocean.com/products/backups/details/pricing/
- Coolify: https://coolify.io/docs/start-with-self-hosted
