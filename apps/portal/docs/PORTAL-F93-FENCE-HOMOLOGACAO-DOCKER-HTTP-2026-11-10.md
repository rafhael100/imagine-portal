# PORTAL-F93 — FENCE de homologação Docker/HTTP (10/11/2026)

## Objetivo
Fortalecer a linha de segurança do Portal consolidada em F92, sem ativar rotas comerciais, usuários reais, Ads, billing, banco de clientes ou deploy.

O gate F93 é uma auditoria estática de repositório com mutações negativas dos arquivos reais de homologação, em duas versões do Node.js. Ele verifica:
- routes/web.php e routes/console.php como únicos arquivos de rotas; ausência de Route:: e exigência do health /up.
- imagem Docker com COPY allowlist, ausência de tools, tests, labs, .env, remoção de classes laboratoriais antes do classmap final e usuário www-data;
- Compose com apenas portal e gateway, um único mapeamento 127.0.0.1:18080:8080, isolamento de rede, read-only e sem privilégios;
- Nginx com exatamente location = /up (GET/HEAD) e location / com HTTP 404, sem novos caminhos, aliases ou proxy.

## Teste negativo
A suíte apps/portal/tests/ui/test-f93-homologation-fence.mjs cria um espelho temporário das configurações auditadas e confirma que o verificador nega mudanças em rotas, portas públicas, rede privada, privilégios Docker, inclusão de laboratório, autoload autoritativo, caminhos Nginx e /up.

## Limites e linhas divergentes
- review/portal-f92-budget-audit-security-convergence-2026-11-10 e dev/portal-f73-lab-runtime-exposure-guard-2026-11-10 seguem divergentes. F93 não os mescla e não certifica compatibilidade automática.
- F93 testa texto/configuração. Não observa a imagem efetivamente montada, não substitui o smoke Docker F64, a validação runtime F85/F88 ou a auditoria AppSec/DBA/LGPD.
- O gate bloqueia toda nova rota de negócio nesta etapa. Para ativar login/API/requisições autenticadas, será preciso novo contrato de autorização e revisão deliberada do gate.
- SHA-256 de pacotes DEMO não é autenticação, assinatura externa nem testemunha de rollback SEC-R03.
- A validação final exige sucesso dos jobs na HEAD da branch, além de revisão independente das alterações.

DRAFT / NO MERGE / NO DEPLOY / NÃO HÁ CUSTO NOVO. Main, VPS, DNS, clientes, Google/Meta e cobranças inalterados.
