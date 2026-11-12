# Portal F143 — Mapa ilustrativo do fluxo de Solicitações

A aplicação já contém o `ServiceRequestWorkflowPreview`, motor puro de estados com revisão otimista e transições válidas de negócio. A F143 aproveita essa lógica existente para apresentar, na tela de Solicitações, um **mapa legível de sete transições** com estado atual, perfil fictício, ação ilustrativa e próximo estado. Não reproduz manualmente regras em um segundo catálogo.

## Implementação

`DemoRequestWorkflowMap::rows()` percorre os seis estados, dois perfis simbólicos e sete ações tipadas, consultando exclusivamente `ServiceRequestWorkflowPreview::evaluate()` com uma organização, serviço e solicitação fictícios. Somente transições de negócio válidas são mostradas. A projeção exige `isExecutable()===false`, revisão nova controlada, tenant correto e um total estrito de sete transições; divergências exigem nova revisão do contrato.

A matriz representa:
- Rascunho → Enviada (cliente fictício)
- Enviada → Em análise (equipe fictícia)
- Em análise → Aguardando informações do cliente (equipe fictícia)
- Em análise → Resolvida (equipe fictícia)
- Aguardando informações → Em análise (cliente fictício)
- Resolvida → Encerrada ou Em análise (equipe fictícia).

Nenhuma ação é apresentada como permissão de usuário, botão, formulário, endpoint, promessa de prazo, alteração em PostgreSQL ou chamada a sistemas externos. Estados "Encerrada" não possuem transição posterior no contrato atual.

PHPUnit valida as sete transições exatas, invariantes de não execução e ausência de reabertura de encerradas. O teste Blade verifica que o quadro é renderizado com sete linhas e quatro colunas, sem HTTP, formulário ou script.

**NO-GO:** identidade e autorização servidoras, direitos por empresa, persistência transacional, auditoria, aprovação AppSec/DBA e homologação ainda são necessárias antes de permitir qualquer solicitação real. Sem `main`, implantação, despesas ou dados de clientes.
