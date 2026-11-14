# C6-SBX-02 — Certificado PKCS#12 (P12/PFX), sem extração da chave privada

O C6 pode fornecer certificado PEM (\`.crt\`+\`.key\`) **ou** PKCS#12 (\`.p12\`/\`.pfx\`). O formato real depende do pacote fornecido à empresa. Não converter nem compartilhar chaves no ChatGPT.

O preflight \`C6SandboxCredentialPreflight\` aceita **um único formato por diretório** e valida certificado vigente, correspondência da chave e permissões restritas. Em P12 lê o pacote e a senha diretamente de arquivos root-only, sem extrair nem persistir a chave PEM.

**Passos após revisão de infraestrutura em VPS de homologação isolada:**

- Para PEM: \`sudo bash apps/portal/ops/c6-sandbox/install-sandbox-secrets.sh\`.
- Para P12/PFX: \`sudo bash apps/portal/ops/c6-sandbox/install-sandbox-p12.sh\`.
- Em ambos: \`sudo php apps/portal/ops/c6-sandbox/check-sandbox.php\`.

Os scripts **não devem ser executados um após o outro**; recusam sobrescrever um diretório já existente. Eles não conectam o runtime Laravel ao banco ou expõem o cofre a um contêiner. A senha P12 é digitada em terminal SSH e nunca passada em argumentos de processo. Preservar e remover cópias transitórias segundo política segura.

Para autenticação OAuth/mTLS e primeira consulta, ainda é necessário o **contrato oficial não secreto de API** obtido no C6 Developers. Não presumir host, caminho, headers, método ou grants vindos de material de terceiros. Asaas permanece como único PSP habilitado.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-KEYS.**
