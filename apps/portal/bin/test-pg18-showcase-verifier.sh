#!/usr/bin/env bash
# Adversarial acceptance test for the synthetic-only PG18 preview verifier.
# Runs against copies of the already-generated disposable CI artifact.
set -euo pipefail

if [[ "$#" -ne 1 || ! -d "$1" ]]; then
  echo "Usage: bash bin/test-pg18-showcase-verifier.sh SHOWCASE_DIRECTORY" >&2
  exit 2
fi

source_dir="$(cd "$1" && pwd -P)"
verifier="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)/verify-pg18-showcase-package.php"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT

php "$verifier" "$source_dir"

case_dir=""
prepare_case() {
  case_dir="$tmp/$1"
  mkdir -p "$case_dir"
  cp "$source_dir/index.html" "$case_dir/index.html"
  cp "$source_dir/manifest.json" "$case_dir/manifest.json"
}

expect_rejected() {
  local name="$1"
  if php "$verifier" "$case_dir" >"$tmp/output" 2>&1; then
    echo "FAIL: $name was accepted" >&2
    cat "$tmp/output" >&2
    exit 1
  fi
  echo "Rejected: $name"
}

refresh_digest() {
  php -r '
    $dir=$argv[1];
    $manifest=json_decode(file_get_contents($dir."/manifest.json"),true,512,JSON_THROW_ON_ERROR);
    $manifest["html_sha256"]=hash_file("sha256",$dir."/index.html");
    file_put_contents($dir."/manifest.json",json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\\n");
  ' "$case_dir"
}

prepare_case changed_html
printf '\n<!-- modified after export -->\n' >> "$case_dir/index.html"
expect_rejected "HTML digest mismatch"

prepare_case forbidden_manifest
php -r '$p=$argv[1]; $m=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR); $m["customer_access_approved"]=true; file_put_contents($p,json_encode($m,JSON_THROW_ON_ERROR));' "$case_dir/manifest.json"
expect_rejected "customer approval forged in manifest"

prepare_case extra_file
printf 'unexpected' > "$case_dir/extra.txt"
expect_rejected "extra file inventory"

prepare_case symlink_manifest
mv "$case_dir/manifest.json" "$tmp/manifest-original.json"
ln -s "$tmp/manifest-original.json" "$case_dir/manifest.json"
expect_rejected "symlink manifest"

prepare_case injected_script_with_rehashed_manifest
printf '<script>alert(1)</script>\n' >> "$case_dir/index.html"
refresh_digest
expect_rejected "active script with recomputed digest"

prepare_case injected_external_url_with_rehashed_manifest
printf '<a href="https://example.invalid">external</a>\n' >> "$case_dir/index.html"
refresh_digest
expect_rejected "external URL with recomputed digest"

prepare_case missing_marker_with_rehashed_manifest
php -r '$p=$argv[1]; $html=file_get_contents($p); $html=str_replace("DEMO-REQ-PERFORMANCE","DEMO-REQ-REMOVED",$html); file_put_contents($p,$html);' "$case_dir/index.html"
refresh_digest
expect_rejected "missing synthetic marker with recomputed digest"

echo "Seven adversarial variants rejected; original synthetic package accepted."
