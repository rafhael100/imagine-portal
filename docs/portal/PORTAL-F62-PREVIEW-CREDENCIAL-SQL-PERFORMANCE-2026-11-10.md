# PORTAL F62 — contrato offline do painel de campanhas com origem em PostgreSQL protegida

Data: 10/11/2026. Branch filha de F61; sem endpoints ou operação.

## O que mudou

O Portal agora dispõe de um **consumidor tipado e estritamente de leitura** para o DTO `perf.portal.credential-bound-workbench.synthetic.v1` produzido pelo novo leitor F100 do Imagine Performance AI. O DTO reúne contas candidatas fictícias, campanhas e revisões históricas originadas de **três VIEWs PostgreSQL com tenant vinculado ao login SQL**, sem aceitar um tenant passado pelo chamador.

O Portal F62 valida o schema e a ordem exatos dos campos, os indicadores de NO-GO, os enums reais de canais e estados F61, a correspondência entre campanha e conta do mesmo canal, a associação de cada revisão à campanha e as contagens. Rejeita duplicatas, referência de conta não DEMO, estado de campanha `serving`, aprovação falsamente operacional, review sem journal, contador inconsistente, flags de despacho/billing elevadas, inclusão de token e qualquer coluna adicional. Não cria sessão, permissão, rota, operação, venda ou cobrança.

`credential_binding_confirmed=true` **não** é identidade do usuário Portal nem permissão do anunciante; significa apenas que o PostgreSQL de CI autenticou um login mapeado. `hasPortalIdentity()`, `canPublishAds()`, `canBill()`, `isRealTime()` permanecem false sempre.

## Evidências e limites

O CI do Portal verifica os casos de risco e deverá ser exercitado também dentro do workflow F100 do Performance com JSON **produzido diretamente do PostgreSQL descartável**, evitando uma fixture copiada. O app atual não expõe essa classe em uma página operacional e nenhum dado cliente real entra no contrato.

Impedimentos: IAM Portal autenticado, MFA/S2S e auditoria tenant/grants, compatibilidade de contratos operacionais, OAuth/autorização Google/Meta, política de orçamento e cobrança, LGPD, revisão independente AppSec/DBA, P0/HT-R01/SEC-R03/T3 e VPS/homologação. O código é **somente um ensaio de integração**, sem deploy ou alteração da `main`.
