# GSC-F152 — Tendências agregadas de consultas/páginas no Portal

A CI do Portal usa o SEO Engine RC29 fixado por SHA `bbcc198b5eb88585534c0a0374ed8c787f40e7ca`, gera duas janelas sintéticas e executa a análise F65 de evolução da sobreposição de consultas. O novo validador PHP confere os contadores, a fração de consultas associadas a mais de uma página, a variação, datas equivalentes, tenant/propriedade, status de cobertura e sinal editorial conservador. Campos extras, consultas sensíveis, permissões forjadas e dados matematicamente inconsistentes são recusados.

A resposta é apenas agregada; não retorna palavras-chave, URL, token, sessão nem dados de cliente. Só funciona em PHP CLI, `APP_ENV=testing` e `PORTAL_GSC_TRENDS_LAB=1`. Não registra rota de produção, não faz HTTP e não habilita OAuth ou exibição a clientes.

**HOLD:** prova sintética de compatibilidade, não aprovação de acesso ao Google; sem dados reais em Git, deploy, DNS, 301, merge main ou custos novos. Faltam IAM/MFA e OAuth operacionais do Portal, vault, revisão independente SEG/DBA/LGPD e homologação autenticada.
