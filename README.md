# Imagine Portal

Site institucional, portal de clientes e administração da Imagine Comunicação Digital.

**Versão 0.1.0 — em homologação.** Ainda não implantado e ainda não homologado na conta Asaas. Os testes financeiros usam um provedor simulado; não geram cobranças reais.

## Iniciar localmente

Requer Node.js 22.16+ ou 24+, com `node:sqlite`.

```sh
npm test
npm start
```

Abra `http://127.0.0.1:4173/portal`. Não há dependências externas para instalar. Sem configuração Asaas, é possível usar contas e pedidos, mas a geração de cobranças permanece desabilitada.

## Estrutura

| Caminho | Conteúdo |
| --- | --- |
| `index.html` | Site da empresa |
| `portal.html`, `portal.css`, `portal.js` | Interface do cliente e administrador |
| `server/` | Autenticação, permissões, pedidos, banco, fila e integração Asaas |
| `tests/` | Testes automatizados |
| `assets/` | Logomarca original |
| `.env.example` | Exemplo sem credenciais |
| `.github/workflows/test.yml` | Testes automáticos no GitHub |

## Documentação

- [Instalação e operação](INSTALACAO-CPANEL.md).
- [Verificação e limites dos testes](TESTES.md).
- [Regras comerciais e arquitetura](docs/PROJETO.md).
- [Histórico de versões](CHANGELOG.md).

## Fluxo de trabalho sugerido

Manter o repositório privado na conta da Imagine. Usar `main` como base revisada e branches para alterações. Cada pull request deve descrever o que muda e passar nos testes. Versões aprovadas recebem tags e notas de versão.

O workflow está preparado, mas só executará após a publicação no GitHub e a habilitação do Actions. Não há deploy automático nesta versão. Uma atualização do código não altera o servidor até uma implantação deliberada.

## Configurações privadas e dados

Não versionar `.env`, chaves, bancos SQLite, backups ou dados de clientes. Configurar os segredos no servidor; futuras automações podem usar GitHub Secrets. Manter somente `.env.example`, sem valores reais.

O GitHub guarda o histórico do código. O banco de produção e os arquivos privados precisam de backup próprio, com teste de restauração.
