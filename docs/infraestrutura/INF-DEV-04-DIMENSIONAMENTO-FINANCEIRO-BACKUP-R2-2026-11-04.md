# INF-DEV-04 — Dimensionamento financeiro e implantação controlada do backup da VPS Imagine

**Data:** 04/11/2026.
**Estado vigente:** **APROVADA COMO BASE DOCUMENTAL DE PLANEJAMENTO FINANCEIRO** pela [DEC-INF-DEV-04](DEC-INF-DEV-04-APROVACAO-BASE-DIMENSIONAMENTO-BACKUP-R2-2026-11-04.md). **NÃO É APROVAÇÃO DE VALORES REAIS, TETO FINANCEIRO, COMPRA OU EXECUÇÃO.**
**Origem:** resposta “ok” do responsável à proposta de avançar para a **INF-DEV-04, começando pelo custo previsto para 30 dias de backups**.
**Documentos precedentes:** [INF-DEV-01 — VPS Leaseweb](INF-DEV-01-VPS-CENTRAL-LEASEWEB-2026-11-04.md); [DEC-INF-DEV-02 — R2 Standard EU destino aprovado](DEC-INF-DEV-02-DESTINO-R2-EU-APROVADO-2026-11-04.md); [DEC-INF-DEV-03 — Restic e política-base aprovados documentalmente](DEC-INF-DEV-03-POLITICA-BACKUP-RESTIC-R2-APROVADA-2026-11-04.md).
**Proibições vigentes:** nenhuma compra, criação de bucket, token, instalação, backup real, agendamento, envio de dados ou programação dos produtos foi autorizada por este estudo. Gate C da Universidade NÃO autorizado.

## 1. Tarifas oficiais verificadas em 04/11/2026

Destino aprovado: Cloudflare R2 **Standard**, objetos restritos à jurisdição **EU**; tarifa pública informada pela Cloudflare:

| Unidade de faturamento | Preço público (USD) | Franquia Standard na conta elegível, compartilhada |
|---|---:|---:|
| Armazenamento | US$ 0,015 por GB-mês | 10 GB-mês/mês |
| Operações Class A (gravação/listagem etc.) | US$ 4,50 por milhão de requisições | 1 milhão/mês |
| Operações Class B (leitura etc.) | US$ 0,36 por milhão de requisições | 10 milhões/mês |
| Egress do R2 | US$ 0 | Sem cobrança R2 |
| Leitura/retrieval Standard | Sem tarifa específica de recuperação | Operações Class B continuam pertinentes |

**Arredondamento:** verificar o faturamento vigente por unidades inteiras conforme documentação; quantidades ilustrativas abaixo foram calculadas **sem ajuste de arredondamento**, que pode adicionar centavos ou gerar degraus de milhões de operações. **Não atribuir toda a franquia gratuita da conta à infraestrutura**, pois a Imagine planeja usar Cloudflare para outras aplicações.

Fonte: https://developers.cloudflare.com/r2/pricing/ (atualização pública 30/10/2026); https://www.cloudflare.com/products/r2/ .
Jurisdição: https://developers.cloudflare.com/r2/reference/data-location/ ; confirmar EU na criação do bucket, pois a jurisdição é imutável depois da criação.

**Não incluir como se fosse R2:** fatura da Leaseweb VPS 3, IVA/impostos, câmbio, pagamentos financeiros, monitoramento pago, execução humana, segunda cópia em outro provedor, custos de disaster recovery, eventualmente tráfego excedente da origem Leaseweb. A VPS 3 foi exibida no configurador em € 13,99/mês antes do carrinho final, **não contratada nem autorizada financeiramente**.

## 2. Como estimar a retenção de 30 dias

Restic é deduplicado e incremental: **não multiplicar 30 backups completos pelo volume inicial**. Como o volume real é desconhecido, usar uma hipótese transparentemente artificial:

