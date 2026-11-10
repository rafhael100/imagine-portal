# PORTAL-F59 — revisão integrada para homologação (não é liberação de produção)

Data de corte: **09/11/2026**, atualização final na janela UTC de 10/11/2026. Base da comparação: `dev/fast-track-portal-2026-11-05`.

## Evidência de código já validado

A linha Laravel Portal da consolidação F56/PR #187 foi ampliada por:

- **F57/PR #188**: ferramenta CLI de comparação do schema CreateProject v1 entre checkouts Portal e Sites, sem fetch remoto, com snapshot pinado. CI PHP/Node: sucesso.
- **F58/PR #189**: exportação de oito HTML fictícios e navegáveis (sete telas + índice) em `storage/app/portal-demo-preview`, somente `APP_ENV=local/testing`; nenhuma rota operacional criada. CI PHP/Node: sucesso; arquivo GitHub Actions com retenção de dois dias.
- **F59**: registro deste inventário e PR de revisão integrada, sem alteração de produção.

Na F56, a comparação com a base de dev mostrou **260 commits ahead, 0 behind, 185 arquivos** antes de F57/F58. A revisão final deve repetir compare e diff: estes números não são estatísticas de implementação produtiva.

## O que os CI comprovam (e o que não comprovam)

| Evidência | Comprovado | Não comprovado |
| --- | --- | --- |
| Unit/Feature PHP8.3/8.4 | Domínio sintético, views, limites HTTP e contratos locais | Usuários, MFA/roles reais ou permissionamento confiável |
| Node22/24 | Ferramental/legado testado | Backend operacional do novo Laravel |
| PG18 descartável | Isolamento, RLS e revogação apenas em fixtures | Banco/identidades/clientes reais em homologação |
| F57 | Schema candidato fixado igual ao checkout indicado | Origem remota autenticada, execução, assinatura, publicação |
| F58 | HTML de demonstração visualizável sem servidor | Navegação produtiva, login ou serviços online |

## Dependências de outras linhas que NÃO foram incorporadas por esta consolidação

1. **Autenticação/Performance:** PRs [#126](https://github.com/rafhael100/imagine-portal/pull/126), [#128](https://github.com/rafhael100/imagine-portal/pull/128) e [#130](https://github.com/rafhael100/imagine-portal/pull/130) tratam de emissor Ed25519 e autoridade PostgreSQL em **outra cadeia experimental**, sem prova de login/tenant/serviço produtivos. Não presumir que foram integrados.
2. **SEC-R03:** issue [#64](https://github.com/rafhael100/imagine-portal/issues/64), PRs [#124](https://github.com/rafhael100/imagine-portal/pull/124) e [#132](https://github.com/rafhael100/imagine-portal/pull/132): anti-rollback externo, witness e evidência de promoção não resolvidos operacionalmente.
3. **Comercial/SEO:** PR [#183](https://github.com/rafhael100/imagine-portal/pull/183) contém fluxo PHP/PostgreSQL→Astro em outra linha, ainda review-only; não integra automaticamente F59.
4. **Imagine Sites:** contrato candidato CreateProject v1, revisões/editorial/aceite/publicação reais devem ser integrados e homologados com versão compartilhada e identidade de serviço; snapshot F55 e verificador F57 não são API.
5. **VPS Leaseweb:** produção/homologação, backups externos, restauração, DNS e monitoramento não estão liberados pelo CI deste PR.

## Critérios objetivos de saída antes de merge de integração

- [ ] Auditoria independente dos arquivos alterados (incluindo dependências/lockfiles, permissões, licenças, segredos, fluxos de leitura/escrita, riscos LGPD e auth).
- [ ] Revisão específica das autoridades Portal-only: login real, recuperação, MFA, sessão, owner/tenant, revogação concorrente e isolamento do CRM.
- [ ] Evidência reprodutível de SQL/RLS em banco de homologação próprio, privilégio mínimo, migrações reversíveis e restauração com anti-rollback testemunhado fora do banco.
- [ ] Assinatura e transporte autenticados Portal↔Sites/Performance, HMAC/Ed25519 conforme protocolo acordado, idempotência persistente, at-least-once, auditoria e tratamento de falhas.
- [ ] Controle de aceite documentado do cliente vinculado ao conteúdo e versão exatos; nenhum status `approved_demo` deve conceder publicação ou faturamento.
- [ ] Homologação isolada na VPS liberada; runbooks de observabilidade, backup, restore, rollback, rate limit e incidentes; nenhuma credencial no repositório/CI público.
- [ ] Testes E2E de todas as jornadas aprovadas (cadastro/login, serviços, contratação, revisões, Sites, CRM, Performance e Universidade) com segurança de acesso, LGPD e responsabilidade jurídica.
- [ ] Decisão final de merge/deploy registrada separadamente; `main`, DNS, PSP, clientes e produção permanecem intactos até o gate.

## Próximo recorte de desenvolvimento recomendado

**PORTAL-AUTH-R01:** extrair e reconciliar os experimentos de autoridade dos PRs #126/#128/#130 com as regras do domínio Laravel F1 (sem copiar provas sintéticas como credenciais de produção); documentar interfaces, migrações e testes de revogação antes de abrir qualquer rota. Paralelamente, proteger anti-rollback SEC-R03 com testemunha independente e ensaio de restore. Só depois permitir endpoints com dados reais.

## Política desta entrega

**F59 é uma proposta de revisão em `dev`, não um release candidate operacional**. Não fazer merge automático, provisionamento, deploy ou faturamento. Os testes concluídos evidenciam apenas os recortes especificados. Não declarar porcentagem de produção a partir de número de PRs ou testes.
