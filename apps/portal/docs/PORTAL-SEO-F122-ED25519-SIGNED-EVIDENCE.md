# Portal SEO F122 — prova de origem do relatório com assinatura Ed25519

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação de [F121 #859](https://github.com/rafhael100/imagine-portal/pull/859).

## Entrega
Uma classe PHP isolada, `PortalSeoSignedBuildEvidenceLab`, recebe um envelope assinado por **Ed25519** contendo o JSON original de auditoria produzido pelo [SEO Engine RC45 #176](https://github.com/rafhael100/imagine-seo-engine/pull/176).

O Portal só valida esse envelope em **PHP CLI + CI=true + APP_ENV=testing** com as flags F118 e F122. Uma chave pública de confiança é fornecida **pelo processo chamador**, jamais extraída do envelope. O emissor do teste gera um par de chaves **efêmeras por execução**, sem salvar chave privada ou credencial de cliente.

A mensagem assinada usa uma constante de separação de domínio `IMAGINE-SEO-F122-SIGNED-BUILD-REPORT\n` seguida dos **bytes exatos** do JSON das reivindicações, evitando diferenças de serialização entre PHP e Node. O payload inclui hash SHA-256 do JSON F114 original; issuer/audience de laboratório; tenant, ID da execução e SHA exata do Engine; issuedAt/expiry com janela curta; nonce aleatório.

Depois da assinatura, o PHP recusa payload adulterado, versão inesperada, issuer/audience inadequados, empresa/execução errada, Engine não autorizado, timestamp expirado, hash incorreto, flags de autoridade falsificadas e dados inconsistentes. Só então reaplica o contrato restrito F118 e retorna dados **agregados, sem nomes, caminhos ou URLs**.

## Matriz CI
`.github/workflows/portal-seo-f122-signed-evidence-ci.yml`:

- Fixa o Engine em `7847c8c66d5be228a08a94282da562c790cdd440`.
- Gera HTML sintético em pasta temporária, chama a função F114 real e obtém JSON.
- Cria chaves Ed25519 descartáveis, assina o relatório e verifica com PHP 8.3/8.4.
- Testa 1 cenário legítimo e 9 negativos: assinatura inválida, payload alterado, outro tenant, outro run, expirado, outro commit Engine, chave pública diferente, issuer falso e campo de privilégio inesperado.
- Não acessa VPS, cliente, serviços Google ou rede IBGE. Descarta os arquivos temporários.

## Limites obrigatórios
**Assinatura válida de uma chave temporária não equivale a um emissor operacional confiável.** Não há KMS, distribuição de chaves, autenticação real de tenant, storage por empresa, trilha de auditoria persistente, nonce ledger de uso único ou proteção garantida contra replay dentro da janela. Todos esses gates são explicitamente falsos, inclusive `replayProtectionVerified=false`, `realSigningAuthorityVerified=false` e `publicationAuthorized=false`.

Antes do Portal exibir isso a um cliente, serão necessários: emissor real com chave privada protegida, chave pública confiável configurada do lado do servidor, controle de replay/revogação, vínculo tenant a sessão MFA, RBAC, banco seguro/RLS, revisão AppSec/LGPD e prova de deploy na VPS.

Nenhuma rota HTTP, configuração de produção ou custo foi habilitado.
