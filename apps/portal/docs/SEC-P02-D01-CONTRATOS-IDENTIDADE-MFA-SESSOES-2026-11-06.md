# SEC-P02-D01 — Contratos técnicos candidatos de identidade, autenticação e acesso do Portal Imagine

**Data:** 06/11/2026 · **Estado:** PROPOSTA DOCUMENTAL PARA DELIBERAÇÃO; NÃO HOMOLOGADA · **Prioridade:** habilitar no futuro navegação segura para clientes do Portal, inclusive Performance AI.

## 1. Decisão de avanço e preservação de escopo

A direção respondeu “aprovado” à recomendação de **definir e submeter para aprovação contratos técnicos de autenticação real**, após o merge SEC-P01, PR #23. Este aceite autoriza **a elaboração documental desta proposta**, não define automaticamente parâmetros ainda não apresentados nem encerra D02-SEC01–06, D03 físico/RLS, P0 ou G-BB01–08. Nenhum usuário real, cookie de autenticação, rota, login, migrations, PostgreSQL, MFA efetivo, chave, integração, deploy ou VPS foi criado por este pacote.

Precedência: PORTAL-TEC-D02 determina **logins humanos Portal e CRM independentes** (sem SSO e sem compartilhamento de senha, sessão ou banco). O PR #19 de F1-01-B-B documenta modelo PostgreSQL, vínculos canônicos, FKs compostas, RLS e revogação; DEC-01 a DEC-04 ratificaram apenas diretrizes preliminares. Seu D05 ainda requer evidências e gates; não duplicar nem fundir PRs. O PR #23 contém uma política **sintética**, que jamais é prova de identidade ou autorização operacional.

## 2. Fronteira de confiança e atores

**Autoridade futura de identidade:** somente serviço autenticador do próprio Portal Imagine; user_id local emitido pelo servidor, com estado, revisão/versão e verificação de credencial. Uma string PortalUserId, endereço de e-mail, CNPJ, associação no CRM, cookie não validado ou dado de request não comprova origem. Não adotar login compartilhado Portal–CRM. Acesso ao Performance AI pelo Portal é uma experiência de produto, NÃO autorização automática a recursos do motor Performance nem SSO entre serviços.

**Organização selecionada:** é intenção de navegação, tratada como entrada não confiável, mesmo quando recebida por sessão. O backend revalida identidade, membership canônica vigente, versão de concessões, estado da organização e titularidade do recurso. Confirmação de pagamento/contrato, provimento do tenant Performance e conexão de anúncios exigem autoridades e contratos adicionais. Funcionário Imagine não recebe poder de leitura amplo por sua ocupação.

**Princípio de negação:** requisição sem ator autenticado, vínculo ativo, escopo válido, recurso da organização, permissão explícita ou condição de segurança atual é rejeitada antes de ler, renderizar, exportar, aprovar ou executar. Nenhum valor allow criado pelo frontend é aceito. A mesma pessoa em duas empresas deve executar a troca de empresa como nova verificação contextual, não como transferência de direitos.

## 3. D02-SEC01 — Contrato candidato de login

- **Cadastro:** um registro de identidade exclusivo Portal com ID gerado no servidor; e-mail verificado como meio de comunicação, não como chave universal de autorização; convite/associação a empresas somente por fluxo de vínculo oficial aprovado. Sem associação automática por e-mail, domínio, CNPJ ou dados do legado Node/SQLite.
- **Autenticador primário candidato:** senha com hash resistente a ataques offline (Argon2id, parâmetros calibrados em hardware e revisados), proteção a senhas vazadas quando tecnicamente disponível, validação de tamanho e proteção contra enumeração. Senha não armazenada nem registrada em logs; não definir segredo padrão.
- **Segundo fator proposto:** TOTP ou WebAuthn/passkey sujeito a verificação de suporte. Proposta de lançamento: MFA exigida para administradores, responsáveis por contratos, cobrança, membros e operações sensíveis; avaliar aplicação a todos os usuários de forma a não criar rota alternativa insegura. E-mail sozinho **não** deve ser tratado como segundo fator de alta garantia para ação crítica. Códigos de recuperação de uso único, armazenados como verificadores seguros, revogados na reutilização.
- **Challenge e step-up:** login primário não abre recursos privados até conclusão do fator exigido. Alterar permissões, contas vinculadas, condições comerciais, cobrança ou dados de recuperação exige autenticação recente com fator apropriado. O modelo final de WebAuthn, de inscrição e de perda do fator é gate específico, não resolvido pelo PR SEC-P01.
- **Proteção a abuso:** limite por combinação de conta/IP/risco, respostas genéricas, alertas, atraso progressivo ou bloqueio temporário proporcionais, sem facilitar negação de serviço contra usuário legítimo. Parâmetros quantitativos exigem simulação, observabilidade e aprovação da Segurança.

## 4. Sessão do Portal — ciclo de vida e cookies

**Arquitetura candidata:** sessão opaca validada e revogável no servidor, em armazenamento isolado do Portal, com identificador imprevisível; não colocar permissões ou tenant autorizadores em cookie do cliente nem usar dados de sessão CRM. HTTPS obrigatório, cookie Secure, HttpOnly, SameSite apropriado à navegação, escopo de domínio/path restrito e proteção CSRF para ações mutáveis. Não compartilhar cookie entre subdomínios/produtos; avaliar prefixo __Host- quando aplicável. Tokens de serviço a serviço são contratos separados, nunca sessão de usuário.

**Estados candidatos:** authentication_pending → factor_pending → authenticated → step_up_required ou expired/revoked. Sessão só passa a authenticated após os desafios obrigatórios; emitir novo identificador/rotacionar na autenticação e alteração sensível de privilégios/contexto; invalidar sessão no logout e na revogação; proibir reaproveitar estado pre-auth para login efetivo.

