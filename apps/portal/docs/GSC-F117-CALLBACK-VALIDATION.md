# GSC-F117 — Interpretação estrita do callback OAuth Google (sem rota HTTP)

## O que foi implementado

A classe PHP de domínio `GoogleSearchConsoleCallbackParser` recebe **somente o QUERY_STRING bruto** e o state esperado. Ao contrário de `parse_str()`, identifica e nega chaves OAuth duplicadas, nomes alterados para arrays (`state[]`), campos adicionais, erros acompanhados de `code`, percent-encoding inválido e tentativas de ampliar o escopo.

O parser exige exatamente um state Base64URL canônico de 256 bits e o compara em tempo constante com o valor esperado. O código de autorização só é extraído quando todos os filtros sintáticos passam, inclusive validação do escopo `webmasters.readonly` se presente. Denega fragmentos, caracteres de controle, valores vazios, códigos fora de tamanho, alterações de `authuser` e `prompt` desconhecido.

O código extraído **não é um token** e **não autoriza nenhuma operação**. A classe não autentica a procedência do estado recebido do chamador, não consulta banco, não consome state, não efetua token exchange, não confere consentimento, MFA nem propriedade Google. Em backend real, somente uma sessão Portal autenticada com grant empresarial poderá fornecer o state confiável; o fluxo deverá usar o ledger F111/F112 para queimar state de uso único e revalidar as permissões antes da troca de código. O erro de OAuth não deve ser registrado com seus parâmetros brutos nem devolvido com detalhes ao navegador.

As rotas de início e callback Google permanecem inexistentes (404). Nada foi mesclado em main, implantado, enviado ao Google ou conectado a dados reais.

## Próximo requisito de integração

A equipe de AppSec/DBA precisará aprovar o algoritmo de callback, a vinculação à sessão e à propriedade, o armazenamento dos segredos, a política de revogação, o emissor de estado e a troca de código exclusivamente no backend. Esta PR não substitui esses gates.
