# F414 — Revisão real de navegador do simulador Performance (offline)

Este recorte testa o frontend estático criado na F409 (PR #799)
sob Chromium real em viewports desktop e mobile, preservando a
cadeia segura F410–F413 do Portal em revisão. Não conecta ao
site comercial, checkout, Portal autenticado, CRM, Asaas, C6, Ads
ou Google Search Console.

## Evidências de qualidade

- Inicial: Meta selecionado (inclui Facebook + Instagram como **um**
  canal), Google disponível mas não selecionado e ChatGPT **desabilitado**.
- Marcar Google altera ativação de R$490 para R$980 e soma orçamento
  planejado, sem simular cobrança real de mídia.
- O valor de 20% é **apenas informativo** sobre o orçamento; os
  honorários reais são pós-pagos após medição e revisão do período civil.
- Orçamento abaixo do mínimo mostra erro e zera totais calculados
  para não deixar propostas obsoletas visíveis.
- Sem canais a interface recusa cálculo e não abre checkout.
- HTTP na UI é limitado a `127.0.0.1`, sem solicitações externas,
  scripts de terceiros, cookies, envio de leads ou dados pessoais.
- Desktop/mobile são registrados como PNG sintéticos com retenção de
  sete dias pelo GitHub Actions, para auditoria visual da equipe.
- Nenhuma interface de produção nem publicação na VPS foi configurada.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CHARGE.**

A validação de navegador não autoriza contrato, captação de leads,
acesso de clientes, anúncio ou faturamento. Integração de conta
autenticada, consentimento e validação de preços continuam obrigatórias.
