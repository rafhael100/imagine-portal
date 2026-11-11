# GSC-F147 — Integração de segurança RC26 no Portal

O workflow F79 do Portal passa a usar o SEO Engine RC26 fixado por SHA `725173ba6551560452a94dc690f3c1e8b74dab7d`. Além da paridade dos contratos F50/F54/F56/F57/F59 e da tela estática, executa diretamente os testes adversariais F61 (arrays estritos) e F62 (URLs de página sem aliases ambíguos). Isso evita que uma mudança no motor passe despercebida pela integração.

A CI continua com dados sintéticos e saída de laboratório. A propriedade real no OpenSEO é uma conexão distinta e não fornece credenciais ao Portal. Nenhuma rota de produção, OAuth, token vault, tenant grant real, deploy, DNS, 301 ou merge main foi ativado. Revisão independente AppSec/DBA/LGPD e homologação autenticada permanecem obrigatórias.
