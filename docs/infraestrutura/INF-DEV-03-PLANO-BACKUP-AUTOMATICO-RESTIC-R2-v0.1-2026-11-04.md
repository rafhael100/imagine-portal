# INF-DEV-03 — Plano técnico de backup automático da VPS central Imagine

**Data:** 04/11/2026.
**Estado vigente:** **APROVADO COMO BASE TÉCNICA DOCUMENTAL** por [DEC-INF-DEV-03](DEC-INF-DEV-03-POLITICA-BACKUP-RESTIC-R2-APROVADA-2026-11-04.md). **Não aprovado para instalação, contratação, execução ou gasto.**
**Origem:** a direção respondeu “ok” à proposta de avançar somente com o **planejamento técnico** de backups Restic + Cloudflare R2.
**Referências:** [VPS Leaseweb INF-DEV-01](INF-DEV-01-VPS-CENTRAL-LEASEWEB-2026-11-04.md); [R2 EU aprovado como destino DEC-INF-DEV-02](DEC-INF-DEV-02-DESTINO-R2-EU-APROVADO-2026-11-04.md).
**Limite:** nenhuma compra, contratação, bucket, script, servidor, segredo, execução de testes, backup ou programação autorizados por esta proposta.

## 1. Arquitetura candidata

- **Origem:** futura VPS Leaseweb central de desenvolvimento (Ubuntu 24.04, projeto a projeto, dados fictícios); a VPS ainda não está comprovadamente adquirida.
- **Executante proposto:** Restic (backup incremental com criptografia no cliente) em serviço Linux com acesso restrito, disparado por temporizadores systemd, sem dependência de n8n/servidor de IA.
- **Destino já escolhido:** Cloudflare R2 **Standard**, bucket privado com jurisdição de armazenamento **eu**, fora da VPS de origem.
- **Endpoint técnico:** os buckets com jurisdição EU usam https://ACCOUNT_ID.eu.r2.cloudflarestorage.com, não o endpoint genérico. A jurisdição não poderá ser alterada depois da criação. Conta e bucket ainda não existem de forma verificada.
- **Separação entre projetos:** recomendar repositórios Restic distintos (Portal, CRM, Sites, Performance AI, Universidade), com caminhos, credenciais e escopo limitados. Para isolamento forte, avaliar buckets exclusivos por projeto. Não compartilhar chaves administrativas ou publicar objetos.
- **Custódia:** chaves de recuperação criptográfica guardadas fora da VPS e do GitHub, sob responsáveis nomeados. Identidade de backup não poderá administrar configurações globais de R2.
- **Limite:** esta VPS dev e seus backups não substituem backups de produção dos projetos; o servidor central de agentes/n8n está fora do escopo.

Fontes oficiais: https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html ; https://developers.cloudflare.com/r2/reference/data-location/ ; https://developers.cloudflare.com/r2/api/tokens/ .

## 2. Escopo de proteção por projeto

| Projeto | O que copiar quando existir no ambiente de desenvolvimento | Consistência e ressalvas |
|---|---|---|
| Portal Imagine | SQLite de testes, volumes de arquivos de testes e configurações persistentes | O Portal atual usa Node/SQLite; executar backup consistente pela API/ferramenta SQLite apropriada, não copiar cegamente banco aberto |
| CRM Imagine | PostgreSQL de desenvolvimento, uploads fictícios e configurações essenciais | Gerar dump consistente por base com pg_dump e validar; CRM de produção não é alvo |
| Imagine Sites | Artefatos de testes, volumes persistentes e arquivos não reconstruíveis a partir de GitHub | Não duplicar backups de produção de sites de clientes |
| Performance AI | Bancos/configurações de desenvolvimento e dados fictícios | Sem credenciais reais de anúncios e sem dados de campanhas reais |
| Universidade Imagine | PostgreSQL educacional de testes e conteúdo fictício **apenas quando sua implementação for autorizada** | Gate C não autorizado; não existe banco para copiar |

Exclusões propostas: segredos de produção, caches recomputáveis, diretórios temporários desnecessários, arquivos públicos já reconstruíveis pelo GitHub, banco de produção, dados pessoais reais e servidor de IA/n8n.

**Cuidado com bancos:** cópia de arquivos brutos de PostgreSQL em execução não substitui dump consistente. Restic permite guardar saída de comando controlado; validar exit code do dump e não marcar cópia vazia como sucesso. Fonte: https://restic.readthedocs.io/en/stable/040_backup.html .

## 3. Agenda candidata

| Rotina | Frequência proposta | Validação/critério |
|---|---|---|
| Preparar dumps consistentes por aplicação | Diária, janela noturna | Dump falhou = rotina do projeto falhou, com aviso |
| Restic backup para R2 | Diária, horários escalonados | Sugestão de janela 02:00–05:00 UTC; não sobrepor manutenção ou testes pesados |
| Verificar execução, idade do último snapshot e volume | Após cada backup e diariamente | Alertar para ausência de snapshot íntegro com mais de **36 h**, falhas de upload e custo fora do previsto |
| Restic check | Semanal | Verificar estrutura; inspeção com leitura de dados por amostragem em frequência dimensionada |
| Expiração e compactação (forget/prune) | Semanal, **após homologação** | Manutenção separada dos backups; sem exclusão antes de teste completo |
| Restauração de dados sintéticos | Mensal | Validar arquivos, aplicação e banco restaurados em ambiente isolado |
| Simulação de perda completa da VPS | Trimestral como ponto de partida | Recuperar sem depender de credenciais ou arquivos exclusivos da VPS perdida |

Esses horários/intervalos não constituem SLA de produção e ainda dependem de aprovação. Alertas devem ir a responsáveis e substitutos a nomear, sem exposição de senhas ou dados nos logs.

