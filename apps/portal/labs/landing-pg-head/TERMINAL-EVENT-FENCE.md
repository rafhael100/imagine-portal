# Portal → Sites: evento terminal de briefing Landing não reversível (candidato)

Continuação isolada de Portal #1015 (HEAD base `3c3ba328111445394ad756b9a12507db393a62d1`), encadeada às candidatas Sites #541/#542 e #558. Não modifica as branches anteriores.

## Defeito de segurança no laboratório

O leitor PG18 anterior negava eventos **posteriores** à aprovação, mas aceitava uma sequência inválida `revoked → approved` (ou `superseded → approved`) na **mesma versão e epoch**. Um emissor inconsistente ou comprometido poderia, na fixture, ressuscitar um briefing terminalmente revogado. A revisão aprovada não deve ser reabilitada com o mesmo identificador; qualquer nova aprovação exige uma versão/epoch própria, com trilha auditável.

## Alteração e prova

O predicado SQL agora recusa, para a versão atual, **qualquer** evento `revoked` ou `superseded`, independentemente da posição na sequência, e continua a negar qualquer outro evento posterior ao evento de aprovação pareado. Não afeta outra empresa, pedido, site ou revisão.

O teste `terminal-event-probe.php` cria no PostgreSQL 18 descartável as sequências `revoked → approved`, `superseded → approved`, `approved → revoked`, uma nova revisão positiva e uma revisão draft mais recente. Confirma isolamento entre empresas, hash/bytes do briefing, negação à injeção, transações fechadas e ausência de escrita de eventos durante leituras. O workflow de PR draft roda o teste em PHP 8.3 e 8.4 e, em seguida, repete os testes originais da #1015 e de aprovação offline. Registrar resultados somente após verificar GitHub Actions.

## Limites — NO-GO operacional

O esquema e os atores continuam **sintéticos**; a leitura positiva usa usuário privilegiado só em banco descartável de CI. Não existe ledger oficial, aprovação autenticada, MFA, custodiante, RLS operacional nem transporte S2S homologado. O predicado não elimina revogação após a última leitura, concorrência ou comprometimento da fonte. Nenhuma fonte é ligada à aplicação, a endpoints, a rotas ou a produção. A aprovação de briefing **não concede** autorização comercial, cobrança, geração de release, publicação nem aceite. Dez gates operacionais permanecem bloqueados.

Sem merge, deploy, alteração de main, VPS, DNS/Cloudflare, credenciais, Asaas, produção ou contratação.
