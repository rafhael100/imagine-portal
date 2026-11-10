# PORTAL-F57 — verificador de deriva do CreateProject v1 (somente CLI/offline)

## Problema

O F55 fixou um snapshot do schema `CreateProject v1` que estava em `imagine-sites/dev` em 09/11/2026, com blob Git `042f4ba31776b486ced757e9624bc42757c46e74`. Até agora os testes só verificavam o snapshot copiado no Portal; não detectavam automaticamente alterações posteriores no checkout **real** do motor Sites.

## Operação segura

Com dois checkouts locais (por exemplo, na futura VPS de homologação, **somente quando liberada**), executar no diretório `apps/portal`:

```bash
php bin/verify-sites-create-project.php /workspace/imagine-sites/contracts/portal-sites/v1/create-project.schema.json
```

O CLI verifica arquivos locais limitados a 64 KiB, confirma que a cópia Portal ainda tem o blob Git fixado e faz comparação exata com o arquivo da árvore Sites informada. Exit 0 quando idênticos; 1 se houve deriva; 2 para uso inválido. A própria origem/autenticidade do checkout **não** é verificada por este comando: exigir Git ref confiável e auditoria de revisão no procedimento operacional.

Não há fetch da internet, envio HTTP, segredo, chaves, produção, autenticação de usuário, publicação ou conexão com a VPS. Se a equipe tornar os repositórios privados, o comando continua funcionando entre checkouts autorizados sem depender de raw.githubusercontent.com.

## Evidência e limites

A suíte `SiteEngineSchemaDriftGateTest` verifica o hash de blob Git do arquivo pinado, ausência/limites de arquivo, alteração de whitespace no motor, alteração maliciosa também do snapshot Portal e negação permanente de autorização de integração.

`matched=true` **não é aceite de cliente, prova de origem remota, assinatura, contrato operacional, paridade do payload runtime nem permissão de publicar**. Contrato do Sites continua sendo a fonte da verdade; ao detectar drift, bloquear promoção e negociar versão/semântica em PRs dos dois projetos, com revisão AppSec/DBA.

**DRAFT; sem merge/deploy nem novos custos.**
