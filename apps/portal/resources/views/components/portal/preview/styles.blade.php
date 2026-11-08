<style>
  :root{color-scheme:light;--pv-ink:#183632;--pv-paper:#f5f8f6;--pv-white:#fff;--pv-dark:#163c36;--pv-teal:#116b60;--pv-muted:#59746e;--pv-line:#dfe8e4;--pv-highlight:#f9bd62}
  *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--pv-paper);color:var(--pv-ink);font-family:system-ui,-apple-system,"Segoe UI",sans-serif;font-size:15px;line-height:1.55}
  .pv-skip{position:absolute;left:12px;top:-100px;background:#fff;color:var(--pv-ink);padding:10px;border:2px solid var(--pv-teal);border-radius:8px;z-index:10}.pv-skip:focus{top:12px}
  .pv-layout{display:grid;grid-template-columns:232px minmax(0,1fr);min-height:100vh}.pv-sidebar{background:var(--pv-dark);color:#e4f5ee;padding:30px 16px;position:relative}
  .pv-brand{display:flex;align-items:center;gap:9px;font-size:21px;font-weight:800;letter-spacing:-.6px;margin:0 10px 28px}.pv-brand-mark{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:var(--pv-highlight);color:var(--pv-dark)}
  .pv-nav-label{margin:0 12px 12px;color:#b0ccc3;text-transform:uppercase;letter-spacing:1.5px;font-weight:800;font-size:11px}.pv-nav{display:grid;gap:4px}.pv-nav-link{display:block;color:#effaf6;border-radius:10px;text-decoration:none;padding:11px 14px;font-weight:550}.pv-nav-link:hover,.pv-nav-link:focus{background:#2d5a51}.pv-sidebar-note{padding:22px 12px;border-top:1px solid #356257;margin-top:30px;color:#c0d8ce;font-size:12px}
  .pv-main{min-width:0}.pv-topbar{background:var(--pv-white);border-bottom:1px solid var(--pv-line);padding:25px 5%;display:flex;justify-content:space-between;align-items:center;gap:16px}.pv-topbar strong{font-size:18px}.pv-topbar small{color:var(--pv-muted)}
  .pv-container{max-width:1150px;margin:0 auto;padding:26px 5% 50px}.pv-demo-warning{border:1px solid #edcd97;border-radius:12px;background:#fff9e9;color:#685026;padding:13px 16px;font-size:13px;font-weight:650}
  .pv-section{margin:28px 0 55px;padding:0 0 30px;border-bottom:1px solid var(--pv-line);scroll-margin-top:14px}.pv-section h2{font-size:clamp(25px,3vw,34px);letter-spacing:-1px;line-height:1.18;margin:12px 0}.pv-section>p{max-width:750px;color:var(--pv-muted);margin:0 0 22px}
  .pv-chip{display:inline-block;background:#e6f4ed;border-radius:99px;padding:5px 11px;color:var(--pv-teal);font-size:11px;font-weight:800;letter-spacing:.3px}
  .pv-grid{display:grid;gap:15px;grid-template-columns:repeat(2,minmax(0,1fr))}.pv-grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}.pv-card{background:var(--pv-white);border:1px solid var(--pv-line);border-radius:16px;padding:21px;min-width:0}
  .pv-card h2{font-size:17px;margin:0 0 9px}.pv-card h3{font-size:15px;margin:0 0 7px}.pv-card p{font-size:13px;color:var(--pv-muted);margin:6px 0 0}.pv-metric-label{font-size:12px;color:var(--pv-muted)}.pv-metric-number{font-size:29px;line-height:1.15;letter-spacing:-.8px;font-weight:850;margin:10px 0}
  .pv-service-status{display:inline-block;margin-top:14px;background:#eff3f1;color:#526d64;border-radius:30px;padding:4px 10px;font-size:11px;font-weight:800}
  .pv-callout{background:#eaf5ef;border:1px solid #cfe6db;border-radius:15px;padding:22px;margin-top:18px}.pv-callout strong{display:block}.pv-callout p{margin:6px 0 0;color:#41665c}
  .pv-summary{font-size:12px;color:var(--pv-muted)}.pv-footer{font-size:12px;color:var(--pv-muted);padding:20px 5% 35px}.pv-list{padding-left:20px;color:#48695f}
  a:focus-visible{outline:3px solid #c78b20;outline-offset:3px}
  @media(max-width:980px){.pv-layout{grid-template-columns:minmax(0,1fr);min-width:0;max-width:100%}.pv-sidebar{padding:15px}.pv-brand{margin:0 0 11px}.pv-nav-label,.pv-sidebar-note{display:none}.pv-nav{display:flex;flex-wrap:wrap}.pv-grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:620px){.pv-nav{flex-wrap:nowrap;overflow-x:auto;white-space:nowrap}.pv-nav-link{padding:9px 11px;font-size:13px}.pv-grid{grid-template-columns:1fr}.pv-grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}.pv-card{padding:16px}.pv-metric-number{font-size:25px}.pv-topbar{padding:17px 5%}}
  @media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}}

  /* PORTAL-PREVIEW-UX-01: pure-CSS responsive navigation, no routes or JS. */
  .pv-sidebar{position:sticky;top:0;align-self:start;min-width:0;max-width:100%;height:100dvh;max-height:100dvh;overflow-y:auto;overscroll-behavior:contain}
  .pv-nav-link{display:flex;align-items:center;min-height:44px;overflow-wrap:anywhere}
  .pv-nav-help{display:none}
  .pv-visually-hidden{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0}
  .pv-topbar-title{display:grid;gap:2px}
  .pv-topbar-brand{color:var(--pv-muted);font-size:12px;font-weight:700}
  .pv-document-title{font-size:20px;line-height:1.25;letter-spacing:-.5px;margin:0}
  .pv-card{overflow-wrap:anywhere}
  .pv-section:target{outline:2px solid #b6d7cb;outline-offset:5px;border-radius:10px}
  .pv-section:focus-visible{outline:3px solid #c78b20;outline-offset:5px}
  body:has(#pv-inicio:target) .pv-nav-link[href="#pv-inicio"],
  body:has(#pv-servicos:target) .pv-nav-link[href="#pv-servicos"],
  body:has(#pv-performance:target) .pv-nav-link[href="#pv-performance"],
  body:has(#pv-solicitacoes:target) .pv-nav-link[href="#pv-solicitacoes"],
  body:has(#pv-universidade:target) .pv-nav-link[href="#pv-universidade"],
  body:has(#pv-financeiro:target) .pv-nav-link[href="#pv-financeiro"]{background:#2d5a51;color:#fff}
  @media(max-width:980px){
    .pv-sidebar{position:sticky;top:0;z-index:5;height:auto;max-height:none;overflow:visible;box-shadow:0 4px 14px rgba(0,0,0,.13)}
    .pv-nav{width:100%;min-width:0;max-width:100%;overflow-x:auto;scroll-snap-type:x proximity;scrollbar-width:thin;overscroll-behavior-x:contain}
    .pv-nav:focus-within{scroll-snap-type:x mandatory}
    .pv-nav:focus-within .pv-nav-link{scroll-snap-align:none}
    .pv-nav:focus-within .pv-nav-link:focus-visible{scroll-snap-align:center}
    .pv-nav-link:focus-visible{outline-offset:-3px}
    .pv-nav-link{flex:0 0 auto;white-space:nowrap;scroll-snap-align:start;overflow-wrap:normal}
    .pv-nav-help{display:block;margin:6px 0 0;color:#d2e6de;font-size:11px}
    .pv-section{scroll-margin-top:218px}
  }
  @media(max-width:620px){
    .pv-sidebar{padding:11px 14px}
    .pv-brand{margin:0 0 8px}
    .pv-nav{flex-wrap:nowrap;gap:2px}
    .pv-nav-link{min-height:42px}
    .pv-nav-help{margin:5px 0 0;font-size:12px;line-height:1.4}
    .pv-demo-warning{padding:8px 10px;font-size:12px;line-height:1.45}
    .pv-section{scroll-margin-top:165px}
    .pv-topbar{align-items:flex-start;gap:5px;flex-wrap:wrap}
  }
  @media(max-width:360px){
    .pv-grid-4{grid-template-columns:1fr}
  }
  @media print{
    .pv-sidebar{position:static;height:auto;max-height:none}
    .pv-nav{display:none}
    .pv-section{break-inside:avoid}
  }
</style>
