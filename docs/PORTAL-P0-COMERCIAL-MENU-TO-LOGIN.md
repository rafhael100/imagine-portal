# P0 — acessos ao Portal do Cliente pelo site Comercial (Chromium)

## Objetivo

Verificar a jornada solicitada: cliente ou administrador chega ao **Portal do Cliente diretamente pelo menu** da homepage Imagine Comercial, acessa seu painel e vê exclusivamente seus dados. O teste cobre desktop e mobile com a home Astro compilada, o Laravel real de homologação e pedidos fictícios gravados em PostgreSQL 18.

## Como o laboratório evita exposição

- A compilação do Comercial recebe somente uma URL de login HTTPS oficialmente permitida pelo componente de navegação. **Isso não demonstra publicação dessa URL.**
- O navegador recebe uma cópia temporária não publicada do build, na qual **somente** os dois links do menu são reescritos para o Laravel local `127.0.0.1:18083`. O build e o código-fonte originais não são modificados.
- Bloqueio de conexões para qualquer host externo; duas contas fictícias e senhas fixas de CI, nenhuma conta real ou dado pessoal.
- Fluxo desktop: abre o menu, chega ao login, autentica o cliente de teste, lê o seu pedido durável e a tela **Meus serviços**, e não consegue abrir a administração.
- Fluxo mobile: expande o menu, chega ao mesmo login, autentica o administrador de teste e confirma acesso à lista administrativa e bloqueio da área restrita ao cliente.
- O restante do workflow ainda confere os quatro CTAs comerciais, idempotência, CSRF, recusa de leitura entre clientes, PostgreSQL RLS, captura de sete telas e recuperação após reiniciar PostgreSQL e Laravel.

**Gates críticos ainda pendentes:** autenticação de clientes reais, CPF/CNPJ e empresa verificados, MFA, recuperação de senha, autorização de compra/contrato real, confirmação PSP, integração Site/CRM de produção, LGPD/AppSec, backup externo, revisão de migration e HTTPS autenticado na VPS.

**NO-GO:** nenhum merge, DNS, publicação, cobrança, alteração na VPS ou serviço de cliente é executado por esta entrega. 
