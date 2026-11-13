# SEC-R03-F10 — Publicação assinada e leitura posterior sobre estado SQLite confirmado

**Laboratório apenas. O signed receipt comprova uma declaração assinada de estado SQLite local, não uma revogação real durável nem testemunha externa.**

O `Tests\\Support\\DisposableSignedSqliteWitnessAdapter` une a tabela de eventos monotônicos de #619 aos contratos do domínio de #617/#618:

1. O teste prepara explicitamente um evento de origem na base SQLite descartável separada do PostgreSQL. Não existe controle administrativo ou endpoint operacional capaz de criar uma testemunha.
2. Ao receber um evento UUID de revogação, organização, pisos mínimos e nonce do cliente, o adaptador consulta um evento já registrado e, se necessário, executa append monotônico em uma transação SQLite. Só **depois do COMMIT** relê o evento durável e assina o comprovante de publicação `imagine-portal/revocation-ack/v1` vinculado ao mesmo UUID e nonce.
3. A leitura subsequente consulta **o próprio arquivo SQLite** e devolve uma nova assinatura Ed25519 do piso em resposta a outro nonce.
4. `PortalRevocationPublicationProbe`, `PortalSignedWitnessReader` e `PortalRevocationReadbackGate` usam os verificadores de domínio e exigem comprovação do evento mais duas observações concordantes. As classes operacionais seguem com transportes registrados **apenas como deny**, nunca como esse adaptador de laboratório.
5. Repetir o mesmo evento após reiniciar o leitor usa idempotentemente o estado gravado, sem incrementar artificialmente a sequência ou alterar pisos antigos. Lock de escrita impede emissão de comprovante até que uma transação seja concluída.

A suíte `DisposableSignedSqliteWitnessAdapterTest` cobre leitura depois de restart, dois eventos consecutivos, reprovação por lock, outra empresa, nonce inválido, chave pública incorreta, emissão após commit e um cenário adversarial explícito: **um arquivo SQLite antigo e a mesma chave efêmera permitem gerar assinaturas válidas para um piso antigo**. O gate, mesmo diante dessas assinaturas, **jamais autoriza acesso HTTP, sessão ou produção**.

Não há serviço de witness em VPS, independência física/administrativa, protocolo de confirmação de logout do Portal com transação PostgreSQL, geração de evento operacional, hardware seguro para chave, replicação remota, política de backups imutáveis, credenciais ou rota de produção. O protocolo é pré-requisito de investigação, não liberação. Issues #64 e #341 ainda bloqueadas por AppSec/DBA e homologação independente.
