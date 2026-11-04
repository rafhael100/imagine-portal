# INF-DEV-05 — Preparação da implantação segura dos backups da VPS de desenvolvimento Imagine

**Data:** 04/11/2026.
**Situação:** **PLANEJAMENTO TÉCNICO PREPARADO PARA REVISÃO, NÃO RATIFICADO NESTA ETAPA**.
**Origem:** direção respondeu “ok, próxima etapa” à proposta de iniciar **INF-DEV-05 — Preparação da implantação segura**, exclusivamente no planejamento.
**Decisões anteriores (vigentes):**
- [INF-DEV-01 — Leaseweb, VPS central de desenvolvimento](INF-DEV-01-VPS-CENTRAL-LEASEWEB-2026-11-04.md): provedora escolhida em princípio; configuração VPS 3 candidata; **sem confirmação de compra/ativação**.
- [DEC-INF-DEV-02 — R2 Standard EU](DEC-INF-DEV-02-DESTINO-R2-EU-APROVADO-2026-11-04.md): destino principal de backup **aprovado arquiteturalmente**.
- [DEC-INF-DEV-03 — Política-base Restic](DEC-INF-DEV-03-POLITICA-BACKUP-RESTIC-R2-APROVADA-2026-11-04.md): backups diários, retenção inicial 30 dias, alertas e restaurações **aprovados documentalmente**.
- [DEC-INF-DEV-04 — Método financeiro](DEC-INF-DEV-04-APROVACAO-BASE-DIMENSIONAMENTO-BACKUP-R2-2026-11-04.md): dimensionamento e controle de gastos **aprovados como método**, sem orçamento/teto/ativação.

**Proibição:** este documento não cria conta, bucket, token, backup, instalação, novo serviço, infraestrutura, gasto ou código. Gate C da Universidade e bloqueios de programação do CRM permanecem vigentes.

## 1. Objetivo e fronteiras

Planejar as condições técnicas, organizacionais e de segurança para que **uma futura VPS Leaseweb** de desenvolvimento envie dados sintéticos de múltiplos projetos Imagine a **Cloudflare R2 Standard sob jurisdição EU**, com capacidade de recuperar cada projeto separadamente e de recuperar dados mesmo que a VPS original desapareça ou seja comprometida.

**Não se aplica à produção.** Portal, CRM, Sites, Performance AI, Universidade e servidor de IA/n8n conservam políticas e gastos de backup próprios.

## 2. Modelo candidato de isolamento dos backups

| Unidade | Destino R2 candidato | Acesso operacional | Observação |
|---|---|---|---|
| Portal Imagine | 1 bucket privado EU de desenvolvimento | Token escopado ao bucket Portal e Restic próprio | SQLite e arquivos de teste; não é backup de produção |
| CRM Imagine | 1 bucket privado EU de desenvolvimento | Token escopado ao bucket CRM e Restic próprio | PostgreSQL de dev apenas após ambiente autorizado |
| Imagine Sites | 1 bucket privado EU de desenvolvimento | Token escopado ao bucket Sites e Restic próprio | Arquivos persistentes de testes, não sites de clientes |
| Performance AI | 1 bucket privado EU de desenvolvimento | Token escopado ao bucket Performance e Restic próprio | Somente dados fictícios, nunca credenciais reais Google/Meta |
| Universidade Imagine | 1 bucket privado EU de desenvolvimento | Token escopado ao bucket Universidade e Restic próprio | Ambiente só quando Gate C for autorizado |
| Recuperação protegida | Bucket/área separada EU de pontos de recuperação verificados | Identidade de proteção e restauração **fora da VPS**, com regras de retenção homologadas | Não reutilizar como repositório Restic ativo |

**Escolha de buckets por projeto é RECOMENDAÇÃO DESTE BLOCO, ainda não ratificada.** Cada bucket R2 deve ter nome administrativo a definir conforme política de nomenclatura; não pressupor que já exista. Não declarar custo zero de operação pelo número de buckets: os custos são principalmente volume/operacões, que devem ser dimensionados.

A separação de credenciais por projeto restringe danos de uma credencial vazada; **não impede** que um invasor com controle root da VPS leia credenciais de todos os projetos. Para esse risco, proteger host, chaves e cópias de recuperação com identidade externa. Contêineres Docker também compartilham kernel/host; não equivalem a máquinas virtuais independentes.