**Parâmetros para validação (NÃO HOMOLOGADOS):** 30 minutos de inatividade; 12 horas máximas absolutas; 5 minutos para step-up sensível. O relógio e a expiração devem ser impostos no backend, sem depender do JavaScript. Esses números são **candidatos**, distintos dos 600 segundos arbitrários do SEC-P01, e dependem de análise de risco e experiência de uso. Limites diferentes por classe de ação podem ser necessários.

**Reversão/revogação:** mudança de senha, remoção de fator, suspensão de usuário/empresa, revogação de vínculo ou logout global invalidam sessões pertinentes de modo rastreável. Sessões em múltiplas abas/dispositivos precisam tratar revogação, rotação, aba obsoleta, retries e troca de contexto. Não admitir cache local como prova de autorização após revogação.

## 5. Recuperação de acesso sem contornar o MFA

- Solicitação com resposta indistinguível para e-mail existente/inexistente, limite de tentativas e registro mínimo.
- Link/token de recuperação aleatório, single-use, associado à identidade Portal e finalidade; armazenamento apenas de verificador, expiração curta candidata (15 minutos), consumo atômico e invalidação de tokens antigos quando necessário.
- Redefinir senha não concede direitos de empresa nem recupera automaticamente MFA perdido. Revogar sessões conforme política; notificar titular por canal existente.
- Perda de MFA/conta de responsável crítico exige fluxo de alta garantia, revisão humana/alçadas definidas, trilha e anti-insider. Suporte não pode burlar fator por mero conhecimento de e-mail/CNPJ ou fornecer códigos no chat.
- Não informar se o usuário existe; proteção contra phishing e troca de endereço de e-mail precisa de revisão separada.

## 6. D02-SEC02 + D03 — autorização e isolamento PostgreSQL

A arquitetura ratificada no PR #19 permanece **diretriz preliminar**: entidades de identidade Portal separadas dos dados privados de organização; memberships por par canônico, concessões versionadas, FKs compostas, runtime SQL com menor privilégio e RLS como segunda barreira. A política final deve validar SELECT/INSERT/UPDATE/DELETE, ownership antes e depois das mutações, ausência de BYPASSRLS e de elevação pelo runtime; tratar pool de conexões, contexto local por transação e revogação concorrente.

**Sequência futura de requisição:** (1) servidor prova identidade Portal e estado da sessão; (2) recebe empresa como intenção; (3) obtém organização ativa e vínculo/grants atuais de fontes servidoras; (4) consulta ownership do recurso sem aceitar ID vindo do cliente como prova; (5) autoriza ação específica com política versionada; (6) executa sob escopo tenant transacional e RLS apropriados; (7) registra evento mínimo exigido. Se qualquer etapa falhar, negar sem retornar dados.

**Não usar SET LOCAL como certificado de identidade:** é apenas transporte de contexto para a transação. O desenho deve demonstrar quem pode defini-lo, quais roles podem alterá-lo, riscos de SQL injection, serviços internos, jobs e réplica atrasada. Testes PostgreSQL com dados fictícios de empresas A/B só após autorização própria de ambiente e escopo.

**Regra de revogação:** transição de membership/role/grant deve possuir barreira efetiva perante transações concorrentes; nenhum allow de longa duração reaproveitado em requisições ou filas. Definir política explícita para último gestor, reingresso, delegação e desativação de organização.

## 7. Navegação no Portal e Performance AI

Uma área do cliente poderá expor menus e páginas somente quando houver autenticação real e autorização de contexto em cada ação. Esconder um menu NÃO substitui o guard de backend nem RLS. O painel Blade da P02-D é atualmente **não roteado**. A próxima implementação futura precisa de middleware e controllers que dependam de fontes oficiais (sem transformar DemoPortalSession ou DemoNavigationVerdict em autenticação real).

O Portal será fonte comercial e de identidade de seu próprio usuário. O motor Performance conserva sua autoridade operacional e tenant próprio, com chamadas servidor a servidor autenticadas, escopo mínimo, idempotência durável, revogação e contrato INTEG-01 homologado. Abertura do painel não significa conta conectada, campanha autorizada nem serviço pago. CRM humano permanece isolado.

## 8. Trilha, privacidade e operação

Eventos mínimos candidatos: login bem-sucedido/falhado de forma agregada, desafio e recuperação, criação/remoção de fator, mudança/rotação/revogação de sessão, seleção e troca de empresa, alteração de vínculo ou permissão, tentativa cross-tenant e step-up. Registrar autor confiável, contexto, tipo, resultado e versão; NÃO registrar senha, token, código TOTP, cookie, dados de anúncio ou conteúdo pessoal desnecessário. Retenção, acesso, criptografia, backup, resposta a incidente e LGPD dependem de política própria ratificada e plano de restauração que não reabra privilégios revogados.

## 9. O que esta proposta NÃO aprova

Não criar migrations, SQL, RLS real, Eloquent persistente, login, endpoints, usuários, convites, cookie funcional, MFA verdadeiro, gateways, PSP, integração CRM/Google/Meta, custos, merge, alteração da main, deploy ou mudança na VPS. Não declarar homologação D02-SEC01–06, D03 físico, G-BB01–08 ou P0 por conclusão de documento ou CI sintético.

A autorização de laboratório, implementação, integração, merge em dev, homologação independente, promoção em main e produção são **portões distintos**, cada um exigindo evidência e ordem expressa. Ver SEC-P02-D02 para os itens pendentes de ratificação.
