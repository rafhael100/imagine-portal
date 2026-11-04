# DEC-INF-DEV-02 — Aprovação do destino principal dos backups da VPS central Imagine

**Data:** 04/11/2026.
**Decisão:** **APROVADO COMO ARQUITETURA E DESTINO PRINCIPAL**: Cloudflare R2 Standard, com restrição de jurisdição de armazenamento **União Europeia (EU)**, para os backups externos da **VPS central de desenvolvimento Imagine**.
**Aceite expresso:** resposta “ok” do responsável à pergunta “Você aprova o Cloudflare R2 Standard, com armazenamento restrito à União Europeia, como destino principal dos backups da VPS central de desenvolvimento Imagine? Essa aprovação definiria a arquitetura, mantendo a contratação, o teto de custos e a configuração dependentes de decisões posteriores.”
**Documento-base:** [INF-DEV-02 — Comparação de destinos](INF-DEV-02-DESTINO-BACKUP-EXTERNO-2026-11-04.md).
**VPS associada:** [INF-DEV-01 — VPS Leaseweb compartilhada](INF-DEV-01-VPS-CENTRAL-LEASEWEB-2026-11-04.md).

## Escopo ratificado

1. **Destino principal:** bucket privado Cloudflare R2 **Standard**, com restrição de jurisdição **EU**, destinado às cópias externas dos ambientes de desenvolvimento Imagine.
2. **Independência:** o backup não poderá ter sua **única cópia** armazenada na VPS de origem. O snapshot incluído na Leaseweb é mecanismo auxiliar e não substitui backup externo.
3. **Segurança de projeto:** criptografia dos backups no cliente, permissões mínimas, acesso administrativo separado, recuperação de chaves fora da VPS, sem exposição pública, sem guardar credenciais no código.
4. **Isolamento lógico por projeto:** políticas e artefatos distinguíveis para Portal, CRM, Sites, Performance AI e Universidade, conforme escopo de cada ambiente e sem incluir bancos de produção nem dados pessoais reais de clientes.
5. **Rotina de referência para posterior dimensionamento:** backup automatizado diário e retenção **inicial proposta** de 30 dias, com alertas e testes de recuperação. A periodicidade, retenção operacional, volumes, mecanismos anti-exclusão e execução ainda exigem especificação, teste e aprovação.
6. **Compatibilidade e proteção contra exclusão:** avaliar ferramenta de backup S3-compatível e políticas de proteção R2; não ativar lock incompatível com manutenção/prune de repositórios incrementais.
7. **Limite geográfico:** jurisdição EU **não** garante cidade específica, país específico nem distância mínima da VPS Leaseweb em Amsterdam.

## Aprovações NÃO concedidas

Esta decisão **não autoriza**:
- contratação, ativação de serviços pagos, criação de bucket, emissão de tokens, configuração de servidor, instalação de ferramentas ou automação de backup;
- orçamento, **teto monetário**, custo final de storage/operações, novas despesas, alteração de planos Cloudflare, ou compra/ativação da VPS Leaseweb;
- início de programação dos sistemas; o **Gate C da Universidade permanece NÃO AUTORIZADO**, e cada produto mantém suas restrições;
- backups ou acesso a ambientes de produção existentes sem decisão operacional independente.

## Próximas pendências antes de execução

- Confirmar conta e capacidade de faturamento Cloudflare, residência EU, políticas de acesso e limites de consumo.
- Dimensionar o volume médio efetivo (dados persistentes, bancos e histórico), frequência e retenção.
- Definir ferramenta e arquitetura de execução (Restic ou outra solução tecnicamente homologada), segredos, gerenciamento de chaves, alertas, restauração e segregação de papéis.
- Estimar preço **total** incluindo operações, backup e pessoal, câmbio e impostos; submeter **teto financeiro** e autorização explícita.
- Validar tecnicamente processo de restauração de ambiente de desenvolvimento e política de retenção antes de depender da cópia.

**Resultado:** destino R2 Standard + EU **ratificado como escolha arquitetural**; implantação, despesa e operação **pendentes de decisões específicas futuras**.
