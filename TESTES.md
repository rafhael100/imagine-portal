# Validação da versão 0.18

Executar `npm test`. A validação final reúne 65 testes com provedores simulados; não gera cobranças ou notas reais.

Cobertura: autenticação/CSRF/titularidade; propostas; preservação do legado; briefing e arquivos privados; jornada e aprovação; atividades e BI; regras fiscais; cadastro fiscal; contatos e e-mail; recorrência D90; parcelas D0/D30/D60; falhas e timeouts; retomada pelo cliente; proteção contra duplicidade; token criptografado sem PAN/CVV; rotas oficiais Asaas; dados de conta e troca de e-mail protegida por senha.

Prévia visual local com cadastro e projetos fictícios. Sem envio de cartão real, cobrança real, emissão fiscal, troca de senha ou aceite de contrato no navegador.

Limites: não houve homologação na conta Sandbox do usuário, nem validação de adquirência/3DS, entrega de e-mail real, regras municipais em produção ou instalação no servidor. Checkout público direto ainda é prévia; a contratação integrada está no pedido autenticado.
