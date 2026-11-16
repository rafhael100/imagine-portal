# Imagine Performance AI — Fase3, execução e limites

16/11/2026 UTC. DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.

**Resultado:** candidata com cliente Laravel e receptor PHP realmente separados por HTTPS/mTLS no CI, inbox PostgreSQL persistente, revogação e isolamento reforçados. Negócio/autoridades continuam sintéticos. Não é homologação operacional completa nem liberação para clientes reais.

## Bases efetivamente consultadas

| Referência | HEAD verificada antes de alterar |
|---|---|
| Portal891 | faeb6251cab35ae8860333788a13208add1c87a2 |
| Portal892 | 4012bb3d2669fd37c56af29a5f66efb5540e7a61 |
| Performance414 | 99300bb0259b557d0580674e37fdffe51e00d103 |

Todas abertas/DRAFT/não merged. O relatório Fase2 foi lido. Worktrees novos preservaram as alterações anteriores e as branches de outras frentes. Atualizações usaram lease de SHA; nenhuma main/dev foi alterada.

## Implementações e commits

| PR DRAFT | HEAD funcional | Entrega |
|---|---|---|
| [Performance415](https://github.com/rafhael100/imagine-performance-ai/pull/415) | 607b5bdb9263e0fe37fbf50174de141c99f306e5 | Tempo atual/revalidação após lock e leitura; SQL/recibo limitados |
| [Performance416](https://github.com/rafhael100/imagine-performance-ai/pull/416) | 3b2b9a56befb56cca6e57b95eb0de2ccb9b5f5cb | Adaptador receptor privado com principal técnico e lease vigente |
| [Performance417](https://github.com/rafhael100/imagine-performance-ai/pull/417) | 3b26c6a9425735cbf7e94add018996df5c096695 | Planos não executáveis de leitura Google/Meta, métricas null |
| [Performance418](https://github.com/rafhael100/imagine-performance-ai/pull/418) | 5c5802bbcf231853764d7241fd3acf1a35588de7 | RLS nativo da inbox, auditoria imutável e cache vinculado à revisão exata da concessão |
| [Portal898](https://github.com/rafhael100/imagine-portal/pull/898) | 1a4d88e78b6acba510f3772ed697baf37f260e29 | Destino HTTP privado pinado e limite de resposta |
| [Portal899](https://github.com/rafhael100/imagine-portal/pull/899) | 715f879057cca4df6a3f482c64f0fc399c45b467 | Política da ponte de identidade específica Performance |
| [Portal900](https://github.com/rafhael100/imagine-portal/pull/900) | e9e476ea065a5716681f796368ef3fe4ac48e73b | Candidata integrada, mTLS, teste real entre aplicações, Compose exclusivo/limitado |

Branches empilhadas nas bases das PRs anteriores, sem merge. O commit posterior a esta HEAD integrada acrescenta documentação, sem alterar código funcional.

## Revisão e correções

Revisão adicional **do autor**. Não foi realizada/aprovada por revisor externo independente; AppSec/DBA/UX independentes continuam pendentes. Escopo e lacunas detalhados em [gates operacionais](ADMIN-PHASE3-OPERATIONAL-GATES.md).

1. Inbox reutilizava horário inicial mesmo após esperar por lock/leitura. Agora usa relógio atual e revalida autoridade após lock e antes do commit; statement_timeout5s, lock_timeout2s e recibo64KiB. Expiração/revogação durante leitura causam rollback.
2. A aplicação filtrava empresas, mas a role SQL da inbox conseguia consultar todos os recibos. Migration candidata003 adiciona FORCE RLS por session_user/issuer/company, validade/revogação e auditoria imutável dos principals. Runtime tem SELECT/INSERT explicitamente só em recibos/nonces, sem autoridade/auditoria.
3. Cache não distinguia revisão da concessão. O adaptador inclui o fingerprint da autorização no digest idempotente; uma concessão nova não recupera o recibo da anterior.
4. Cliente HTTP agora exige host/porta exatos, IP privado RFC1918 pinado com CURLOPT_RESOLVE, CA/hostname verificados, certificado/chave cliente e ausência de proxy/redirects. Limites Content-Length/progress/body64KiB, JSON depth16 e erros sanitizados. Duas tentativas com nonce novo e idempotência estável; credenciais humanas não são encaminhadas.
5. Compose recebe namespace imagine-performance-admin-staging, CPU/RAM/PID limitados e logs rotativos. Não foi iniciado em VPS.

Falhas dos ensaios corrigidas: contagem de recibos de restore após novos cenários; comparação estrita de ordem das chaves JSONB; renderer de exceção Laravel que não produzia exit1 no verificador CLI (agora há teste de falha proposital); indentação YAML dos limites do DB; teste incorreto de CA usando o próprio certificado servidor como trust anchor. **A primeira execução com job verde mas verificador falhando foi descartada**, não usada como prova de sucesso. Nenhuma proteção operacional foi desligada para passar testes.

## Comunicação real entre aplicações no laboratório

O cliente Laravel SignedServiceReadClient envia HTTPS para um servidor TLS separado, pinado em IP privado do runner. CA/hostname e certificado cliente são verificados; o servidor compara serial inscrito e só então fornece principal técnico ao processo PHP receptor. Não há backend HTTP plaintext exposto nem sessão humana como identidade S2S. O receptor usa PDO com role restrita e a mesma inbox persistente/RLS.

O harness exige CI explícito, usa credenciais/certificados efêmeros e é removido ao terminar. Não é o bootstrap de um receiver operacional nem uma receita de deploy. Sem mocks Http::fake no ensaio HTTPS; os testes unitários do cliente continuam separados e rotulados.

Cenários: leitura N/D, repetição idempotente, empresa não permitida, revogação durante leitura e retry revogado, CA não confiável, certificado incompatível e certificado válido da mesma CA não inscrito,503, timeout com duas tentativas e resposta excessiva. Nonces, recibos e locks são PostgreSQL reais no runner. Nenhum anúncio ou financeiro externo é acionado.

O login/MFA real da Central é testado no mesmo CI, **mas ainda não existe a ligação operacional do usuário autenticado a uma delegação assinada do serviço**. Não confundir os dois testes com uma cadeia IAM real aprovada.

## Evidências por HEAD

| Escopo | Resultado |
|---|---|
| Performance418 /5c5802b | 14 asserções de planos read-only,24 contratos,42 PostgreSQL; laboratório administrativo43; backup cifrado/HMAC e restore separado aprovados |
| Portal900 /e9e476e | 18 testes/226 asserções, browser120, HTTPS/mTLS14, Laravel8.3/8.4, Node22/24 e staging Docker aprovados;8 check runs success (incluem Node push/PR duplicados, não8 suítes exclusivas) |
| Execução anterior integrada /f1a53c3 | 18 testes/226 asserções, browser120, HTTPS/mTLS14, Laravel8.3/8.4, Node22/24 e staging Docker aprovados; supersedida pela pinagem final |

[Performance CI43808103350](https://github.com/rafhael100/imagine-performance-ai/actions/runs/43808103350) comprova RLS nativo, duas identidades SQL, expiração/revogação, nonce/replay, idempotência/conflito, espera concorrente por lock, revogação durante leitura, nova concessão não reutilizando cache, recibos separados e recuperação de auditoria/revogação/nonce após restore. Não é teste de carga nem revogação distribuída atômica operacional.

Evidência visual: browser testa dez módulos,320/390/768/1440, senha/TOTP, pausa persistida, filtros/contexto, CSRF e seis perfis no backend. Artefatos credential-free incluem screenshots e análises axe, com retenção7 dias, sem credenciais/TOTP. Avaliação automatizada não substitui revisão humana de acessibilidade/UX. Links finais abaixo.

O executor local não possui PHP/Docker; testes PHP/PG/browser foram executados no GitHub Actions, não apresentados como execução local. Python compile/YAML parse/diff-check locais passaram.

## Identidade, fontes e financeiro

- Ponte candidata exige operador/empresa/produto Performance, contrato/serviço ativo, epoch de sessão, MFA recente, papel, permissão performance.admin específica, expiração/revogação e revisão. seo.view e empresa trocada negam acesso. Fonte operacional não está conectada; fixtures permanecem.
- Inbox possui segregação SQL nativa, mas faltam gestão operacional dos principals, cofre/rotação/revogação de chaves e fonte Portal de delegação por operador/contrato. Verificação por callbacks não substitui locks/fencing da autoridade sob concorrência distribuída.
- Google/Meta: planos de leitura com origem fixa e entradas/períodos limitados; nenhum SDK/conector autenticado foi ligado. N/D=null, sem métricas inventadas. ChatGPT Ads bloqueado. APIs/versões/consentimentos devem ser revalidados antes da ligação operacional.
- Baseline financeiro preservado: implantação490/canal,20% da mídia verificada e mínimo170 quando aplicável; verba separada. Proposta versionada de parcial/inclusão/retirada/suspensão/reativação/revisão está nos gates, **não aceita automaticamente**. Parcial continua bloqueado até decisão contratual. Asaas/C6 sandbox não consultados, sem transação ou credenciais.

## VPS, demonstração e NO-GO

Não houve comandos, inventário novo, deploy ou alteração na VPS nesta fase Performance. A tarefa anterior de Sites observou CRM18081 e iniciou Sites18090; isso não comprova porta livre/capacidade atual para Performance. Inventário fresco e escolha de porta continuam gates antes de qualquer futura implantação. A proposta Compose é exclusiva e não deve reutilizar redes, DBs, credenciais ou volumes existentes.

Sem nova URL de demonstração. Exigir domínio privado HTTPS autorizado, acesso restrito, autenticação nominal/MFA, APP_ENV=staging, identidades/segredos próprios, imagens/ações pinadas, TLS PostgreSQL verificado, backup externo/restauração/rollback no host e autorização específica de deploy. Runbook completo nos gates.

Para clientes reais permanecem NO-GO: revisão independente integral; fonte nativa de empresas/contratos/delegações e receiver operacional; rotação/revogação/auditoria de resultados ponta a ponta; dois operadores simultâneos na autoridade real; fontes oficiais read-only e conciliação; regra parcial aceita; gateways sandbox; inventário/domínio/acesso e recuperação na VPS. Nenhum resultado de laboratório fecha esses gates por flag.

## Links finais de execução e evidência

- [Central, navegador e aplicações HTTPS/mTLS — CI43808223792](https://github.com/rafhael100/imagine-portal/actions/runs/43808223792), HEAD e9e476ea065a5716681f796368ef3fe4ac48e73b. Logs confirmam18/226, browser120 e HTTPS14 às05:09 UTC.
- [Docker/staging real descartável — CI43808223843](https://github.com/rafhael100/imagine-portal/actions/runs/43808223843): build, migrations, runtime restrito, HTTPS/acesso nominal, gates desligados, login/MFA, sessões DB e navegador; limpeza dos recursos CI aprovada. Não é deploy na Leaseweb.
- [Laravel8.3/8.4 — CI43808223914](https://github.com/rafhael100/imagine-portal/actions/runs/43808223914) e [Node22/24 — CI43808223816](https://github.com/rafhael100/imagine-portal/actions/runs/43808223816), mesma HEAD funcional.
- [Performance418 — CI43808103350](https://github.com/rafhael100/imagine-performance-ai/actions/runs/43808103350), HEAD5c5802bbcf231853764d7241fd3acf1a35588de7: planos14, contratos24, PG42 e restore. O laboratório43 permanece separado, sem somar como cobertura exclusiva.
- Artefato visual verificado no run43808223792: **performance-admin-credential-free-evidence**, ID14198969766, digest SHA256 fe115e7b53b1c6e4086b9155cfdf886ba1bf5306f95f9cccbc08ef18510ed787, expiração23/11/2026 05:09 UTC. Abrir a seção Artifacts do run para screenshots/axe; não contém nova URL de aplicação ou credencial.

Nenhum teste funcional permanece vermelho na HEAD integrada acima. Os gates operacionais continuam NO-GO pelos motivos listados; isso é uma pendência de homologação, não um teste de laboratório falsificado como aprovado.
