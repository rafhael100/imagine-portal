# Portal F131 — Gate bilateral usando classes reais dos dois repositórios

Até aqui o Portal testava o fluxo comercial com um CRM **simulado dentro do próprio repositório**. O CRM Imagine criou, na PR F3-42, uma prova de integração cruzada que executa código PHP existente nos dois projetos e dois serviços PostgreSQL separados.

A F131 torna a checagem **também propriedade do Portal**, para impedir que futuras alterações no seu contrato de outbox/F119/F122 quebrem silenciosamente a compatibilidade com o receptor do CRM. O CI usa a versão **atual da branch do Portal** sob teste, junto ao commit público fixado `401dbaf59e58965480a5b41dab43aebb3b023aa2` do CRM F3-42.

Duas bases efêmeras: PostgreSQL 18 (Portal, porta local 5433), PostgreSQL 16 (CRM, 5432). O roteiro PHP do CRM exercita as classes reais do Portal e do CRM com metadados UUID sintéticos, assinatura HMAC, nonce, idempotência, resposta perdida depois de COMMIT, estado `unknown` persistente, consulta assinada de reconciliação, adulteração de tenant/destino e separação das bases. PHP 8.3/8.4. Nenhum mock de negócio substitui essas classes; o receptor ainda é **laboratório**, não a API operacional de CRM.

As integrações ficam em loopback `127.0.0.1`. A referência do CRM é SHA exato e testada antes da execução, e o checkout do Portal usa o código do próprio PR. O ambiente de CI gera as chaves de teste e as descarta. Não existe deploy, serviço externo ativo, dados reais ou consumo da VPS.

O gate não prova operação em rede independente, TLS/KMS, propriedade de tenant real, consentimento/LGPD, MFA, idempotência do CRM produtivo nem revisão AppSec/DBA. Exige revisão [#315](https://github.com/rafhael100/imagine-portal/issues/315), infraestrutura homologada e autorização de publicação. Sem merge na `main`, DNS, contrato de cliente ou despesas.
