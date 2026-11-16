# Imagine Performance AI — Fase4, 16/11/2026

DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE. Esta entrega não é homologação operacional ou revisão independente.

## Candidatas e consolidação

- Portal PR909, código funcional `241fbcd0fe1c3cf9cfff98708154ee285eea0b65`, sobre PR900 HEAD atual verificada `3f1e2cd5573fe483608587f3b9aa5c07b40dbf00` (avançou além da referência e9e476).
- Performance PR419, código funcional `0fec80aa81a81a103481664dd568f79bd85ce588`, sobre PR418 `5c5802bbcf231853764d7241fd3acf1a35588de7`.
- Portal898 `1a4d88e78b6acba510f3772ed697baf37f260e29`,899 `715f879057cca4df6a3f482c64f0fc399c45b467`,892 `4012bb3d2669fd37c56af29a5f66efb5540e7a61` preservadas na ancestralidade. Última891 `faeb6251cab35ae8860333788a13208add1c87a2` consolidada na candidata via commit3dd672. Seu conteúdo já estava incorporado em grande parte por commits anteriores; conflito de asserts CI resolvido preservando namespace exclusivo imagine-performance-admin-staging. Nenhuma PR foi mesclada e main/dev permanecem intactas.
- Performance415 `607b5bdb9263e0fe37fbf50174de141c99f306e5`,416 `3b2b9a56befb56cca6e57b95eb0de2ccb9b5f5cb`,417 `3b26c6a9425735cbf7e94add018996df5c096695` continuam dependências herdadas.
- Inventários integrais por Git, sem limite de página API: Portal481 arquivos entre main verificada50b683a e241fbcd, Performance242 entre mainb152687 e0fec80a. Listas completas e hash SHA256 dos diffs nos arquivos ADMIN-PHASE4-PORTAL-INVENTORY.json e ADMIN-PHASE4-ENGINE-INVENTORY.json. Isso é inventário, não parecer independente sobre cada linha.
- Compatibilidade com a frente automações Portal908 foi calculada por merge-tree sem tocar sua branch: conflitos em AppServiceProvider.php, config/database.php e routes/web.php. São registros compartilhados de bindings, conexões e rotas; a futura consolidação precisa preservar ambas as autoridades/flags e rodar ambas as suítes. Não incluímos a frente automações por merge presumido e não declaramos uma release global sem conflitos.

## Implementação nativa de identidade e serviço

Migration aditiva `2026_11_16_000020_create_portal_performance_authority.php`, sem seeds, distinta da000019 da frente automações. Cria serviços/contratos por empresa, concessões específicas Performance por operador/operação/papel/revisão/validade/revogação e auditoria correlacionada. Usa conexão runtime dedicada, sem credenciais herdadas do migrador, RLS FORCE e rejeição de papéis elevados/owners. Rollback de schema destrutivo bloqueado.

NativePerformanceAuthority lê o usuário Portal-admin atualizado, atividade, sessão/epoch e MFA recente (900s), empresa, contrato/serviço ativos e concessão específica. Não recebe ator do navegador e não herda seo.view/CRM. Bloqueia cancelamento, suspensão, expiração, revogação e indisponibilidade. PerformanceAuthorityBridge existente produz lease que vincula versões. Contratos são **synthetic_persisted**: fonte operacional portal_contract_authority continua bloqueada até integração/homologação. Não há pessoas ou contratos reais cadastrados.

AuthorizedPerformanceRead e factory ligam a autoridade ao cliente HTTP privado: revalidação antes/depois da consulta e nas resoluções de identidade durante retries; mudança de lease impede apresentar resposta. Intenção, resultado e falhas autorizáveis são persistidos com correlação. Revalidação distribuída não torna revogação atomicamente instantânea em todos os sistemas: permanece uma janela entre verificação final e entrega, a revisar operacionalmente.

Rotas privadas opt-in `/portal-admin/performance-private` e `/{company}` usam login/MFA e autoridade nativos, seletor limitado a25 empresas autorizadas, resultado com origem/estado/período indisponível explícito e retorno ao Portal. Orange/cinza Imagine, layout responsivo; não substituem os dez módulos laboratoriais por dados operacionais. Flags desligadas por padrão e produção não reutiliza perfil local/testing.

## Receiver e isolamento

PrivateAdministrativeService compõe receiver/inbox existentes com delegações PostgreSQL por login SQL, issuer, key_id, SHA256 de certificado, empresa, operação, revisão e validade. Função resolve_delegation SECURITY DEFINER com search_path restrito; runtime sem acesso direto ao ledger de concessões. Principal/empresa e FORCERLS de inbox/auditoria são revalidados. Auditoria de intenção e resultado persistente; retry de mesmo pedido não duplica receipt, nonce repetido é negado e revogação técnica impede retry.

Bootstrap FPM aceita somente POST exato, sem cookies/token humano, mTLS verificado via parâmetros FastCGI controlados pelo Nginx, DSN PostgreSQL verify-full e arquivos privados de senha/keyring. Nginx valida CA e CRL; certificado real é mapeado por SHA256, não por nome enviado em header. Reader retorna unavailable/metrics=null: nenhuma métrica oficial inventada. Falhas negam sem vazar stack, credenciais ou payload.

Compose exclusivo imagine-performance-private-admin, PostgreSQL18 próprio/TLS, volume próprio e duas redes internas; zero host ports, Docker socket ou rede externa. Limites: PG1CPU/768MiB, FPM0,5CPU/256MiB, proxy0,25CPU/128MiB. Imagens devem ser pinadas; CI resolveu digests oficiais no runner, isso não é aprovação de release operacional. Material operacional/CA/chaves/segredos não fornecidos e feature OFF. Não é pacote autorizado a instalar automaticamente.

## Testes e interface

