# PORTAL-F59 — consumidor offline do DTO de workbench do Performance F84

O contrato `perf.portal.workbench.offline.synthetic.v1` será produzido pelo Imagine Performance AI F84, a partir de **PostgreSQL 16 descartável, SQL RLS e leitura F28 restrita**. O Portal F59 aceita esse contrato apenas em CLI com `APP_ENV=testing` e `PORTAL_F59_WORKBENCH_LAB=1`.

Valida todas as propriedades, ordem e tipos esperados, a organização, serviço e tenant **estritamente fictícios**, canais `google_ads` ou `meta_ads`, contagens, duplicidades, referências de campanha e status ainda **sem mapeamento para os enums Portal**. Não abre rotas, não consulta anunciantes, não autentica clientes e nunca permite ações nem venda. O teste interno é só uma fixture; teste cruzado do Performance deverá provar que a saída de F84 no banco descartável pode ser consumida por esta classe.

**Pendente para go-live:** desenho de versão operacional com fonte Portal confiável, RBAC/MFA e S2S, estados de campanhas e solicitações homologados, idempotência/antirollback, credenciais Google/Meta, validação AppSec/DBA, LGPD e VPS. `main`, staging, produção e clientes reais não alterados.
