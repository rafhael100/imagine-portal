# INF-DEV-01 — VPS central de desenvolvimento dos projetos Imagine (Leaseweb)

**Data:** 04/11/2026.
**Origem da decisão:** direção determinou: “vamos pegar então uma VPS daquelas da Leaseweb para ser a nossa VPS de desenvolvimento dos projetos”.
**Estado normativo:** **PROVEDORA LEASEWEB ESCOLHIDA EM PRINCÍPIO para uma VPS de desenvolvimento COMPARTILHADA entre projetos Imagine**.
**Tela de configuração da Leaseweb fornecida em 04/11/2026 e conferida**, com VPS 3 em Amsterdam, Ubuntu 24.04, contrato/cobrança mensal e preço-base de € 13,99. **Não há comprovação de carrinho finalizado, contratação, pagamento, ativação, IP designado, instalação ou autorização de programação.** Ainda devem ser confirmados impostos, total do carrinho, termos e autorização financeira.

## 1. Finalidade e limites

Ter **um ambiente central de desenvolvimento** para, conforme autorização específica de cada projeto, desenvolver e testar com dados fictícios Portal Imagine, CRM Imagine, Imagine Sites, Performance AI e Universidade Imagine; adicionar outros serviços Imagine somente mediante revisão de capacidade e risco.

- A VPS de desenvolvimento é **separada dos serviços de produção**, do banco real de clientes e do **servidor central de agentes de IA/n8n**.
- Compartilhar uma VPS de desenvolvimento **não autoriza que aplicativos compartilhem banco, segredos, redes de contêiner ou privilégios**.
- Não utilizar credenciais de produção nem dados reais de clientes. Homologação com dados reais, piloto e lançamento seguem gates próprios.
- O encerramento documental do Gate D da Universidade **não abriu Gate C**. A seleção da infraestrutura não autoriza sua programação. O CRM conserva suas aprovações próprias de planejamento/programação.

## 2. Comparação de pacotes do catálogo Leaseweb

| Plano | vCPU | RAM | NVMe | Mensalidade ilustrativa em Amsterdam |
|---|---:|---:|---:|---:|
| VPS 2 | 6 | 16 GB | 200 GB | € 8,99 sem desconto contratual |
| **VPS 3 — recomendação técnica a aprovar** | **8** | **24 GB** | **300 GB** | **€ 13,99 sem desconto contratual** |
| VPS 4 | 12 | 48 GB | 400 GB | € 25,99 sem desconto contratual |

**Os preços são indicativos, obtidos em fontes de comparação de setembro de 2026, NÃO verificados no checkout atual nem aprovados**. Fonte oficial para catálogo/capacidades: https://www.leaseweb.com/products-services/cloud/virtual-private-server ; configuração candidata: https://www.leaseweb.com/en/configure/vc/product/entityKey/VPS02_3/2001 . Referências de preços: https://rackrate.dev/providers/leaseweb/ e https://rackrate.dev/vps/leaseweb-vps-large/ . Impostos, localização, câmbio, descontos, compromisso e adicionais podem alterar os totais.

**Recomendação técnica atual:** VPS 3 para iniciar múltiplos ambientes de desenvolvimento de baixa/média carga. VPS 2 pode ser suficiente para poucos ambientes leves; VPS 4 somente caso testes de carga simultâneos justifiquem mais RAM/CPU. Não há benchmark do nosso conjunto de aplicativos; a capacidade precisa ser validada após contratação **autorizada**.

**Contratação:** preferir período **mensal sem fidelidade anual**, quando elegível, para observar carga real antes de assumir compromisso longo. A Leaseweb informa upgrade possível, mas downgrade indisponível na oferta VPS consultada. Qualquer preço final exige confirmação visual/documental no checkout da conta contratante.

## 2.1. Evidência direta do configurador Leaseweb (04/11/2026)

O responsável encaminhou **captura de tela do configurador**, com os seguintes itens efetivamente selecionados e exibidos no resumo:

| Campo mostrado | Valor observado |
|---|---|
| Data Center Location | **Amsterdam (AMS-01)** |
| Package | **Leaseweb VPS 3** |
| Cloud Instance | **8 vCPU, 24 GB RAM, 300 GB NVMe Disk** |
| Connectivity | **30 TB Volume** incluído; **1 × 10GE port Virtual** incluído; **1 endereço IPv4 individual** incluído |
| Services | **Basic** incluído; **DDoS IP Protection Standard** incluído |
| Software | **No control panel** incluído; **Ubuntu 24.04** incluído |
| Contract Term | **1 month** |
| Billing Cycle | **1 month** |
| Monthly Base Price / Monthly Fee | **€ 13,99 / € 13,99** conforme tela |
| Setup Fee | **€ 0,00** conforme tela |

