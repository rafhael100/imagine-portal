# PVIEW-04 — demonstração navegável para baixar, sem deploy

**Data:** 08/11/2026. **Objetivo aprovado pela Direção:** disponibilizar uma forma simples de **visualizar e navegar** nas telas do Imagine Portal enquanto os módulos reais continuam em desenvolvimento protegido. Não criar site público, dados reais ou login fictício que se confunda com autenticação.

## Entrega

O workflow `.github/workflows/portal-f8-portable-preview.yml` utiliza a Blade PVIEW-03 já aprovada como referência visual para gerar um **único `index.html` portátil**, inteiramente estático. O arquivo é criado **somente** sob `RUNNER_TEMP` no GitHub Actions e validado com o mesmo teste Chromium CDP das oito seções e três tamanhos de tela.

O ZIP do artifact `Imagine-Portal-Demonstracao-Navegavel` inclui `index.html`, `LEIA-ME.txt` e capturas sintéticas na pasta `capturas/` produzidas pelo teste de navegador. O usuário abre o HTML no browser local depois de descompactar o ZIP; não precisa de PHP, servidor, rede ou login.

**Instrução de acesso:** abrir o PR PVIEW-04, selecionar o run `Portal F8 - downloadable navigable offline preview` em **Checks/Actions**, baixar `Imagine-Portal-Demonstracao-Navegavel` nos **Artifacts** e descompactar. GitHub Actions exige eventual login GitHub para baixar artifacts, dependendo da visibilidade e permissões. O prazo de retenção do artifact é de **7 dias**; a branch e o workflow persistem para nova execução se necessário. O arquivo **não é publicado via Pages, Cloudflare ou domínio**.

## Escopo visual e proteção

- Oito áreas PVIEW-03 fictícias: visão geral, serviços, catálogo, solicitações, contratos, performance, universidade e avisos.
- Interação de navegação em hash `#pview-*` puramente local; sem chamadas HTTP, banco, cookies, credenciais, API, contratação, cobrança, envio de solicitação ou integração com CRM/Performance/Sites/Universidade.
- `render_blade_preview.php` valida oito seções, oito links e impede `form`, `iframe` e script remoto antes de gravar; CI executa as verificações no Chrome em três tamanhos e não permite requisições externas.
- A CSP inline usada para o mock **não** é configuração aceitável de produção. O visual ainda exige revisão humana, leitor de tela, zoom 200%, autenticação/tenant real, persistência e gates de segurança.
- Esta entrega independe do PostgreSQL F1–F7: o preview é apenas interface, não apresenta dados reais nem afirma que um cliente já possa entrar.

## Segurança e governança

- O workflow exige branch filha `feat/portal-f8-portable-preview-2026-11-08`, não contém segredo operacional e usa apenas Composer lock do repositório, Node nativo e Actions já utilizados na PVIEW-03.
- **SEM MERGE**, sem VPS, Cloudflare, abertura de rota, banco real, push de dados, custo de fornecedor contratado ou publicação.
- Artifact não é hospedagem nem prova de produto homologado; o fluxo de clientes continuará bloqueado até gates P0, D02/D03, SEC-R03/T3 e release.
- Evidência de CI com SHA e ID do artifact deve ser registrada no PR após o run completar. Não presumir que artifact existe sem resposta da API do GitHub.

**Próximo recorte de engenharia independente:** recuperação após restore de backup anterior à revogação, com fonte de corte fora do snapshot em laboratório; conexão do visual real ao backend somente após o fechamento das políticas operacionais de autenticação e autorização.