## 3. Organização Cloudflare e responsabilidades

1. **Titularidade:** usar conta Cloudflare controlada formalmente pela Imagine. Antes de decidir conta nova versus conta existente, avaliar separação de administradores, controles financeiros e auditoria; não presumir que uma conta existente tem franquia livre.
2. **Administrador e substituto:** responsáveis nomeados e contas pessoais individuais com MFA; evitar credenciais humanas compartilhadas.
3. **Administrador de backup / custódia de chaves:** papel apartado de operação diária; credenciais de restauração e segredos de repositórios guardados **fora da VPS**, sem colocar valores reais no GitHub, no chat ou em imagens.
4. **Serviço de backup diário:** token R2 específico por bucket e com menor escopo possível. A documentação oficial permite escopar tokens de leitura e escrita de objetos a buckets; **testar as operações efetivamente exigidas pelo Restic** antes de retirar permissões ou declarar token append-only.
5. **Serviço de manutenção:** executar expiração/compactação Restic apenas com identidade e janela controladas, depois de verificar se a separação de permissão de exclusão é compatível com locks temporários do Restic.
6. **Serviço de alerta:** permissões mínimas de leitura dos indicadores para monitoramento, sem ter acesso a segredos ou conteúdos dos backups; canal e plantonista a definir.
7. **Responsável financeiro:** alguém designado para acompanhar storage/operations, custos account-wide e gasto específico dos buckets de backups; determinar alçada e teto antes da ativação.
8. **Responsável por recuperação:** pessoas autorizadas a solicitar, executar, revisar e registrar a restauração, sem publicar os dados recuperados.

Documentação oficial: https://developers.cloudflare.com/r2/api/tokens/ . O R2 exige ativação/contratação do serviço na conta antes da geração de token; portanto, a etapa de configuração **não** poderá ser realizada no estágio documental atual.

## 4. Região, acesso e proteção dos objetos

- **Jurisdição EU obrigatória** no instante da criação de cada bucket; não usar padrão Automatic nem confundir com Location Hint (este não garante residência).
- Usar o endpoint de jurisdição **https://ACCOUNT_ID.eu.r2.cloudflarestorage.com** com S3 do Restic, sem construir o endpoint com IDs inventados. A jurisdição deve ser conferida para cada bucket, pois não poderá ser alterada posteriormente.
- Todos os buckets privados, sem acesso anônimo, sem custom domain público, compartilhamento público ou credencial geral de administrador nos serviços.
- Comunicação via TLS, validação de certificados e proteção de nomes/credenciais nos logs; revisão periódica de tokens e procedimento de revogação/rotação.
- Evitar que servidor Linux ou aplicação possam alterar configuração de bucket, billing, bloqueios, usuários ou políticas de Cloudflare.
- Recomenda-se criptografia do Restic na origem, mesmo com eventual criptografia no provedor; as senhas devem sobreviver à perda total do servidor.

Fontes: https://developers.cloudflare.com/r2/reference/data-location/ e https://developers.cloudflare.com/r2/api/tokens/ .

## 5. Políticas funcionais herdadas e regras de consistência

| Item | Linha de base já aprovada documentalmente | Detalhe INF-DEV-05 ainda a validar |
|---|---|---|
| Frequência de backup | Diária | Horário, escalonamento por projeto e janela de manutenção |
| Retenção | 30 dias iniciais | Sintaxe e resultado efetivo de forget/prune, sem apagar pontos necessários |
| Integridade | Verificações semanais | Extensão/amostragem da leitura de dados, evidências e custo |
| Teste de restore | Mensal | Checklist reproduzível por projeto e aceite |
| Simulação de perda da VPS | Trimestral | Dependência de credenciais externas e aprovação de ambiente provisório |
| Monitoramento | Aviso de falha/ausência de backups | Canal, responsáveis, escalonamento e evidência |
| Segurança | Criptografia e isolamento | Tokens, custódia, recursos, proteção anti-exclusão e procedimentos |

**Consistência dos bancos:** para Portal usar backup consistente de SQLite por método próprio; para projetos PostgreSQL usar dump consistente validado e teste de restore. Não copiar simplesmente o diretório de dados com banco ativo. Falha na geração do dump não deve ser mascarada como sucesso do backup. Nenhuma base de produção ou dados pessoais reais entram na VPS dev.

