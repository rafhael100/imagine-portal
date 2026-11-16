# Central de automações P0 — visão administrativa candidata

Autorização da direção em 16/11/2026. Base integrada DRAFT #900, SHA `3f1e2cd5573fe483608587f3b9aa5c07b40dbf00`, não presumida em main. CRM candidatos #632/#633, Agents #339/#371. DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE.

Rota administrativa nova, desligada por padrão: `/portal-admin/automations`. Usa autenticação administrativa nominal, MFA/epoch de sessão e security gate de staging existentes. Modo `isolated_ci` só funciona em testing; `private_staging` exige política de segurança de staging válida. Nenhuma permissão SEO/Performance se transforma em autorização de automação ou acesso a empresa.

A página apresenta catálogo versionado **de responsabilidades**, filtros controlados e gates. Não consulta dados de tenants, não atribui limites de planos, não edita políticas nem executa ações. Falhas/consumo/auditoria são explicitamente indisponíveis, nunca zero. Até contrato de metadados homologado, não haverá dashboard preenchido por eventos fictícios com aparência operacional.

Cores #F9941D, branco e grafite reaproveitam layout Imagine; grade responsiva, labels e foco visível. Não há editor n8n. Operações n8n/IA/pagamentos/publicação são impossíveis por essa rota, que só aceita GET/HEAD.

Testes de feature usam senha/TOTP reais contra PostgreSQL descartável; verificam MFA obrigatório, navegação/filtro, tenant forjado 422, operação POST 405, epoch revogado e flag desativada após registro das rotas. O pipeline administrativo existente é reaproveitado, mantendo motor Performance pinado, teste HTTPS privado e navegação existentes. Não equivale a deploy ou fonte operacional.

Falta autoridade granular para editar políticas globais, provedor S2S somente leitura de metadados/auditoria, revisão independente, implantação privada autorizada e fonte oficial dos limites. Nenhuma migration, usuário real, custo ou VPS alterada.
