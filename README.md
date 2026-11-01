# Imagine Portal

Site institucional, portal de clientes e administração da Imagine Comunicação Digital.

**Versão 0.18.2 — em homologação.** Ainda não implantado e ainda não homologado na conta Asaas. Os testes financeiros usam um provedor simulado; não geram cobranças reais.

## Iniciar localmente

Requer Node.js 22.16+ ou 24+, com `node:sqlite`.

```sh
pnpm install --frozen-lockfile --ignore-scripts
npm test
npm start
```

Abra `http://127.0.0.1:4173/portal`. O envio SMTP usa Nodemailer; instale as dependências antes de iniciar. Sem configuração Asaas, é possível usar contas e pedidos, mas a geração de cobranças permanece desabilitada.

## Estrutura

| Caminho | Conteúdo |
| --- | --- |
| `index.html` | Site da empresa |
| `portal.html`, `portal.css`, `portal.js` | Interface do cliente e administrador |
| `briefing-ui.js` | Formulário de briefing, arquivos e revisão |
| `server/` | Autenticação, permissões, pedidos, banco, fila e integração Asaas |
| `tests/` | Testes automatizados |
| `assets/` | Logomarca original |
| `.env.example` | Exemplo sem credenciais |
| `.github/workflows/test.yml` | Testes automáticos no GitHub |

## Documentação

- [Instalação e operação](INSTALACAO-CPANEL.md).
- [Verificação e limites dos testes](TESTES.md).
- [Regras comerciais e arquitetura](docs/PROJETO.md).
- [Fiscal, Focus NFe e cobranças](docs/FISCAL-E-COBRANCAS.md).
- [Assinatura automática e atualização de cartão](docs/ASSINATURA-CLIENTE.md).
- [Minha conta e cadastro](docs/MINHA-CONTA.md).
- [Histórico de versões](CHANGELOG.md).

## Fluxo de trabalho sugerido

Manter o repositório privado na conta da Imagine. Usar `main` como base revisada e branches para alterações. Cada pull request deve descrever o que muda e passar nos testes. Versões aprovadas recebem tags e notas de versão.

O workflow está cadastrado no GitHub Actions para executar os testes em Node.js 22 e 24 a cada push e pull request. Consulte a aba Actions para o resultado de cada execução. Não há deploy automático nesta versão. Uma atualização do código não altera o servidor até uma implantação deliberada.

## Configurações privadas e dados

Não versionar `.env`, chaves, bancos SQLite, backups ou dados de clientes. Configurar os segredos no servidor; futuras automações podem usar GitHub Secrets. Manter somente `.env.example`, sem valores reais.

O GitHub guarda o histórico do código. O banco de produção e os arquivos privados precisam de backup próprio, com teste de restauração.

## Materiais após o pagamento

O Onepage inclui briefing privado por pedido, com logomarca, manual, serviços, apresentação, links e contatos da empresa. O cliente pode salvar rascunhos e enviar para revisão. A Imagine aprova ou solicita complemento. A solicitação de apoio com IA fica registrada para a equipe; a geração automática não está implementada.

Arquivos ficam em `DATA_DIR/uploads`, fora da raiz pública, com limite de 10 MB por arquivo e 50 MB por pedido. O backup precisa incluir banco e arquivos. A migração de esquema 2 acrescenta as tabelas de briefing sem alterar pedidos existentes.

## Jornada de produção do Onepage

Preparação com briefing, produção com marcos reais, prévia versionada, duas rodadas de ajustes e autorização do cliente antes da publicação. Prazo para novas contratações: sete dias úteis após validar os materiais. [Regras e limites](docs/JORNADA-ONEPAGE.md). Migração aditiva 3 preserva os pedidos existentes.

## Recorrência e dados cadastrais

Novos pedidos incluem recorrência autorizada na compra, com mensalidade de R$ 79,90 a partir do 90º dia. A implantação usa três cobranças de R$ 330 em D0/D30/D60. O cliente atualiza o cartão e retoma a recorrência pelo portal, sem aprovação da Imagine. Contratos legados preservam suas condições. A configuração real e homologação ainda são necessárias.

Configure BILLING_TOKEN_KEY de 64 caracteres hexadecimais no ambiente privado para criptografar tokens Asaas. Não grave o cartão completo ou CVV. Uma instância da aplicação precisa permanecer ativa para a fila de parcelas e conciliação. Dados de conta e cadastro fiscal têm validação e isolamento por titular.
