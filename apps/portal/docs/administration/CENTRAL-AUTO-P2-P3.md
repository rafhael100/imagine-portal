# CENTRAL-AUTO-P2/P3 — contrato de estados e auditoria

Candidata sobre Portal #903, SHA 3d948486609c05d0df2f053870519cc8bc47c7f5. Fonte candidata CRM #634. DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE.

O CRM acrescenta `/automacoes/estado` exclusivamente na jornada privada fictícia. Requer responsável nominal, sessão/MFA e empresa derivada no backend. Versão/configuração, contagens e auditoria são consultadas no PostgreSQL sob o lock de escrita da empresa; eventos têm só ação/instante. Sem nomes, leads, tokens ou atores na resposta. Outro tenant recebe somente seus próprios resultados; parâmetros de empresa/ator não são autoridade.

Esta candidata prepara o consumidor Portal: contrato fechado CRM_AUTOMATION_STATE_V1, tamanho 8KB, validade de 60s, estado/contagem limitados e compatibilidade apenas com synthetic_private_database e operational_authorization=false. São dados de laboratório, não concessões para clientes reais. Números só aparecem quando existe provedor explicitamente vinculado pelo servidor e autorização independente por administrador/empresa antes e depois da leitura. Ausência de provedor/concessão/falha mantém indisponível, nunca zero. SEO/Performance grants não são reaproveitados para automações.

O provedor **não é registrado por padrão**. O contrato de transporte exige identidade técnica própria e autorização do destino: não deve enviar cookie, token ou senha humanos ao CRM, nem abrir bancos compartilhados. O endpoint nominal de origem não é uma API S2S operacional. Falta o receiver S2S operacional homologado e a concessão nominal de escopo por serviço/empresa. Portanto não existe comunicação operacional Portal→CRM habilitada nesta entrega; injetar fixture em teste não a substitui.

Testes: leitor puro (escopo, revogação durante I/O, empresa alterada, alegação operacional), página sob login/TOTP (sem provedor, grant independente, renderização, empresa não autorizada e tentativa de forjar administrador). Fontes nos testes Portal são fixtures explicitamente sintéticas; o exportador CRM tem testes HTTP separados contra PG real descartável. Não alegar teste de duas aplicações conectadas com base nesses casos.

## Continuidade P3/P4

AUTO_IA_S2S_V1/inbox e validade de chave #384 permanecem o contrato técnico já existente. #387 comprova workflow n8n oficial network-none, sem regras configuráveis de cliente. Nada aqui duplica workflow, Gateway, fila ou assinatura. Uma futura implementação de source deve reutilizar esses controles e mTLS, revalidar concessão no destino e impedir replay persistente. Não realizar proxy de sessão humana para habilitar o leitor.

Sem SSH agent/configuração de host no ambiente desta rodada: inventário Leaseweb não executado, nenhuma porta reservada ou serviço alterado. Preparação P4 deve continuar em configuração/procedimentos offline; dependências reais registradas antes de instalação. Licença, segredo, rede, backup externo/restore e reversão continuam NO-GO.
