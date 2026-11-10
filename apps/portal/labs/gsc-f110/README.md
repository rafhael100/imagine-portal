# GSC-F110 — Autorização OAuth com o esquema real de identidade do Portal (somente CI)

A F110 deixa de utilizar exclusivamente a tabela fictícia de grants da F109 para validar emissão OAuth: em um **PostgreSQL 18 descartável**, instala as migrations reais F66/F76 de identidades, organizações, memberships, permissões e sessões e executa o predicado de emissão sobre essas mesmas tabelas.

## Controle implementado

- Apenas sessão Portal sintética **ativa**, dentro do prazo, e de identidade com e-mail e MFA registrados.
- Revisões de identidade, vínculo e empresa devem coincidir com as da sessão; revogação e suspensão bloqueiam a emissão.
- Permissão explícita `seo.gsc.connect` no grant do membro, sem herança automática de `owner`, `manager` ou `services.view`.
- Propriedade candidata exata associada à mesma organização numa allowlist de laboratório. Esta allowlist **não comprova** titularidade Google.
- Horário de emissão próximo ao relógio do PostgreSQL; validade fixa de cinco minutos; state de 256 bits com SHA-256 no banco e PKCE encryptado via F107/F108.
- Uma função SECURITY DEFINER de papel **NOLOGIN/NOSUPERUSER/NOBYPASSRLS** com SELECT apenas nas colunas indispensáveis. Policies RLS **só no banco descartável** para esse papel técnico.
- Papel cliente só executa a função F110 e o consumo F108; emissores F106/F108/F109 não são acessíveis diretamente, fechando desvios da autorização.

## Limites críticos para produção

A migration original do Portal **não** permite a permissão SEO nem habilita policies RLS operacionais. A F110 muda temporariamente essa restrição **somente no banco de CI**. Não instala policies, permissões ou migrations na aplicação real. A sessão de teste é inserida por administrador do runner, não é emitida por uma autenticação real; não há endpoints Laravel, client OAuth Google, consentimento, propriedade autenticada, token exchange, cofre/KMS ou VPS.

**Não tratar CI verde como autorização de produção.** A evolução exigirá revisão independente de AppSec e DBA, definição segura da migration da permissão SEO, sessão/CSRF/MFA operacional, atualização dos grants por empresa, callback e key management; nenhuma rota HTTP pode usar este laboratório. `main` e produção permanecem intocadas.
