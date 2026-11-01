# 0.18.1 — Vitrine de novos projetos

- Novo projeto apresenta benefícios, oferta do Onepage e soluções sob medida para sistemas, aplicativos e agentes de IA.
- Condições comerciais atualizadas: implantação em três pagamentos de R$ 330 e assinatura obrigatória de R$ 79,90/mês a partir de 90 dias.
- Chamadas abrem o fluxo existente com o serviço selecionado, sem criar pedido ou cobrança automaticamente.
- Layout responsivo, exemplo visual identificado como ilustrativo, etapas da contratação e dúvidas frequentes.
- Validação visual e seleção dos quatro serviços no navegador; sintaxe JavaScript verificada. Sem alterações na integração financeira.
# 0.18.0 — Recorrência automática e Minha conta

- Compra do Onepage inclui assinatura mensal de R$ 79,90, autorizada na contratação e com primeiro vencimento D+90.
- Implantação em três cobranças de R$ 330 em D0/D30/D60; fila executa somente nas datas devidas.
- Cliente atualiza cartão, retoma assinatura e autoriza regularização do valor vencido pelo portal, sem liberação da Imagine.
- Tokenização Asaas, tokens opacos criptografados, conciliação de respostas inconclusivas, operações idempotentes e avisos de recusa. Número completo e CVV não são persistidos.
- Minha conta reúne responsável, empresa, contatos, dados fiscais, endereço, informações por projeto e segurança.
- Migrações aditivas 12/13; contratos legados preservados. Novos pedidos usam subscription-v2.
- 65 testes automatizados aprovados na validação final, com provedores simulados; homologação bancária real e implantação ainda pendentes.

# 0.17.0 — Manutenção e suporte do cliente

- Área de cuidados do site com suporte técnico, alterações e condições do plano.
- Sites publicados primeiro, filtros, situação do plano e última mensagem por projeto.
- Formulário envia solicitações identificadas na conversa autenticada do pedido, sem autorizar custos adicionais.
- Orientações de atendimento e acesso aos pagamentos; sem presumir monitoramento ou execução de backups.
- 50 testes aprovados. Regras financeiras e integrações reais permanecem inalteradas.

# 0.16.0 — Central financeira do cliente

- Resumo de valores pagos, abertos, vencidos e confirmados, sem misturar os status.
- Cobrança prioritária, filtros combinados, paginação, detalhes e exportação CSV.
- Recorrência por projeto a partir do cadastro existente; notas fiscais e cadastro fiscal na mesma tela.
- 49 testes aprovados. Não altera a regra legada Asaas nem ativa cobranças ou emissão fiscal.

# 0.15.0 — Jornada visual do cliente

- Meus pedidos com próxima ação, etapas visuais, filtros e busca.
- Atalhos para materiais, produção, prévia e aprovação; condições comerciais recolhidas.
- Pendências calculadas dos materiais salvos, orientações da equipe e progresso por marcos reais.
- Resumo privado por proprietário e perfil; 47 testes aprovados.

# 0.14.1 — Regras fiscais comerciais aprovadas

- Padrões: implantação pelo total do serviço na entrega e assinatura por competência da cobrança mensal.
- Automação e aprovação tributária permanecem desativadas; sem emissão retroativa ou geração de notas.
- Migração 11 preserva configurações personalizadas e só atualiza o cadastro inicial nunca editado.

# 0.14.0 — Comunicação financeira e Focus NFe

- Prévia de cobrança, preparação para WhatsApp, envio SMTP idempotente e histórico.
- Módulo de notas fiscais para administração/Financeiro e consulta própria do cliente.
- Adaptador Focus NFe municipal/nacional, regras por entrega, competência ou recebimento, configuração administrativa e credenciais no servidor.
- Referências estáveis, conciliação de timeout/webhook, rejeições e solicitação de cancelamento.
- Migração aditiva 10, dependência Nodemailer instalada e sete novos testes (46 no total).
- Emissão/envio real aguardam credenciais, validação fiscal e homologação; sem alteração na cobrança legada Asaas.

# 0.13.0 — BI exclusivo de atividades

- Indicadores, gráficos clicáveis e tabela por colaborador, cliente, serviço, jornada, situação, prioridade e datas.
- Análise do banco completo, paginação, CSV integral filtrado e impressão.
- Tempo de ciclo e pontualidade distinguem conclusões explícitas de datas históricas aproximadas.
- Migração aditiva 9 registra as próximas conclusões automáticas em histórico próprio.
- Acesso exclusivo do administrador validado no servidor; quatro novos testes, incluindo mais de mil tarefas.

# 0.12.0 — Edição, cancelamento e arquivo de atividades

- Edição completa com motivo, checklist e responsáveis conforme a função.
- Cancelamento sem exclusão física; automação não recria ou reativa a tarefa cancelada.
- Arquivamento de concluídas/canceladas e restauração pelo administrador.
- Histórico de gestão com autor, motivo e snapshots antes/depois; migração aditiva 8.

# 0.11.0 — Cadastro manual de atividades

- Nova atividade para administrador ou colaborador, com pedido opcional, prioridade, prazo, instruções e checklist.
- Administrador atribui a si ou a membro ativo e visualiza toda a equipe; colaborador cadastra para si e vê somente as próprias.
- Atividades internas incluídas no resumo e carga operacional; sem criação de pedidos artificiais.
- Cadastro idempotente, alterações auditadas e migração aditiva 7.

