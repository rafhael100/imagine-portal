# PORTAL-PREVIEW-BLADE-01 — Conversão da prévia navegável em componentes Laravel Blade

**Data:** 08/11/2026. **Status:** incremento de interface demonstrativa em branch isolada, autorizado para programação e testes sintéticos; **não homologado**, **não publicado** e **não autorizado para merge**.

## Origem e limite

- Origem no [PR #59](https://github.com/rafhael100/imagine-portal/pull/59) (prévia HTML estática), HEAD `49e80ab20c7cc93a5d0d29c2ae86767c59fc5890`; o presente recorte é uma **branch filha** para reaproveitar os conceitos visuais sem ativar as funções do produto.
- Base do PR: `dev/fast-track-portal-2026-11-05` apenas para checagem CI. **O diff combinado inclui os quatro arquivos de PR #59, que continua em rascunho; não realizar merge agregado nem inferir aprovação automática do PR anterior.**
- `main`, DEV original, branches de autoridade, CRM, Performance AI, Universidade, VPS, pagamentos e operações de clientes **não foram alterados**.

## Componentes novos da entrega

| Arquivo relativo a apps/portal/resources/views | Finalidade |
|---|---|
| `components/portal/preview/shell.blade.php` | Estrutura visual autocontida, identificação de demonstração, menu acessível por âncoras de documento |
| `components/portal/preview/styles.blade.php` | CSS responsivo local de protótipo sem dependências externas |
| `components/portal/preview/nav-item.blade.php` | Item de navegação com lista fechada de seis destinos |
| `components/portal/preview/section.blade.php` | Seção com `id` e cabeçalho vinculados para navegação acessível |
| `components/portal/preview/metric.blade.php` | Cartão de métrica ilustrativa, com texto escapado pelo Blade |
| `components/portal/preview/service-card.blade.php` | Cartão reutilizável de serviço, com descrição e status de exemplo |
| `portal/preview/showcase.blade.php` | Composição das seis seções: visão geral, serviços, Performance AI, solicitações, Universidade e financeiro |

**A prévia Blade não precisa de JavaScript:** a navegação por âncora usa links locais e permite examinar as seis seções num único documento sem backend de sessão. O HTML anterior no PR #59 continua sendo uma prévia **estática e offline** independente.

**Sem rotas criadas:** `portal.preview.showcase` é uma view renderizável por teste ou em ambiente local autorizado, **não** um endpoint cliente nem uma área que esteja publicada online. Arquivos em `routes/web.php`, bootstrap, middleware e controladores existentes permanecem inalterados.

## Proteções

- Somente conteúdo **fictício** — Empresa Aurora, leads, solicitações, campanhas, números e textos de exemplos. A marcação não atribui contratos, direitos, permissões, dados ou faturas reais a ninguém.
- Domínios separados: **CRM sem SSO**, Performance AI com motor independente, Sites com Onepage/Landing Page independentes, Universidade condicionada a serviço elegível ativo (não assinatura avulsa).
- Nenhum formulário, CTA operacional, `<script>`, chamada de API, storage, e-mail, pix/boleto, anúncios, webhook, autenticação ou ingestão de dados. CSP da prévia contém `connect-src 'none'`, `script-src 'none'`, `form-action 'none'`. O `style-src 'unsafe-inline'` atende **somente** a renderização interna do protótipo autocontido e não determina CSP produtiva.
- Tokens de navegação limitados a seis valores por componente; texto dinâmico escapa via `{{ }}`.
- A visualização demonstrativa **não valida** acessos reais, políticas de RLS, segurança de sessões, assinatura, LGPD operacional ou fluxos externos. Quando houver autorização para publicação, será necessário desenvolver rotas, autenticação, verificação de organização e autorização, CSP e assets específicos sob outra aprovação.

## Evidência técnica

- `tests/Feature/PortalPreviewBladeTest.php` renderiza a view por Laravel sem conexão de DB e testa navegação, seis seções, sem links externos, sem scripts/formulários, escaping de dados hostis, ausência de mudanças na tabela de rotas e sessão não iniciada.
- Pipeline Laravel já existente `.github/workflows/laravel-f1-01-a.yml` testa PHP 8.3 e PHP 8.4 com Composer travado; os jobs Node e prévia HTML estática são independentes e devem permanecer verdes.
- **A CI só é considerada válida quando os workflows concluírem na HEAD exata do novo PR**, sem substituir inspeção visual real por aprovação automática.

## Decisão futura necessária

Antes de qualquer merge na DEV, integrar PR #59 e esta branch exige revisão de dependências, segurança, compatibilidade e autorização de merge **separada da Direção**. **Não liberar login, checkout, cobrança, clientes reais, campanhas, deploy, main nem VPS neste recorte.**
