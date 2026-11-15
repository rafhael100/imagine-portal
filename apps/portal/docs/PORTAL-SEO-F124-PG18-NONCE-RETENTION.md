# Portal SEO F124 — retenção mínima do ledger anti-replay PostgreSQL 18

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação de [F123 #862](https://github.com/rafhael100/imagine-portal/pull/862).

## Implementação
A F123 consome apenas uma vez cada relatório assinado, persistindo o digest do nonce sob RLS por empresa. A F124 adiciona **limpeza conservadora**, não um cron produtivo.

`PortalSeoPgEvidenceNonceRetentionLab::purgeExpired()`:
- É acessível somente em PHP CLI, `APP_ENV=testing`, `CI=true`, PostgreSQL de teste e feature flags específicas.
- Exige contexto tenant+actor resolvido pelo chamador; usa transação com `app.tenant_ref` restrito ao escopo local da transação, role sem superusuário nem bypass RLS.
- Exclui **apenas** registros com `expires_at < now - 3600`, preservando uma hora após a expiração do relatório.
- Limita a 500 registros por transação usando seleção limitada seguida de `DELETE ... RETURNING` atômico; a própria exclusão aplica os bloqueios necessários. Execuções simultâneas podem aguardar a mesma linha, sem duplicar exclusões. A consulta não exige privilégios de `UPDATE` do papel de limpeza.
- Retorna exclusivamente quantidades, não os hashes ou identificadores de empresas.
- Não habilita exclusão em produção nem acesso de clientes.

## Prova PG18
O mesmo job real do PostgreSQL 18 executa F123 antes de F124. F124 acrescenta 502 registros vencidos, dois não elegíveis (recentemente expirado e ainda ativo) e um registro vencido de outra empresa.

Espera 500+2 exclusões para a empresa principal; zero nas chamadas posteriores; nenhum registro ativo ou dentro do prazo de graça removido; o registro da outra empresa permanece até uma execução separada no contexto dela. Um papel somente leitura não pode limpar; a conexão sem `app.tenant_ref` não visualiza registros; modo produção é recusado.

Testar `.github/workflows/portal-seo-f123-evidence-pg18-ci.yml`, matriz PHP 8.3/8.4.

## Produção ainda bloqueada
Não há agendamento, política definitiva de retenção LGPD, métricas operacionais, notificações, revisão de backup/restauração, autenticação real do Portal, emissor gerenciado/KMS, rotação/revogação efetiva, nem infraestrutura VPS de homologação. O corte de 1 hora é critério conservador **de laboratório**, não política aprovada.

Nenhum cliente, token real, deploy, Google OAuth ou banco de produção é acessado.

## Ajuste de permissão verificado
A primeira tentativa de CI mostrou que o `FOR UPDATE SKIP LOCKED` exigia concessão extra de `UPDATE` no PostgreSQL. Em vez de dar essa permissão a um papel encarregado apenas de limpeza, a F124 usa DELETE atômico limitado, sob `SELECT` + `DELETE`. O lote máximo e a política RLS são mantidos.
