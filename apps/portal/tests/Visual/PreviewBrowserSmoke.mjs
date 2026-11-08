// PORTAL-PREVIEW-UX-01 — headless Chromium layout test with synthetic Blade HTML.
// Loopback-only DevTools and synthetic HTML fixture; no app route, external API or credentials.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { accessSync, constants, existsSync, mkdirSync, mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';

const [htmlArg, outputArg] = process.argv.slice(2);
assert.ok(htmlArg && outputArg, 'usage: node PreviewBrowserSmoke.mjs <rendered-html> <output-dir>');
const htmlPath = resolve(htmlArg);
const outputDir = resolve(outputArg);
accessSync(htmlPath, constants.R_OK);
mkdirSync(outputDir, { recursive: true });

const executable = ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium', '/usr/bin/chromium-browser'].find(existsSync);
assert.ok(executable, 'Chrome/Chromium missing from GitHub-hosted runner');

// Serve only the already-rendered synthetic document on loopback; no Laravel route.
const previewServer = createServer((req, res) => {
  if (req.url !== '/') { res.writeHead(404).end(); return; }
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
  res.end(readFileSync(htmlPath));
});
await new Promise((resolve) => previewServer.listen(0, '127.0.0.1', resolve));
const previewURL = 'http://127.0.0.1:' + previewServer.address().port + '/';

const profile = mkdtempSync(join(tmpdir(), 'imagine-preview-chromium-'));
const chrome = spawn(executable, [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--no-first-run',
  '--disable-extensions', '--disable-background-networking', '--no-default-browser-check',
  '--disable-sync', '--disable-translate', '--no-proxy-server',
  '--remote-allow-origins=*', '--remote-debugging-port=9222',
  '--user-data-dir=' + profile, 'about:blank',
], { stdio: ['ignore', 'pipe', 'pipe'] });
let stderr = '';
chrome.stderr.on('data', (buf) => { stderr = (stderr + String(buf)).slice(-5000); });
chrome.stdout.on('data', () => {});
let ws = null;

async function pageSocketURL() {
  for (let retry = 0; retry < 80; retry++) {
    if (chrome.exitCode !== null) throw new Error('Chrome exited: ' + stderr);
    try {
      const response = await fetch('http://127.0.0.1:9222/json');
      if (response.ok) {
        const pages = await response.json();
        const page = pages.find((p) => p.type === 'page' && p.webSocketDebuggerUrl);
        if (page) return page.webSocketDebuggerUrl;
      }
    } catch { /* Chrome is starting. */ }
    await sleep(200);
  }
  throw new Error('Could not connect to local Chrome DevTools: ' + stderr);
}

class CDP {
  constructor(socket) {
    this.socket = socket;
    this.id = 0;
    this.pending = new Map();
    socket.addEventListener('message', (event) => {
      const data = JSON.parse(event.data);
      if (!data.id || !this.pending.has(data.id)) return;
      const entry = this.pending.get(data.id);
      this.pending.delete(data.id);
      if (data.error) entry.reject(new Error(data.error.message));
      else entry.resolve(data.result || {});
    });
  }
  call(method, params = {}) {
    const id = ++this.id;
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.socket.send(JSON.stringify({ id, method, params }));
    });
  }
  async evaluate(expression) {
    const r = await this.call('Runtime.evaluate', {
      expression, returnByValue: true, awaitPromise: true,
    });
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails));
    return r.result.value;
  }
}

const metricsExpression = [
  '(() => {',
  ' const nav = document.querySelector(".pv-nav");',
  ' const aside = document.querySelector(".pv-sidebar");',
  ' const warning = document.querySelector(".pv-demo-warning");',
  ' return {',
  ' width: innerWidth,',
  ' visualWidth: visualViewport ? visualViewport.width : null,',
  ' screenWidth: screen.width,',
  ' documentWidth: document.documentElement.scrollWidth,',
  ' navWidth: nav.clientWidth,',
  ' navScrollWidth: nav.scrollWidth,',
  ' sidebarWidth: aside.getBoundingClientRect().width,',
  ' overflow: document.documentElement.scrollWidth > innerWidth + 1,',
  ' headingCount: document.querySelectorAll("h1").length,',
  ' sectionCount: document.querySelectorAll(".pv-section").length,',
  ' linkCount: document.querySelectorAll(".pv-nav-link").length,',
  ' sidebarPosition: getComputedStyle(aside).position,',
  ' navScrollable: nav.scrollWidth > nav.clientWidth + 5,',
  ' warningVisible: !!warning && warning.getBoundingClientRect().width > 0,',
  ' warningHeight: warning.getBoundingClientRect().height,',
  ' warningText: warning.textContent.trim(),',
  ' helpText: document.querySelector(".pv-nav-help").textContent.trim(),',
  ' helpVisible: getComputedStyle(document.querySelector(".pv-nav-help")).display !== "none",',
  ' reducedMotion: getComputedStyle(document.documentElement).scrollBehavior,',
  ' scripts: document.querySelectorAll("script").length,',
  ' forms: document.querySelectorAll("form").length',
  ' };',
  '})()',
].join('\n');

