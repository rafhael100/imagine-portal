# INF-DEV-02 — Destino do backup externo da VPS central de desenvolvimento Imagine

**Data:** 04/11/2026
**Situação vigente:** **DESTINO PRINCIPAL APROVADO EM 04/11/2026 (Cloudflare R2 Standard, jurisdição EU)** conforme [DEC-INF-DEV-02](DEC-INF-DEV-02-DESTINO-R2-EU-APROVADO-2026-11-04.md). Este documento preserva os comparativos; **orçamento/teto, retenção operacional, ferramenta de backup e implementação permanecem não aprovados**.
**Referência:** [INF-DEV-01 — VPS central Leaseweb](INF-DEV-01-VPS-CENTRAL-LEASEWEB-2026-11-04.md).
**Execução:** nenhum bucket, assinatura, backup, despesa ou mudança de infraestrutura foi realizado.
**Detalhamento técnico pré-implantação:** [INF-DEV-03 — plano técnico Restic + R2](INF-DEV-03-PLANO-BACKUP-AUTOMATICO-RESTIC-R2-v0.1-2026-11-04.md) — proposta de rotina, retenção, isolamento, restauração e anti-exclusão; **Restic e parâmetros ainda não ratificados**.

## 1. Destino principal ratificado — implementação pendente

**Cloudflare R2 Standard como armazenamento externo principal, em bucket privado com restrição de jurisdição EU, criptografia no cliente e acesso restrito.** A VPS Leaseweb da Imagine ficará em Amsterdam, mas os backups ficarão em outro serviço e conta administrativa segregada. Jurisdição EU assegura residência dentro da União Europeia, NÃO país/cidade exatos nem distância mínima de Amsterdam. Fonte: https://developers.cloudflare.com/r2/reference/data-location/ .

**Não exige outra VPS.** É serviço de armazenamento de objetos S3 compatível. O software de backup e a rotina de restauração serão responsabilidade da Imagine, depois de autorização.

## 2. Comparação de alternativas

| Provedor | Localização | Preço público de referência | Operação |
|---|---|---|---|
| **Cloudflare R2 Standard** | Restrição de jurisdição UE configurável; localização exata não garantida | **US$ 0,015/GB-mês**, 10 GB-mês gratuitos se disponíveis na conta; tarifas por operações, sem egress | Backup autogerenciado, endpoint S3, criptografia, alerta e retenção configurados pela Imagine |
| **Backblaze B2** | Região EU Central (Amsterdam) disponível | **A partir de US$ 6,95/TB-mês**, 10 GB grátis; franquia egress contratual | Storage S3 gerenciado por outro provedor; rotina de backup autogerenciada |
| **Leaseweb Acronis** | Região de backup escolhida no momento do pedido | **€ 5,50/50GB; € 10/100GB; € 18,25/200GB mensais**, licença inclusa | Sistema de backup gerenciado da Leaseweb, cobrado à parte |

Fontes oficiais: https://developers.cloudflare.com/r2/pricing/ ; https://www.backblaze.com/cloud-storage/pricing ; https://www.backblaze.com/docs/en/cloud-storage-data-regions ; https://www.leaseweb.com/en/products-services/back-up/acronis ; https://kb.leaseweb.com/kb/acronis-backup/backup-acronis-backup-ordering-acronis-backup-from-the-leaseweb-portal/ .

## 3. Custo inicial de R2 — ilustrações brutas

Volume médio mensal efetivamente ocupado pelas cópias, depois de retenção. Antes de franquia gratuita, cobrança de operações e impostos:

| Volume protegido armazenado | Preço mensal bruto Standard |
|---:|---:|
| 50 GB | US$ 0,75 |
| 100 GB | US$ 1,50 |
| 200 GB | US$ 3,00 |
| 300 GB | US$ 4,50 |

A franquia de 10 GB-mês é da conta elegível; não supor que esteja livre se outros projetos já utilizarem R2. Os 300 GB de SSD da VPS não são 300 GB de backups. O armazenamento real será dimensionado por volume, compressão, deduplicação, frequência e retenção. Custos de equipe, monitoramento, eventuais serviços e impostos também devem ser orçados.

## 4. Controles técnicos propostos, ainda não implementados

1. Criar destino **privado**, com conta/permissões segregadas, MFA administrativo, sem URL pública de arquivos. Tokens mínimos por finalidade e projeto.
2. Automatizar **backup diário criptografado no cliente**, com dumps consistentes dos bancos PostgreSQL de cada projeto e volumes persistentes. Código e documentação permanecem também no GitHub.
3. Propor retenção inicial de **30 dias** e monitorar ocupação/consumo; avaliar retenção ampliada para itens irrecuperáveis. A exclusão automática exige ensaio e validação de requisitos de privacidade.
4. Proteger chaves de descriptografia fora da VPS; criar plano testado de restauração completa e pontual, e alertas de falha.
5. R2 oferece **bucket locks** para impedir sobrescrever e excluir objetos durante prazo definido. IMPORTANTE: locks aplicados diretamente ao repositório vivo de Restic podem conflitar com manutenção, prune e expurgo; ensaiar e, se apropriado, usar arquivos imutáveis separados da rotina incremental. Fonte: https://developers.cloudflare.com/r2/buckets/bucket-locks/ .
6. Snapshot Leaseweb gratuito serve como medida auxiliar antes de mudanças críticas; **não substitui cópia externa**.
7. Não usar dados reais de clientes nos ambientes de desenvolvimento. Não confundir backup desta VPS com a política de backups dos ambientes de produção do CRM, Portal ou Universidade.
8. Testar recuperação com o servidor de origem indisponível, para comprovar que o repositório externo e as chaves são realmente independentes.

## 5. Pendências para escolha definitiva

| Marco | Estado |
|---|---|
| Destino R2 Standard + jurisdição EU | **APROVADO COMO DESTINO/ARQUITETURA**, conforme [DEC-INF-DEV-02](DEC-INF-DEV-02-DESTINO-R2-EU-APROVADO-2026-11-04.md) |
| Fornecedor alternativo B2 ou Acronis | **COMPARADO** |
| Conta Cloudflare disponível, permissões, limites de pagamento e jurisdição | **NÃO VERIFICADOS** |
| Volume real protegido e custos de operações | **NÃO MEDIDOS** |
| Teto financeiro em US$/R$, responsável e autorização de contratação | **NÃO APROVADOS** |
| Ferramenta, scripts, cronograma, retenção, alertas e teste de restauração | **NÃO EXECUTADOS** |

**Destino já ratificado:** Cloudflare R2 Standard, jurisdição EU. **Próximas decisões independentes:** dimensionar volume/retensão, definir rotina e ferramenta, confirmar conta Cloudflare, total de custos e teto e obter autorização financeira/operacional explícita antes de qualquer ativação. Esta escolha não autoriza Gate C da Universidade ou programação do CRM.
