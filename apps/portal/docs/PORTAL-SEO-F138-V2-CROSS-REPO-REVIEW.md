# Portal SEO F138 — verificação separada Ed25519 v2 do Engine F126

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação integrada do Portal RC49 #887; o verificador F122/v1 existente permanece exatamente inalterado.

## Contrato e fronteira de confiança

- Classe nova `PortalSeoSignedMultilingualV2Lab` usa domínio Ed25519 **diferente**: `IMAGINE-SEO-F126-SIGNED-MULTILINGUAL-REVIEW\n`.
- Aceita envelope `seo-f126-signed-multilingual-review-v2`, emitido pelo Engine F126, com relatório `seo-municipal-multilingual-review-dto-v2`.
- A chave pública de 32 bytes, tenant sintético, run sintético, commit imutável do Engine e hora confiável são **parâmetros do runner**, nunca copiados do envelope.
- Filtro fail-closed: schemas exatos, rejeita atributos extras, payload alterado, assinatura inválida, domínio v1, hash incorreto, base64 não canônico, nonce malformado, reports maiores que 16 KiB, timestamps vencidos e chaves erradas.
- Confere três status internos e valores de contadores para não aceitar `human_independent_review_required` com problema técnico ou editorial declarado.
- Não traz paths, cidade, código municipal, conteúdo, domínios ou hash de páginas na resposta. `publicationAuthorized`, `indexingAuthorized` e `customerDisplayAuthorized` são permanentemente falsos.
- Só executa em **PHP CLI**, `CI=true`, `APP_ENV=testing`, `PORTAL_SEO_F138_LAB=1`. Sem API pública, integração de login real ou autorização de produção.

## Interoperabilidade verdadeira em laboratório

`.github/workflows/portal-seo-f138-multilingual-v2-ci.yml` fixa o commit Engine:

`38b80fe77bd74ea6e19b8751014038ac3b9f8400`

Em PHP 8.3/8.4 + Node 22, a CI faz checkout desse commit, confere SHA, gera **HTML real temporário** em três idiomas, chama o emissor F126 do próprio Engine e envia ao verificador F138 do Portal. Há 11 cenários: válido, bloqueado por hreflang faltante, payload adulterado, chave errada, tenant divergente, expirado, versão errada, campo de autorização forjado no report assinado, status inconsistente, claim adicional e prefixo v1 indevido.

Chaves Ed25519 são descartáveis e geradas em memória no runner; arquivos temporários têm permissões privadas e são apagados. São credenciais de **teste sintético**, não KMS nem identidade real.

## Bloqueios operacionais

O protocolo F138 não cria uma rota HTTP de ingestão e **não** consome nonce em PostgreSQL; portanto, replay não está impedido nesse componente. A implementação real exige integrar com o ledger PG18 já desenvolvido na linha Portal RC47/RC49, controlar chave/rotação/revogação confiável, autenticação tenant/RBAC/MFA e revisão de migração por DBA/AppSec.

Faltam ainda fonte IBGE validada, SEO editorial legítimo, autorização de consulta Google Search Console, staging Leaseweb, restore/rollback e consentimento/publicação explícitos. Testes passarem **não** transforma a versão em produção autorizada.
