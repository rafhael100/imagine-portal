# CENTRAL-AUTO-P19 — cliente candidato

Base Portal #904, sem merge. Cliente HTTPS com destino fechado crm-s2s:8443, validação de CA/hostname, certificado de serviço e MAC ligado a operação/empresa/request/nonce. Limite de resposta, timeout, redirects bloqueados e nenhum Cookie/Authorization.

`reader($authorize)` conecta ao AutomationStateReader existente. A closure de autorização precisa ser fornecida pela autoridade administrativa do Portal e é verificada antes/depois do I/O pelo reader. Nenhum provider é vinculado automaticamente: sem concessão administrativa homologada a interface continua indisponível. Não reutilizar permissões SEO/Performance.

O entrypoint de CI inicializa a aplicação Laravel Portal e exercita o cliente contra Laravel CRM/FPM por nginx mTLS em contêineres e bancos separados. A callback nominal é EXPLICITAMENTE sintética; não prova login/MFA ou autorização operacional. Nenhuma sessão humana é enviada ao CRM. Estados retornados vêm do PostgreSQL próprio do CRM, não de servidor mock.

Teste integrado pertence à candidata CRM P19 e fixa a SHA deste cliente. Não confundir criação de teste com execução aprovada. Sem deploy, VPS, n8n dispatch ou custos IA. DRAFT/NO-GO.
