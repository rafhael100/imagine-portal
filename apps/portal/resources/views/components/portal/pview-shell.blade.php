<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'">
    <title>Prévia Laravel · Imagine Portal — demonstração</title>
    <style>
        :root{font:15px/1.6 system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#1c2943;background:#f4f7fb}
        *{box-sizing:border-box}
        body{margin:0}
        a{color:inherit}
        a:focus-visible{outline:3px solid #d48d00;outline-offset:3px;border-radius:5px}
        .pi-preview-skip{position:absolute;top:-80px;left:10px;background:#fff;padding:10px;z-index:5}
        .pi-preview-skip:focus{top:10px}
        .pi-preview-layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100dvh}
        .pi-preview-nav{background:#142341;color:#eff3ff;padding:24px 16px}
        .pi-preview-brand{font-weight:850;font-size:23px;margin:0 0 24px 10px;letter-spacing:-.04em}
        .pi-preview-brand small{display:block;font-size:11px;font-weight:550;letter-spacing:.1em;color:#b7c4e0;text-transform:uppercase}
        .pi-preview-nav nav{display:grid;gap:5px}
        .pi-preview-nav a{border-radius:9px;display:block;text-decoration:none;padding:11px 13px;font-size:13px;color:#d8e3fa}
        .pi-preview-nav a:hover,.pi-preview-nav a:focus{color:#fff;background:#2c4577}
        .pi-preview-main{min-width:0;max-width:1400px;width:100%;padding:28px clamp(18px,4vw,48px);margin:0 auto}
        .pi-preview-alert{padding:12px 16px;border:1px solid #eddda9;background:#fff8e6;border-radius:11px;color:#624f21;font-size:12px;margin:0 0 25px}
        .pi-preview-muted{color:#637088}
        .pi-preview-head{font-size:clamp(26px,3vw,37px);line-height:1.1;letter-spacing:-.04em;margin:0 0 10px}
        .pi-preview-subhead{font-size:18px;margin:0 0 5px}
        .pi-preview-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:22px 0}
        .pi-preview-card{background:#fff;border:1px solid #e1e6ef;border-radius:14px;padding:20px;min-width:0}
        .pi-preview-card h3{font-size:16px;margin:0 0 8px}
        .pi-preview-card p{font-size:13px;color:#657089;margin:0}
        .pi-preview-panel{background:#fff;border:1px solid #e1e6ef;border-radius:14px;padding:22px;margin:22px 0;scroll-margin-top:22px}
        .pi-preview-panel:target{border-color:#5774c8;box-shadow:0 0 0 2px #e5eaff}
        .pi-preview-tag{display:inline-block;background:#eaf0ff;color:#3656a4;font-size:11px;border-radius:99px;padding:4px 9px;font-weight:700;margin-top:11px}
        .pi-preview-footer{color:#77839a;font-size:12px;margin:36px 0 12px}
        @media(max-width:800px){.pi-preview-layout{grid-template-columns:1fr}.pi-preview-nav{padding:14px}.pi-preview-brand{margin:0 0 12px}.pi-preview-nav nav{display:flex;overflow-x:auto;gap:4px}.pi-preview-nav a{white-space:nowrap;padding:8px 10px}.pi-preview-main{padding:24px 16px}}
        @media(max-width:470px){.pi-preview-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <a class="pi-preview-skip" href="#pview-main">Pular para conteúdo</a>
    <div class="pi-preview-layout">
        <aside class="pi-preview-nav">
            <div class="pi-preview-brand">imagine <small>Prévia do Portal</small></div>
            <nav aria-label="Seções demonstrativas do Portal">
                @foreach (\App\Presentation\PviewSection::cases() as $section)
                    <a href="#pview-{{ $section->value }}">{{ $section->label() }}</a>
                @endforeach
            </nav>
        </aside>
        <main class="pi-preview-main" id="pview-main" tabindex="-1">
            <div class="pi-preview-alert" role="note"><strong>DEMONSTRAÇÃO OFFLINE / LARAVEL</strong> — Empresa Aurora fictícia. Sem login, contratos, cobrança, dados reais ou integração de serviços.</div>
            {{ $slot }}
            <p class="pi-preview-footer">Imagine Portal · PVIEW-02 · protótipo interno não operacional · nenhum acesso de cliente autorizado</p>
        </main>
    </div>
</body>
</html>
