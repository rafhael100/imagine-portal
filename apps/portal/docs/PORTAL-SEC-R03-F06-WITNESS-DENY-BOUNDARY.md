# SEC-R03-F06 — Ligação de testemunha externa: recusar por padrão

**Estado:** contrato de transporte + leitor de comprovante + binding Laravel somente de recusa. Nenhuma testemunha, rede, sessão, autorização ou recurso externo foi ativado.

## Entrega

O Portal passa a expor o contrato de domínio `PortalWitnessChallengeTransport`: ele define a futura leitura de um comprovante assinado usando uma organização e um **desafio aleatório fornecido pelo cliente**. Não define endereço de rede, endpoint, credenciais, token de serviço ou privilégio.

O `PortalSignedWitnessReader` gera um nonce novo de 256 bits em cada leitura, solicita uma resposta pelo contrato e usa `PortalWitnessAttestationVerifier` (PR #614) para checar desafio, assinatura Ed25519, chave pública fixada, empresa e formato de pisos. Retorna um `LabExternalWitnessFloor` **sintético**, ou `null` em qualquer falha. Não guarda cache que poderia esconder indisponibilidade.

O `DenyingPortalWitnessChallengeTransport` implementa **sempre `null`**, sem conexão de rede. Ele é o **único** implementation binding oferecido pelo `PortalWitnessSafetyServiceProvider` registrado em `bootstrap/providers.php`. Não há URL ou switch de variável de ambiente para habilitar uma autoridade externa. O código não registra provedor remoto, controladores, middleware de acesso, comandos de recovery ou recursos do CRM.

Os testes HTTP e Laravel `PortalWitnessSafetyBindingsTest` cobrem: resolução real do contêiner para o transportador negador, falha na ausência de testemunha, simulação de testemunha assinada ainda incapaz de autorizar sessões, indisponibilidade/exceção de transporte sem vazar segredos, chave inválida e ausência de rotas de recuperação/cliente.

## Bloqueios de segurança

- Uma resposta autenticada não comprova que o estado do assinador não foi revertido. O laboratório #611 prova que um processo restaurado pode gerar uma nova assinatura válida para um piso antigo.
- Não existem serviço externo monotônico, mecanismo de publicação de revogação linearizável, coordenação de tentativas simultâneas, prova de separação de backups, cobertura das filas/cache nem autenticação operacional do witness.
- `PortalSignedWitnessReader::canAuthorizeServing()` e os três métodos do `LabRestoreFenceVerdict` continuam false; toda sessão real e toda operação comercial permanecem no estado NO-GO.
- A interface não deve ser conectada a rede ou usada como autorização de serving sem revisão independente AppSec/DBA, aprovação do domínio de falha e testes end-to-end exigidos na [issue #64](https://github.com/rafhael100/imagine-portal/issues/64). A integração [#341](https://github.com/rafhael100/imagine-portal/issues/341) também permanece aberta.

**Sem merge, deploy, alteração de main/dev/VPS, criação de contas ou despesa adicional.** PR DRAFT / NO-GO.
