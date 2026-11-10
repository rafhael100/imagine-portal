# PORTAL-F97 — Remoção de classes e páginas DEMO da imagem Docker

Data: 10/11/2026. Ramo derivado da F96, sem merge na main.

## Alterações verificáveis

A configuração de homologação F64, preservada e endurecida pelas revisões F92–F96, agora remove, **antes** do composer dump-autoload autoritativo:

- classes PHP com padrão de arquivo *Demo*.php sob app/Domain e app/Presentation;
- resources/views/portal/demo (páginas internas de exemplo);
- resources/views/performance/demo-dashboard.blade.php (dashboard fictício).

Continuam removidos app/Labs e quatro consumidores offline já excluídos em F92. A imagem permanece sem tools, tests, labs, database ou .env, com usuário www-data, rede privada e exposição HTTP restrita a GET/HEAD /up por Nginx.

## Evidências

- F97 acrescenta verificações estáticas aos testes negativos do gate F93 para recusar perda da remoção de classes e páginas demonstrativas.
- O workflow F97 roda essas mutações em Node 22 e 24.
- O smoke Docker F64 roda automaticamente no PR e inspeciona **a imagem executada**, exigindo ausência das páginas DEMO, arquivos PHP *Demo*.php e classes autoloadable demonstrativas selecionadas.
- Em caso de falha, o PR não está aprovado para integração; conferir resultados na HEAD exata.

## Limites

Esta redução de superfície é conservadora, mas não transforma o Portal em produto pronto. Outras classes read-only sem nome Demo, dependências e recursos Blade legítimos podem permanecer no runtime. A ausência dos arquivos DEMO não substitui IAM/RLS, AppSec, homologação na VPS, controle de segredos, SEC-R03 ou consentimento de clientes. Todas as funções HTTP de negócio continuam intencionalmente fechadas.

Sem deploy, contas reais, cobrança, integração Ads ou novos custos. PR draft / NO-GO de produção.
