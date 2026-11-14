#!/usr/bin/env bash
# Root-only C6 sandbox P12/PFX staging on isolated homologation host.
set -Eeuo pipefail
umask 077
if [[ $EUID -ne 0 || ! -t 0 ]]; then
  echo 'Requer terminal SSH interativo e sudo. Não passe senhas em argumentos.' >&2
  exit 1
fi
TARGET=/etc/imagine/secrets/c6-sandbox
if [[ -e "$TARGET" ]]; then
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
install -d -m 0700 -o root -g root /etc/imagine /etc/imagine/secrets "$TARGET"
printf '%s' "$CLIENT_ID" > "$TARGET/client_id"
printf '%s' "$CLIENT_SECRET" > "$TARGET/client_secret"
printf '%s' "$P12_PASSWORD" > "$TARGET/client_p12_passphrase"
unset CLIENT_ID CLIENT_SECRET P12_PASSWORD
install -m 0600 -o root -g root -- "$P12" "$TARGET/client.p12"
chmod 0600 "$TARGET"/*
echo 'Segredos C6 sandbox P12 instalados localmente e protegidos; nenhum token ou pagamento solicitado.'
