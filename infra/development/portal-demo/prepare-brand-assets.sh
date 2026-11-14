#!/usr/bin/env bash
# F398: local-only copy of the original, already-approved Imagine PNG.
# No network, deployment, backend credentials, Laravel routes or writes to DB.
set -Eeuo pipefail
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../.." && pwd)"
src="$root/assets/logo-imagine.png"
dst="$root/apps/portal/public/_development/branding/logo-imagine.png"
if [[ ! -f "$src" || -L "$src" ]]; then
  echo "Approved Imagine source logo unavailable" >&2
  exit 1
fi
signature="$(head -c 8 -- "$src" | od -An -tx1 | tr -d '[:space:]')"
if [[ "$signature" != "89504e470d0a1a0a" ]]; then
  echo "Original Imagine logo is not canonical PNG" >&2
  exit 1
fi
mkdir -p -- "$(dirname -- "$dst")"
if [[ -L "$dst" ]]; then
  echo "Refusing to overwrite branding symlink" >&2
  exit 1
fi
cp -- "$src" "$dst"
chmod 0644 "$dst"
if ! cmp -s -- "$src" "$dst"; then
  echo "Branding asset copy failed integrity check" >&2
  exit 1
fi
echo "PASS F398: original Imagine PNG copied intact into local Laravel preview; no deploy."
