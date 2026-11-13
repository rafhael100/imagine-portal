# Imagine Portal F15 — validação do histórico antes de projetar solicitações na interface

## Objetivo

O leitor experimental `DisposablePg18ProjectInboxReader` (F14) agora confere, **na mesma transação `REPEATABLE READ / READ ONLY`**, se cada solicitação recuperada tem histórico íntegro em `portal_service_request_transitions`. Ele não exibe o estado atual de um projeto caso o histórico registrado no PostgreSQL esteja incompleto ou não explique a transição.

Cada evento deve pertencer à mesma empresa do projeto, começar com a criação `NULL → draft` na revisão 1 e seguir sequencialmente, sem lacunas nem repetição. Os únicos pares aceitos continuam sendo o fluxo do domínio: `draft→submitted→in_review→waiting_on_customer→in_review→resolved→in_review/closed`, considerando os dois caminhos adicionais `in_review→resolved` e `resolved→closed`. O último evento precisa corresponder **exatamente** ao status e revisão persistidos na tabela principal.

A consulta do histórico é limitada a **500 eventos para o conjunto de até 50 solicitações**. Exceder esse teto causa rejeição integral, não truncamento silencioso. As verificações são feitas antes de gerar qualquer resultado de prévia Blade. Tentativas incorretas retornam `invalid_preview_input` sem itens, paginação ou autorização de cliente.

## Evidência adversarial em CI

`PortalPg18RequestHistoryReconciliationTest` usa somente **um PostgreSQL 18 descartável, via papel de laboratório**. Os testes constroem um fluxo legítimo e simulam os ataques que um operador privilegiado poderia introduzir durante uma restauração: remover evento intermediário, alterar número da revisão, substituir o estado por transição inválida, associar evento à empresa errada, modificar a tabela de solicitação sem gravar evento ou adicionar evento sem atualizar o estado atual. Cada manipulação leva à rejeição integral do leitor. A alteração temporária de trigger existe só dentro dos testes para demonstrar falha fechada, nunca no runtime.

**Limites:** isso detecta descompassos locais, mas um administrador com acesso a ambos os dados pode reescrever histórico e estado de modo consistente. Não é assinatura criptográfica, witness externo, proteção anti-rollback operacional, autorização HTTP, sessão ou prova de cliente. O código do leitor permanece em `tests/Support`; o role de aplicação continua sem grants nem políticas RLS permissivas. Seguem bloqueios #64, #341 e revisão AppSec/DBA antes de deploy.
