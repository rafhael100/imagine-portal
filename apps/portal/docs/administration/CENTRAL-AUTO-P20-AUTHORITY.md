# CENTRAL-AUTO-P20 — autoridade administrativa candidata

Sobre #907/#904. Reutiliza login nominal, TOTP e epoch de sessão existentes do Portal. A nova autoridade sempre relê usuário ativo/MFA/epoch e uma concessão administrativa própria de automações; não aceita seo.view nem permissões Performance. O reader #904 continua verificando a autoridade antes/depois do I/O. A callback sintética P19 é apenas fixture de CI e não é vinculada como autoridade.

Migration incremental acrescenta grants e auditoria com UUID de empresa, operação fechada, validade, revogação/revisão e RLS FORCE por ator/empresa. Não cria usuários/concessões ou importa dados de laboratório; rollback de código preserva histórico. Não aplicar à VPS sem revisão de schema, privilégios e backup. O cadastro confiável da empresa/contrato/serviço no Portal não é criado por esse grant: autoridade comercial permanece pendente e o receiver CRM tem autorização independente.

Conexão automation_authority usa a mesma base Portal com credencial própria: apenas SELECT na concessão, INSERT na auditoria, sem ownership/membership de owner ou UPDATE/DELETE/TRUNCATE. Bootstrap/grants do usuário SQL operacional são procedimento revisável separado. Banco staging exige verify-full e CA; a exceção plaintext requer ambiente testing + CI + marcador exclusivo do ensaio. Não configurar VPS como testing/local.

IMAGINE_AUTOMATION_TRANSPORT_ENABLED permanece false por padrão. Binding exige a política administrativa existente e usa arquivos de CA/certificado/chave/signatura próprios do serviço. Variáveis novas não são provas de homologação. Se sessão, permissão, auditoria ou autoridade falham, nega; a interface mantém dados indisponíveis, sem zeros substitutos. Nenhuma sessão humana atravessa S2S. Auditoria registra verificações de autorização, não execuções de automações.

Testes novos exercitam o kernel HTTP nominal/TOTP, concessão PostgreSQL via role não-superusuária/RLS, SEO insuficiente, outras empresas/atores, expiração, revogação durante I/O, epoch/deativação, falha de auditoria/autoridade, role insegura, feature flag e produção negada. O corpo da fonte de leitura é fixture explícita nesses testes de autorização. Não alegar nova prova de duas aplicações/n8n ou MFA operacional na Leaseweb; transporte P19 é evidência anterior reaproveitada.

Pendentes: credenciais nominais e concessões reais revisadas, origem comercial das empresas, CA/custódia/rotação operacionais, revisão independente e migração autorizada. Portal MFA não constitui MFA do editor n8n. Sem deploy, NO-GO operacional, dispatch e IA paga desligados.
