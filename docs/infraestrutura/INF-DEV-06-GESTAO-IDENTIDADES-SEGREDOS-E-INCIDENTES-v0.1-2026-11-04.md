# INF-DEV-06 — Gestão de identidades, permissões, custódia de chaves e resposta a incidentes

**Data:** 04/11/2026  
**Estado:** **PROPOSTA DOCUMENTAL PREPARADA PARA REVISÃO, AINDA NÃO RATIFICADA**.  
**Origem:** resposta “ok” à proposta de iniciar a próxima etapa **INF-DEV-06 — Gestão de identidades, permissões mínimas, custódia e recuperação de chaves e plano de resposta a incidentes**.  
**Escopo:** apenas **backups da futura VPS central de DESENVOLVIMENTO Imagine**, com dados sintéticos e isolamento por projeto. **NÃO se aplica a backup dos serviços em produção nem ao servidor separado de IA/n8n**.

**Referências normativas:** [INF-DEV-01 — VPS Leaseweb](INF-DEV-01-VPS-CENTRAL-LEASEWEB-2026-11-04.md); [DEC-INF-DEV-02 — R2 Standard EU aprovado como destino](DEC-INF-DEV-02-DESTINO-R2-EU-APROVADO-2026-11-04.md); [DEC-INF-DEV-03 — Restic e política-base de backup](DEC-INF-DEV-03-POLITICA-BACKUP-RESTIC-R2-APROVADA-2026-11-04.md); [DEC-INF-DEV-04 — Base financeira](DEC-INF-DEV-04-APROVACAO-BASE-DIMENSIONAMENTO-BACKUP-R2-2026-11-04.md); [DEC-INF-DEV-05 — Buckets segregados e recuperação protegida](DEC-INF-DEV-05-APROVACAO-BASE-IMPLANTACAO-SEGURA-2026-11-04.md).

> **Limite:** este documento não autoriza contratação, cobrança, criação de conta, bucket, token, implementação de script, acesso à VPS, instalação, teste, restauração real, programação dos sistemas ou abertura do Gate C da Universidade.

## 1. Princípios de segurança a ratificar

1. **Propriedade empresarial e continuidade:** ambientes de backup sob titularidade Imagine, com governança e ao menos um administrador substituto, sem compartilhar credenciais pessoais entre integrantes da equipe.
2. **Menor privilégio:** separar administração de conta, operações de backup, verificação, manutenção/expurgo e restauração. Nenhum serviço cotidiano de VPS possui credencial de Super Administrator ou Admin Read & Write de R2.
3. **Isolamento por bucket:** padrão documental já ratificado na INF-DEV-05: cinco buckets privados R2 Standard EU para Portal, CRM, Sites, Performance AI e Universidade; área/bucket separado para recuperação protegida. Nomes, contas e permissões definitivas ainda não escolhidos.
4. **Limite real de isolamento:** cinco tokens em uma única VPS são um controle de escopo, mas a invasão da VPS com privilégio de root pode expor todos os segredos lá armazenados. A cópia contra exclusões deve ser administrada de fora do host.
5. **Confidencialidade e recuperabilidade:** dados criptografados pelo Restic antes do upload; cópia independente das chaves de descriptografia, procedimentos e inventário fora da VPS, acesso com MFA, segregação por projeto.
6. **Proteção contra exclusão:** o Restic ativo exige operações de manutenção; não prometer credencial append-only ou imutabilidade diretamente sobre repositório ativo sem homologação. Para cópia protegida, exigir rotina consistente, identidade separada, retenção e teste de restauração.

## 2. Decisão pendente: conta Cloudflare de backup separada ou existente

**Recomendação inicial, sujeita a aprovação financeira e administrativa:** estudar **conta Cloudflare exclusiva para os backups de desenvolvimento** da Imagine, administrada independentemente da conta onde operam sites e aplicações de produção. Isso reduz o impacto de permissões e mudanças de faturamento cruzadas, mas **não é condição de segurança absoluta**, não elimina dependência do mesmo provedor e pode mudar franquias, organização de billing e custo.

**Alternativa:** usar uma conta Imagine existente com buckets privados por projeto e governança reforçada. Antes de selecionar, validar: proprietário real da conta, quem tem Super Administrator, políticas de acesso e troca de responsáveis, billing, custos por conta, possibilidade de dupla administração, procedimentos de recuperação, permissões dos tokens e controles de faturamento.

**Nenhuma conta nova foi criada nem a atual foi inspecionada.** A escolha de conta será uma decisão específica depois desta base.

## 3. Matriz de papéis e permissões propostas