**Suporte Basic** selecionado: a tela menciona **response time 12hrs**, telefone 24/7 e substituição de hardware em 24 horas. Serviços opcionais Bronze/Silver/Gold/Platinum foram mostrados como adicionais pagos, **não selecionados**.

**Escopo da evidência:** esse é um **resumo de configuração anterior ao botão “Add to cart”**, não a totalização final do checkout, fatura, pagamento ou contrato. Impostos, taxas aplicáveis, regras de renovação, eventuais add-ons ou conversão para reais **não estão confirmados pela captura**. A expressão “30 TB Volume” se refere à franquia de conectividade na seção indicada, não a backup ou 30 TB de disco. A descrição “10GE port Virtual” não comprova throughput dedicado garantido. O resumo **não mostra uma política de backup externo ou restauração**, que deve ser verificada à parte.

**Parecer técnico sobre a configuração:** manter VPS 3, Amsterdam, Ubuntu 24.04, sem painel, serviço Basic e contratação/cobrança de 1 mês como **configuração recomendada** para desenvolvimento compartilhado, antes de conferir o checkout definitivo. Isto **não** substitui a autorização financeira de compra e de qualquer programação.

## 3. Configuração operacional candidata, não executada

- **Ubuntu 24.04 LTS**, sem WHM/cPanel ou painel pago; Docker Engine e Docker Compose v2 apenas depois de autorização da gestão de infraestrutura. Fonte Docker: https://docs.docker.com/engine/install/ubuntu/ .
- Cada projeto usa stack e rede Docker próprias, banco com usuário/volume segregado e política de permissões de menor privilégio. Para riscos maiores, containers separados não substituem VPS distintas.
- Gerenciar limites de CPU, RAM, processos, disco e logs por projeto; pipeline CI/CD de cada repositório **não publica produção automaticamente**.
- SSH com chaves, MFA onde aplicável, acesso remoto protegido/restrito, uso de segredos fora do Git e firewall externo da Leaseweb complementado por regras no host. Atenção: a publicação de portas Docker pode contornar regras simplificadas de UFW; consultar https://docs.docker.com/engine/network/packet-filtering-firewalls/ .
- Não expor PostgreSQL, Redis, filas ou serviços internos publicamente; TLS e autenticação para interfaces de testes acessíveis externamente.
- Backup criptografado externo conforme risco, documentação de restauração e controle de chaves. Snapshot único incluído no plano não substitui backups independentes.
- Monitorar utilização e alarmes; limitar jobs pesados simultâneos. Se risco de interferência superar o benefício financeiro, migrar somente a carga afetada para ambiente exclusivo mediante decisão e orçamento.

> **Comparação de localização e fornecedores publicada em 04/11/2026:** [INF-DEV-02 — destino de backups externos](INF-DEV-02-DESTINO-BACKUP-EXTERNO-2026-11-04.md). A proposta recomenda **Cloudflare R2 Standard com restrição UE**; **não há ratificação do destino, custo/teto financeiro ou ativação de armazenamento**. Backblaze B2 e Acronis permanecem alternativas.

## 3.1. Backup e recuperação — conferência em fontes oficiais Leaseweb

**Pesquisa documental em 04/11/2026; não houve contratação nem configuração.**

