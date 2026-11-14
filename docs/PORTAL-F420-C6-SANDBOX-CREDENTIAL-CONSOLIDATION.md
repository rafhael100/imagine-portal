# F1-420 — consolidar credenciais C6 Bank sandbox com segurança

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-KEYS.**

## Origem
Esta revisão integra na linha financeira e de identidade do Portal F419 os
oito arquivos isolados da [PR #800](https://github.com/rafhael100/imagine-portal/pull/800),
sem sobrescrever outras implementações de checkout, HMAC/RLS ou OAuth.
O C6 continua **desabilitado** na política comercial de roteamento.

## Bootstrap PEM ou P12/PFX (não executado na VPS)

Duas alternativas estão previstas **apenas para um operador root em terminal
SSH interativo do ambiente de homologação**:

- `apps/portal/ops/c6-sandbox/install-sandbox-secrets.sh`: certificado
  PEM e chave privada PEM.
- `apps/portal/ops/c6-sandbox/install-sandbox-p12.sh`: pacote P12/PFX
  e passphrase.

Ambas exigem materiais **previamente transferidos por canal seguro** ao
servidor; solicitam ID, segredo e passphrase pelo terminal, sem argumentos,
logs ou uploads para GitHub. O instalador:

1. Rejeita diretório-alvo existente, incluindo links simbólicos quebrados.
2. Rejeita diretório-pai simbólico e origem simbólica.
3. Exige senha/segredos não vazios, origem com permissão privada.
4. Prepara todos os arquivos em uma pasta temporária de modo 0700, arquivos
   0600, diretório sob `/etc/imagine/secrets` sem acesso público.
5. Publica o bundle **somente completo**, sem sobrescrever a configuração
   preexistente; abortos descartam a pasta temporária.

O verificador `C6SandboxCredentialPreflight::inspect` mantém resposta
limitada a formato, validade do certificado e estado `can_charge=false`.
Rejeita permissões inseguras, symlinks, dono diferente, arquivos de backup,
bundle incompleto ou misto e certificado/chave inválidos. Não verifica
OAuth, mTLS real, C6 API, bancarização ou dinheiro.

## Testes
A CI `portal-c6-sandbox-check.yml` foi fixada ao SHA da revisão,
valida sintaxe de ambos instaladores e testes PHP sintéticos, inclusive
arquivo de backup oculto. Nenhum certificado operacional ou chave privada
é incluído no repositório.

## Pendências concretas para integração C6
- Documentação oficial de ambiente, OAuth e mTLS confirmada, produtos
  contratados por modalidade, limites, roteamento e preço real.
- Instalação local do sandbox em servidor controlado, revisão dos
  privilégios do operador e gestão de rotação/expiração/revogação.
- Cliente de API autenticado com assinatura, idempotência e integração
  financeira, sob autorização empresarial/tenant e segregação de funções.
- Homologação de Pix, boleto, cartão e Pix Automático **somente se cada
  produto estiver efetivamente habilitado**, mais conciliação e estornos.
- Backup/restore, auditoria AppSec/DBA/LGPD, teste de falhas e GO formal.

**Esta revisão não usa credenciais fornecidas pelo usuário, não configura
nenhuma VPS e não realiza chamadas ao C6.** O fluxo comercial Asaas segue
intocado e não migra assinaturas antigas.
