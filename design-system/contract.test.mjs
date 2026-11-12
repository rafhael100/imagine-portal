import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),"..");
const legacy=readFileSync(path.join(root,"portal.css"),"utf8");
const tokens=readFileSync(path.join(root,"design-system/tokens.css"),"utf8");
const blade=readFileSync(path.join(root,"apps/portal/resources/views/components/portal/styles.blade.php"),"utf8");
const logoOriginal=readFileSync(path.join(root,"assets/logo-imagine.png"));
const logoLaravel=readFileSync(path.join(root,"apps/portal/public/assets/logo-imagine.png"));
const brand=readFileSync(path.join(root,"apps/portal/resources/views/components/portal/brand.blade.php"),"utf8");
const layout=readFileSync(path.join(root,"apps/portal/resources/views/components/portal/layout.blade.php"),"utf8");

test("Fonte original Node permanece laranja/grafite",()=>{
  assert.match(legacy,/--orange:#f9941d/);
  assert.match(legacy,/--ink:#171b19/);
  assert.match(legacy,/\.sidebar\{background:white/);
});
test("Contrato visual identifica as cores da versão Node",()=>{
  for(const token of [
    "--imagine-orange: #f9941d",
    "--imagine-ink: #171b19",
    "--imagine-white: #ffffff",
    "--imagine-surface: #f7f7f8",
    "--imagine-orange-soft: #fff1e0",
  ]) assert.ok(tokens.includes(token),token);
});
test("Blade do Portal usa marca original e conserva foco responsivo",()=>{
  for(const css of [
    "--pi-ink:#171b19",
    "--pi-bg:#f7f7f8",
    "--pi-nav:#ffffff",
    "--pi-accent:#f9941d",
    "background:var(--pi-accent-soft)",
    "color:var(--pi-accent-ink)",
    "outline:3px solid #b75c00",
    "@media(max-width:640px)"
  ]) assert.ok(blade.includes(css),css);
  assert.doesNotMatch(blade,/--pi-accent:#225fc2/);
  assert.doesNotMatch(blade,/--pi-nav:#101e33/);
});

test("Logo oficial original é reutilizado byte por byte e servido apenas localmente",()=>{
  assert.deepEqual(logoLaravel,logoOriginal);
  assert.match(brand,/src="\/assets\/logo-imagine\.png"/);
  assert.match(brand,/alt="Imagine Comunicação Digital"/);
  assert.match(blade,/\.pi-brand img\{display:block/);
  assert.ok(layout.includes("img-src 'self' data:"));
  assert.doesNotMatch(brand,/<svg|https?:\/\//);
});

const experience=readFileSync(path.join(root,"design-system/experience-system.md"),"utf8");
test("Contrato de experiência cobre navegação, responsividade e páginas de todos os produtos",()=>{
  for(const section of ["Navegação e menus","Design visual e componentes","Acessibilidade e desempenho percebido","Critério de pronto por tela","Imagine CRM","Imagine Sites","Imagine Comercial","Imagine Performance AI","Universidade Imagine","Imagine AI Agents","Imagine SEO Engine"]){
    assert.ok(experience.includes(section),section);
  }
  for(const token of ["--imagine-touch-target: 44px","--imagine-content-max: 1380px","--imagine-radius-panel: 14px"]){
    assert.ok(tokens.includes(token),token);
  }
  for(const expected of ["position:sticky","scroll-snap-type:x proximity","min-height:var(--pi-touch)","prefers-reduced-motion:reduce",".pi-primary-action"]){
    assert.ok(blade.includes(expected),expected);
  }
});