1. **Snapshot:** a Leaseweb inclui **um snapshot gratuito por VPS**, acessível no Customer Portal em `Virtual Private Server > Leaseweb VPS > Manage VPS > Snapshot`. Referências oficiais: https://www.leaseweb.com/products-services/cloud/virtual-private-server e https://kb.leaseweb.com/kb/leaseweb-vps/managing-leaseweb-vps-snapshot/ . **O snapshot não é backup independente.** Pode afetar a disponibilidade da VPS durante criação/restauração; usar sobretudo antes de mudanças críticas e não como única proteção.
2. **Acronis Backup adicional:** serviço da Leaseweb para cópias externas automatizadas, políticas de periodicidade/retensão, arquivos e sistema inteiro. **Não incluído na mensalidade-base VPS 3 da captura.** Preços publicados em https://www.leaseweb.com/en/products-services/back-up/acronis (sujeitos à confirmação de checkout/impostos): **25 GB € 3,75/mês; 50 GB € 5,50/mês; 75 GB € 8,25/mês; 100 GB € 10,00/mês; 200 GB € 18,25/mês; 500 GB € 41,25/mês**. Caminho de contratação: Customer Portal > Acronis Backup > Order Backup; documentação https://kb.leaseweb.com/kb/acronis-backup/backup-acronis-backup-ordering-acronis-backup-from-the-leaseweb-portal/ .
3. **Autogerenciado:** também é possível usar ferramentas gratuitas como `restic` com depósito externo distinto (S3/R2/B2/SFTP) e agendamento pelo servidor. O **software pode ser livre**, mas armazenamento, transferência e manutenção possuem custos e riscos; guardar os backups **somente nos 300 GB da própria VPS não protege contra perda total do servidor**. Referência: https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html .
4. **Conteúdo mínimo para proteger:** arquivos de configuração sem expor segredos, volumes persistentes relevantes, dumps **consistentes** dos PostgreSQL de desenvolvimento, artefatos não versionados, rotinas de restauração e histórico de alterações. O GitHub preserva código versionado, **não** substitui cópias de bancos/volumes/secrets. Não armazenar dados reais de clientes em ambientes de desenvolvimento.
5. **Política técnica candidata:** backup externo criptografado diário dos dados persistentes relevantes, retenção inicial de 30 dias **a dimensionar**, revisão de falhas diária, teste real de restauração periódico, chaves independentes e acesso limitado. Snapshot antes de atualizações de infraestrutura. Ajustar o volume externo pela **quantidade efetivamente protegida e histórico de retenção**, não confundir com disco NVMe de 300 GB.
6. **Recomendação financeira:** para a VPS compartilhada de desenvolvimento, comparar custo total e esforço da proteção **restic + storage externo** versus **Acronis**, privilegiando capacidade efetiva de restaurar. Não contratar Acronis nem storage novo sem confirmar custos e **autorização financeira expressa**.

**Status de backup:** `snapshot gratuito = CONFIRMADO COMO CARACTERÍSTICA DA OFERTA`; `backup externo automático = NÃO CONTRATADO / A DEFINIR`; `preço e franquia Acronis = REFERÊNCIA PÚBLICA, NÃO COTAÇÃO DO CARRINHO`.

## 4. Portas para aquisição e ativação

| Marco | Condição a verificar | Estado |
|---|---|---|
| DEV-01 | Provedora Leaseweb como padrão de VPS central de desenvolvimento | **DEFINIDO EM PRINCÍPIO** |
| DEV-02 | Escolher VPS 3 ou outro plano, região, sistema operacional e contratação mensal | **CONFIGURAÇÃO OBSERVADA E RECOMENDADA**: VPS 3, AMS-01, Ubuntu 24.04, 1 mês, Basic; confirmação de compra pendente |
| DEV-03 | Obter preço final com impostos, moeda, serviços opcionais, prazo e regra de renovação | **PREÇO-BASE CONFIGURADO € 13,99/mês + setup € 0 verificados na captura; TOTAL FINAL COM IMPOSTOS E TERMOS AINDA NÃO CONFIRMADO** |
| DEV-04 | Registrar orçamento/teto, responsável financeiro, autorização de compra e critérios de reembolso/cancelamento | **NÃO APROVADO** |
| DEV-05 | Confirmar aquisição e entrega; validar isolamento com Docker, rede e quotas | **NÃO EXECUTADO** |
| DEV-06 | Preparar e autorizar separadamente o início de programação de cada projeto | **PENDENTE; UNI Gate C NÃO AUTORIZADO** |
| DEV-07 | Homologar capacidade e segurança em testes **após** autorizações adequadas | **NÃO EXECUTADO** |

## 5. Preservação das decisões anteriores

- Universidade: Gate D encerrado **documentalmente**; C-00 é **escopo de planejamento** aprovado; ainda sem orçamento/teto de desenvolvimento nem Gate C. Documento de referência: https://github.com/rafhael100/imagine-universidade/blob/main/docs/00-governanca/DECISAO-UNI-PRE-GATE-C01-ESCOPO-C00-2026-11-04.md .
- CRM: permanecer observando a documentação de segurança, isolamento e aprovação de programação. A adoção do servidor não autoriza alterar CRM ou Portal.
- Este documento não altera decisões dos serviços de produção do Portal, Sites, CRM, Performance AI ou Universidade.
- O servidor central n8n/IA segue separado desta VPS de desenvolvimento, e o custo de um não substitui orçamento do outro.

**Próxima decisão específica:** verificar **total final do checkout, impostos e condições de renovação**, e então obter autorização financeira expressa para comprar o **pacote e região já configurados**. Nenhuma cobrança efetivada ou ação sobre servidores por este documento.
