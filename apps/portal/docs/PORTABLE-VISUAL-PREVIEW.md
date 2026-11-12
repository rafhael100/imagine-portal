# Prévia offline do Portal Imagine com a identidade original

Esta etapa permite avaliar o Portal Laravel **sem hospedar**, sem abrir rotas de clientes e sem serviços externos. A prévia preserva a identidade do Node original (`#F9941D`, `#171B19`, layout claro e **exatamente o PNG** de `main/assets/logo-imagine.png`).

## Como avaliar

1. Na PR de identidade visual/preview, abrir o workflow **Validacao isolada Laravel F1-01-A** do HEAD aprovado.
2. Baixar o artefato **`imagine-portal-original-brand-offline-preview`** (gerado pela matriz PHP 8.3).
3. Extrair o ZIP em uma pasta local. Abrir `index.html` diretamente no navegador. Seguir os links para as sete páginas fictícias, inclusive catálogo, contratos, solicitações e visão geral.
4. Conferir navegação e leitura em larguras aproximadas de **320, 390, 768 e 1440 pixels**, contraste, sidebar branca, foco de teclado, logomarca, tabela com rolagem e textos de demonstração. Registrar os ajustes necessários antes da homologação humana.

O HTML incorpora a logomarca oficial como `data:image/png;base64`, gerada diretamente do arquivo versionado **e validada pelo blob Git SHA-1**. Assim a imagem aparece mesmo sem rede ou servidor local. A CSP da demo continua bloqueando scripts e formulários; os únicos links da prévia são páginas locais conhecidas e “Pular para o conteúdo”.

**Manifesto:** `manifest.json` registra hashes SHA-256 das sete telas e do índice, além de `synthetic_only=true` e todos os campos de autorização como `false`. O manifesto mede integridade acidental; **não é assinatura criptográfica nem autorização de publicação**. A CI verifica o inventário de nove arquivos (oito HTML + um manifesto).

Para desenvolvedores com dependências PHP instaladas e `APP_ENV=local`, existe `PORTAL_ALLOW_LOCAL_STATIC_PREVIEW=1 php bin/export-portable-preview.php`. A saída fixa é `apps/portal/storage/app/portal-demo-static`. O destino deve estar ausente; o exportador não substitui arquivos, escreve em diretório temporário privado e só conclui por renomeação após verificar o pacote.

## Limites de segurança

- O pacote não autentica ninguém, não cria contratos ou sessões, não publica sites, não consulta PostgreSQL/CRM/Sites, não integra pagamentos e não concede elegibilidade à Universidade.
- Os links adicionados são **exclusivamente para HTML offline** e não existem como rotas Laravel. O ambiente operacional permanece fechado.
- Não inserir dados reais nos fixtures nem divulgar essa prévia como produto publicado.
- Revisão AppSec/DBA/LGPD, visual em dispositivos reais, anti-rollback de autenticação (issue #64) e decisão específica de merge/deploy (issue #341) continuam pendentes.
