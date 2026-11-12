# Invalidação automática por alteração da identidade

A autenticação SQL compara a revisão gravada na sessão com a revisão atual da identidade. Sem um gatilho para alterações de credenciais/estado, uma rotina administrativa que esquecesse de incrementar a revisão poderia deixar sessões anteriores utilizáveis. Esta migration candidata impõe a revisão no próprio PostgreSQL, sem novos grants ou papéis.

Mudança de status/e-mail ou substituição/remoção de senha, cifra MFA e confirmações existentes exige revisão maior. A revisão explícita já maior é preservada; o incremento de ativação não é contado duas vezes. O identificador é imutável. Troca de e-mail exige email_verified_at nulo para nova verificação. Não há reativação silenciosa de conta suspensa nem reversão de revogação terminal. Revisão e contador MFA não podem recuar. Avançar apenas o contador TOTP não invalida outras sessões válidas.

Primeiros valores em uma identidade invited ainda sem credenciais não elevam a revisão antes da ativação: isso conserva o vínculo do desafio de e-mail consumido durante a inscrição inicial. A prova de compatibilidade aqui é uma sequência SQL dessa transição; ela não substitui a matriz completa dos roteiros legados F115/F116. Reativação, recuperação, troca de e-mail e recadastro MFA precisam de fluxos próprios revisados; este gatilho não concede essas operações.

O rollback recusa remover a proteção enquanto existirem identidades ou sessões. O ensaio PG18 acrescenta casos de alteração de senha antes/depois de MFA, troca de e-mail, contador/revisão regressivos, suspensão/revogação, inscrição inicial e rollback bloqueado. Também repete o transporte HTTP e a persistência criptografada com essa migration instalada.

Proposta incremental sobre o vínculo de envelope da PR #482, que depende da candidata #478. Sem merge de candidatas sobrepostas, providers novos, rotas, migração operacional ou ativação de login. Revisão de segurança e ensaio na VPS continuam pendentes.