## 6. Proteção contra exclusões e capacidade de restauração

**Risco:** Restic usa operações de criação/consulta e, em fases específicas, de exclusão de objetos; credenciais comprometidas podem atingir o repositório remoto. A criptografia protege sigilo, **não disponibilidade contra exclusão**.

**Proposta de duas camadas**:
- **A. Operacional:** bucket EU por projeto com Restic ativo, snapshots deduplicados, 30 dias de retenção, manutenção cuidadosamente controlada; **não aplicar bucket locks ou lifecycle por idade indistintamente no repositório vivo** (poderiam impedir restic unlock/prune ou eliminar objetos referenciados).
- **B. Protegida:** cópias ou pontos de restauração independentes, **consistentes e testados**, com administração e credenciais **não disponíveis na VPS**. Avaliar bucket separado, regra R2 Bucket Lock com prefixo/prazo, validação de restauração e expurgo controlado após retenção. Não é suficiente copiar arbitrariamente partes de um repositório Restic ativo enquanto ele muda.
- **Sequestro da conta Cloudflare:** duas camadas sob a mesma conta/provedor não equivalem à independência entre provedores. Reavaliar segunda cópia em outro provedor diante de risco/valor de dados aumentado.
- O estado protegido e as permissões reais devem ser **homologados com dados descartáveis** antes de configurar rotinas de exclusão.
- **Bucket Lock:** Cloudflare documenta bloqueios de eliminação/sobrescrita, inclusive por prefixo; alterações da configuração por conta privilegiada são risco distinto. Fonte: https://developers.cloudflare.com/r2/buckets/bucket-locks/ .
- **Lifecycle:** não usar regra geral de expiração de objetos para representar 30 dias de histórico Restic; snapshots de 30 dias podem depender de objetos mais antigos. Fonte: https://developers.cloudflare.com/r2/buckets/object-lifecycles/ .

## 7. Ordem de implantação candidata — somente depois de autorizações separadas

| Etapa | Trabalho que poderá ser autorizado no futuro | Entrada obrigatória | Critério de saída |
|---|---|---|---|
| 01 | Contratar/provisionar VPS Leaseweb | Checkout final, orçamento/teto e autorização financeira | VPS sob controle Imagine, sem instalações arbitrárias |
| 02 | Preparar sistema Linux de dev e segurança | Autorização operacional de infraestrutura; inventário, plano de rollback | Hardening, backup de configuração e logs revisados |
| 03 | Ativar/contratar R2 e definir conta/papéis | Autorização financeira e administrativa específica | Conta, faturamento e controles revisados |
| 04 | Criar buckets privados EU, tokens restritos e secret storage | Nome, segregação, localização, política de acesso e aprovação de execução | Jurisdição e políticas verificadas; nenhum acesso público |
| 05 | Homologar Restic em repositório **descartável** com dados sintéticos | Acesso e recursos de testes aprovados, sem dados reais | Backup + check + restore comprovados, falhas observadas |
| 06 | Homologar dumps SQLite/PostgreSQL e volumes por projeto | Gates de cada projeto que autorizem ambiente dev | Restore consistente por projeto, sem outro projeto exposto |
| 07 | Homologar prune e cópia protegida separada | Política de retenção, locks e identidade aprovadas | Restauração comprovada após manutenção e simulação de ataque |
| 08 | Agendar rotina, alertas e relatório | Autorização operacional, responsabilidade e custo | Alertas reais e recuperação de falha comprovados |
| 09 | Aceite operacional | Evidências de testes, custos e responsabilidade | Backup de dev declarado operacional pelo responsável |

**Nenhuma etapa desta sequência foi realizada.** A aprovação de implantação de infraestrutura não concede autorização de programação dos produtos. Universidade Gate C e CRM permanecem em seus estados próprios.

## 8. Cenários de teste obrigatórios (para execução futura, não realizada)

