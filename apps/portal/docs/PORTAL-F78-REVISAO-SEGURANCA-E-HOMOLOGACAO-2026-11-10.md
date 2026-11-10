# PORTAL-F78 — consolidação de segurança do Portal para revisão de homologação

**Corte:** 10/11/2026. Proposta de revisão sobre `dev/fast-track-portal-2026-11-05`, sem merge ou implantação.

## Estado do repositório efetivamente observado

Na comparação da branch F78 com a base de desenvolvimento, o GitHub relatou **364 commits à frente, 0 atrás, 276 arquivos alterados**. Isso evidencia que houve desenvolvimento, mas **não mede percentual de produto pronto ou cobertura de segurança**. Um diff de 276 arquivos exige revisão independente, inclusive licenças, lockfile, dependências, CI, caminhos de acesso, risco de vazamento de segredos, SQL e segurança da infraestrutura.

### Linha consolidada

| Versão | Código/resultado | Limite operacional |
| --- | --- | --- |
| F63 | Laravel/contratos Sites; preview HTML, gate SEC-R03 de NO-GO, PostgreSQL/RLS sintético, Ed25519 Portal→Performance | Sem usuários/clientes ou mídia |
| F64 | Docker privado com PHP8.4/nginx, `/up` único, porta loopback e rota negada | Não é VPS implantada |
| F65 | Preview offline do DTO Performance (Google/Meta) | Não controla campanhas |
| F66–F67 | Tabelas Portal-only de identidades, empresa, membership, grant e sessão; cinco modelos Eloquent | Sem política de leitura autorizativa ou login |
| F69–F70 | Argon2id; TOTP RFC6238; counter SQL monotônico e CAS | Sem MFA registrado na identidade real |
| F71–F73 | Transação sintética MFA→sessão e criptografia secretbox do segredo TOTP | Antes F75, o segredo ainda vinha do chamador |
| **F75** | Decifra o TOTP cifrado **da identidade bloqueada no PostgreSQL**, sem aceitar segredo informado pelo chamador; prova de replay/revogação | Secret key só do runner, SQL superuser de teste |
| **F76** | Predicado SQL `SECURITY DEFINER` com role **não-superuser/EXECUTE-only**, sem acesso direto a tabelas, conferindo sessão ativa, MFA, email, empresa, grant e revisões; nova revisão de organização nas sessões | Hash da sessão é bearer-equivalente; função é **somente laboratório** |
| **F77** | Rate limiter persistente PG18 com HMAC de escopo, cinco tentativas em 15 min, UPSERT atômico, role sem SELECT e `FORCE RLS` | Não é endpoint/login, falta proxy, gestão de pepper e anti-DoS |

### Evidência de teste e rastreabilidade

- F74/PR #208: [11 workflows SUCCESS na HEAD `176126cc7f00daaaf996d50f354f0e0099dcb508`](https://github.com/rafhael100/imagine-portal/pull/208).
- F75/PR #209: [CI PostgreSQL18 PHP8.3/8.4](https://github.com/rafhael100/imagine-portal/actions/runs/42753150314) **SUCCESS**, com casos de ciphertext adulterado, chave errada, revisão stale e replay.
- F76/PR #211: [CI PostgreSQL18 PHP8.3/8.4](https://github.com/rafhael100/imagine-portal/actions/runs/42753763517) **SUCCESS**, com roles de privilégios mínimos, revisão de organização, RLS, revogação e rollback.
- F77/PR #212: execução PostgreSQL18 PHP8.3/8.4 e smoke Docker ainda deve ser comprovada na HEAD, não inferida das etapas anteriores.
- A **própria HEAD F78 precisa executar novamente** Laravel, Node, PostgreSQL e Docker, inclusive workflows F75/F76/F77, antes de esta proposta ser considerada validada.

### O que o sistema ainda NÃO executa

O Portal Laravel servido pelo Docker F64 permanece fechado: apenas `/up` é rota HTTP. As telas de serviços/Performance são HTML internos. Os privilégios no PG F66 são default-deny. Os helpers de autenticação F69–F77 estão isolados: não há endpoints reais `/login`, registro de clientes, cookie de sessão, recuperação de conta, MFA real com chave custodiada, autorização por empresa em runtime, contrato assinado do cliente ou ativação de serviço.

### Critérios de liberação ainda abertos

1. **IAM/RLS:** revisão AppSec/DBA independente de funções `SECURITY DEFINER`, papéis dedicados sem superuser, provedores de segredo, verificação de identidade e proveniência, recuperação segura, autenticação de sessão, rate limit de ponta a ponta, e contra-ataque de enumeração/bloqueio por terceiros.
2. **SEC-R03/Z4:** testemunha antirollback externa **fora do backup PostgreSQL**, validação de recovery e revogação com evidência própria.
3. **Operação:** VPS Leaseweb homologada/isolada, segredos/rotação, HTTPS/TLS, proxy, backups, restore, logs/alertas, gestão de incidentes, mitigação de ataque, rate limits de gateway e runbooks.
4. **Negócios/integrações:** aceite versionado, LGPD, cobrança/PSP, integração real Sites/Performance/CRM, contratos elegíveis/Universidade, idempotência transacional, filas e E2E.
5. **Governança:** revisão de todo o diff, CI sem falhas, plano de deploy/rollback e aprovação específica de liberação; não promover `main` por contagem de testes ou PRs.

**Decisão: NO-GO operacional.** Este PR é apenas consolidação para revisão. Não houve merge na `dev` ou `main`, deploy na VPS, DNS, clientes, anúncios, cobrança ou contratação adicional. A execução futura com usuários reais depende dos gates acima.
