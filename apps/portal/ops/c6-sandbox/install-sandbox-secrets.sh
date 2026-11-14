#!/usr/bin/env bash
# Execute interactively as root ONLY in dedicated homologation VPS.
set -Eeuo pipefail
umask 077
if [[ $EUID -ne 0 || ! -t 0 ]]; then
  echo 'Requer terminal SSH interativo e sudo; não passe segredos como argumentos.' >&2
  exit 1
fi
TARGET=/etc/imagine/secrets/c6-sandbox
if [[ -e "$TARGET" ]]; then
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
install -d -m 0700 -o root -g root /etc/imagine /etc/imagine/secrets "$TARGET"
printf '%s' "$CLIENT_ID" > "$TARGET/client_id"
printf '%s' "$CLIENT_SECRET" > "$TARGET/client_secret"
unset CLIENT_ID CLIENT_SECRET
install -m 0600 -o root -g root -- "$CRT" "$TARGET/client.crt"
install -m 0600 -o root -g root -- "$KEY" "$TARGET/client.key"
chmod 0600 "$TARGET"/*
echo 'Segredos sandbox instalados apenas localmente com acesso root-only.'
echo 'Nenhuma autenticação ou cobrança foi executada. Proteja arquivos de origem.'
