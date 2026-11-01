# Imagine Portal — arquitetura e regras atuais

Site institucional e portal Node.js 22.16+/24 com HTTP nativo, SQLite persistente e uma instância da aplicação. Sessões HttpOnly, senhas scrypt, CSRF, verificação de titular e perfis no servidor.

Onepage: implantação em R$ 330/D0, R$ 330/D30 e R$ 330/D60; recorrência mensal obrigatória R$ 79,90 com início D90, autorizada na compra. Contratos antigos preservados no modelo legacy. A ativação por cliente e a retomada após atualização de cartão não exigem aprovação administrativa. [Detalhes](ASSINATURA-CLIENTE.md).

Portal centraliza materiais, sete dias úteis após validação, produção por marcos reais, prévias versionadas, considerações e aprovação antes de publicar. Administra atividades, equipe, permissões, financeiro, BI e fiscal. [Minha conta](MINHA-CONTA.md) reúne cadastro, fiscal e dados dos projetos.

Fiscal Focus NFe: implantação por entrega no valor total do serviço; assinatura por competência mensal. Credenciais e aprovação fiscal ainda pendentes. Não confundir recebimento e emissão da nota.

Dados de cartão transitam apenas durante a requisição de tokenização; PAN e CVV não são gravados. Tokens opacos são criptografados com chave do ambiente do servidor. Banco, uploads e chave de criptografia precisam de backup privado restaurável. Nenhum dado de runtime pertence ao repositório.

Estado: homologação local com gateway simulado. Sem implantação, credenciais financeiras reais, homologação bancária ou emissão fiscal real. A ativação financeira depende da configuração e habilitação do Asaas uma única vez para a operação; não de autorização manual por cliente.
