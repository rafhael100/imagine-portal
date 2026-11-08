import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const base = dirname(fileURLToPath(import.meta.url));
const html = readFileSync(join(base, 'index.html'), 'utf8');
const routes = ['inicio', 'servicos', 'performance', 'solicitacoes', 'universidade', 'financeiro'];

test('offline prototype contains exactly six labeled, navigable in-page sections', () => {
  for (const route of routes) {
    assert.match(html, new RegExp('href="#' + route + '" data-nav="' + route + '"'));
    assert.match(html, new RegExp('class="page" data-page="' + route + '"'));
    assert.match(html, new RegExp(route === 'inicio' ? 'data-page="inicio"' : 'data-page="' + route + '" hidden'));
  }
  assert.equal((html.match(/data-nav="/g) ?? []).length, routes.length);
  assert.equal((html.match(/class="page" data-page="/g) ?? []).length, routes.length);
  assert.match(html, /location\.hash/);
  assert.match(html, /hashchange/);
  assert.match(html, /aria-current/);
  assert.match(html, /section\.hidden = section\.dataset\.page !== key/);
});

test('visible data is clearly invented and never passed off as a real customer', () => {
  assert.match(html, /DADOS 100% FICTÍCIOS/);
  assert.match(html, /Empresa Aurora · fictícia/);
  assert.match(html, /Sem autenticação, backend, integrações, analytics, armazenamento ou envio de dados/);
  assert.match(html, /não gera cobrança, Pix, boleto/);
  assert.match(html, /Nenhuma conta de anúncios conectada/);
  assert.match(html, /não verifica elegibilidade/);
});

test('no external requests, forms, external assets, storage or operational APIs', () => {
  assert.doesNotMatch(html, /https?:\/\//i);
  assert.doesNotMatch(html, /<(?:form|iframe|input|textarea|video|img|link|object|embed)\b/i);
  assert.doesNotMatch(html, /\b(?:fetch|XMLHttpRequest|WebSocket|EventSource|localStorage|sessionStorage|navigator\.sendBeacon)\s*(?:\(|\.)/i);
  assert.doesNotMatch(html, /<script\b[^>]*\bsrc=/i);
  assert.match(html, /connect-src 'none'/);
  assert.match(html, /form-action 'none'/);
  assert.match(html, /noindex,nofollow/);
});

test('accessibility and responsive interface use native navigation and landmarks', () => {
  for (const tag of ['<main ', '<header ', '<nav ', '<aside ', '<footer ']) {
    assert.ok(html.includes(tag), 'Missing semantic element ' + tag);
  }
  assert.match(html, /lang="pt-BR"/);
  assert.match(html, /@media\(max-width:750px\)/);
  assert.match(html, /:focus-visible/);
  assert.match(html, /prefers-reduced-motion/);
});

test('no claim of automatic entitlement, contracts, campaign publication or payment', () => {
  assert.match(html, /Cada produto mantém seus contratos, permissões e funcionamento independentes/);
  assert.match(html, /O acesso real depende da política de elegibilidade aprovada/);
  assert.match(html, /Sem transações/);
  assert.match(html, /Nenhum cartão comprova contrato ou benefício ativo/);
});
