import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import assert from "node:assert/strict";

const dir = dirname(fileURLToPath(import.meta.url));
const html = readFileSync(resolve(dir, "../pview-ecossistema-06.html"), "utf8");
const nav = [...html.matchAll(/data-nav="([^"]+)"/g)].map((m) => m[1]);
const sections = [...html.matchAll(/<section class="screen" id="([^"]+)"/g)].map((m) => m[1]);
assert.equal(nav.length, 11);
assert.equal(sections.length, 11);
assert.equal(new Set(nav).size, 11);
assert.deepEqual(new Set(nav), new Set(sections));
const labels = html.split("  const labels = {")[1].split("};")[0];
for (const id of nav) assert.ok(labels.includes(id + ":"), "missing route label: " + id);
for (const id of ["overview", "sites", "crm", "performance", "university", "agents"]) {
  assert.ok(nav.includes(id), "missing project preview: " + id);
}
assert.match(html, /connect-src 'none'/);
assert.match(html, /DEMONSTRAÇÃO OFFLINE/);
for (const forbidden of [/<form\b/i, /<iframe\b/i, /\bfetch\s*\(/, /XMLHttpRequest/, /WebSocket/, /localStorage/, /<script\s+src=/i, /https?:\/\//i]) {
  assert.doesNotMatch(html, forbidden);
}
console.log("PVIEW-03: 11 rotas e restricoes estaticas verificadas.");
