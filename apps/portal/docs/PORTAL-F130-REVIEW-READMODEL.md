# Portal F130 — Modelo de leitura estrito para a fila de revisão

O objetivo é separar a representação para tela da estrutura técnica PostgreSQL F125, antecipando validações que serão necessárias quando existir uma consulta autenticada.

`DeliveryReviewMetadataProjector` é uma função pura em PHP, sem chamadas de banco, cliente OAuth, CRM, sessão ou HTTP. O chamador entrega as linhas obtidas de fonte confiável e uma organização já verificada **pelo servidor** (a classe não comprova essa origem). O método rejeita integralmente conjuntos de dados quando identifica tenant divergente, UUID incorreto, referência duplicada, motivo não previsto, limite de 100 entradas ultrapassado, contadores inválidos, flags de confirmação indevidas ou estrutura alterada.

O resultado é minimizado: referência técnica, motivo do bloqueio, versão e tentativas. Campos extras como e-mail, token, nome ou payload são descartados. Mesmo com dados aceitos, `can_retry=false`, `real_crm_verified=false` e `can_acknowledge_visitor=false`. Não há autorização de consulta ou permissões novas.

A demonstração offline F129 usa três fixtures para mostrar também uma contagem por motivo de revisão, produzida pelo projetor sem dados reais. Os testes unitários cobrem respostas típicas do PDO PostgreSQL (booleans nativos e `'t'/'f'`), cross-tenant, PII extra, contagem, referências repetidas e todas as saídas bloqueadas.

**NO-GO:** um `project()` bem-sucedido não autoriza tela ou exportação e não é evidência de entrega CRM. Fonte confiável de sessão/empresa, RLS operacional, acesso real auditado, revisão independente SEG/DBA/LGPD e homologação continuam exigidos. Sem `main`, deploy, dados reais, pagamentos ou novas despesas.
