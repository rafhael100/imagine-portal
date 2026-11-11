# GSC-F140 — Fluxo completo SEO F56 → relatório F24 → tela F79

A integração de CI passa a produzir o relatório GSC diário com o próprio código do SEO Engine RC22 (`e1ab006d74ff5be8e14362d01a2db77f917f1bf8`), fixado por commit. O relatório combina auditoria técnica do site e comparação diária sintética; o SEO Engine sanitiza e retorna o contrato v1 existente, que é consumido pelo parser PHP do Portal e pela tela HTML estática F79 já implementada.

A prova evita inventar uma segunda rota ou um contrato paralelo de relatório. Testes negativos tentam injetar flags de autorização e dados brutos e exigem `HOLD`. Os arquivos JSON e HTML são temporários, sem commit de dados ou artefatos em produção.

**NO-GO:** somente PHP CLI no ambiente `APP_ENV=testing` com os flags locais de laboratório. Não existe OAuth próprio do Portal, sessão operante, autorização Google, token vault, serviço HTTP, consentimento, deploy ou merge na `main`. A conexão de propriedade GSC disponível no OpenSEO não altera estes gates. Revisão independente SEG/DBA/LGPD e homologação seguem obrigatórias.