| ID | Caso de validação | Evidência exigida |
|---|---|---|
| DEV05-T01 | Bucket criado explicitamente com jurisdição EU | Configuração da jurisdição e endpoint da conta |
| DEV05-T02 | Bucket sem leitura pública | Teste de acesso anônimo negado |
| DEV05-T03 | Token do Portal NÃO acessa buckets de CRM/Universidade | Erros de acesso cruzado auditados |
| DEV05-T04 | Restic não permite restaurar com senha errada | Tentativa negada e conteúdo não exposto |
| DEV05-T05 | A origem perdida não impede acesso a chaves e instruções de recuperação | Recuperação a partir de custódia externa |
| DEV05-T06 | Backup do SQLite do Portal pode ser aberto após restore | Integridade SQLite e aplicação de teste |
| DEV05-T07 | Dump PostgreSQL restaura tabelas esperadas | Comparação de contagens/checksums/relatório |
| DEV05-T08 | Falha de dump/backup gera alerta e não falso sucesso | Evento e responsável notificados |
| DEV05-T09 | Indisponibilidade temporária de R2 não gera corrupção/eliminação | Retry seguro, alerta e reconciliação |
| DEV05-T10 | Retenção de 30 dias preserva todos pontos esperados | Simulação e resultado pós forget/prune |
| DEV05-T11 | Bloqueio/imutabilidade não impede o backup operacional | Restauração e manutenção comprovadas |
| DEV05-T12 | Recuperação de desastre ocorre sem VPS original | Host limpo, acesso custodiado e dados íntegros |
| DEV05-T13 | Tentativa de apagar ponto protegido com token do host falha | Exclusão negada e auditoria |
| DEV05-T14 | Uso/custo por projeto detecta anomalias e dispara alerta | Métricas de storage/operações e avisos |
| DEV05-T15 | Rotação/revogação de tokens não perde os backups históricos | Restore com novo acesso homologado |

Os testes são **planos futuros**, não foram executados nem têm resultado aprovado.

## 9. Matriz de pendências e alçadas

| Código | Decisão pendente | Status |
|---|---|---|
| DEV05-D01 | Ratificar buckets por projeto e bucket separado de recuperação protegida | **PROPOSTO** |
| DEV05-D02 | Definir se os buckets ficarão em conta Cloudflare exclusiva de backups ou conta existente segregada | **A DEFINIR** |
| DEV05-D03 | Definir nomes reais dos responsáveis por administração, substituição, segredos e auditoria | **A DEFINIR** |
| DEV05-D04 | Especificar os tokens mínimos viáveis para operações reais do Restic e manutenção | **A HOMOLOGAR** |
| DEV05-D05 | Validar formato/frequência de ponto protegido e compatibilidade com Bucket Lock | **A HOMOLOGAR** |
| DEV05-D06 | Dimensionar custo por projeto, GB-mês, operações, segunda cópia e teto total | **NÃO APROVADO** |
| DEV05-D07 | Autorizar formalmente contratação Leaseweb e Cloudflare R2 | **NÃO AUTORIZADO** |
| DEV05-D08 | Autorizar separadamente hardening, instalações, backups, testes e operação | **NÃO AUTORIZADO** |
| DEV05-D09 | Revisar incidentes de credenciais, conta sequestrada e perda de chave | **A DEFINIR** |
| DEV05-D10 | Determinar alçadas de exceção, recuperação, exclusão e restauração | **A DEFINIR** |

## 10. Próximo ato solicitado

Solicitar aprovação **apenas como base documental da INF-DEV-05**, especialmente o princípio de **isolamento por bucket/projeto**, separação do acesso administrativo e do mecanismo de recuperação e a matriz de homologação. Permanecem abertos os detalhes de conta, nomes, políticas exatas, permissões, custos e execução.

**Sem conclusão operacional:** a VPS não está comprovadamente contratada, nenhum bucket existe nesta documentação, nenhum backup foi efetuado e nenhum teste foi executado. Aprovação documental não é autorização financeira, operacional, de homologação de código ou de Gate C.

## Fontes técnicas consultadas

- Cloudflare R2: https://developers.cloudflare.com/r2/api/tokens/
- Cloudflare R2: https://developers.cloudflare.com/r2/reference/data-location/
- Cloudflare R2: https://developers.cloudflare.com/r2/buckets/bucket-locks/
- Cloudflare R2: https://developers.cloudflare.com/r2/buckets/object-lifecycles/
- Restic: https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html
- Cloudflare preços: https://developers.cloudflare.com/r2/pricing/
