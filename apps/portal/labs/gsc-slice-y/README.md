# GSC Slice Y — envelope de POST OAuth de uso único

O Lote Y fortalece o último objeto interno antes da futura chamada HTTPS: `GoogleSearchConsoleOAuthTokenPostRequest`, que contém código OAuth, segredo do cliente e o verificador PKCE.

## Segurança implementada

- `takeForTrustedHttpsTransport()` só entrega o formulário **uma vez**. A próxima chamada retorna `null`, sem expor o código outra vez.
- Antes de entregar o formulário, o envelope é marcado como consumido e seu armazenamento privado é substituído por um objeto `SensitiveParameterValue` vazio. Isso reduz o tempo em que o objeto retém segredos; **não equivale a zerar todas as cópias de memória**, pois o backend receptor ainda precisa usá-las.
- `__clone`, `__serialize` e `__unserialize` são bloqueados explicitamente para impedir cópias acidentais em sessões, filas, logs ou caches.
- O método `safeReview()` continua mostrando somente parâmetros estruturais, nunca conteúdo sensível. A classe **não** envia HTTP e não concede permissão para usar tokens.

## Testes

O laboratório L comprova que serializar ou clonar é recusado e que uma segunda retirada do payload falha. Os laboratórios com PostgreSQL J/N validam o mesmo contrato após o callback legítimo, evitando reuso do plano.

Somado ao Lote X, o encadeamento agora exige **um único estado consumido no banco → um único plano de troca → um único envelope transportável**, tudo ainda sintético.

## Pendências

O próximo serviço HTTPS real precisará verificar configuração OAuth do Google, origem TLS/certificado, resposta estrita, sessão Portal e grants multiempresa. O transporte poderá falhar; não deve fazer replay do mesmo código, que é de uso único.

**DRAFT/NO-GO:** sem merge em dev/main, chamada real ao Google, credenciais de clientes, migração produtiva, deploy ou custo. Revisão AppSec/DBA/LGPD e staging autenticado obrigatórios.
