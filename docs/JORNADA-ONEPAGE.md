# Jornada de produção do Onepage

1. Preparação: pagamento confirmado libera briefing e materiais privados. Cliente salva, envia e recebe pedidos de complemento. A Imagine aprova o briefing completo.
2. Produção: administrador inicia após aprovação do briefing; previsão inicial de sete dias úteis de segunda a sexta (sem calendário automático de feriados). Atualizações de 0/25/50/75/100% refletem marcos reais, com comentário obrigatório. O tempo decorrido não aumenta o progresso. Feriados, bloqueios e prazo das revisões precisam ser alinhados na conversa.
3. Considerações e aprovação: administrador envia link HTTPS da prévia e comentário. Cada envio cria uma versão. Cliente titular revisa, solicita até duas rodadas ou autoriza publicação. Apenas aprovação explícita da versão atual permite registrar publicação e link definitivo. Aprovar não publica automaticamente nem altera DNS.

Histórico registra autor, momento, versão e decisão; comentários gerais permanecem no pedido em todas as etapas. Alterações concorrentes são rejeitadas pelo controle de versão. Rotas autenticadas respeitam a propriedade do pedido, papel, CSRF e pagamento confirmado. O seletor antigo de fase não pode pular a aprovação dos sites.

Migração aditiva 3 cria produções e histórico ao iniciar a aplicação. Pedidos anteriores começam visualmente em preparação; a equipe precisa validar seus materiais e registrar seu andamento. Não há avanço ou aprovação simulada. O prazo de sete dias úteis substitui os cinco dias úteis da oferta anterior para novas contratações; propostas já aceitas mantêm seus snapshots. O novo checkout financeiro ainda depende da integração Asaas.