| Papel técnico | Acesso estritamente necessário | Não permitido |
|---|---|---|
| **Titular/Super Admin** | Conta, faturamento, membros e API tokens administrativos apenas quando necessário | Uso de login privilegiado em cron diário ou como credencial na VPS |
| **Super Admin substituto** | Recuperação administrativa, revogação de acessos e continuidade, condicionado a procedimento de emergência | Conta compartilhada ou acesso rotineiro sem justificativa |
| **Custodiante de segredos** | Cofre corporativo criptografado, cópias offline de chaves Restic e códigos de recuperação conforme alçada | Divulgação no GitHub, chat, logs, tickets ou envio a sistemas de testes |
| **Serviço diário Restic — por projeto** | Operações S3 de leitura/gravação/listagem estritamente necessárias no próprio bucket, com identidade específica e expiração/rotação | Acesso a outros buckets, administração de conta/lock/billing, credenciais de Super Admin |
| **Operador de manutenção Restic** | Operações de forget/prune após pré-validação e janela autorizada em bucket designado | Apagar área protegida, executar scripts sem supervisão nem testes |
| **Responsável pela cópia protegida** | Ler fontes de cópias verificadas e criar pontos protegidos via identidade fora da VPS; gerenciar políticas somente com aprovação | Guardar chaves de exclusão ou de gestão do bucket protegido na VPS |
| **Responsável pela recuperação** | Leitura do bucket específico e segredo de descriptografia sob autorização, preferivelmente em ambiente isolado | Alterar política de retenção, usar bucket errado ou consultar dados não autorizados |
| **Monitoramento/auditoria** | Metadados mínimos: sucesso, falha, ID de snapshot, horário, volume e consumo, conforme controle de acesso | Ler conteúdo sensível ou credenciais de backup |

**Capacidades reais do R2:** a Cloudflare documenta tokens do tipo Account e User; tokens Account são adequados para serviços corporativos duradouros, enquanto tokens User dependem do usuário. O tipo **Object Read & Write** pode ser limitado a buckets, mas é um direito operacional amplo sobre objetos, **não uma garantia de não exclusão**. A compatibilidade com todos os comandos Restic deve ser homologada antes de reduzir permissões. O token de objeto funciona na API S3 compatível, não na API REST de gestão do R2. **Não pressupor capacidade nativa de S3 append-only sem teste.**

Documentação: https://developers.cloudflare.com/r2/api/tokens/ ; https://developers.cloudflare.com/r2/platform/troubleshooting/ ; https://developers.cloudflare.com/fundamentals/manage-members/roles/ .

## 4. Identidade humana, autenticação e desligamentos

- Exigir **MFA** para todos os administradores e integrantes com acesso de backup, preferindo chave de segurança ou método forte adequado; armazenar códigos de recuperação offline ou no cofre corporativo aprovado, nunca na VPS.
- Manter pelo menos **duas pessoas reais com papel administrativo autorizado**, contas individuais e mecanismo comprovado de substituição; avaliar a necessidade de dois Super Admins, com controle de alçada e auditoria.
- Em admissão e desligamento: conceder ou revogar acesso nominal, inventariar tokens e contas relacionadas, rotacionar segredos devidos e confirmar que o substituto consegue operar.
- Recomendar revisão trimestral de membros, papéis e chaves; em incidente, revogação/rotação imediata conforme risco e sem presumir que uma troca de token preserva sessões anteriores.
- Testar **recuperação do login Cloudflare** separadamente da recuperação da senha Restic: os códigos de recuperação 2FA não substituem a senha criptográfica Restic, e ter a senha Restic não substitui acesso aos objetos no R2.
- Não adotar conta pessoal de funcionário como único proprietário dos backups empresariais.

Fontes: https://developers.cloudflare.com/fundamentals/manage-members/manage/ ; https://developers.cloudflare.com/fundamentals/user-profiles/2fa/ ; https://developers.cloudflare.com/fundamentals/user-profiles/account-recovery/ .

## 5. Gestão de segredos e chaves — plano de custódia

| Item | Local proposto | Recuperação prevista |
|---|---|---|
| Senha/segredo Restic por repositório | Cofre criptografado externo à VPS, com acesso nominal; cópia segura adicional conforme risco | Procedimento de acesso emergencial com autorização e registro |
| Tokens S3 R2 de cada projeto | Provisionamento futuro em armazenamento restrito do serviço; **não versionar nem enviar por chat** | Revogação e emissão substituta por administrador autorizado |
| Credenciais e códigos 2FA Cloudflare | Contas humanas individuais e códigos em cofre fora do host; fator de recuperação separado | Segundo administrador e procedimento Cloudflare |
| Inventário de buckets, endpoint EU e repositórios | GitHub apenas com metadados sem segredos + cópia fora da VPS | Reconstrução da configuração segura em host novo |
| Identidade de cópia protegida | Credencial fora da VPS, sob domínio do custodiante de recuperação | Evitar que comprometimento da VPS comprometa também esta identidade |

