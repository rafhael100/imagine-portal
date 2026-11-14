# P0 — revisão do fluxo comercial até conta e pedido próprios no Portal

O pipeline `portal-private-purchase-preview.yml` utiliza o **Astro real compilado** do Imagine Comercial (SHA imutável da PR #704), quatro botões de contratação e a imagem isolada **Laravel + PostgreSQL18** do Portal. O navegador cria clientes fictícios distintos, entra na sessão privada, cria pedidos próprios e grava briefing.

Esta extensão produz sete capturas do **mesmo teste real de navegador** (não mockups) para facilitar uma revisão rápida de navegação e usabilidade, sem abrir domínio nem coletar dados de clientes reais:

| Nome | Conteúdo |
| --- | --- |
| 01-comercial-desktop.png | Site Comercial, 1280px |
| 02-comercial-mobile.png | Site Comercial, 390px e verificação sem overflow horizontal |
| 03-portal-login.png | Login privado de laboratório após clicar em Onepage |
| 04-customer-offer.png | Oferta preservada no Portal após cadastro fictício |
| 05-customer-order-pending.png | Pedido individual inicialmente pendente |
| 06-customer-briefing-durable.png | Briefing salvo e disponível no PostgreSQL18 de CI |
| 07-customer-my-services.png | Pedido próprio exibido em Meus serviços |

O runner mascara campos de identificação, senhas e códigos de pedido. Cada PNG é validado por cabeçalho, limite de tamanho e SHA256 no arquivo `manifest.json`. O artefato `imagine-portal-auth-customer-commerce-visual-review` é retido por sete dias em GitHub Actions, sujeito às permissões de acesso ao repositório.

**Não há upload à VPS, rede pública, gateway de cobrança, clientes reais, merge em dev/main ou autorização operacional.** O fluxo é apenas uma simulação autenticada com identidades fictícias.

**Bloqueio de segurança que permanece aberto:** o adaptador atual `CustomerPreviewStore` utiliza login SQL da empresa e filtra por cliente nas queries PHP, mas o banco **não** separa clientes da mesma empresa no papel de acesso. O laboratório OWN-01 #694 demonstra RLS por cliente com logins restritos distintos, mas ainda não está integrado ao runtime. Não habilitar dados pessoais ou contas reais enquanto isso e as revisões de IAM/MFA, revogação, consentimento, LGPD e contrato/PSP estiverem pendentes.

**Gate: DRAFT / NO-GO.**
