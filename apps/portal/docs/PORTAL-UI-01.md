# PORTAL-UI-01 — Componentes visuais isolados

## Base e concorrência

Base conferida em 06/11/2026: `dev/fast-track-portal-2026-11-05`,
SHA `0aef6838b0452fcbb195fcad2e50aed748ec41c5`.
Branch filha: `dev/portal-ui-01-componentes-2026-11-06`.

Conferidas as branches P02-A/B/C/D, SEC-P01, domínio F1-01-B e revisão de segurança,
e os PRs documentais #19, #24 e #25 (abertos, sem alterações nesta entrega).
O padrão visual vem de `resources/views/performance/demo-dashboard.blade.php`:
paleta azul, navegação escura, cards, indicadores e tabelas. Seu arquivo e sua
lógica de negócio permanecem intactos.

## Arquivos criados

Nenhum arquivo existente foi modificado. Todos os caminhos abaixo são relativos a `apps/portal/`.

- `resources/views/components/portal/brand.blade.php`
- `resources/views/components/portal/header.blade.php`
- `resources/views/components/portal/sidebar.blade.php`
- `resources/views/components/portal/badge.blade.php`
- `resources/views/components/portal/message.blade.php`
- `resources/views/components/portal/unavailable.blade.php`
- `resources/views/components/portal/empty-state.blade.php`
- `resources/views/components/portal/loading.blade.php`
- `resources/views/components/portal/panel.blade.php`
- `resources/views/components/portal/indicator.blade.php`
- `resources/views/components/portal/service-card.blade.php`
- `resources/views/components/portal/list.blade.php`
- `resources/views/components/portal/table.blade.php`
- `resources/views/components/portal/layout.blade.php`
- `resources/views/components/portal/styles.blade.php`
- `resources/views/portal/demo/overview.blade.php`
- `resources/views/portal/demo/services.blade.php`
- `resources/views/portal/demo/available.blade.php`
- `resources/views/portal/demo/contracts.blade.php`
- `resources/views/portal/demo/modules.blade.php`
- `tests/Feature/PortalUiComponentsTest.php`
- `docs/PORTAL-UI-01.md` (este relatório).

## Uso

Componentes anônimos Blade no namespace `x-portal.*`: layout, marca, cabeçalho,
navegação lateral, card de serviço, indicador, painel de resumo, tabela, lista,
badge, mensagem, estado vazio, carregamento e aviso de indisponibilidade.
O partial `styles` centraliza CSS sem CDN, dependências, JavaScript ou build adicional.
Os breakpoints de 1050px e 640px mantêm a navegação visível e reorganizam os cards;
a tabela tem rolagem horizontal e região com foco por teclado.

Exemplo em uma view interna confiável:

```blade
<x-portal.layout title="Resumo">
    <x-portal.indicator label="Serviços" value="2" />
    <x-portal.panel title="Atividades">
        <x-portal.list label="Atividades" :items="['Exemplo fictício']" />
    </x-portal.panel>
</x-portal.layout>
```

Renderização interna das cinco telas: `view('portal.demo.overview')->render()`,
`services`, `available`, `contracts` e `modules` no mesmo namespace.
Não cadastrar rotas para essas views nesta etapa.

Props textuais e células usam `{{ }}`. Tons são limitados a uma lista fixa.
Slots são destinados a markup Blade confiável, com dados dinâmicos escapados pelo
chamador; não passar HtmlString nem HTML de usuário como slot/prop.
Não há encaminhamento arbitrário de atributos, URLs dinâmicas ou saída Blade bruta.
A aparência de um badge não representa autorização ou estado real de contratação.

As telas usam exclusivamente Empresa Aurora, DEMO-001 e exemplos fictícios.
CRM é um serviço independente com login e sessão próprios. Não há SSO,
provisionamento, acesso aos módulos, login ou assinatura real.

## Validação local e limites

- Verificação estática local em Python: **aprovada**, nos 21 arquivos de implementação
  e testes: escopo apps/portal, referências de componentes existentes, ausência de
  saída Blade bruta, forms, scripts, iframes e links externos.
- Comando tentado em `apps/portal/`:
  `php vendor/bin/phpunit tests/Feature/PortalUiComponentsTest.php`.
  **Não executado**: shell retornou 127, `php: command not found`.
  PHP e Composer não estão disponíveis; a obtenção do repositório por Git também
  falhou por indisponibilidade do proxy. Leitura e gravação do código via conector GitHub.
- Seis testes PHPUnit preparados: renderização das cinco telas; escape de payload
  HTML nas props e células; restrição de tons; estados vazios/carregamento;
  independência CRM; registro de rotas inalterado e sessão não iniciada.
  O serviço de banco é substituído por mock que proíbe `connection()`.
  Sem RefreshDatabase, migrations, fixtures de clientes ou requisições HTTP.
- Não foram executados testes de banco, autenticação, domínio ou do legado.
  Nenhum workflow foi disparado manualmente. Workflows preexistentes podem reagir
  automaticamente ao push/PR; não representam validação local desta entrega.
- Rotas, bootstrap, providers, configuração, dependências e domínio não são alterados.
  A base registra somente o health-check `/up`; nenhuma nova rota é adicionada.
  Confirmação por diff e inspeção estática; teste do registro em runtime ainda pendente.
- Revisão em navegador, teclado, leitor de tela e larguras desktop/tablet/celular
  permanece pendente. HTML semântico, contraste, skip link, títulos, caption,
  scope, foco visível e mensagens acessíveis foram preparados, sem afirmar homologação.

## Integração futura

Executar exclusivamente o arquivo de testes acima em ambiente local com PHP e
dependências do lock já disponíveis, sem banco. Revisar visualmente as cinco
telas e os estados. Depois, conforme novas autorizações, definir origem confiável
dos dados, contratos de view models, navegação e estados reais, autenticação,
persistência e autorização. Essas integrações não fazem parte deste PR.

Sem SQL, migrations, PostgreSQL/RLS, integrações, pagamentos, VPS, deploy,
alterações na main, merge ou modificação dos PRs #19/#24/#25.
