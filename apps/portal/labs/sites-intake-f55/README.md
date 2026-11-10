# PORTAL-F55 — Paridade de contrato CreateProject v1, somente snapshot offline

Cópia textual de `rafhael100/imagine-sites/contracts/portal-sites/v1/create-project.schema.json`,
observada em **09/11/2026**, revisão do Imagine Sites `ca51fb4fb2e625f140e253d7820da42b4ab78a49` (PR #92, F1-70) e SHA de blob `042f4ba31776b486ced757e9624bc42757c46e74`.

**Não é um segundo protocolo nem um validador JSON Schema de produção.** A fonte de verdade permanece no repositório motor Imagine Sites. O snapshot existe exclusivamente para testes determinísticos offline da compatibilidade Portal F50-F54.

`SiteEngineCreateProjectSchemaParityTest.php` compara:
- versão Draft 2020-12, oito propriedades requeridas em ordem canônica, `additionalProperties=false`;
- `schemaVersion`, `contractRevision` inteiro positivo e dois `productCode` permitidos;
- tipos e regex de cinco referências;
- payloads sintéticos Onepage e Landing Page gerados no Portal em relação ao snapshot.

Os testes **não comprovam sincronização automática** com futuras revisões do Sites. Ao atualizar o contrato upstream, comparar diff, validar semântica, revisar com AppSec/DBA e criar PR coordenado nos dois repositórios. Os testes locais F52 exigem serialização PHP mais restritiva do que o schema genérico.

Permanecem bloqueados autenticação, aceite, order/contract real, publicação, faturamento, integração de rede e deploy. DRAFT SEM MERGE, sem VPS nem custo.