**Ponto de falha único:** se todas as chaves estiverem somente no host, a recuperação falhará após perda da VPS. Se o cofre depender unicamente de uma pessoa ou um dispositivo, a ausência dessa pessoa pode impedir o restore. A solução definitiva deve conter custódia testável sem distribuir chaves amplamente.

**Rotação:** inventário de segredos por projeto, dono, validade, último teste e procedimento de revogação. A periodicidade exata de rotação fica pendente de política operacional e capacidade real do provedor.

## 6. Proteção da camada de recuperação

A área protegida ratificada pela INF-DEV-05 não é, por si só, prova de imutabilidade ou resistência a ransomware. Antes de escolher o mecanismo:

1. Definir **formato consistente e restaurável** dos pontos protegidos, não cópia aleatória de arquivos de um repositório Restic que está sofrendo alterações.
2. Definir periodicidade (candidata: semanal), retenção distinta, quem a cria e como verificar que a credencial não reside na VPS.
3. Avaliar uso de **R2 Bucket Lock com escopo por prefixo e retenção**; não ativar lock que impeça forget/prune/unlock no repositório Restic ativo.
4. A documentação Cloudflare permite que identidade privilegiada **altere/remova regras de Bucket Lock**. Assim, lock não equivale a retenção juridicamente imutável contra comprometimento do administrador; pode ser necessário outro provedor/conta para proteção mais forte.
5. Simular exclusão por token da VPS, revogação, perda de conta, alteração administrativa e reconstituição do backup sem acesso ao host original.
6. Não misturar registros de produção ou informações reais de clientes.

Fontes: https://developers.cloudflare.com/r2/buckets/bucket-locks/ ; https://restic.readthedocs.io/en/stable/060_forget.html .

## 7. Plano de resposta a incidentes — procedimentos futuros

| Cenário | Contenção inicial projetada | Recuperação e aceitação | Escalonamento |
|---|---|---|---|
| Credencial R2 de um projeto exposta | Revogar credencial, restringir host e inventariar eventos; evitar expurgos até confirmar integridade | Emitir token substituto; comparar último snapshot íntegro e executar restore de amostra | Administrador de segurança + responsável pelo projeto |
| Root da VPS comprometido | Isolar VPS, preservar evidências, suspender tokens do host comprometido; não reutilizar segredos | Reconstruir host limpo, restaurar a partir de cópia íntegra e chaves externas, validar cinco projetos separadamente | Incidente crítico; direção e segurança |
| Conta Cloudflare comprometida | Usar Super Admin alternativo e canais oficiais; revogar tokens/sessões e revisar alterações | Garantir integridade e acesso ao backup; se inexistente, registrar falha de recuperação, não presumir cópias | Direção, administrativo, segurança e provedor |
| Perda de dispositivo 2FA | Utilizar códigos de recuperação corporativos autorizados; não contornar MFA por meios inseguros | Recuperar identidade e registrar procedimentos; se necessário, recuperação oficial Cloudflare | Super Admin substituto |
| Perda da senha Restic | Acionar custódia externa e segundo guardião; não tentar recuperar cifra por suposição | Sem cópia válida do segredo, repositório criptografado pode ficar inacessível: tratar como incidente grave | Segurança, custodiante e direção |
| Excluir snapshot/repositório indevidamente | Interromper prune/lifecycle e revisar locks e credenciais | Recuperar ponto protegido íntegro, se existir, com restauração verificada | Responsável de backup + segurança |
| Falha do backup/ausência >36h | Alertar e investigar dump, token, rede, limite, espaço e job; não declarar sucesso | Reexecutar com controle e validar snapshot/restauração, sem suprimir evidências | Operação e responsável substituto |
| Custo ou operações R2 anormais | Identificar projeto e classe de operação; restringir jobs não essenciais; não suspender automaticamente única proteção | Revisar orçamento, rota de recuperação e alçada financeira; registrar aprovação | Financeiro e operação |

**Toda comunicação de incidente deve evitar inclusão de credenciais, conteúdos de repositórios ou dados pessoais em logs e tickets.** A política de comunicação externa e eventuais notificações regulatórias são competências de fluxos organizacionais próprios, caso dados reais entrem no incidente, não presumidos aqui.

## 8. Níveis de autorização propostos

