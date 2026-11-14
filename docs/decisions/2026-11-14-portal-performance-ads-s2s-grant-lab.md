# Portal Performance — proposta de autorização S2S de leitura Ads (laboratório)

**14/11/2026 — NÃO É autenticação Portal/IAM real.** Este emissor experimental NÃO consulta usuários, contratos ativos, comprovação de titularidade da conta nem sistema de consentimento.

`PortalPerformanceAdsReadGrantLabIssuer` emite um pequeno envelope HMAC com:
- `tenant_id`, `agreement_id`, `period`, `provider=chatgpt_ads`, `ad_account_id`, referência opaca de credencial e de consentimento;
- escopo único `ads.insights.read`, sem permissão de alteração de anúncios nem cobrança;
- `issuer`, `audience`, versão, `key_id`, nonce e prazo de até 120 segundos.

A assinatura usa **chave segregada** do protocolo comercial de cotações Portal V6 e domínio HMAC `imagine:portal:performance:ads-read-grant:lab:v1`. Mudar a conta, empresa, contrato, referência de segredo, competência ou nonce invalida a assinatura.

O verificador do Performance AI ainda terá que provar paridade com este código em commit exato, validar escopo e usar registro de nonce durável para impedir replay entre processos. **A assinatura não estabelece consentimento válido nem IAM real**, porque o emissor recebe dados sintéticos diretamente dos testes.

Para tornar isso operacional será necessário autenticar o usuário no Portal, verificar autorização atual do contrato e da conta em banco autoritativo, separar consentimento válido/revogado, atestar identidade S2S, gerenciar segredos de assinatura em vault com rotação, usar nonce persistente transacional, registrar auditoria e rejeitar contas já desconectadas. Isso não desbloqueia o HTTP real, Asaas nem chamadas Ads. Todas as permissões de gravação e faturamento permanecem negadas.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.**
