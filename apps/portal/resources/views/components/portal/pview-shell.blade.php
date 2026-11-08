<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    {{-- PVIEW-03 is a THROWAWAY local demo. Inline CSS/JS are not a production CSP. --}}
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'">
    <title>Visão geral · Imagine Portal — DEMONSTRAÇÃO</title>
    <style>
        :root{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#19233d;background:#f5f7fb;--ink:#18233d;--muted:#66718a;--line:#e3e8f0;--nav:#14223f;--blue:#3159c7;--card:#fff}
        *{box-sizing:border-box}
        body{margin:0;min-width:290px}
        a{color:inherit}
        a:focus-visible,[tabindex]:focus-visible{outline:3px solid #e8a52b;outline-offset:3px;border-radius:5px}
        .pi-skip{position:absolute;top:-100px;left:12px;z-index:99;background:#fff;padding:10px 16px}
        .pi-skip:focus{top:10px}
        .pi-demo-layout{display:grid;grid-template-columns:258px minmax(0,1fr);min-height:100dvh}
        .pi-demo-sidebar{background:var(--nav);color:#e7edfa;display:flex;flex-direction:column;gap:20px;padding:26px 17px 22px}
        .pi-brand{display:flex;gap:12px;align-items:center;padding:8px 12px;font-weight:800;letter-spacing:.01em;font-size:20px}
        .pi-mark{width:40px;height:40px;border-radius:12px;color:white;background:linear-gradient(145deg,#3f79ff,#9078ee);display:grid;place-items:center;font-size:23px}
        .pi-brand small{display:block;font-size:10px;font-weight:650;color:#b6c4df;letter-spacing:.15em;text-transform:uppercase}
        .pi-nav-caption{color:#92a6cd;text-transform:uppercase;font-size:10px;padding:0 14px;letter-spacing:.17em;font-weight:700}
        .pi-demo-nav{display:flex;flex-direction:column;gap:4px}
        .pi-demo-nav a{display:flex;align-items:center;gap:11px;text-decoration:none;font-size:13px;font-weight:570;color:#c2cee6;padding:12px 13px;border-radius:10px}
        .pi-demo-nav a:hover{background:#243553;color:white}
        .pi-demo-nav a[aria-current="page"]{color:#fff;background:#354c7d;box-shadow:inset 3px 0 #88afff}
        .pi-nav-symbol{width:22px;text-align:center;font-size:17px;line-height:19px}
        .pi-nav-foot{margin-top:auto;padding:18px 12px 2px;border-top:1px solid #2b3a57;color:#afbeda;font-size:11px;line-height:1.8}
        .pi-nav-foot strong{color:#fff;display:block;font-size:12px}
        .pi-demo-body{min-width:0}
        .pi-topbar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:22px 40px 18px;background:#fff;border-bottom:1px solid var(--line)}
        .pi-breadcrumb{color:var(--muted);font-size:12px}
        .pi-breadcrumb strong{color:var(--ink)}
        .pi-profile{display:flex;align-items:center;gap:9px;background:#f1f4fa;border:1px solid var(--line);color:#3c4e70;padding:7px 11px;border-radius:24px;font-size:12px;font-weight:650}
        .pi-profile::before{content:"A";display:grid;place-items:center;border-radius:50%;width:26px;height:26px;background:#cbdafd;color:#3158a5;font-weight:800}
        .pi-demo-main{max-width:1370px;margin:auto;padding:30px 40px 70px;min-width:0}
        .pi-demo-warning{border:1px solid #e7d49d;background:#fff8e7;color:#654d17;border-radius:11px;padding:12px 15px;font-size:12px;display:flex;gap:10px;margin-bottom:28px}
        .pi-demo-warning strong{white-space:nowrap}
        .pi-demo-screen[hidden]{display:none!important}
        .pi-eyebrow{color:var(--blue);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.13em}
        .pi-screen-title{margin:6px 0 9px;font-size:clamp(26px,3vw,37px);letter-spacing:-.045em;line-height:1.15}
        .pi-lede{color:var(--muted);font-size:13px;max-width:700px;line-height:1.65;margin:0}
        .pi-screen-head{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:28px}
        .pi-pill{font-size:11px;font-weight:750;border-radius:99px;padding:6px 10px;white-space:nowrap;background:#eaf0ff;color:#3e61ac}
        .pi-pill.gray{background:#edf0f6;color:#617088}
        .pi-pill.amber{background:#fff4d9;color:#906700}
        .pi-pill.green{background:#e7f7f0;color:#277659}
        .pi-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
        .pi-grid.four{grid-template-columns:repeat(4,minmax(0,1fr))}
        .pi-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px;min-width:0}
        .pi-card h2{font-size:17px;letter-spacing:-.02em;margin:0 0 12px}
        .pi-card h3{font-size:16px;margin:6px 0}
        .pi-card p{color:var(--muted);font-size:12px;line-height:1.6;margin:0}
        .pi-metric-label{color:var(--muted);font-size:12px;font-weight:650}
        .pi-metric-value{font-size:29px;font-weight:780;letter-spacing:-.05em;padding:10px 0 4px}
        .pi-metric-note{font-size:11px;color:#7d899d}
        .pi-section-gap{margin-top:28px}
        .pi-feature{display:grid;grid-template-columns:1.3fr 1fr;gap:16px}
        .pi-hero{background:linear-gradient(110deg,#1c3463,#395ec0);border-radius:14px;color:#fff;padding:29px}
        .pi-hero h2{font-size:23px;letter-spacing:-.03em;margin:0 0 9px}
        .pi-hero p{color:#d9e5ff;font-size:13px;line-height:1.6}
        .pi-hero a{display:inline-block;margin-top:23px;text-decoration:none;background:#fff;color:#244697;border-radius:9px;padding:11px 15px;font-size:12px;font-weight:750}
        .pi-soft{border:1px solid #e0e7fa;background:#f0f4ff;border-radius:14px;padding:24px}
        .pi-soft h2{font-size:17px;color:#263c71;margin:0 0 12px}
        .pi-soft p{color:#66769b;font-size:12px;line-height:1.65}
        .pi-soft a,.pi-link{display:inline-block;color:#2853bf;font-size:12px;font-weight:700;text-decoration:underline;text-underline-offset:3px;margin-top:16px}
        .pi-rowhead{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
        .pi-rowhead h2{margin:0}
        .pi-listitem{border-top:1px solid var(--line);padding:16px 0;display:flex;gap:12px;justify-content:space-between;align-items:center}
        .pi-listitem:first-of-type{border-top:0}
        .pi-listitem b{display:block;font-size:13px}
        .pi-listitem small{display:block;color:var(--muted);font-size:11px;margin-top:4px;line-height:1.5}
        .pi-symbol{width:42px;height:42px;flex:0 0 42px;background:#eff3ff;color:#3359b6;border-radius:11px;display:grid;place-items:center;font-size:21px}
        .pi-listcontent{display:flex;gap:12px;align-items:center;min-width:0}
        .pi-tile{display:flex;flex-direction:column;gap:10px;min-height:195px}
        .pi-tile .pi-link{margin-top:auto}
        .pi-tablewrap{overflow-x:auto}
        .pi-tablewrap table{width:100%;border-collapse:collapse;min-width:490px}
        .pi-tablewrap th,.pi-tablewrap td{border-bottom:1px solid var(--line);padding:16px 14px;text-align:left;font-size:12px}
        .pi-tablewrap th{color:#71809a;background:#f9fbff;font-size:11px}
        .pi-tablewrap td{color:#3e4c67}
        .pi-empty{padding:45px 24px;border:1px dashed #cdd6e5;background:#fff;border-radius:13px;text-align:center}
        .pi-empty h2{margin:0 0 12px}
        .pi-empty p{color:var(--muted);font-size:13px;max-width:490px;margin:0 auto}
        .pi-footer{text-align:center;color:#8793a9;font-size:11px;margin-top:50px}
        @media(max-width:1100px){.pi-grid.four{grid-template-columns:repeat(2,minmax(0,1fr))}.pi-feature{grid-template-columns:1fr}.pi-demo-main{padding:26px}.pi-topbar{padding:18px 26px}}
        @media(max-width:720px){.pi-demo-layout{display:block}.pi-demo-sidebar{padding:13px;gap:12px}.pi-brand{font-size:17px;padding:0 3px}.pi-mark{width:33px;height:33px}.pi-nav-caption,.pi-nav-foot{display:none}.pi-demo-nav{flex-direction:row;overflow-x:auto;gap:3px;padding:0 0 5px}.pi-demo-nav a{white-space:nowrap;flex:0 0 auto;font-size:12px;padding:9px 11px}.pi-nav-symbol{display:none}.pi-topbar{padding:15px 17px}.pi-demo-main{padding:21px 17px 45px}.pi-grid{grid-template-columns:1fr}.pi-grid.four{grid-template-columns:repeat(2,minmax(0,1fr))}.pi-demo-warning{flex-wrap:wrap}}
        @media(max-width:410px){.pi-grid.four{grid-template-columns:1fr}.pi-profile{font-size:10px}.pi-screen-head{align-items:flex-start;flex-direction:column}}
        @media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important}}
    </style>
</head>
<body>
<a class="pi-skip" href="#pview-main">Pular para o conteúdo principal</a>
<div class="pi-demo-layout">
    <aside class="pi-demo-sidebar">
        <div class="pi-brand"><span class="pi-mark" aria-hidden="true">✦</span><span>imagine<small>Portal de clientes</small></span></div>
        <span class="pi-nav-caption">Espaço do cliente · exemplo</span>
        <nav class="pi-demo-nav" aria-label="Seções demonstrativas do Portal">
            @foreach (\App\Presentation\PviewSection::cases() as $section)
                <a href="#pview-{{ $section->value }}" data-nav="{{ $section->value }}">
                    <span class="pi-nav-symbol" aria-hidden="true">◇</span>{{ $section->label() }}
                </a>
            @endforeach
        </nav>
        <div class="pi-nav-foot"><strong>Protótipo PVIEW-03</strong>Sem contas reais, contratos, cobranças ou conexão com fornecedores. Todos os dados são fictícios.</div>
    </aside>
    <div class="pi-demo-body">
        <header class="pi-topbar">
            <div class="pi-breadcrumb">Imagine Portal / <strong id="pview-current-label">Visão geral</strong></div>
            <div class="pi-profile" aria-label="Empresa Aurora, organização fictícia">Empresa Aurora · Fictícia</div>
        </header>
        <main class="pi-demo-main" id="pview-main" tabindex="-1">
            <div class="pi-demo-warning" role="note"><strong>DEMONSTRAÇÃO OFFLINE / LARAVEL</strong><span>Interface ilustrativa com dados fictícios. Sem login, contrato, cobrança ou acesso a serviços reais.</span></div>
            {{ $slot }}
            <footer class="pi-footer">Imagine Portal · PVIEW-03 · protótipo Laravel sem rotas, sessão ou backend operacional</footer>
        </main>
    </div>
</div>
<script>
(function () {
    "use strict";
    const labels = @json(collect(\App\Presentation\PviewSection::cases())->mapWithKeys(fn ($section) => [$section->value => $section->label()])->all());
    const main = document.getElementById("pview-main");
    const screens = Array.from(document.querySelectorAll("[data-screen]"));
    const links = Array.from(document.querySelectorAll("[data-nav]"));
    function render(focus) {
        const raw = window.location.hash.slice(1).replace(/^pview-/, "");
        const selected = Object.prototype.hasOwnProperty.call(labels, raw) ? raw : "overview";
        for (const screen of screens) {
            screen.hidden = screen.dataset.screen !== selected;
        }
        for (const link of links) {
            if (link.dataset.nav === selected) link.setAttribute("aria-current", "page");
            else link.removeAttribute("aria-current");
        }
        document.getElementById("pview-current-label").textContent = labels[selected];
        document.title = labels[selected] + " · Imagine Portal — Demonstração";
        if (focus) {
            const screen = screens.find((item) => item.dataset.screen === selected);
            if (screen) screen.focus({preventScroll:true});
            window.scrollTo(0, 0);
        }
    }
    window.addEventListener("hashchange", function () { render(true); });
    render(false);
}());
</script>
</body>
</html>
