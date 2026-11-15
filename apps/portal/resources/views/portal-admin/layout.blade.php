<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>@yield('title', 'Administração') · Imagine</title>
    <style>
        :root{--orange:#F9941D;--ink:#18283d;--muted:#536579;--bg:#f5f7fb}
        *,*::before,*::after{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--ink);
            font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}
        header{background:#172438;color:white;padding:20px max(20px,calc((100vw - 1000px)/2))}
        .brand{font-weight:850;letter-spacing:-.055em;font-size:27px}
        .brand b{color:var(--orange)}
        main{max-width:470px;margin:65px auto;padding:0 20px}
        article{background:#fff;border:1px solid #dfe7f0;border-radius:16px;
            padding:30px;box-shadow:0 8px 30px #1429430a}
        h1{font-size:26px;line-height:1.2;letter-spacing:-.04em;margin:0 0 12px}
        p{margin:9px 0 20px;color:var(--muted)}
        label{display:block;font-weight:650;font-size:14px;margin:14px 0 5px}
        input{display:block;width:100%;padding:12px;border:1px solid #c8d2df;
            border-radius:8px;font:inherit;color:var(--ink);background:white}
        input:focus{outline:2px solid var(--orange);outline-offset:1px}
        button{display:block;width:100%;border:0;border-radius:9px;
            background:var(--orange);color:#18283d;font:inherit;
            font-weight:800;padding:13px;cursor:pointer;margin-top:23px}
        .secondary{background:#e8edf4}
        .error{border:1px solid #e9ae99;background:#fff0e9;
            color:#812e1d;padding:12px;border-radius:8px;font-size:14px}
        .note{font-size:13px;color:#5f6f80}
        footer{text-align:center;padding:30px;font-size:12px;color:var(--muted)}
    </style>
</head>
<body>
<header><div class="brand">imagine<b>.</b></div></header>
<main><article>@yield('content')</article></main>
<footer>Administração Imagine · Acesso restrito, sem permissões de clientes</footer>
</body>
</html>
