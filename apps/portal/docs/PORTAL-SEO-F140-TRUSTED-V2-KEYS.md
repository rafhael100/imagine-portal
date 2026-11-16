# Portal SEO F140 — assinatura multilíngue com chave confiável e revogação sincronizada

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continua a linha F138/F139 (PRs #893/#894). Nenhuma rota, dado, assinatura ou serviço de produção foi ativado.

## Reforço da fronteira de confiança

A F139 aceita uma chave pública fornecida por um chamador sintético de CI. A F140 move essa confiança para o **registro separado PostgreSQL18** `portal_seo_build_signing_keys` já criado pela linha F127 e protegido por FORCE tenant RLS.

- O chamador confiável informa `tenantRef`, `actorRef`, `signerRef`, `runRef`, SHA imutável do Engine e relógio; **nunca fornece a chave de verificação** ao método F140.
- Sob a transação RLS, usa o **mesmo advisory lock** de F128/F129 em `(tenant, signer)`; lê no máximo quatro chaves ativas válidas para o horário.
- Cada chave pública tem base64 canônico e fingerprint SHA-256 conferidos. Uma única assinatura deve validar estritamente o contrato F126 v2.
- O nonce assinado é então consumido na **mesma transação**, por `ON CONFLICT DO NOTHING RETURNING`, na tabela existente de evidências. Sem chave cadastrada, chave expirada/revogada, outro tenant, signer divergente, assinatura adulterada ou token repetido, recusa a operação.
- A revogação utiliza F128/F129 com o mesmo lock e evento de auditoria obrigatório. SQL privilegiado fora desse protocolo continua sendo uma ameaça explicitamente não resolvida.
- Somente em CLI + CI descartável, com ambiente de testes e papéis SQL mínimos.

## Prova cruzada real

A CI F140 fixa o Engine F126 no commit `38b80fe77bd74ea6e19b8751014038ac3b9f8400`, executa o emissor Node sobre HTML local real PT/EN/ES e verifica em PHP 8.3/8.4 + PostgreSQL18. Testa recusa sem inscrição prévia; inscrição no banco; tenant/signer corretos; leitura SQL sem INSERT; consumo único; bloqueio editorial assinado; revogação e evento de auditoria transacional; assinatura de chave revogada negada; RLS multiempresa e negação fora do CI.

## Gates remanescentes

Sem custódia de chave KMS/HSM, emissão e distribuição confiáveis em produção, identidade real/serviço ativo, autenticação administrativa/MFA ligada a transações, política de rotação/revogação e retenção LGPD aprovadas, backup/restore e staging Leaseweb testados, qualquer implantação permanece **NO-GO**.

A origem IBGE ao vivo, o conteúdo original por localidade, traduções humanas e o consentimento/controle OAuth GSC ainda precisam de aprovação e validação operacional. Não publicar milhares de páginas automaticamente nem apresentar os resultados sintéticos como tráfego real.