- D = volume inicial de dados únicos efetivamente submetidos a backup, em GB.
- p = novos dados únicos médios armazenados a cada dia como fração de D, após deduplicação/compressão.
- N = 30 snapshots diários.
- **Ocupação ilustrativa no final do dia 30** = D × [1 + (N-1) × p].
- A estimativa de **armazenamento faturado** da Cloudflare usa GB-mês, com média do consumo ao longo dos dias. Para um mês de inicialização com crescimento linear e sem prune, a ocupação média aproximada é D × [1 + (N-1) × p ÷ 2], não igual à ocupação de fim de mês.
- Num mês subsequente em regime estacionário, com retenção de 30 dias, a ocupação tende ao intervalo do fim de mês **desde que alterações antigas possam ser eliminadas** por manutenção validada; volumes de trabalho, packs reaproveitados e prune podem alterar muito o resultado.
- A política de 30 dias do Restic precisa ser testada; proteger objetos contra exclusão no repositório vivo pode impedir pruning e causar crescimento indefinido.

Fontes: https://restic.readthedocs.io/en/stable/060_forget.html ; https://developers.cloudflare.com/r2/pricing/ .

### Cenário-base com alteração nova de 2% do volume inicial por dia

| D inicial | GB únicos novos por dia | Volume fim do dia 30 | Custo de storage se esse volume fosse mantido todo o mês | Média estimada no 1º mês (crescimento linear) | Custo de storage bruto estimado no 1º mês |
|---:|---:|---:|---:|---:|---:|
| 20 GB | 0,4 GB | 31,6 GB | US$ 0,47 | 25,8 GB-mês | US$ 0,39 |
| 50 GB | 1,0 GB | 79,0 GB | US$ 1,19 | 64,5 GB-mês | US$ 0,97 |
| 100 GB | 2,0 GB | 158,0 GB | US$ 2,37 | 129,0 GB-mês | US$ 1,94 |
| 200 GB | 4,0 GB | 316,0 GB | US$ 4,74 | 258,0 GB-mês | US$ 3,87 |

**Leitura obrigatória da tabela:** são simulações **brutas somente de storage Standard**, sem franquia grátis, operações, arredondamentos, segunda cópia protegida, impostos ou testes de recuperação. O cenário presume que todos os bytes diários de mudança se mantêm únicos nos 30 snapshots; dados que não mudam não são copiados 30 vezes. Não há medição do consumo real da Imagine.

### Sensibilidade para 100 GB iniciais

| Dados novos únicos/dia | Ocupação no fim de 30 dias | Armazenamento bruto/mês se mantido |
|---:|---:|---:|
| 1% = 1 GB/dia | 129 GB | US$ 1,94 |
| 2% = 2 GB/dia | 158 GB | US$ 2,37 |
| 5% = 5 GB/dia | 245 GB | US$ 3,68 |
| 10% = 10 GB/dia | 390 GB | US$ 5,85 |

Projetos com cópia diária de dumps de banco que mudam de forma extensa, arquivos de mídia grandes ou deduplicação pouco eficaz podem atingir percentuais maiores. Não calcular armazenamento como simples equivalência ao SSD NVMe de 300 GB anunciado para a VPS.

## 3. Operações: risco de preço além do storage

**Restic** divide dados em pacotes, faz listagens e consultas de metadados; não estimar operações somente pelo número de backups. Para controle inicial, planejar medição das classes A e B no painel Cloudflare e alertas por tendência.

Exemplos didáticos **antes de franquia e arredondamento**, se houvesse cobrança proporcional:
- 100 mil operações Class A correspondem a US$ 0,45 proporcionalmente ao milhão; faturamento real pode ser arredondado por unidade.
- 1 milhão de operações Class B corresponde a US$ 0,36.
- 1 milhão de operações Class A corresponde a US$ 4,50.

Esses exemplos são **componentes isolados**, não previsões operacionais. Não configurar teto financeiro com base apenas em storage. Se a conta exceder as franquias, verificar o arredondamento e o total faturado com o extrato do provedor.

## 4. Cópia adicional protegida: custo ainda não dimensionado

Conforme INF-DEV-03, o armazenamento Restic ativo deve coexistir com medida contra exclusão maliciosa. Um **ponto completo adicional de 50 GB** em bucket/área com retenção verificada acrescentaria **até aproximadamente US$ 0,75/mês em armazenamento bruto** se ocupasse 50 GB durante um mês, sem considerar mudanças, operações e impostos. Para 100 GB seria aproximadamente US$ 1,50.

Isso **não autoriza** espelhar toda a pasta Restic com locks: locks podem impedir forget/prune/unlock. Planejar formato e periodicidade da cópia protegida separadamente, sempre com teste real de restauração e manutenção.

Fontes: https://developers.cloudflare.com/r2/buckets/bucket-locks/ .

