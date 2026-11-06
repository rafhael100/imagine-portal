# PORTAL-UI-02 — QA visual das cinco telas Blade

## Escopo e origem

Issue [#31](https://github.com/rafhael100/imagine-portal/issues/31).
Base conferida: `663e7d26f6bced2bc8bae53c962786401b12a0b7`,
`dev/fast-track-portal-2026-11-05`, com UI-01 integrada.
Branch: `dev/portal-ui-02-qa-visual-2026-11-06`.
PR [#32](https://github.com/rafhael100/imagine-portal/pull/32), manter aberto em rascunho, sem merge.
PRs abertos #19/#24/#25 conferidos e preservados.

Revisão realizada em **06/11/2026, aproximadamente 14h00–14h05 (America/Sao_Paulo)**.
Ambiente de captura: Chromium 156.0.8078.79, Linux Debian 13, Playwright Python já
instalado, headless, device scale factor 1. Nenhum pacote instalado.

## Método e proveniência

O executor local não possui PHP/Composer. O Blade foi renderizado de verdade pelo
Laravel no CI existente, com as dependências do lock; HTML fictício exportado
temporariamente nos logs de um teste sob o mesmo mock PHPUnit que proíbe conexão
de banco. Nenhum HTML foi reconstruído manualmente nem gerado por imagem.

- Baseline: commit `18fdf0a17aad1d5bc9330ad185bd043652a7633b`,
  [CI de exportação](https://github.com/rafhael100/imagine-portal/actions/runs/42257437959).
- CSS/tabela corrigidos e fonte das capturas finais:
  `5fd3d4ed9558abc1140f2e60753480e2d96404a5`,
  [CI de renderização](https://github.com/rafhael100/imagine-portal/actions/runs/42257873321).
- [Log PHP 8.3 contendo HTML Base64 das cinco views](https://github.com/rafhael100/imagine-portal/actions/runs/42257873321/job/128029765713).
  Marcador: `PORTAL_UI_HTML:<view>:<base64>`.
- O teste temporário de exportação foi removido na entrega final; componentes,
  estilos e conteúdo capturados são os mesmos da entrega. Nenhum workflow alterado.

O navegador recebeu o HTML em memória via `page.set_content()`, sem servidor,
rotas ou acesso a produção. Navegação file:// estava bloqueada pela política do
Chromium; set_content foi usado para o documento local. A execução do Chromium
exigiu permissão de processo fora do sandbox restrito. Requisições HTTP/HTTPS
foram bloqueadas pelo Playwright; zero requisições observadas nas quinze páginas.

## Inventário das capturas reais

Viewport CSS definido antes da captura; `full_page=True` inclui toda a altura do
documento, portanto os PNGs podem ser mais altos que o viewport. Arquivos na pasta
temporária `/workspace/work/ui2-final-shots/`, não versionados no repositório:

| View | Desktop 1440×900 | Tablet 768×1024 | Celular 390×844 |
| --- | --- | --- | --- |
| overview | overview-1440.png | overview-768.png | overview-390.png |
| services | services-1440.png | services-768.png | services-390.png |
| available | available-1440.png | available-768.png | available-390.png |
| contracts | contracts-1440.png | contracts-768.png | contracts-390.png |
| modules | modules-1440.png | modules-768.png | modules-390.png |

Também gerados `contracts-focus-{1440,768,390}.png`, três pranchas
`contact-{1440,768,390}.png`, `checks.json` e `manifest.json`.
As três pranchas e a captura de foco em 390px foram exibidas no chat da execução.
**Limite de distribuição:** PNGs são temporários no workspace, sem URL de download
persistente ou artefato de imagens no CI. O diretório de outputs indicado pelo
cliente não pôde ser criado (permissão do filesystem). Os logs acima permitem
verificar e recuperar o HTML, mas não são um upload das capturas. Não prometer
disponibilidade futura dos PNGs nem afirmar que foram anexados ao GitHub.
Nenhum binário foi commitado.

Hashes SHA-256 de duas evidências representativas:
- `contracts-390.png`: `329789475f27f10f42d6e9c5e160daa3487911de4e79ff68d67c08f9e22c74b7`.
- `services-1440.png`: `fef9d3a3f50ffb421066a5ba5702968760ed7354d1c1d5b0cb83e16d35f670be`.

## Observações por tela e correções

| Tela | Desktop | Tablet | Celular |
| --- | --- | --- | --- |
| Visão geral | Indicadores e resumos sem sobreposição; topo consistente após correção comum | Grade 2+1 dos indicadores preservada; navegação com cinco itens | Coluna única; estados vazios legíveis; página longa com rolagem vertical |
| Meus serviços | Antes o conteúdo curto ficava centralizado verticalmente; agora começa no topo | Dois cards legíveis e alturas consistentes | Cards empilhados; avisos fictícios mantidos |
| Serviços disponíveis | Quatro cards e avisos legíveis, sem contratação | Grade 2×2 sem recorte | Coluna única, CRM independente explícito |
| Contratos | Tabela e mensagem de erro alinhadas; correção do topo comum | Quatro colunas cabem | Antes quebrava palavras dos cabeçalhos; agora largura mínima 36rem, rolagem interna e orientação visível |
| Módulos | Grade e estado de carregamento sem sobreposição | Dois cards por linha e navegação visível | Cards empilhados; estado estático e acesso indisponível explícitos |

Alterações finais:
- `resources/views/components/portal/styles.blade.php`: `margin:0 auto` no main,
  alinhamento ao início no layout de tablet/celular e tabela com largura mínima,
  preservando rolagem apenas na região da tabela; espaçamento da orientação.
- `resources/views/components/portal/table.blade.php`: instrução visível de rolagem.
- `tests/Feature/PortalUiComponentsTest.php`: teste da orientação, região focável,
  caption e quatro cabeçalhos semânticos; mock PHPUnit `never()->connection()`,
  escaping, tons, sessão e igualdade do registro de rotas preservados.
- Este relatório. Nenhuma view de demo foi alterada; nenhum componente novo.

## Verificações efetivamente executadas

Inspeção das quinze capturas em pranchas, mais foco da tabela em celular:
sem sobreposição observada. Medições DOM nas quinze combinações:
- `documentElement.scrollWidth === innerWidth`; nenhum overflow da página.
- Nenhum recorte de largura nos h1/h2/p/badges/itens de navegação verificados.
- Exatamente cinco itens de navegação ilustrativa e um `aria-current="page"`.
- Zero forms, inputs, selects ou botões operacionais.
- Tab alcança o skip link; Enter move foco para `pi-content`.
- Em contratos, próximo Tab alcança a região da tabela; ArrowRight rola
  horizontalmente em 390px (320px disponíveis, 576px de conteúdo).
  Desktop/tablet não precisam dessa rolagem.
- `role=alert` presente somente na demonstração de erro em contratos.
- No desktop, main inicia em y=0 nas cinco telas após a correção.

Reflow equivalente a ampliação de 200%: repetidas medições com metade do viewport
CSS (720, 384 e 195px). Sem overflow da página; tabela continua em região própria.
**Não foi exercitado o controle nativo de zoom do navegador**, nem zoom de texto
do sistema; esse ensaio de reflow não deve ser descrito como homologação de zoom.

Contraste calculado pela fórmula de luminância sRGB WCAG para os onze pares
textuais usados na paleta: mínimo **5,66:1**, máximo 14,95:1. Exemplos:
muted/branco 6,11; muted/fundo 5,74; navegação 10,73; estado ativo 11,07;
info 8,06; sucesso 6,69; aviso 6,54; erro 7,04.
Cores não precisaram ser alteradas. Não foi executado axe nem leitor de tela;
checks DOM e contraste são verificações limitadas, não auditoria completa WCAG.

## Testes e CI

[CI de renderização do HTML corrigido](https://github.com/rafhael100/imagine-portal/actions/runs/42257873321):
PHP 8.3 e 8.4 aprovados, **111 testes / 1634 asserções** por versão, incluindo o
exportador temporário (que não permanece no HEAD final).

Comando do workflow existente: `php artisan test --no-ansi`.
O CI final após remover o exportador deve ser conferido no
[PR #32 / Checks](https://github.com/rafhael100/imagine-portal/pull/32/checks);
o registro de encerramento no PR fornece SHA final, run e totais finais.
O resultado do run de exportação acima não substitui a validação do SHA final.
O workflow geral do Portal também deve ser conferido para o mesmo SHA.

Não houve teste PHP local, pois o binário está ausente. Não houve banco,
migrations, testes de produção ou conexão com integrações. As rotas existentes
`up` e `storage/{path}` são apenas comparadas no teste; não foram alteradas.

## Reprodução sem rotas

Em ambiente local que já tenha PHP, vendor do lock e Chromium/Playwright:

```php
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$path = sys_get_temp_dir().'/portal-ui-02';
if (!is_dir($path)) { mkdir($path, 0700, true); }
foreach (['overview','services','available','contracts','modules'] as $screen) {
    file_put_contents($path.'/'.$screen.'.html', view('portal.demo.'.$screen)->render());
}
```

Executar o script acima via CLI a partir de `apps/portal`, fora do servidor web.
Não cadastrar rota. Abrir cada HTML em memória com Playwright:

```python
from pathlib import Path
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium')
    for name in ['overview','services','available','contracts','modules']:
        for w,h in [(1440,900),(768,1024),(390,844)]:
            page = browser.new_page(viewport={'width':w,'height':h}, device_scale_factor=1)
            page.route('http://**/*', lambda r: r.abort())
            page.route('https://**/*', lambda r: r.abort())
            page.set_content(Path('/tmp/portal-ui-02', name+'.html').read_text())
            page.screenshot(path=f'/tmp/portal-ui-02/{name}-{w}.png', full_page=True)
            assert page.evaluate('document.documentElement.scrollWidth === innerWidth')
            page.close()
    browser.close()
```

O caminho temporário depende do sistema; ajustar `/tmp` ao diretório produzido pelo
PHP. Asserções de isolamento de banco/sessão/rotas são responsabilidade da suíte
PHPUnit, não do script de exportação manual.

## Pendências

Leitor de tela humano, zoom nativo 200%, outros motores de navegador/dispositivos
reais e hospedagem durável das capturas permanecem pendentes. Navegação ocupa
espaço vertical no celular, mas mantém todos os cinco itens e aviso ilustrativo;
não foi introduzido menu interativo. Revisão humana e autorização de integração
são posteriores. Nenhum gate G-ID/G-BB/P0 é homologado por esta atividade.

Sem login, sessões reais, banco, SQL, migrations, pagamentos, integrações,
produção, VPS, alteração de main ou merge.