- [Portal Fase4](https://github.com/rafhael100/imagine-portal/actions/runs/43945753526): aprovado na HEAD241fbcd: oito cenários nativos PostgreSQL/48 assertions e uma jornada HTTPS/16 assertions, executada em processo separado e depois no FPM/Nginx/PG TLS reais.
- [Performance Fase4](https://github.com/rafhael100/imagine-performance-ai/actions/runs/43945644754), HEAD0fec80a:12 assertions de serviço/concessões/certificado/replay/auditoria +9 de conectores/decimais/consentimento/paginação, aprovadas.
- Prova de navegador já executada em0c01134: [CI43945479194](https://github.com/rafhael100/imagine-portal/actions/runs/43945479194),14 verificações desktop1440/mobile390, duas contas e contextos independentes, consultas simultâneas e empresa cruzada negada, sem violações serious/critical nas regras WCAG testadas. Execução final em241fbcd também aprovada, incluindo o pin final do receiver e14 verificações de navegador.
- Artifacts `performance-phase4-private-evidence` contêm PNGs e relatórios axe sanitizados, retenção7dias no CI correspondente. Não existe URL navegável implantada na Leaseweb. Screenshot/axe automáticos não constituem revisão humana independente de UX/acessibilidade.
- Primeiro teste de comparação de resposta falhou por ordem de chaves JSONB e foi corrigido para igualdade semântica. Primeiro navegador falhou por contexto implícito incompatível com axe; corrigido para contexto explícito. Falhas não foram ocultadas. O único skipped da primeira etapa é a jornada HTTPS, executada explicitamente nas duas etapas seguintes com servidor ativo.
- Regressão herdada Portal: login/MFA/sessões/SEO isolado, Laravel, dez módulos/ações/filtros/browser e stagingDocker nas HEADs correspondentes. Não assumir que somente o novo teste prova todos os fluxos administrativos; todos os workflows da HEAD241fbcd foram verificados com sucesso.

## Google/Meta e financeiro

AdministrativeAdsReadConnector prepara consumo paginado limitado usando planos existentes Googlev26/Metav27.0, autorização por empresa/conta revalidada entre páginas, limite de10páginas/20mil linhas/1MiB por página, cursor opaco sem seguir URLs arbitrárias, moeda/fuso/período preservados. Valores monetários são strings decimais exatas; custo Google em micros convertido sem float. Ausência de métricas permanece null. Só aceita laboratory_fixture e nunca produz official_evidence.

Fontes primárias consultadas: [Google reporting paging](https://developers.google.com/google-ads/api/docs/reporting/paging), [Google Search v26](https://developers.google.com/google-ads/api/reference/rpc/v26/GoogleAdsService/Search?transport=rest), [Meta Insights](https://developers.facebook.com/docs/graph-api/reference/adaccount/insights). Não foram conectadas contas, OAuth/vault/quotas operacionais ou transporte oficial autenticado. HTTP de leitura real aos provedores ainda depende dessas concessões/homologação. ChatGPT Ads continua indisponível.

Matriz proposta no Performance `reports/ADMIN-PHASE4-FINANCIAL-DECISIONS.md`: primeiro mês, inclusão/exclusão, suspensão/reativação, correção e revisão. Exemplos com mínimo integral versus proporcional, sem decidir política ainda não aprovada. Baseline490/canal,20% sobre mídia verificada,mínimo170 conforme contrato e separação da mídia preservadas. Asaas/C6 sem adaptador transacional homologado ou credenciais; nenhuma transação sandbox/real executada. Contratos técnicos/execução dependem de endpoints/schemas oficiais, identidade sandbox, idempotência e conciliação aprovados. Não substituímos essa lacuna por pagamento simulado com aparência operacional.

## Leaseweb e GO/NO-GO

Inventário real de16/11 [issue414](https://github.com/rafhael100/imagine-ai-agents/issues/414#issuecomment-6925460120) reutilizado, não reexecutado:8CPUs,~22GiB RAM e273GiB disco livres no snapshot,18contêineres/8Compose,Docker29.1.3/Compose2.40.3. CRM18081 e Sites18090 ocupadas e preservadas. Nenhuma porta Performance reservada;17990 também não reservada. Redes/volumes próprios planejados, capacidade sob carga e isolamento/egress finais pendentes. Console anteriormente acessível, SSH do executor não configurado; não se afirma auditoria atual de firewall/backup ou recursos em tempo real.

**GO somente após:**

- [ ] Resolver integração com demais candidatas Portal e executar regressão conjunta da árvore de release.
- [ ] Autoridade real de empresas/serviços/contratos e concessões; operadores nominais/MFA e revogações homologados, não fixtures.
- [ ] PKI operacional, rotação/revogação, hostname, CA/CRL/pins, keyring/vault e destinos privados homologados.
- [ ] Parecer independente AppSec/DBA/UX; nenhum responsável elegível foi confirmado/impersonado nesta entrega.
- [ ] Destino externo de backup, custódia separada, restore isolado com revogações atuais, inbox/outbox e rollback preservados.
- [ ] Porta/reserva, recursos/egress, imagens/SBOM, monitoramento/alertas e migrations de menor privilégio aprovados.
- [ ] Decisões financeiras aprovadas e sandboxes homologados antes de habilitar financeiro; fontes oficiais antes de apresentar métricas verificadas.
- [ ] Autorização específica para implantação privada; sem DNS/publicação/produção nesta fase.

Próxima entrega técnica: revisão da candidata integrada e resolução controlada das três colisões com a frente automações, mantendo ambas as autoridades independentes; depois ensaio privado autorizado com custódia/backup reais. Operação por clientes reais permanece NO-GO. Não houve deploy, merge em main/dev, DNS, cobrança, Ads-write ou chamada paga de IA.