# 0.10.0 — Atividade com foco na solicitação do cliente

- Relato original, autor e data vinculados ao evento que gerou a atividade.
- Ajustes referenciam a versão correta; mensagens e reclamações mantêm o conteúdo original.
- Briefing e arquivos privados próximos à execução, respeitando as funções de acesso.
- Orientações gerais no ícone de ajuda com hover, foco e clique; checklist recolhido.

# 0.9.0 — Resumo diário da administração

- Visão geral com alertas de atraso, mensagens, reclamações e bloqueios.
- Recebimentos do dia, valores a receber e gráfico dos últimos sete dias no horário de Brasília.
- Atalhos filtrados, próximas decisões e marcos registrados hoje.
- Atualização de leitura automática a cada minuto; sem sincronização extra com Asaas.

# 0.8.0 — Ambiente de execução das atividades

- Fila de trabalho e detalhe com objetivo, instruções por tipo e checklist persistente.
- Prioridades Urgente/Alta/Normal/Baixa, filtros de prazo e atalhos de atraso, hoje, execução e bloqueio.
- Início rápido, acompanhamento e conclusão com conferência; alterações administrativas auditadas.
- Migração aditiva 6 preserva atividades existentes e suas edições.

# 0.7.0 — Controle de pedidos e reclamações

- Filtros de jornada, serviço, responsáveis, prazos, pendências e pagamento.
- Lista do banco completo com alertas, próxima ação e paginação.
- Reclamações explícitas com autoria, prioridade, atendimento, resolução, reabertura e histórico.
- Tarefas automáticas de reclamação, permissões no servidor e migração aditiva 5.

# 0.6.0 — Carteira de clientes

- Busca por responsável, empresa e contato; filtros e ordenação, com paginação de 25 clientes.
- Total recebido acumulado desde o primeiro pagamento, independentemente do serviço filtrado.
- Ficha com contatos por empresa, todos os pedidos e histórico financeiro, incluindo valores separados por status.
- Fonte: banco completo do portal; Atendimento não recebe dados financeiros na API.

# 0.5.0 — BI de gestão

- BI exclusivo do administrador, calculado no banco completo.
- Filtros de período e serviço; gráficos mensais e por serviço, comparação de recebimentos, carteira e previsão.
- Situação atual da produção, carga da equipe, clientes e qualidade dos dados.
- Custos e lucro permanecem indisponíveis até cadastro de despesas; demonstração continua fictícia.
- Testes dos cálculos, períodos, serviços e ausência de dados financeiros.

# 0.4.0 — Equipe, atividades e financeiro

- Central de atividades com distribuição automática, responsáveis, prazos e estados.
- Equipe com funções Atendimento, Produção e Financeiro; permissões verificadas na API e revogação de sessões.
- Migração aditiva 4 e testes de isolamento, automação e acesso direto à API.
- Painel financeiro com filtros, gráfico, previsão e CSV filtrado.
- Orientações de materiais específicas para cliente e administrador.
- Demonstração local; envio de convites e nova oferta financeira ainda não integrados.

# 0.3.0 — Jornada do Onepage

- Materiais, produção, considerações e aprovação no portal.
- Prévia por versão, duas rodadas e publicação somente após aprovação do titular.
- Marcos reais de evolução com histórico; prazo inicial de sete dias úteis após validação.
- Migração aditiva 3, com controle de concorrência e autorização.
- 23 testes automatizados aprovados com Asaas simulado.
- Novo checkout financeiro e ativação sem senha inicial continuam pendentes.

# Histórico de versões

## 0.2.0 — 2026-10-30

- Briefing de Onepage liberado após confirmação do pagamento.
- Materiais privados por pedido: logo, manual e dados da empresa.
- Rascunho, envio, solicitação de complemento e aprovação administrativa.
- Controle de versão para evitar sobrescrever dados de outra aba.
- Validação de arquivos, limites e download autenticado; migração 2 preservando pedidos existentes.
- Registro de solicitação de apoio da Imagine com IA, sem geração automática.
- Condições confirmadas: 5 dias úteis, duas rodadas de ajustes, domínio do cliente e hospedagem incluída na manutenção.
- 18 testes automatizados locais aprovados com pagamentos simulados.


## 0.1.0 — 2026-10-30

Primeira versão em homologação, organizada no repositório privado da Imagine.

- Site com logomarca original e identidade laranja, branco e preto.
- Atuação destacada em todo o Brasil, Estados Unidos e Portugal.
- Serviços: sites, sistemas, aplicativos e agentes de IA.
- Contato do rodapé exclusivo por WhatsApp.
- Portal com contas, login, pedidos, propostas, etapas e mensagens.
- Administração com catálogo, clientes, financeiro do portal, CSV e atividades.
- Integração Asaas por API, cobranças por pedido, webhooks e conciliação.
- Site Onepage de R$ 990,00 e manutenção opcional de R$ 39,90/mês, sem modificações, com primeiro vencimento em D+60 da contratação.
- Doze testes automatizados, executados localmente com provedor financeiro simulado.
- Workflow preparado para testar em Node.js 22 e 24 após publicação no GitHub.

Pendente: homologação Asaas Sandbox, configuração final de ofertas e implantação em servidor compatível.