## 5. Limite financeiro e avisos — alerta NÃO equivale a teto

Os **Budget Alerts da Cloudflare são notificações informativas de despesa de uso total da conta (account-wide)**. O próprio fabricante esclarece que **eles NÃO pausam nem limitam o consumo e a fatura continuará acumulando**. Estão disponíveis a contas Pay-as-you-go elegíveis, não a contratos Enterprise.

Fonte: https://developers.cloudflare.com/billing/manage/budget-alerts/ (atualizado em maio/2026).

Recomendação documental:
- **Não aprovar agora valor fixo contratual/teto**, pois o volume inicial e a conta Cloudflare não foram medidos.
- Na futura ativação autorizada, criar alertas account-wide de cobrança e **alertas operacionais próprios específicos dos buckets da VPS**, com limites de GB, variação diária, contagem de operações e volume de objetos; definir alçada de revisão humana.
- Um limite contábil interno de despesa **não é bloqueio nativo da Cloudflare**. Evitar interromper o backup básico automaticamente quando excedido, pois isso cria risco de perda de dados; priorizar suspender jobs não essenciais e acionar incidente/decisão financeira.
- Não misturar custos dos produtos Imagine Sites (Workers/R2 produção) ao orçamento específico deste backup de desenvolvimento.
- Antes de contratar, obter teto em USD (e margem em BRL), responsável de aprovação, cobrança/impostos e orçamento do total R2 + Leaseweb + esforço operacional.

## 6. Contas, segregação e governança

1. Definir **quem administrará a conta R2** da Imagine e quais pessoas serão substitutas para perda de acesso; habilitar MFA administrativo e auditoria.
2. Criar apenas depois de autorização um bucket privado, **jurisdição EU definida explicitamente na criação**, e verificar endpoint EU em toda integração S3.
3. Avaliar **bucket por projeto** ou por classe de retenção para que credenciais mínimas limitem alcance de incidentes; não pressupor permissão segura por prefixo se o token do serviço tiver acesso ao bucket inteiro.
4. Segregar identidade de **backup diário** da identidade de **prune/manutenção** e de alteração das regras R2; resguardar chave de criptografia do Restic fora da VPS.
5. Documentar recuperação de credenciais/chaves, troca de responsáveis, acesso emergencial e restauração com origem destruída.
6. Não ativar lock em repositório incremental Restic sem homologação; avaliar bucket separado para ponto de restauração protegido.
7. Confirmar que não serão enviados dados pessoais reais de clientes dos ambientes de produção: **apenas ambientes de desenvolvimento autorizados**.

## 7. Fases de implantação controlada (para autorização FUTURA)

| Fase | Entregável esperado | Estado |
|---|---|---|
| DEV04-A | Orçamento por cenários e preço oficial (este documento) | **PREPARADO, AGUARDA REVISÃO/ACEITE** |
| DEV04-B | Inventário real de dados, crescimento e operações por projeto | **NÃO MEDIDO** |
| DEV04-C | Escolha final de buckets, custodiante, credenciais, locks e protocolo de restauração | **NÃO APROVADO** |
| DEV04-D | Estimativa de preço total e **teto financeiro explícito**, com autorização do responsável | **NÃO APROVADO** |
| DEV04-E | Autorização separada para contratação/ativação de VPS e R2, instalação, timers, acesso e custos | **NÃO CONCEDIDA** |
| DEV04-F | Homologação com dados sintéticos, operações e teste de restauração antes de produção de backups | **NÃO EXECUTADA** |
| DEV04-G | Aceite operacional, alarmes e relatórios periódicos | **NÃO EXECUTADO** |

## 8. Decisão a submeter

**A INF-DEV-04 está aprovada como base de método financeiro/documental** pela [DEC-INF-DEV-04](DEC-INF-DEV-04-APROVACAO-BASE-DIMENSIONAMENTO-BACKUP-R2-2026-11-04.md), com fórmula de dimensionamento, controles de alertas/contas e a proibição de execução até autorização separada. Os números da tabela são hipóteses e não constituem compromisso nem limite de custos.

**Estado final:** Leaseweb selecionada como provedora, VPS 3 configurada como candidata sem comprovação de compra; Cloudflare R2 Standard EU aprovado arquiteturalmente; Restic + política-base aprovada documentalmente; **INF-DEV-04 aprovada exclusivamente como método financeiro documental, sem teto/compra/instalação**.
