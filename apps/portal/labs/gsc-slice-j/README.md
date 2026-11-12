# GSC Slice J — Callback com state consumido uma única vez

A classe `GoogleSearchConsoleOAuthCallbackCoordinator` conecta três componentes concretos, todos dentro do backend, sem criar rota pública: preparação PKCE (G), classificação OAuth (H) e ledger PostgreSQL cifrado (I).

O método `review(callback, trustedContext, trustedNow)` executa:
1. Busca o state pendente em banco, ligado a `tenantRef`, `actorRef` e validade, sem aceitar metadados do browser como evidência.
2. Classifica o retorno `code` ou `error` e recusa escopos inesperados, estados expirados, parâmetros extras, injeções e callbacks inválidas.
3. **Consome o state atomicamente** com o `UPDATE ... RETURNING` da Fase I. Caso outro worker tenha consumido antes, retorna nulo e impede replay.
4. Para retorno com código, produz objeto interno `GoogleSearchConsoleOAuthCallbackOutcome` com código OAuth e verificador PKCE encapsulados em `SensitiveParameterValue`; `json_encode`, `var_export`, `print_r` e método `safeReview()` não expõem segredos. Para erro do provedor, descarta ambos.
5. `safeReview()` contém apenas status e flags **false** de OAuth, grants, troca de tokens, exibição do cliente e publicação.

**Importante:** `takeForTrustedBackend()` revela código/verificador exclusivamente para uma futura implementação interna de troca OAuth, que **não existe neste lote**. Nunca devolver esse método ou objeto para uma API de cliente, nem registrar logs. O código de erro/consentimento negado também consome o state para evitar replay.

## Testes reais

O workflow PostgreSQL descartável da matriz A–J cria registros com PKCE aleatório e valida o ciclo inteiro: callback válida com consumo único; segunda tentativa em duas conexões negada; consentimento negado consumido; cross-tenant e operador incorreto recusados; payload de autorização malformado não consome state; vencimento não permite reutilização; objetos e relatórios não revelam código, state ou verifier. Sem dados de cliente.

## Condições pendentes

Ainda faltam autenticação real Portal (sessão, MFA/RBAC e grants por tenant/propriedade), endpoint/callback HTTP, Google Cloud OAuth registrado, token exchange HTTPS, proteção de refresh tokens em vault, tratamento de revogação/escopo, limpeza e retenção do banco, staging e parecer independente AppSec/DBA/LGPD. Uma chamada interna bem-sucedida não concede permissão automática a nenhum desses recursos.

**DRAFT/NO-GO:** nenhuma rota pública, acesso Google, dados reais, merge dev/main, deploy, DNS/301 ou cobrança.
