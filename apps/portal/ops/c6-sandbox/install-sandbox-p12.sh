#!/usr/bin/env bash
# Root-only C6 sandbox P12/PFX staging on isolated homologation host.
set -Eeuo pipefail
umask 077
if [[ $EUID -ne 0 || ! -t 0 ]]; then
  echo 'Requer terminal SSH interativo e sudo. Não passe senhas em argumentos.' >&2
  exit 1
fi
TARGET=/etc/imagine/secrets/c6-sandbox
if [[ -e "$TARGET" || -L "$TARGET" ]]; then
  echo 'C6 sandbox já configurado. Não será sobrescrito automaticamente.' >&2
  exit 1
fi
read -r -p 'Caminho ABSOLUTO do certificado P12/PFX já transferido à VPS: ' P12
if [[ "$P12" != /* || ! -f "$P12" || -L "$P12" ]]; then
  echo 'Arquivo P12 inválido ou caminho inseguro.' >&2
  exit 1
fi
mode=$(stat -c '%a' -- "$P12")
if (( (8#$mode & 0077) != 0 )); then
  echo 'Restrinja o P12 com chmod 600 antes de prosseguir.' >&2
  exit 1
fi
read -r -s -p 'Client ID sandbox: ' CLIENT_ID
printf '\n'
read -r -s -p 'Client Secret sandbox: ' CLIENT_SECRET
printf '\n'
read -r -s -p 'Senha P12/PFX (não será exibida): ' P12_PASSWORD
printf '\n'
if [[ -z "$CLIENT_ID" || -z "$CLIENT_SECRET" || -z "$P12_PASSWORD" ]]; then
  echo 'Credenciais ou senha vazias. Abortado.' >&2
  exit 1
fi
# Refuse path redirection before creating or writing any credential.
if [[ -L /etc/imagine || -L /etc/imagine/secrets ]]; then
  echo 'Diretório de credenciais indireto ou inseguro.' >&2
  exit 1
fi
# Never chmod/chown a pre-existing shared configuration directory:
# that could break other Imagine services on the same VPS.
for PARENT in /etc/imagine /etc/imagine/secrets; do
  if [[ -e "$PARENT" ]]; then
    if [[ ! -d "$PARENT" || "$(stat -c '%u' -- "$PARENT")" != '0' ]]; then
      echo 'Diretório pai inválido ou não pertencente ao root.' >&2
      exit 1
    fi
    mode=$(stat -c '%a' -- "$PARENT")
    if [[ "$PARENT" == '/etc/imagine/secrets' ]]; then
      if (( (8#$mode & 0077) != 0 )); then
        echo 'Diretório de segredos deve ser privado antes da instalação.' >&2
        exit 1
      fi
    elif (( (8#$mode & 0022) != 0 )); then
      echo 'Diretório de configuração compartilhado é gravável por terceiros.' >&2
      exit 1
    fi
  else
    install -d -m 0700 -o root -g root -- "$PARENT"
  fi
done
STAGE=$(mktemp -d /etc/imagine/secrets/.c6-sandbox-install.XXXXXXXX)
chmod 0700 "$STAGE"
cleanup() {
  if [[ -n "${STAGE:-}" && -d "$STAGE" ]]; then
    rm -rf -- "$STAGE"
  fi
}
trap cleanup EXIT
printf '%s' "$CLIENT_ID" > "$STAGE/client_id"
printf '%s' "$CLIENT_SECRET" > "$STAGE/client_secret"
printf '%s' "$P12_PASSWORD" > "$STAGE/client_p12_passphrase"
unset CLIENT_ID CLIENT_SECRET P12_PASSWORD
install -m 0600 -o root -g root -- "$P12" "$STAGE/client.p12"
chmod 0600 "$STAGE"/*
# Publish the complete bundle in one rename. Never expose partial files.
if [[ -e "$TARGET" || -L "$TARGET" ]]; then
  echo 'C6 sandbox configurado por outro processo: operação cancelada.' >&2
  exit 1
fi
mv -Tn -- "$STAGE" "$TARGET"
if [[ -d "$STAGE" ]]; then
  echo 'Não foi possível publicar o bundle sem sobrescrever.' >&2
  exit 1
fi
STAGE=''
echo 'Segredos C6 sandbox P12 instalados localmente e protegidos; nenhum token ou pagamento solicitado.'
