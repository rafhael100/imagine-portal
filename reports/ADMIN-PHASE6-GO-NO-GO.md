# Fase6 — decisão de instalação privada Imagine Performance AI

16/11/2026. DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.

**Status: NO-GO operacional.** Desenvolvimento/candidata preparados, sem instalação ou nova URL. Não é pedido para contornar gates pendentes.

## PRs e SHAs

- Portal#917 base83c4e37e06b6b8daefbe8f84153946856eed27b3, preservada.
- Performance#419 base0fec80aa81a81a103481664dd568f79bd85ce588, preservada.
- Engine#420 código `d15de1ae9704feee6f9fcf4497f19721b95b5b95`: correções de revisão independente por agente.
- Portal#920 candidata integrada Fase6; código testado3da1eaa980ee402bb4160dbcaaf2fe2a2567c777; inventário integral em ADMIN-PHASE6-INVENTORY.json.

## Testes e revisão

Portal código final **3da1eaa980ee402bb4160dbcaaf2fe2a2567c777**. Engine pinado **d15de1ae9704feee6f9fcf4497f19721b95b5b95**. Relatório/procedimento de cache posteriores alteram só documentação.

- [CI Portal43977679598](https://github.com/rafhael100/imagine-portal/actions/runs/43977679598), job133685755262: success.77 testes/380assertions; um teste contextual executado em etapas próprias. Duas execuções HTTPS,1teste/16assertions cada;14checks de navegador desktop/mobile, senha/MFA, empresa autorizada, receiver separado e correlação.
- Renderização base e base+Access aprovadas sem iniciar serviços. Smoke adicional do **Compose exato** iniciou seis serviços descartáveis não root, capacidades removidas, doisPGs e HTTPS401/200. FlagsOFF, nenhum schema/grant administrativo criado nesse smoke: não confundir com a jornada funcional acima ou com TLS/SQL runtime completo do pacote na VPS.
- PHASE6_INGRESS_SANITIZATION_PASS: querycanary não aparece nos logs de ingresso, projeção limitada a schema/request_id/status/duration e respostas401/200 observadas. NativePerformance log usa evento/UUID fixos. Logs operacionais externos continuam pendentes.
- Oito execuções de workflows aprovadas na mesma HEAD: ensaioFase6, P20/Automações, central administrativa, F132autenticação, F138Docker, Laravel e Nodeduplicado. Não são oito suítes independentes.
- [CI engine43973995600](https://github.com/rafhael100/imagine-performance-ai/actions/runs/43973995600), job133673139663:16parserDSN,12serviço,9isolamento/revogação SQL/privilégios,9conectores. As outras duas suítes do motor também aprovadas na HEADd15.
- Artefato performance-phase6-readiness-evidence ID14282340702, digestsha256:9e77c9cd301a24a0bffc05a8fdd5dba333a5fc3c053c80c9b7e97e201513a573, na execução Portal, expira23/11/2026. Inclui evidências visuais laboratoriais; nenhum material operacional foi publicado.
- Falhas preservadas nos runs anteriores: teste de contador PHP corrigido para capturar variável por referência no escopo correto; startup inicial recusou autenticação localpeer do migrador diferente do usuárioSO (fixture passou a SCRAM); depois o pacote apresentou500 por manifesto Laravel do CI referenciando Collisiondev ausente na imagem--no-dev. A causa foi comprovada em stderr; corrigidas exclusão/limpeza de caches e regeneração package:discover com dependências de produção. Não se habilitou APP_DEBUG nem bypass. O run final comprovou o fechamento.
- Revisão separada consultou diretamente o run final e confirmou fechamento dos achados de código/startup, mantendo NO-GO operacional. Sem PHP/Docker locais, execução efetiva ocorreu no GitHubActions; os certificados/atores são sintéticos.

Parecer separado: `ADMIN-PHASE6-INDEPENDENT-REVIEW.md`. Revisão por agente distinto, não humano; achados reais TLS/snapshot/grants corrigidos e logs/UX explicitados. O pacote sofreu correções adicionais de execução não privilegiada e overlay Access. O revisor não autorizou deploy.

## Pacote preparado

`apps/portal/deploy/phase6/`: Compose exclusivo imagine-performance-p6-staging, seis serviços base (Portal/FPM, web, PG18; receiver, web mTLS, PG18), quatro volumes e seis redes próprias. Overlay Access opcional com sétimo serviço; nenhuma rede/volume dos outros oito projetos é anexada. Sem banco/FPM público, Docker socket ou auto-migrations. Recursos máximos4,25CPU/2816MiB base; com connector4,5CPU/2944MiB. Não são reservas nem teste de carga real. Imagens operacionais precisam de digest revisado; images temporárias CI não são publicadas como release.

Porta **18092** e CIDR **10.87.6.0/28**, receiver10.87.6.3, são propostas **não verificadas/reservadas na VPS nesta rodada**. O IP é pinado com TLS hostname/mTLS. Script readonly-inventory.py coleta todos os contêineres, stacks, redes/IPAM, volumes, listeners, rotas, recursos e hash do host; sempre mantém installation_authorized=false. Revalidar antes de instalar.

Tentativa real de acesso nesta fase: aba anterior4 não existe; aba7 noVNC encontrada, mas permaneceu sem console/sessão utilizável. Nenhum comando novo executado no host. Última observação real reaproveitada da rodada Sites aproximadamente18:42 Brasília: oito stacks/18contêineres em execução, Sites app/PG healthy, proxy18090, CRM18081 preservados; recursos22GiB RAM/273GiB disco livres são históricos, não nova medição. Não afirmar portas livres com base nisso.

## Acesso sem ferramentas no computador

Proposta: hostname `performance-hml.imaginecomunica.com.br` (precisa confirmação do domínio), Cloudflare Tunnel próprio + Access default-deny e allowlist nominal, verificação JWT e TLS da origem, depois login/senha+TOTP do Portal. O usuário usa navegador normal; não precisa instalar VPN/WARP/túnel local. Arquivo cloudflared.example.yml/overlay preparado, mas túnel, DNS, Access app/audience/team e credenciais **não criados**. Sem endpoint operacional divulgado ou CA temporária CI reutilizada.

Roteiro: login+MFA→Portal home→dez módulos locais explicitamente simulados→seletor de empresaUUID autorizada→consulta privada S2S→resposta/correlação/auditoria. O receiver permanece unavailable/null para indicadores oficiais. Campanhas/agentes/aprovações simulados são do laboratório local, não atribuídos à fonte S2S. Histórico e alterações internas continuam exclusivamente laboratoriais.

## PKI, administrador e autorização

Procedimento completo em RELEASE-GATES.md: materiais exclusivos, CA offline/custódia, SAN/EKU, CRL, rotação/revogação, arquivos0400/0600 de donosUID33/101/999/65532 conforme serviço. Aplicação lê chave e senhas SQL por arquivos montados, não envinspecionável. Admin nominal deverá ser provisionado via comando existente portal-admin:bootstrap em one-shot confiável após liberação, com senha escolhida pelo responsável e TOTP entregue/configurado em canal seguro; flags de provisionamento nunca habilitadas no HTTP runtime. portal-admin:invalidate-sessions para revogar/desativar sob alçada apropriada. Nenhum usuário real, senha ou TOTP foi criado nesta fase.

Grants somente de empresas sintéticas persistidas, expiram, separados por produto/operação. Nomes lab-alfa/beta não conferem contrato real. Migrations aditivas e SQL mínimo em bancos novos próprios, owner fora do app; não aplicar schema descartável CI em serviços existentes. Recusas precoces têm projeção sanitizada sem tenant/ator não verificado; auditoria SQL de sucesso continua obrigatória e RLS não é ampliado para registrar sob grant revogado.

## Backup, recuperação e rollback

R2 foi informado habilitado pela direção; bucket/prefixo, credencial limitada, custódia de chave, custo e recibo de cópia externa ainda não comprovados. Não houve upload ou teste externo. Proposto destino próprio de homologação, sem compartilhar credenciais com outros produtos, backup cifrado/autenticado dos dois PGs, storage, manifests e recuperação separada de app key/keyring/PKI. Restore em projeto/DB novos, comparar hashes, grants/RLS, inbox/nonces/receipts/audit e revogações atuais antes de reabrir. RPO24h/RTO4h e retenção7diários/4semanais são propostas para aprovação, não resultados obtidos.

Atualização/rollback: pin do release antigo+candidato, backup recuperável antes de migration, alterações só no nono projeto; comparar IDs/StartedAt/restarts dos oito outros. Desabilitar ingresso/flags, voltar à imagem/config própria segura compatível, manter dados/auditoria/revogações e volumes. Não usar migrate:rollback destrutivo, volume deletion ou downgrade para código com falhas conhecidas. Restore/cutover exige alçada específica.

Monitoramento previsto: logs JSON limitados/sanitizados, SQL/HTTPtimeouts, alertas de403/5xx/expiry/disco/RAM/idade de backup. Ainda falta destino/operador de alertas e retenção externa; isso não é monitoramento operacional implantado.

## Custos

Nenhuma despesa/contratação/upgrade executado. Meta para a demonstração: usar VPS existente e serviços já habilitados, sem custo fixo adicional. Cloudflare Access oferece planoFree com limite de usuários; elegibilidade da conta precisa ser verificada. R2 cobra conforme armazenamento/operações além de franquias globais; não garantir gratuidade sem medir uso de todos os projetos. Definir limite aprovado antes de enviar backup; qualquer custo novo exige decisão. Referências oficiais consultadas16/11: https://www.cloudflare.com/plans/ ; https://developers.cloudflare.com/r2/pricing/ ; https://developers.cloudflare.com/tunnel/reference/origin-parameters/ .

## Liberações e bloqueios objetivos

| Gate | Evidência/ação ainda necessária | Responsável proposto |
|---|---|---|
| Código e revisão técnica | Resultados finais CI e parecer do agente anexados | Codex/revisor distinto |
| Host atual | Reconectar console autorizado; executar inventário real sem modificar serviços | Direção libera sessão, Codex inspeciona |
| Domínio/acesso | Confirmar hostname, administradores e política; aprovar criação do túnel/DNS/Access privado | Direção/custódia Cloudflare |
| Materiais exclusivos | CA/certificados/chaves sob custódia e grants técnicos/nominais | Responsável de segurança designado |
| Recuperação | Destino externo autorizado, credencial limitada em cofre, limite de custo, restore isolado e evidências | Direção/custodiante, execução autorizada |
| Instalação | Aprovar SHAs/images/migrations exatos, recursos e aplicação só do projeto novo após gates | Direção |

Ações que uma futura aprovação específica deve cobrir: criar somente o projeto novo e seus bancos/redes/volumes; aplicar migrations/grants revisados nesses bancos; provisionar admin nominal/MFA e PKI próprios; configurar acesso privado do hostname; realizar backup externo/restore isolado; iniciar e testar a demonstração sintética. **Nada disso está executado ou implicitamente aprovado por este relatório.** Sem inventário/acesso/recuperação comprovados, ainda não se apresenta instalação como tecnicamente liberada. Não há merge, deploy, cobrança, Ads-write ou uso de dados reais.
