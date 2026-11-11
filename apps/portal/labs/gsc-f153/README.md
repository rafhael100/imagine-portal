# GSC-F153 — Painel estático de evolução da sobreposição orgânica

A F153 produz uma visualização HTML acessível e estática a partir do contrato de tendências agregadas F65, previamente revalidado pelo PHP F152. Apresenta percentual de consultas associadas a mais de uma página nos períodos anterior/atual, variação em pontos percentuais e indicação de revisão editorial apenas quando os limiares e cobertura permitem. Sem termos de pesquisa, URLs, tokens, fontes externas, scripts, links ou formulários.

A página possui CSP restritiva e `noindex,nofollow`; executa apenas em PHP CLI sob `APP_ENV=testing`, `PORTAL_GSC_TRENDS_LAB=1` e `PORTAL_GSC_TREND_HTML_LAB=1`. A CI fixa SEO RC29 e valida Node → PHP → HTML. Os dois HTMLs de demonstração SEO ficam como artefatos privados de CI por apenas dois dias para revisão de layout.

Esta tela **não** está vinculada a uma rota HTTP e não habilita Google OAuth, banco de clientes, publicação, deploy, DNS, 301 ou merge `main`. São necessários IAM/MFA, consentimento e grants reais, vault, segurança de API, homologação e revisão independente AppSec/DBA/LGPD.
