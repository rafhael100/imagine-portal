# PORTAL-F60 — tela demonstrativa offline de campanhas do Performance

Data-base 09/11/2026. F60 usa o leitor offline de dados do Performance F84 implementado no Portal F59. O gerador `apps/portal/tools/f60-render-performance-workbench.php` cria **um arquivo HTML estático**, sem JavaScript, scripts remotos, imagens, links, formulários ou endpoints, a partir de JSON rigorosamente validado pelo consumidor F59.

A tela apresenta contagens de campanhas gerenciadas/pausadas, revisão pendente, aprovação interna *sem execução*, rejeições e os canais Google Ads e Meta. Todos os itens exibem DEMO e a advertência explícita de que a operação e cobrança estão bloqueadas. As referências e textos passam por `htmlspecialchars`.

CI executa teste de schema e conteúdo, negativa de HTML quando produção/flags desativadas, rejeição de entrada maliciosa e CSP. O consumidor real de dados `Performance F84 → Portal F60` será testado em workflow separado do motor Performance com PostgreSQL16/RLS descartável e **sem deploy**.

Não há autenticação real, acesso a contas Google/Meta, aprovações operacionais, integração HTTP, processamento em filas ou liberação comercial. Mapeamento de estágios Portal continua não homologado. P0, HT-R01, SEC-R03/T3, AppSec/DBA, VPS e LGPD permanecem abertos.
