# Fonte de briefing aprovado Onepage — limite de autorização padrão negado

**19/11/2026 — candidato isolado, sem adaptador operacional.**

## Problema concreto

O Portal já dispõe de um exportador candidato do corpo rico Onepage
([#969](https://github.com/rafhael100/imagine-portal/pull/969)) e de um
compositor editorial independente ([#970](https://github.com/rafhael100/imagine-portal/pull/970),
revisado na [#972](https://github.com/rafhael100/imagine-portal/pull/972)).
O Imagine Sites já registrou e recuperou snapshots no PostgreSQL18
descartável, inclusive recusando contexto e CAS inválidos
([#541](https://github.com/rafhael100/imagine-sites/pull/541) e
[#542](https://github.com/rafhael100/imagine-sites/pull/542)).

Porém, as rotas D0 do Portal ainda dizem apenas
`briefing_available`. Essa indicação não é **a leitura oficial de um
corpo rico, imutável, aprovado e vinculado ao comprador**. Conectar
diretamente o exportador a ela inventaria autoria e aprovação.

## Contratos introduzidos

- `ApprovedOnepageBriefSnapshotSource::readExact(orderRef,siteRef,sourceVersion)`:
  futuro leitor oficial, obrigado a verificar autorização do comprador,
  empresa, pedido, conteúdo original, revisão imutável e revogação.
- `CurrentOnepageBriefApprovalWitness::isCurrent(record)`:
  prova corrente **independente** da fonte do conteúdo, a implementar
  no Portal. Não pode aceitar um campo `approved` vindo do JSON.
- `ApprovedOnepageBriefSnapshot`: projeção tipada do contexto,
  seleção imutável, bytes originais, SHA-256, referência de aprovação
  e geração da autorização. **Construir o objeto não comprova a
  autoridade real**; sua origem só pode ser confiável se a fonte
  e o witness estiverem implementados e auditados.
- `DenyingApprovedOnepageBriefSnapshotSource` e
  `DenyingCurrentOnepageBriefApprovalWitness`: padrões negados.
- `OnepageApprovedBriefExportGate::prepare`: aceita seleção
  **exata** de pedido, site e sourceVersion; não escolhe última
  versão nem recebe corpo rico diretamente do caller. Usa a fonte
  oficial de contrato `PortalSiteContractSource`, cruza contexto,
  exige testemunha corrente, executa o exportador candidato original
  e reconfere contrato, corpo, digest, referência e testemunha.
  Uma mudança observada durante o processo causa recusa.

Sem adapters injetados, o serviço **sempre nega**. Não foi registrado
em service provider, controller, rota, fila, middleware, banco, Asaas
ou ambiente de staging. A entrada de testes pode fornecer dublês
sintéticos para comprovar a recusa/consistência, mas esses dublês
não existem na aplicação operacional.

## Garantias limitadas

Em caso de fonte e witness sintéticos positivos, o resultado é o **mesmo
par candidato** do exportador #969 e mantém
`authorityVerified=false`, `clientAccepted=false`,
`canPublish=false` e `canAcceptRealOrders=false`.
Essas flags não representam um comprovante de pagamento ou aceite.
Os bytes não são aparados, reordenados ou reserializados; sua soma
SHA-256 permanece vinculada ao arquivo original.

Os dois ciclos de leitura sem bloqueio não garantem atomicidade entre
a resposta e um posterior transporte; o produtor oficial precisará
usar versão/epoch e um mecanismo de CAS/revalidação sob sua própria
transação antes de enviar qualquer dado. Este serviço não cria uma
aprovação imutável, não autentica um usuário, não assina o envelope
e **não** substitui um banco de origem auditado.

## Testes e continuidade

`tests/onepage-approved-brief-export-gate-offline.php` exercita
somente dados sintéticos, nunca contato ou pedido real. Verifica
padrão negado, ausência de contrato/briefing/aprovação, formato de
seletores, contexto empresa/pedido/contrato, sourceVersion, bytes/hash,
mudança de revisão de contrato, troca de corpo e aprovação/revogação
entre leituras. O resultado público permanece candidato em todas as
provas positivas.

O workflow dedicado executa PHP 8.3 e 8.4 em PR draft, fixando a
base da #972; não precisa de Composer, banco, rede, chave ou serviço.

**Próxima dependência obrigatória:** implementar e homologar no Portal
um repositório **real de briefing aprovado e versionado**, com
titularidade de sessão, aprovação explícita e revogação atuais, e
um testemunho independente. Depois integrar o canal privado autenticado
e idempotente de envio ao Sites, a verificação de recebimento e a
reconciliação por operação. Não ativar qualquer gate de produção sem
essa origem e as provas comerciais separadas.