try {
  ws = new WebSocket(await pageSocketURL());
  await new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, { once: true });
    ws.addEventListener('error', reject, { once: true });
  });
  const cdp = new CDP(ws);
  await cdp.call('Page.enable');
  await cdp.call('Runtime.enable');
  async function loadOfflineHTML() {
    const navigation = await cdp.call('Page.navigate', { url: previewURL });
    assert.equal(navigation.errorText, undefined, 'loopback synthetic HTML must load');
  }
  await cdp.call('Emulation.setEmulatedMedia', {
    features: [{ name: 'prefers-reduced-motion', value: 'reduce' }],
  });
  const sizes = [
    { name: 'desktop', width: 1440, height: 900, mobile: false },
    { name: 'celular', width: 390, height: 844, mobile: true },
    { name: 'celular-estreito', width: 320, height: 740, mobile: true },
  ];
  const results = [];
  async function key(name, modifiers = 0) {
    await cdp.call('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: name, code: name, modifiers, windowsVirtualKeyCode: name === 'Tab' ? 9 : 13, nativeVirtualKeyCode: name === 'Tab' ? 9 : 13 });
    await cdp.call('Input.dispatchKeyEvent', { type: 'keyUp', key: name, code: name, modifiers, windowsVirtualKeyCode: name === 'Tab' ? 9 : 13, nativeVirtualKeyCode: name === 'Tab' ? 9 : 13 });
    await sleep(250);
  }
  for (const v of sizes) {
    await cdp.call('Emulation.setDeviceMetricsOverride', {
      width: v.width, height: v.height, deviceScaleFactor: 1, mobile: v.mobile,
    });
    await cdp.call('Emulation.setTouchEmulationEnabled', { enabled: v.mobile, maxTouchPoints: 1 });
    await loadOfflineHTML();
    await sleep(260);
    const m = await cdp.evaluate(metricsExpression);
    console.log('BROWSER METRICS', v.name, JSON.stringify(m));
    const image = await cdp.call('Page.captureScreenshot', { format: 'png', fromSurface: true });
    writeFileSync(join(outputDir, 'imagine-portal-' + v.name + '-' + v.width + '.png'),
      Buffer.from(image.data, 'base64'));
    assert.equal(m.width, v.width, v.name + ' viewport');
    assert.equal(m.overflow, false, v.name + ' horizontal overflow');
    assert.equal(m.headingCount, 1, v.name + ' primary heading');
    assert.equal(m.sectionCount, 6, v.name + ' six sections');
    assert.equal(m.linkCount, 6, v.name + ' six navigation links');
    assert.equal(m.sidebarPosition, 'sticky', v.name + ' persistent navigation');
    assert.equal(m.warningVisible, true, v.name + ' fictitious-data warning');
    assert.equal(m.scripts, 0, v.name + ' no operational scripts');
    assert.equal(m.forms, 0, v.name + ' no forms');
    assert.equal(m.reducedMotion, 'auto', v.name + ' reduced motion');
    for (const text of ['100% FICTÍCIOS', 'login', 'formulários', 'contratos', 'anúncios', 'cobranças', 'integrações', 'operações reais']) {
      assert.ok(m.warningText.includes(text), v.name + ' disclaimer: ' + text);
    }
    if (v.mobile) {
      assert.equal(m.navScrollable, true, v.width + 'px menu must scroll');
      assert.equal(m.helpVisible, true, v.name + ' visible navigation orientation');
      assert.ok(m.helpText.includes('6 áreas · deslize →'), v.name + ' six-area hint');
      assert.ok(m.warningHeight <= 108, v.name + ' compact but complete warning');
      const menuBox = await cdp.evaluate('(() => { const b = document.querySelector(".pv-nav").getBoundingClientRect(); return {x: b.x + b.width / 2, y: b.y + b.height / 2}; })()');
      await cdp.call('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: menuBox.x + 80, y: menuBox.y }] });
      for (let step = 1; step <= 8; step++) {
        await cdp.call('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: menuBox.x + 80 - step * 22, y: menuBox.y }] });
        await sleep(20);
      }
      await cdp.call('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      await sleep(160);
      assert.ok(await cdp.evaluate('document.querySelector(".pv-nav").scrollLeft > 0'), v.name + ' horizontal touch swipe');
      await cdp.evaluate('document.querySelector(".pv-nav").scrollLeft = 0');
    }

    // Real keyboard input: skip link, then all six full-label menu links.
    await key('Tab');
    assert.equal(await cdp.evaluate('document.activeElement.className'), 'pv-skip', v.name + ' skip link first');
    await key('Enter');
    assert.equal(await cdp.evaluate('document.activeElement.id'), 'pv-content', v.name + ' skip link focus');
    await loadOfflineHTML();
    await sleep(180);
    await key('Tab');
    const destinations = ['inicio', 'servicos', 'performance', 'solicitacoes', 'universidade', 'financeiro'];
    const labels = ['Visão geral', 'Meus serviços', 'Performance AI', 'Solicitações', 'Universidade', 'Financeiro'];
    for (const [index, id] of destinations.entries()) {
      await loadOfflineHTML();
      await sleep(150);
      for (let step = 0; step < index + 2; step++) await key('Tab');
      const focus = await cdp.evaluate('(() => { const e = document.activeElement; const b = e.getBoundingClientRect(); const n = document.querySelector(".pv-nav").getBoundingClientRect(); const s = getComputedStyle(e); return {href: e.getAttribute("href"), label: e.textContent.trim(), outline: parseFloat(s.outlineWidth), left:b.left, right:b.right, navLeft:n.left, navRight:n.right, scrollLeft: document.querySelector(".pv-nav").scrollLeft, visible: b.left >= n.left - 1 && b.right <= n.right + 1}; })()');
      assert.equal(focus.href, '#pv-' + id, v.name + ' keyboard order');
      assert.equal(focus.label, labels[index], v.name + ' full accessible label');
      assert.ok(focus.outline >= 3 && focus.visible, v.name + ' visible keyboard focus ' + JSON.stringify(focus));
      await key('Enter');
      await sleep(100);
      const target = await cdp.evaluate('(() => { const e = document.querySelector(location.hash); const b = e.getBoundingClientRect(); const sidebar = document.querySelector(".pv-sidebar").getBoundingClientRect(); return {id: e.id, focused: document.activeElement === e, visible: b.top >= (innerWidth <= 980 ? sidebar.bottom : 0) - 1 && b.top < innerHeight, heading: e.querySelector("h2").textContent.trim()}; })()');
      assert.equal(target.id, 'pv-' + id, v.name + ' anchor destination');
      assert.ok(target.focused && target.visible && target.heading.length > 0, v.name + ' heading visible below sticky menu');
      const sectionImage = await cdp.call('Page.captureScreenshot', { format: 'png', fromSurface: true });
      writeFileSync(join(outputDir, 'imagine-portal-' + v.name + '-' + id + '.png'), Buffer.from(sectionImage.data, 'base64'));
    }


    const hash = await cdp.evaluate('(() => { document.querySelector("a[href=\\"#pv-financeiro\\"]").click(); return location.hash; })()');
    assert.equal(hash, '#pv-financeiro', v.name + ' anchor navigation');
    await sleep(150);
    const target = await cdp.evaluate('(() => { const e = document.querySelector("#pv-financeiro"); const b = e.getBoundingClientRect(); return { visible: b.top < innerHeight && b.bottom > 0, focusable: e.tabIndex === -1 }; })()');
    assert.equal(target.visible, true, v.name + ' anchor target visible');
    assert.equal(target.focusable, true, v.name + ' focusable anchor target');
    console.log('PASS viewport', v.width + 'x' + v.height,
      '- no overflow, 6 links and sections, sticky menu, anchor navigation, screenshot');
    results.push({ viewport: v, metrics: m, keyboardDestinations: destinations, result: 'PASS' });
  }
  // Reflow approximations: desktop at 200%/400% effective CSS viewport.
  // These do not replace manual browser zoom or a physical-device review.
  for (const scale of [2, 4]) {
    await cdp.call('Emulation.setDeviceMetricsOverride', { width: 1440 / scale, height: 900 / scale, deviceScaleFactor: 1, mobile: false });
    await loadOfflineHTML();
    await sleep(180);
    const m = await cdp.evaluate(metricsExpression);
    assert.equal(m.overflow, false, scale + 'x reflow horizontal overflow');
    assert.equal(m.sectionCount, 6, scale + 'x reflow six sections');
    const image = await cdp.call('Page.captureScreenshot', { format: 'png', fromSurface: true });
    writeFileSync(join(outputDir, 'imagine-portal-reflow-' + scale + 'x.png'), Buffer.from(image.data, 'base64'));
    results.push({ reflowApproximation: scale, metrics: m, result: 'PASS' });
  }
  writeFileSync(join(outputDir, 'browser-results.json'), JSON.stringify(results, null, 2) + '\n');
  console.log('PORTAL-PREVIEW-UX-01: three real Chromium viewport tests PASSED');
} finally {
  if (ws) ws.close();
  previewServer.closeAllConnections();
  previewServer.close();
  chrome.kill('SIGTERM');
  await sleep(200);
  if (chrome.exitCode === null) chrome.kill('SIGKILL');
  rmSync(profile, { recursive: true, force: true });
}
