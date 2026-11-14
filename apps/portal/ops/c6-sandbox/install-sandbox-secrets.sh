#!/usr/bin/env bash
# Execute interactively as root ONLY in dedicated homologation VPS.
set -Eeuo pipefail
umask 077
if [[ $EUID -ne 0 || ! -t 0 ]]; then
  echo 'Requer terminal SSH interativo e sudo; não passe segredos como argumentos.' >&2
  exit 1
fi
TARGET=/etc/imagine/secrets/c6-sandbox
if [[ -e "$TARGET" || -L "$TARGET" ]]; then
  echo 'C6 sandbox já configurado: não sobrescrever automaticamente.' >&2
  exit 1
fi
read -r -p 'Caminho absoluto do certificado PEM já transferido à VPS: ' CRT
read -r -p 'Caminho absoluto da chave privada PEM já transferida à VPS: ' KEY
for FILE in "$CRT" "$KEY"; do
  if [[ "$FILE" != /* || ! -f "$FILE" || -L "$FILE" ]]; then
    echo 'Arquivo inválido. Envie previamente via SSH/SFTP seguro.' >&2
    exit 1
  fi
done
keymode=$(stat -c '%a' -- "$KEY")
if (( (8#$keymode & 0077) != 0 )); then
  echo 'Chave privada necessita chmod 600 antes da instalação.' >&2
  exit 1
fi
read -r -s -p 'Client ID SANDBOX (não será exibido): ' CLIENT_ID
printf '\n'
read -r -s -p 'Client Secret SANDBOX (não será exibido): ' CLIENT_SECRET
printf '\n'
if [[ -z "$CLIENT_ID" || -z "$CLIENT_SECRET" ]]; then
  echo 'Credenciais vazias: abortado.' >&2
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
unset CLIENT_ID CLIENT_SECRET
install -m 0600 -o root -g root -- "$CRT" "$STAGE/client.crt"
install -m 0600 -o root -g root -- "$KEY" "$STAGE/client.key"
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
echo 'Segredos sandbox instalados apenas localmente com acesso root-only.'
echo 'Nenhuma autenticação ou cobrança foi executada. Proteja arquivos de origem.'
