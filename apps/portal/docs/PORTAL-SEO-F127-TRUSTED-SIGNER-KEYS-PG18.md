# Portal SEO F127 — chaves públicas confiáveis do emissor, isoladas por tenant

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continua [F126 #868](https://github.com/rafhael100/imagine-portal/pull/868).

## Avanço

A F126 já demonstrou a cadeia completa: o Engine assina a própria auditoria, o Portal verifica a assinatura e o banco consome uma vez o nonce. O teste ainda recebia a chave pública pela própria rotina de fixture. Em produção, isso seria insuficiente: um atacante poderia gerar seu próprio par de chaves e assinar um relatório fraudulento, caso a aplicação aceitasse qualquer chave enviada junto com a requisição.

A F127 prepara uma **fronteira de confiança do servidor**:

- Migration `2026_11_15_000008_create_portal_seo_build_signing_keys.php`: chaves públicas Ed25519 cadastradas separadamente por `tenant_ref + signer_ref + fingerprint`, status ativo/revogado, validade e `ENABLE/FORCE ROW LEVEL SECURITY`.
- `PortalSeoPgTrustedBuildSignerLab::accept()` não aceita chave pública como argumento e não a extrai do envelope. Recebe apenas tenant/ator/emissor/run/commit/tempo definidos pelo chamador **sintético de CI**. Consulta no PostgreSQL até quatro chaves públicas **ativas e válidas** para aquele contexto.
- Verifica que a chave pública tem 32 bytes e que seu SHA-256 coincide com o fingerprint armazenado; valida assinatura Ed25519 e os campos fechados do F122.
- Usa a **mesma transação tenant-RLS** para inserir o nonce consumido uma única vez. Um relatório assinado, mas não cadastrado com chave pública de confiança, é recusado sem gravar nonce.
- Emissões válidas que diagnosticam problemas técnicos continuam `REVIEW_BLOCKED`, nunca autorizam publicação.

## Testes PostgreSQL 18

A CI usa o **Engine F121 real e imutável** para gerar os relatórios e suas assinaturas e executa Laravel/PDO em PHP 8.3 e 8.4:

1. Chave não cadastrada: recusa.
2. Chave registrada para tenant e emissor corretos: primeiro uso aceito, replay recusado.
3. Registro com emissor diferente: recusa.
4. Chave de outra empresa: recusa; RLS impede consulta de seus dados.
5. Papel de banco somente leitura: não pode inserir nonce.
6. Segunda chave ativa para rotação: só aceita relatórios assinados por ela, mantendo status de erro técnico quando aplicável.
7. Chave revogada antes do recebimento: recusa.
8. Payload alterado, assinatura desconhecida e expirado: recusados.
9. Contexto `APP_ENV=production`: recusa.

## Limite de segurança ainda aberto

O cadastro de chaves é feito por administrador **de teste**. Ainda não existe KMS/HSM, autenticação da autoridade emissora, registro seguro das chaves de produção, revogação administrativa autenticada, controle de troca ou sincronização de revogação concorrente. A F127 **não comprova que uma revogação simultânea e um consumo de relatório sejam serializados entre si**; sua resposta mantém `keyRevocationRaceProven=false`.

Falta integrar emissor operacional, sessão Portal/MFA/tenant, fiscalização LGPD/AppSec, banco e rede de staging, políticas de retenção aprovadas, backups e reversão. Não há rota HTTP, publicação, segredo produtivo, VPS ou contas reais alterados.