## 4. Política de retenção candidata

- **30 dias corridos de histórico** como proposta inicial, sujeita ao crescimento de dados, riscos e aprovação do custo. Validar regras de retenção reais de Restic por simulação, por exemplo janela temporal keep-within 30 dias, antes de automatizar exclusão.
- Toda rotina de limpeza deve ser executada **fora da janela de backup** porque prune impede backups concorrentes no repositório.
- Alertas e testes de recuperação têm precedência sobre economia de espaço: não remover cópias sem comprovar novas cópias válidas e política de proteção contra eliminação.
- Não aplicar expiração simples de objetos do R2 em todo o repositório ativo: objetos Restic são compartilhados entre snapshots e podem ser eliminados indevidamente por lifecycle de idade de objeto.

Fontes oficiais: https://restic.readthedocs.io/en/stable/060_forget.html ; https://developers.cloudflare.com/r2/buckets/object-lifecycles/ .

## 5. Proteção contra exclusão e ransomware

**Restic protege confidencialidade por criptografia, mas a credencial com permissão de apagar pode destruir backups remotos se a VPS for comprometida.** Proposta com duas camadas:

- **Camada A: repositórios Restic operacionais**, sem bloqueios de objeto incompatíveis com manutenção (forget, prune, unlock). Tokens por projeto e sem permissões de administração da conta ou das regras R2; analisar separação de token de escrita e identidade de manutenção.
- **Camada B: pontos de recuperação protegidos**, mantidos com credencial administrativa **fora da VPS**, em área isolada/bucket próprio, com políticas de retenção, cópia verificada, restore e prazo definidos antes de ativação. Avaliar snapshots imutáveis sem comprometer funções do Restic. Somente uma cópia sem permissão de exclusão no host não garante proteção contra problemas da própria conta Cloudflare.
- **R2 Bucket Locks** protegem contra excluir ou sobrescrever objetos, mas bloqueiam inclusive lifecycle e operações necessárias ao Restic e podem demandar regras por prefixo. Não configurar lock no repositório vivo antes de teste.
- Considerar uma segunda cópia em outro provedor se a criticidade ou risco operacional do desenvolvimento justificar.

Fonte: https://developers.cloudflare.com/r2/buckets/bucket-locks/ .

## 6. Validação de integridade, alertas e restauração

- Backup bem-sucedido exige **status de ferramenta + snapshot identificável + verificações mínimas**, não somente saída de script sem erro aparente.
- Bancos: preferir dump consistente validado. Restic recomenda mecanismo stdin-from-command para detectar falhas do produtor; pipe simples pode mascarar erro.
- Teste mensal: restaurar um projeto e seus dados sintéticos; verificar integridade, usuários, permissões, arquivos e operação da aplicação em ambiente isolado.
- Teste trimestral: simular perda da VPS, recuperar de armazenamento R2 com chave custodiada externamente, sem dados da origem.
- Auditoria de operações: guardar data, identificador do projeto, resultado, tamanho, último snapshot, alertas, ação corretiva e evidência de restauração, sem copiar payloads sensíveis para logs.
- Nenhuma prova de recuperação é declarada antes de execução real autorizada.

## 7. Custos a dimensionar e aprovar

- Armazenamento médio R2 Standard, operações Class A/B, tributação e câmbio, consumo de rede relevante, tempo humano de manutenção, volumes de proteção imutável, possíveis segundas cópias e incidentes.
- Avaliar cenários de **10, 50, 100 e 200 GB** de dados efetivamente retidos, não 300 GB de disco NVMe.
- Não assumir franquia de 10 GB da conta Cloudflare livre enquanto outros projetos Imagine a utilizarem.
- A proteção deve ter **teto de gastos e alçadas em moeda definida**, alertas antecipados e mecanismo de suspensão segura de consumo excedente antes de contratação.

## 8. Matriz de pendências

| Código | Decisão/validação pendente | Estado |
|---|---|---|
| BCK-03-01 | Restic como ferramenta-base para backup da VPS dev | **APROVADO DOCUMENTALMENTE**, sem instalação ou homologação operacional |
| BCK-03-02 | Periodicidade e retenção inicial; janela exata de execução | **DIÁRIO E 30 DIAS APROVADOS COMO POLÍTICA-BASE**; horários e operacionalização sujeitos a homologação |
| BCK-03-03 | Conta/bucket privado EU, tokens e governança Cloudflare | **NÃO CRIADOS** |
| BCK-03-04 | Inventário de volumes, dumps, tamanho e crescimento por projeto | **NÃO VERIFICADO** |
| BCK-03-05 | Criptografia, custódia e segregação de credenciais | **A IMPLEMENTAR APÓS AUTORIZAÇÃO** |
| BCK-03-06 | Camada anti-exclusão, compatibilidade e manutenção | **A HOMOLOGAR** |
| BCK-03-07 | Alertas, responsáveis, recuperação e testes periódicos | **A DEFINIR/EXECUTAR APÓS AUTORIZAÇÃO** |
| BCK-03-08 | Preço integral, teto e aprovação financeira | **NÃO APROVADOS** |
| BCK-03-09 | Contratação, instalação, automação e operação dos backups | **NÃO AUTORIZADAS** |

**Encaminhamento:** o presente documento é o resultado da aprovação para CONTINUAR PLANEJANDO. A escolha Restic e parâmetros gerais de periodicidade/retenção foram ratificados documentalmente em [DEC-INF-DEV-03](DEC-INF-DEV-03-POLITICA-BACKUP-RESTIC-R2-APROVADA-2026-11-04.md). A autorização de orçamento, bucket, aquisição/instalação, segurança e execução permanece posterior e expressa. O Gate C da Universidade e os bloqueios dos demais projetos permanecem intactos.
