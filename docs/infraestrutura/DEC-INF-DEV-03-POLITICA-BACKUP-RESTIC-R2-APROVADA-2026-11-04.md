# DEC-INF-DEV-03 — Aprovação da política-base técnica de backups de desenvolvimento Imagine

**Data:** 04/11/2026.
**Estado:** **INF-DEV-03 APROVADA COMO BASE TÉCNICA DOCUMENTAL**, sem início de operação, contratação ou despesas.
**Registro do aceite:** resposta expressa “ok” à pergunta “Você aprova essa INF-DEV-03 como base técnica, mantendo o orçamento, a criação do bucket, a instalação e a ativação para autorização posterior?”.
**Especificação aprovada como referência:** [INF-DEV-03 — Plano técnico Restic + R2 EU](INF-DEV-03-PLANO-BACKUP-AUTOMATICO-RESTIC-R2-v0.1-2026-11-04.md).
**Destino já aprovado separadamente:** [DEC-INF-DEV-02 — Cloudflare R2 Standard, jurisdição EU](DEC-INF-DEV-02-DESTINO-R2-EU-APROVADO-2026-11-04.md).

## 1. Alcance da aprovação

1. **Restic** como ferramenta-base de projeto para backup criptografado e incremental da **VPS central de desenvolvimento Imagine** (Leaseweb), usando armazenamento **Cloudflare R2 Standard restrito à União Europeia**, com bucket privado e credenciais mínimas por projeto.
2. Backup **diário** como periodicidade-base; janela noturna a homologar, com isolamento de operações concorrentes e verificação de sucesso por snapshot e integridade.
3. Retenção-base **inicial de 30 dias**, sujeita a dimensionamento, privacidade e teste das regras de expiração/manutenção antes de habilitar limpeza automática.
4. **Alertas** por falhas, ausência de cópias recentes e inconsistências; proposta inicial de atenção a snapshots com mais de 36 horas, sujeita a definição de responsáveis e canais.
5. **Verificações de integridade semanais**, **testes de restauração mensais** de dados sintéticos e **simulação trimestral de perda da VPS** como referência técnica, pendentes de implementação e evidências.
6. Para bancos: **cópia consistente do SQLite no Portal e dump consistente de PostgreSQL por ambiente/projeto**, sem copiar bancos de produção, dados pessoais reais ou segredos expostos.
7. Separar **chaves de recuperação fora da VPS**; avaliar proteção adicional contra exclusão/ransomware em área ou bucket segregado. Não aplicar bucket locks indiscriminados ao repositório Restic ativo, pois podem bloquear manutenção e expurgo.
8. **Snapshot gratuito Leaseweb apenas complementar**, não substitui backup externo independente.
9. O sistema central de agentes/n8n e os **ambientes de produção dos produtos Imagine** não estão abrangidos por esta política de backup de desenvolvimento.

## 2. Ressalvas e condicionantes

**Não estão aprovados ou realizados por este ato:**
- contratação/compra da VPS Leaseweb ou armazenamento Cloudflare, criação de buckets, emissão de credenciais, assinatura de serviços ou modificação de configurações;
- orçamento total, **teto financeiro**, preços finais, impostos, consumos, licenças ou novas despesas;
- instalação do Restic, scripts, agendamentos, workflows, publicação, envio de backups, exclusão/prune, testes de restauração ou autenticação operacional;
- configuração de retenção imutável, bucket locks ou políticas definitivas de lifecycle antes de homologação técnica;
- programação do CRM/Universidade ou de outro produto por causa da aprovação desta política. O **Gate C da Universidade permanece NÃO AUTORIZADO**.

A aprovação documental de periodicidades e retenção-base **não** equivale a SLA comprovado, proteção operacional habilitada, recuperação testada nem disponibilidade de servidor.

## 3. Próximo bloco a planejar

**INF-DEV-04 — Dimensionamento financeiro, contas, permissões e implantação controlada:** medir dados persistentes a proteger, estimar volume e operações R2, definir orçamento e teto, responsabilidades, segregação, configuração de restauração, alertas e ordem segura de ativação, separando testes documentais de execução. A ativação só ocorrerá após autorização financeira e operacional **expressa e específica**.

**Status consolidado:** INF-DEV-01 Leaseweb escolhida em princípio (VPS 3 configuração recomendada, ainda sem comprovação de compra); INF-DEV-02 R2 Standard EU aprovado como destino; **INF-DEV-03 Restic e política-base aprovados documentalmente**; INF-DEV-04 custo e autorização pendentes.
