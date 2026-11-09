# SEC-R03-C01 — Candidatos para autoridade Z4 realmente independente

**Estudo documental sem implantação — 09/11/2026.** Anexo à PoC sintética Modelo C, PR #116. Nenhum fornecedor escolhido, ambiente provisionado, conta acessada, despesa aprovada ou arquitetura de produção homologada.

## Necessidade objetiva

A PoC reproduziu o **KNOWN GAP X03/X22**: copiar o arquivo antigo de Z4 antes de uma revogação permite autorizar um ator que já havia sido revogado na autoridade atual. O `OK` do teste confirma a *presença do risco*, não a segurança. A arquitetura operacional precisa de:

- Uma única autoridade de serialização por instalação (CAS verificável);
- Âncora de geração/high-watermark não reversível no mesmo domínio de falha do banco de negócio, do host ou do operador;
- Histórico íntegro de revogações posterior aos backups;
- Papéis/credenciais segregados para leitura operacional, revogação, recuperação e abertura;
- Fence verificável para HTTP, SQL, CLI, filas, jobs, workers, n8n e efeitos externos;
- Operação fail-closed na ausência de Z4, com impacto de disponibilidade documentado;
- Prova de ausência de split-brain, restauração integral do host e reabertura auditável.

## Alternativas para investigação

| Candidata | Potencial | Riscos ainda não resolvidos | Situação |
| --- | --- | --- | --- |
| **Cloudflare Durable Objects com SQLite** como coordenador por instalação | Armazenamento transacional e fortemente consistente por objeto, fora da VPS Leaseweb | PITR também restaura objeto antigo; precisa âncora monotônica e política de recovery independente. Free tem limites cuja superação interrompe operações. Conta e credenciais com privilégios elevados também são risco comum | **Preferência de estudo**, sem escolha de produção |
| **Cloudflare R2 com bucket locks** como histórico adicional append-only | Retenção impede overwrite/delete de objetos protegidos, com consistência forte por objeto | A regra de bucket lock pode ser removida por administrador autorizado; não equivale a WORM inviolável de outra entidade, não resolve CAS no coordenador e é mesmo ecossistema Cloudflare | Candidato a evidência auxiliar, NÃO substitui Z4 |
| **PostgreSQL/ledger em infraestrutura fisicamente separada** com identidade segregada | SQL transacional, CAS e domínio de host independente | Novo ambiente/custos, HA/backup/roles, administração, falhas de rede, sincronismo com commit do banco de negócio e independência de operador | Sem contratação autorizada |
| **Workers KV como Z4 principal** | Serviço simples e conhecido | Consistência eventual e cache de valores antigos não satisfazem revogação imediata / CAS de autoridade | **Não recomendar como fonte de verdade** |
| **Arquivo local, R2 backup simples ou GitHub Actions** | Bom para demonstrar testes | Não constitui autoridade de revogação operacional monotônica com barreira de commit; restauração pode reviver dados | **Rejeitar como Z4 de produção** |

## Fatos conferidos em documentação oficial (09/11/2026)

1. Cloudflare Durable Objects SQLite: armazenamento transacional e fortemente consistente, **por objeto**. O mecanismo tem recurso PITR; restaurar conteúdo antigo do próprio objeto precisa ser coberto pelo desenho de segurança. Fonte: https://developers.cloudflare.com/durable-objects/api/sqlite-storage-api/
2. Durable Objects disponíveis nos planos Workers Free e Paid; o Free permite somente SQLite e, **quando excede limites, a operação falha**. É inadequado assumir disponibilidade sem dimensionamento. Fonte: https://developers.cloudflare.com/durable-objects/platform/pricing/
3. Workers KV é eventualmente consistente, podendo manter versões antigas em cache; impróprio para o high-watermark em que revogações precisam ter visibilidade síncrona. Fonte: https://developers.cloudflare.com/kv/reference/faq/
4. Cloudflare R2 Bucket Locks pode impedir overwrite/delete durante retenção; o próprio gerenciamento permite **remover regras**, o que exige segregação administrativa e trilha independente. Fonte: https://developers.cloudflare.com/r2/buckets/bucket-locks/
5. R2 oferece consistência forte de leitura/escrita de objetos, mas IAM/permissões têm propagação eventualmente consistente e não substituem autoridade de segurança. Fonte: https://developers.cloudflare.com/r2/reference/consistency/

## Recorte técnico proposto para próximo ensaio, SEM autorização de deploy

- Projetar um protocolo mínimo genérico `GetFence`, `CASGeneration`, `AppendRevocation`, `BeginRecovery`, `RecordSnapshot`, `MarkReady`, `RequestOpen` **sem** implementação de Cloudflare/Leaseweb.
- Modelar em simulador um único sequenciador lógico de autoridade e um registro append-only mantido por papel separado. Injetar leitura antiga, queda, replay, duplicação, snapshot adulterado, CAS concorrente e tentativa de abrir sem prova.
- Criar manifesto fechado de writers apenas **proposto**, com caminhos `HTTP/SQL/job/CLI`, mas não alegar inventário operacional completo antes do desenvolvimento.
- Elaborar critérios de admissão de infraestrutura real: isolamento de contas/admins, backup independente da âncora, prova de recuperação da própria Z4, custos máximos, limite de requisições, alertas, RPO/RTO, disponibilidade, região, LGPD e revisão AppSec/DBA.
- Nenhum CLI deploy, namespace DO, bucket lock, conta, token, URL pública, faturamento ou alteração de VPS deve ser executado com base neste estudo.

## Dependências de decisão exclusivas da Direção

**Permanecem ABERTAS** D04 (topologia externa e domínio de falha real), D05 (custódia e pessoas que podem autorizar OPEN), orçamento e fornecedores, revisão de segurança e acesso a ambientes. A autorização para **PoC sem despesas** não é autorização para contratar, ativar recursos de nuvem nem conceder poderes ao coordenador. Regra de segurança: não homologar nem publicar até essas decisões e testes adversariais em ambiente separado.
