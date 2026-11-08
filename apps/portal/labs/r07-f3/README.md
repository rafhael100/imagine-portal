# PORTAL R07-F3 — laboratório lógico/offline de confirmação vinculada à intenção

**Autorização de continuidade gerencial:** Issue #56. Este protótipo existe **exclusivamente para validar invariantes sintéticas** descritas no PR #53 e não está autorizado para login, MFA, WebAuthn, pagamento, aprovação de usuário real ou integração ao Portal.

## Resumo

- Sem pacotes externos, banco, rede, HTTP, Docker, credenciais, PII, VPS ou conexão de serviços; script Python 3.12 de biblioteca padrão.
- A autoridade fictícia mantém atores A/B, recursos A/B, sessão, epoch, membership, grants e versão de política apenas em memória.
- Intenções ficam associadas a sessão, ator, tenant, recurso, ação de cobrança **fictícia**, valor em centavos, moeda, destinatário, nonce, revisões e validade, calculadas por hash canônico versionado.
- Um **mock local de canal independente** produz HMAC para simular confirmação. Não é autenticação humana, não possui separação física, não tem UX, verificador WebAuthn real ou proteção T3. O teste R07-F3-T25 imprime esse limite expressamente.
- O efeito é um registro numa lista Python, não transação financeira. Um lock local impede duplicação do MESMO intent entre threads; não prova locks PostgreSQL, idempotência entre intents, processos, filas ou outbox. Sem dados financeiros reais.
- Nunca importar este módulo no Laravel, frontend, worker, servidor ou n8n, nem armazenar segredos de clientes no GitHub público.

## Testes / interpretação

27 testes unitários sintéticos verificam:
- origem do ator e empresa derivada da autoridade em vez de claims da borda, validação de input/canonicalização;
- impossibilidade de usar prova de outra intenção e mudança de montante/destinatário sem invalidar confirmação;
- rejeição por sessão falsa, validade expirada, revogação, suspensão, grants, membership, epoch, política e recurso reassociado;
- consumo único/idempotência do mesmo intent em thread lock e erro de permissão sem efeito;
- **KNOWN RESIDUAL T3:** se quem domina Z1 também puder acionar o mock de confirmação, o efeito é autorizado. Nada nesta PoC representa vontade independente da pessoa.

CI é somente comprovação do código lógico simulado. Os TX-N01..TX-N20 do PR #53 permanecem **NOT RUN como teste de arquitetura operacional**. P0 R01-01/02/03/09/10, G-ID/G-BB/D02-SEC/D03/RLS, parecer AppSec/DBA e release permanecem abertos.

**Como reproduzir em ambiente de teste:** executar `python3 -m unittest discover -s apps/portal/labs/r07-f3 -p 'test_*.py' -v` a partir do checkout. O CI usa Python 3.12 padrão do runner e nenhum ambiente de produção.

**Sem merge:** PR draft contra dev; main, dev, VPS e credenciais não são alteradas. Qualquer evolução para armazenamento PostgreSQL, canal independente, WebAuthn, login ou infraestrutura exige escopo/parecer/aceite específico.
