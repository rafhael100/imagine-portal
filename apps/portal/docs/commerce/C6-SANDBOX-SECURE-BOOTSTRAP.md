# C6-SBX-01 — Instalação de credenciais sandbox na VPS Imagine

**Estado: DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE.** Não há segredos no código, rotas novas nem conexão bancária.

O C6 confirma a sequência cadastro → credenciais de teste → evidências → termo → liberação de produção (https://www.c6bank.com.br/apis-integracao/).
A documentação detalhada de OAuth, endpoints, cabeçalhos e formato mTLS depende do C6 Developers. **Não serão inventados endpoints/autenticações.**

O instalador desta entrega atende **somente certificado PEM** (\`.crt\` e chave PEM \`.key\`). Caso o banco forneça \`.p12\`/\`.pfx\` ou chave PEM criptografada, não execute esse fluxo até obtermos o procedimento oficial para esse formato.

## Procedimento no Ubuntu (administrador, após revisão de segurança)

1. Em VPS de **homologação isolada**, transfira \`.crt\` e \`.key\` usando SFTP/SCP, com chave em \`chmod 600\`. Não use GitHub, logs, chat ou issue para compartilhar segredos.
2. A partir de uma cópia revisada da branch, execute \`sudo bash apps/portal/ops/c6-sandbox/install-sandbox-secrets.sh\`; o script solicita caminho dos arquivos e segredos no terminal SSH **sem os exibir ou colocar em histórico**.
3. Execute \`sudo php apps/portal/ops/c6-sandbox/check-sandbox.php\`. Saída deve mostrar apenas data de expiração e status. Não imprime Client ID/Secret nem executa HTTP.
4. Proteja ou remova cópias transitórias de certificados da pasta de transferência conforme a política de segurança; planeje backup criptografado e rotação.
5. Os arquivos ficam em \`/etc/imagine/secrets/c6-sandbox\`, root-only (0700/0600). Nada é montado automaticamente em containers ou exposto ao PHP-FPM. Essa etapa dependerá da arquitetura final da VPS e revisão SEG/INFRA.

**Pendente:** contrato técnico de autenticação do C6 por API e produto, acesso SSH a VPS, transferência segura dos arquivos e validação real no sandbox. C6 permanece desativado; Asaas não é alterado. Testes na CI usam par de certificado gerado na hora, fictício, sem rede.

Solicitar ao usuário somente **documentação não secreta** do C6 Developers (URLs/endpoints, métodos, formatos, cabeçalhos, roteiro de homologação), nunca tokens ou chave privada.
