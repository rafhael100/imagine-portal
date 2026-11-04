# DEC-INF-DEV-06 — Aprovação da base documental de identidades, chaves e incidentes

**Data da aprovação:** 04/11/2026.
**Estado:** **APROVADA COMO BASE DOCUMENTAL DE SEGURANÇA, EXCLUSIVAMENTE**.
**Evidência:** resposta “ok” do responsável à pergunta: “Você aprova a INF-DEV-06 como base documental de segurança, mantendo essas pendências abertas e sem iniciar programação, instalações ou contratações?”.
**Documento ratificado:** [INF-DEV-06 — Gestão de identidades, permissões, custódia de chaves e resposta a incidentes](INF-DEV-06-GESTAO-IDENTIDADES-SEGREDOS-E-INCIDENTES-v0.1-2026-11-04.md).
**Precedentes:** [DEC-INF-DEV-02 — R2 Standard EU](DEC-INF-DEV-02-DESTINO-R2-EU-APROVADO-2026-11-04.md), [DEC-INF-DEV-03 — Restic e política-base](DEC-INF-DEV-03-POLITICA-BACKUP-RESTIC-R2-APROVADA-2026-11-04.md), [DEC-INF-DEV-04 — Dimensionamento financeiro](DEC-INF-DEV-04-APROVACAO-BASE-DIMENSIONAMENTO-BACKUP-R2-2026-11-04.md) e [DEC-INF-DEV-05 — Cinco buckets e recuperação separada](DEC-INF-DEV-05-APROVACAO-BASE-IMPLANTACAO-SEGURA-2026-11-04.md).

## 1. O que foi aprovado documentalmente

1. **Modelo de papéis separados:** administração principal e substituta, custódia de segredos, operador de backup por projeto, manutenção, restauração, proteção da cópia adicional e auditoria, aplicando privilégio mínimo.
2. **Credenciais segregadas:** um bucket privado Cloudflare R2 Standard na jurisdição EU por projeto (Portal, CRM, Sites, Performance AI e Universidade), área adicional de recuperação protegida e contas/identidades com acesso mínimo. A configuração real dos tokens ainda exige verificação da capacidade concreta da API S3 do Cloudflare R2.
3. **MFA administrativo, acesso nominal e continuidade:** não utilizar logins corporativos compartilhados; preservar métodos de recuperação e substituição fora da VPS.
4. **Custódia externa das chaves Restic:** cofre/custodiante definido antes de operar, cópia segura de recuperação fora da VPS e nenhum segredo em repositórios, chats, logs ou capturas de tela.
5. **Proteção contra exclusão e perda da VPS:** arquitetura em camadas, com repositórios Restic ativos e pontos de recuperação separados e restauráveis; não aplicar indiscriminadamente Bucket Locks ao repositório ativo, pois podem impedir operações de manutenção.
6. **Resposta a incidentes:** fluxos documentais para credencial vazada, servidor comprometido, perda do segundo fator, conta Cloudflare comprometida, perda de senha de repositório, exclusão de backup e falhas de execução.
7. **Cobertura de homologação futura:** casos INF06-T01 a INF06-T12 aprovados como matriz de planejamento, sem realização ou homologação de qualquer teste.
8. **Escopo:** proteger apenas a **VPS central de desenvolvimento** Imagine, com dados fictícios; backups de produção e servidor central n8n/IA têm regras independentes.

## 2. Pendências expressamente preservadas

| Código | Decisão ou validação pendente | Estado |
|---|---|---|
| DEV06-D01 | Conta Cloudflare exclusiva de backups versus conta existente segregada | **ABERTO; conta exclusiva é apenas recomendação** |
| DEV06-D02 | Identificar administradores, substitutos, custodiante e auditores | **ABERTO** |
| DEV06-D03 | Permissões efetivas, validade, rotação, operações delete/prune e separação operacional Restic | **ABERTO; exige homologação autorizada** |
| DEV06-D04 | Escolher cofre, mecanismo de custódia dupla e recuperação de chaves | **ABERTO** |
| DEV06-D05 | Especificar e homologar formato, retenção e proteção dos pontos de recuperação | **ABERTO** |
| DEV06-D06 | Responsáveis, alçadas, escala e procedimentos oficiais de incidentes | **ABERTO** |
| DEV06-D07 | Estimar valores reais e estabelecer limite financeiro/teto total VPS e R2 | **NÃO APROVADO** |
| DEV06-D08 | Criar conta, buckets, tokens, configurar VPS e instalar Restic | **NÃO AUTORIZADO** |
| DEV06-D09 | Executar casos INF06-T01 a INF06-T12 | **NÃO EXECUTADOS; SEM AUTORIZAÇÃO OPERACIONAL** |
| DEV06-D10 | Conciliar política de backup de PRODUÇÃO por sistema | **PENDENTE NOS RESPECTIVOS PROJETOS** |

Aprovação de princípios e matrizes técnicas **não** constitui seleção definitiva da conta Cloudflare, modelo de cofre, pessoas, preços, credenciais, compatibilidade de permissões ou mecanismo de imutabilidade.

## 3. Autorizações que não foram concedidas

**Proibido neste ato:** contratação, pagamento, ativação do Leaseweb VPS 3, R2 ou ferramentas; criação de buckets/tokens; acesso, instalação, scripts, timers, hardening, testes ou operação em servidores; uso de dados reais de clientes; execução de ações nos projetos; abertura do Gate C da Universidade; alteração das condições de produção do CRM/Portal/Sites/Performance AI/Universidade.

**Situação da INF-DEV-01:** Leaseweb como provedora escolhida em princípio e VPS 3 como configuração candidata; contratação não comprovada nem autorizada expressamente.

## 4. Próximo fechamento documental sugerido

**INF-DEV-07 — Modelo de conta Cloudflare e custódia corporativa de backups:** comparar conta exclusiva versus conta segregada na estrutura existente, responsabilidades e políticas reais, consolidar critérios de seleção do cofre e administração substituta, registrar matriz de dependências/custos e decisões pendentes. Manter teto financeiro e autorização de instalação/ativação como gates específicos posteriores.

**Resultado:** INF-DEV-06 aprovada como base documental de segurança; **todas as pendências técnicas, financeiras e operacionais permanecem abertas**. Nenhuma programação, teste ou contratação autorizada.
