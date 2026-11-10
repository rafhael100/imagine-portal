# GSC-F111 — Callback vinculada à sessão original e revogação pós-emissão (laboratório)

## Alteração

Na F108, o callback verificava estado, usuário, empresa, propriedade e vencimento; na F110, a autorização de IAM ocorria **apenas na emissão**. Isso deixava duas lacunas para um backend futuro: a mesma pessoa poderia tentar completar um state com outra sessão ativa, ou um state emitido antes de uma revogação poderia chegar à troca de código sem nova verificação de permissões.

Esta etapa experimental F111:

1. Acrescenta à linha de state um digest SHA-256 da **sessão Portal original**, armazenado atomicamente após a verificação F110 e junto ao restante da transação.
2. Aplica o relógio **do PostgreSQL**, em vez de aceitar a data do callback.
3. Consome o state uma única vez usando o predicado PG F108 e, **somente após consumi-lo**, confronta o digest da sessão original, as revisões da identidade/membership/empresa, MFA, sessão ativa, grant `seo.gsc.connect` e propriedade candidata ainda permitida.
4. Retorna um envelope PKCE ainda **cifrado**, e apenas ao adaptador CLI de laboratório. Uma tentativa com sessão trocada, grant ou MFA revogado consome o state sem revelar dados.
5. Revoga o EXECUTE dos caminhos F108-consume e F110-issue ao papel cliente para que o fluxo de teste não possa contornar as barreiras.

### Provas

Os testes PostgreSQL18/PHP8.3–8.4 cobrem troca por outra sessão válida da mesma pessoa/empresa, revogação pós-emissão de grant, sessão, propriedade e MFA, não reutilização após recuperação do acesso, cliente sem SELECT direto e chamadas diretas antigas negadas.

## Segurança e limites

Ainda **não há login real**, autenticação HTTP, CSRF, sessão emitida pelo Portal, OAuth real do Google, token exchange ou KMS. Os dados são sintéticos no esquema real F66/F76 em banco descartável. O cliente de teste devolve o verifier somente ao próprio CLI e nunca a uma API. As policies RLS desta etapa e o enum `seo.gsc.connect` são apenas temporários no CI.

**Concorrência:** a revalidação reflete o estado do banco durante a chamada. A serialização frente a uma *revogação concorrente* e a revisão das transações/row-locks entre emissão, callback e publicação são uma etapa adicional obrigatória; não afirmar garantia absoluta de revogação contra transações que ainda não terminaram.

A implementação permanece draft NO-GO, sem merge main, alteração do runtime Laravel, DNS, produção, fornecedores, dados reais ou deploy. AppSec e DBA independentes devem revisar o fluxo completo e as margens de corrida antes de qualquer operação.
