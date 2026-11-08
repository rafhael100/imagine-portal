// PORTAL-PREVIEW-UX-01 — headless Chromium layout test with synthetic Blade HTML.
// Only contacts local DevTools (127.0.0.1); no external API or credentials.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { accessSync, constants, existsSync, mkdirSync, mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
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
  await cdp.call('Emulation.setEmulatedMedia', {
    features: [{ name: 'prefers-reduced-motion', value: 'reduce' }],
  });
  const sizes = [
    { name: 'desktop', width: 1440, height: 900, mobile: false },
    { name: 'celular', width: 390, height: 844, mobile: true },
    { name: 'celular-estreito', width: 320, height: 740, mobile: true },
  ];
  for (const v of sizes) {
    await cdp.call('Emulation.setDeviceMetricsOverride', {
      width: v.width, height: v.height, deviceScaleFactor: 1, mobile: v.mobile,
    });
    await cdp.call('Page.navigate', { url: pathToFileURL(htmlPath).href });
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
    if (v.width === 390) assert.equal(m.navScrollable, true, '390px menu must scroll');


    const hash = await cdp.evaluate('(() => { document.querySelector("a[href=\\"#pv-financeiro\\"]").click(); return location.hash; })()');
    assert.equal(hash, '#pv-financeiro', v.name + ' anchor navigation');
    await sleep(150);
    const target = await cdp.evaluate('(() => { const e = document.querySelector("#pv-financeiro"); const b = e.getBoundingClientRect(); return { visible: b.top < innerHeight && b.bottom > 0, focusable: e.tabIndex === -1 }; })()');
    assert.equal(target.visible, true, v.name + ' anchor target visible');
    assert.equal(target.focusable, true, v.name + ' focusable anchor target');
    console.log('PASS viewport', v.width + 'x' + v.height,
      '- no overflow, 6 links and sections, sticky menu, anchor navigation, screenshot');
  }
  console.log('PORTAL-PREVIEW-UX-01: three real Chromium viewport tests PASSED');
} finally {
  if (ws) ws.close();
  chrome.kill('SIGTERM');
  await sleep(200);
  if (chrome.exitCode === null) chrome.kill('SIGKILL');
  rmSync(profile, { recursive: true, force: true });
}