| Ação | Autorização prevista | Estado |
|---|---|---|
| Definir plataforma e buckets como padrão arquitetural | Já aprovado documentalmente por INF-DEV-02/05 | **Documental** |
| Abrir nova conta, ativar cobrança, comprar VPS ou R2 | Direção/financeiro, valor total e teto aprovados | **NÃO AUTORIZADO** |
| Criar buckets/tokens e instalar Restic | Responsável técnico, segurança e aprovação operacional | **NÃO AUTORIZADO** |
| Rotina diária de backup após implantação | Política homologada e agendamento autorizado | **NÃO EXECUTADO** |
| Forget/prune e alteração de retenção | Processo de mudança, evidências de restore e alçada responsável | **NÃO AUTORIZADO** |
| Restauração de teste | Responsável técnico + ambiente isolado + dados sintéticos | **NÃO EXECUTADA** |
| Restauração emergencial | Responsável por incidente, acesso do custodiante e registro da decisão | **SOMENTE PROCESSO PROPOSTO** |
| Alteração de IAM, bucket locks e exclusão do bucket protegido | Aprovação humana de alçada elevada, dupla verificação e auditoria | **NÃO IMPLEMENTADO** |

## 9. Critérios de homologação para execução futura

- **INF06-T01:** provar que o token Portal não acessa buckets CRM/Sites/Universidade.
- **INF06-T02:** provar que token rotineiro não tem permissão de administrar buckets, locks, membros e faturamento.
- **INF06-T03:** provar que chaves Restic não existem no GitHub nem exclusivamente na VPS e podem ser acessadas pelo custodiante.
- **INF06-T04:** provar restauração sem servidor de origem e sem acesso ao usuário principal.
- **INF06-T05:** revogar e rotacionar token de um projeto sem impedir restauração dos demais.
- **INF06-T06:** verificar comportamento real das operações de delete/prune e auditoria dos papéis.
- **INF06-T07:** verificar impedimento da exclusão de cópia protegida com credenciais operacionais da VPS.
- **INF06-T08:** simular comprometimento do root da VPS e uso de cópia externa.
- **INF06-T09:** simular recuperação de acesso 2FA e troca de administrador, com controle de governança.
- **INF06-T10:** simular perda da senha Restic e acionamento da custódia externa.
- **INF06-T11:** confirmar jurisdição EU em todos os buckets e endpoint S3 EU.
- **INF06-T12:** verificar que alertas de segurança/uso anormal chegam aos responsáveis definidos.

**Nenhum teste foi executado.** Eles dependem de autorização de infraestrutura e de um ambiente fictício controlado.

## 10. Pendências e próximo gate

| Código | Pendência | Situação |
|---|---|---|
| DEV06-D01 | Escolha entre conta Cloudflare exclusiva para backups e conta existente segregada | **PENDENTE** |
| DEV06-D02 | Designação nominal de Super Admin principal, substituto e custodiantes | **PENDENTE** |
| DEV06-D03 | Política final de tokens, validade, rotação, exclusão e separação de credenciais de manutenção | **PENDENTE/HOMOLOGAÇÃO** |
| DEV06-D04 | Cofre, dupla custódia e prova de recuperação de chaves | **PENDENTE** |
| DEV06-D05 | Formato de cópia protegida, prazo, controles anti-exclusão e testes de restore | **PENDENTE/HOMOLOGAÇÃO** |
| DEV06-D06 | Responsáveis, alçadas e comunicação de incidentes | **PENDENTE** |
| DEV06-D07 | Medição de volume e operações, custos Cloudflare/Leaseweb e teto financeiro total | **SEM AUTORIZAÇÃO FINANCEIRA** |
| DEV06-D08 | Criação de conta/buckets/tokens e instalação de software | **NÃO AUTORIZADO** |
| DEV06-D09 | Testes INF06-T01 a T12 e aceite operacional | **NÃO EXECUTADOS** |
| DEV06-D10 | Revisão das políticas de produção em separado; preservação de bloqueios de programação | **ABERTO POR PRODUTO** |

**Próxima decisão documental sugerida:** aprovar a **INF-DEV-06 apenas como BASE DE PLANEJAMENTO DE SEGURANÇA**, com matriz de identidades, custódia externa, incidentes, testes e pendências. A recomendação de conta Cloudflare exclusiva ainda é **hipótese**, e não seleção definitiva. Orçamento, ativação e execução ficam sujeitos a aprovação expressa posterior.

## Fontes oficiais consultadas em 04/11/2026

- Cloudflare R2 tokens: https://developers.cloudflare.com/r2/api/tokens/
- Cloudflare account roles: https://developers.cloudflare.com/fundamentals/manage-members/roles/
- Cloudflare members: https://developers.cloudflare.com/fundamentals/manage-members/manage/
- Cloudflare 2FA: https://developers.cloudflare.com/fundamentals/user-profiles/2fa/
- Cloudflare account recovery: https://developers.cloudflare.com/fundamentals/user-profiles/account-recovery/
- Cloudflare R2 Bucket Locks: https://developers.cloudflare.com/r2/buckets/bucket-locks/
- Cloudflare R2 jurisdiction: https://developers.cloudflare.com/r2/reference/data-location/
- Restic: https://restic.readthedocs.io/en/stable/060_forget.html
