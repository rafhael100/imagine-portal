# Prévia offline do Portal Imagine com a identidade original

Esta etapa permite avaliar o Portal Laravel **sem hospedar**, sem abrir rotas de clientes e sem serviços externos. A prévia preserva a identidade do Node original (`#F9941D`, `#171B19`, layout claro e **exatamente o PNG** de `main/assets/logo-imagine.png`).

## Como avaliar

1. Na PR de identidade visual/preview, abrir o workflow **Validacao isolada Laravel F1-01-A** do HEAD aprovado.
2. Baixar o artefato **`imagine-portal-original-brand-offline-preview`** (gerado pela matriz PHP 8.3).
3. Extrair o ZIP em uma pasta local. Abrir `index.html` diretamente no navegador. Seguir os links para as nove páginas fictícias, inclusive catálogo, contratos, solicitações e visão geral.
4. Conferir navegação e leitura em larguras aproximadas de **320, 390, 768 e 1440 pixels**, contraste, sidebar branca, foco de teclado, logomarca, tabela com rolagem e textos de demonstração. Registrar os ajustes necessários antes da homologação humana.

O HTML incorpora a logomarca oficial como `data:image/png;base64`, gerada diretamente do arquivo versionado **e validada pelo blob Git SHA-1**. Assim a imagem aparece mesmo sem rede ou servidor local. A CSP da demo continua bloqueando scripts e formulários; os únicos links da prévia são páginas locais conhecidas e “Pular para o conteúdo”.

**Manifesto:** `manifest.json` registra hashes SHA-256 das nove telas e do índice, além de `synthetic_only=true` e todos os campos de autorização como `false`. O manifesto mede integridade acidental; **não é assinatura criptográfica nem autorização de publicação**. A CI verifica o inventário de onze arquivos (dez HTML + um manifesto).

Para desenvolvedores com dependências PHP instaladas e `APP_ENV=local`, existe `PORTAL_ALLOW_LOCAL_STATIC_PREVIEW=1 php bin/export-portable-preview.php`. A saída fixa é `apps/portal/storage/app/portal-demo-static`. O destino deve estar ausente; o exportador não substitui arquivos, escreve em diretório temporário privado e só conclui por renomeação após verificar o pacote.

## Limites de segurança

- O pacote não autentica ninguém, não cria contratos ou sessões, não publica sites, não consulta PostgreSQL/CRM/Sites, não integra pagamentos e não concede elegibilidade à Universidade.
- Os links adicionados são **exclusivamente para HTML offline** e não existem como rotas Laravel. O ambiente operacional permanece fechado.
- Não inserir dados reais nos fixtures nem divulgar essa prévia como produto publicado.
- Revisão AppSec/DBA/LGPD, visual em dispositivos reais, anti-rollback de autenticação (issue #64) e decisão específica de merge/deploy (issue #341) continuam pendentes.

## QA responsivo no Chromium — regressão corrigida

Uma inspeção renderizada da prévia anterior identificou **rolagem global indevida: em viewport de 390 px o layout media 912 px**, causada pela coluna `1fr` no breakpoint `max-width:1050px`, cujo tamanho mínimo herdava o conteúdo horizontal da sidebar.

A correção substitui a coluna por `minmax(0,1fr)`, preservando o scroll **somente dentro do menu móvel e das regiões de tabela**. Em QA local de desenvolvimento com Chromium headless, **70 combinações (dez HTML × sete larguras: 320, 375, 390, 640, 768, 1024 e 1440 px)** não tiveram overflow global após injetar a correção; a alteração correspondente já está versionada no Blade. A verificação precisa ser **reexecutada no artefato do HEAD final** após cada alteração.

O teste reprodutível está em `apps/portal/tools/qa_portable_preview.py`. Requer Python Playwright + Chromium instalados localmente; não altera serviços nem publica páginas:

```bash
python apps/portal/tools/qa_portable_preview.py imagine-portal-original-brand-offline-preview.zip --chromium /usr/bin/chromium
```

A saída JSON informa quantidade de telas/larguras, verificação de scroll de tabelas por teclado e problemas encontrados. Código de saída diferente de zero bloqueia a avaliação técnica. O script aceita outros caminhos de navegador. No Windows, omita `--chromium` se o Playwright já tiver o Chromium instalado. A inspeção estrutural automatizada **não equivale a certificação WCAG nem substitui revisão visual humana**.
