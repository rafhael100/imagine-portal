# Portal GSC — Lote F: contrato F88 de evidência original até PHP

Esta etapa acrescenta **somente QA de laboratório** à candidata [Portal #408](https://github.com/rafhael100/imagine-portal/pull/408). Nenhum controller, rota, migration, tela de cliente, transporte OAuth, armazenamento ou chamada HTTP é introduzido.

## Contrato de ponta a ponta

O motor [SEO-F88 #128](https://github.com/rafhael100/imagine-seo-engine/pull/128) é carregado no CI pelo SHA imutável `56d7fa79904903f9af0c88646a59c0fa07546594`. O script Node `emit_f88_original_handoff.mjs` fornece **apenas** uma auditoria técnica fictícia e duas exportações originais, calcula o envelope F88 e transmite JSON por arquivo temporário ao validador PHP `verify_f88_original_handoff.php`.

O PHP executa somente com `APP_ENV=testing` e `PORTAL_GSC_F88_LAB=1`, confere a versão do contrato e os campos exatos permitidos, flags de autenticação/publicação falsas, escopo fictício, indicadores de revisão e ausência de consultas, caminhos ou mensagens privadas. Não usa tokens, Google, rede, sessão, banco ou renderer de cliente.

Cenários da matriz **PHP 8.3/8.4 × Node 22/24**:
- **Complete:** dados originais sintéticos sem PII; F88 calcula os indicadores e o PHP exige o resumo técnico e orgânico, com a ressalva de que as métricas são não verificadas.
- **Redacted:** uma consulta CPF fictícia é ocultada; F88 mantém `repair_canonical` e exige `organic=null` no resumo e no envelope do Portal, sem recomendações orgânicas.
- **Grant forjado:** modifica `customerDisplayAuthorized` no JSON calculado; o PHP precisa recusar a entrada e emitir apenas uma mensagem genérica, sem dados sensíveis.

O CI verifica o SHA tanto do motor SEO RC32 e do F79 quanto do novo F88. A política de caminhos aceitos continua impedindo introdução de rotas/migrations; na candidata consolidada, a contagem total permanece dentro do limite do workflow.

## Próximos gates

A demonstração comprova **somente compatibilidade entre módulos offline**, não propriedade Google, consentimento, identidade do usuário, MFA, isolamento entre empresas, autorização de cliente ou garantia de resultados SEO.

Revisão independente AppSec/DBA/LGPD, staging autenticado, observabilidade segura, quotas, retenção e aprovação de release permanecem exigidos.

**DRAFT/NO-GO.** Sem merge em `main/dev`, deploy, DNS/301, cobrança, token ou dados de clientes.
