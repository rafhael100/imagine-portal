# PORTAL-F17 — Consistência do painel geral entre serviços, solicitações e contratos

**09/11/2026 — em desenvolvimento controlado, exclusivamente sintético.**

## Problema corrigido

A tela `portal.demo.overview` ainda apresentava o valor fixo
`Contratos ilustrativos = 1`, embora o recorte F16 já apresentasse três
documentos fictícios em `portal.demo.contracts`. Um valor desatualizado poderia
induzir o cliente ou a equipe a confiar em indicadores contraditórios.

## Alterações

- `DemoOverviewComposer`: reconciliador em memória de três coleções de
  fixtures (serviços, solicitações e contratos), sem consultar bases.
- Exige chaves únicas, uma solicitação e um contrato por serviço **apenas
  nesta demonstração**, referências cruzadas coerentes, organização do Portal
  consistente e nomes de serviços correspondentes.
- Rejeita entradas incompletas, listas inválidas, duplicatas e registros
  de outra empresa sem devolver indicadores ou linhas parciais.
- `DemoOverviewResult`: snapshot imutável; exposição a cliente, concessão
  de serviço ou autorização de contrato/pagamento são sempre **false**.
- `DemoClientOverview`: adaptador local dos fixtures F14/F15/F16.
- A tela inicial deriva os três indicadores da mesma coleção coerente e
  mostra uma tabela acessível somente de exemplos; não cria links ou formulários.
- PHPUnit testa integridade referencial, organização cruzada, faltas,
  duplicações, ausência de efeitos colaterais e igualdade dos indicadores
  exibidos: **3 serviços, 3 solicitações, 3 contratos fictícios**.

## Limites e observações

A igualdade `1 serviço → 1 solicitação → 1 contrato` foi criada
**apenas para o cenário de exemplo**. Em operação real, um serviço poderá
ter nenhuma ou várias solicitações, versões de contrato e eventos.
O modelo não deve ser transportado cegamente para tabelas de negócio.

O identificador de organização é dado pelo chamador e **não autentica**
usuário, tenant, membership, vínculo ativo ou contrato assinado; a validação
de igualdade de IDs em memória não equivale a RLS nem a uma consulta segura.
O preview permanece sem rotas, sessões, banco, pagamento, assinatura, CRM,
comunicação com motor Sites, dados reais ou efeitos externos.

## Próxima integração segura

1. Rever a separação de domínios: Portal é dono do relacionamento com o
   cliente e contrato; motor Sites produz e publica sob comando autenticado.
2. Consolidar a documentação e os testes da cadeia Laravel/Auth F1–F7,
   incluindo controles contra restauração pós-revogação e IAM/RLS.
3. Definir interfaces de leitura com paginação e controles de autorização
   **antes** de qualquer endpoint de cliente real.
4. Testar diffs da branch exata e CI Laravel PHP 8.3/8.4 + Node 22/24.

**Não autorizado por este PR:** merge em `main` ou fast-track, liberação
de operações, migrações SQL, contas reais, fornecedores, gastos ou deploy.
Gates CORE/SEC/P0, contratos e segurança continuam abertos.
