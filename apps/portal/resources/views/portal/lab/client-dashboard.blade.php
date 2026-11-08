<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'">
    <title>Painel de laboratório · Portal Imagine</title>
    <style>
        :root{font:15px/1.65 system-ui,-apple-system,"Segoe UI",Arial,sans-serif;background:#f5f7fb;color:#19233d}
        *{box-sizing:border-box}body{margin:0}header{background:#142341;color:#fff;padding:20px max(20px,calc((100vw - 1050px)/2));display:flex;gap:16px;justify-content:space-between;flex-wrap:wrap}
        header strong{font-size:21px;letter-spacing:-.04em}header span{color:#b8c8e9;font-size:12px}
        main{max-width:1050px;margin:auto;padding:34px 20px 70px}h1{font-size:clamp(25px,4vw,37px);line-height:1.15;letter-spacing:-.04em;margin:7px 0 14px}
        .notice{background:#fff8e6;color:#6b531a;border:1px solid #e5d394;padding:13px 17px;border-radius:12px;margin-bottom:30px;font-size:12px}
        .eyebrow{text-transform:uppercase;letter-spacing:.12em;color:#3159c7;font-weight:750;font-size:11px}
        p{color:#67738b;margin:0 0 19px}section{background:#fff;border:1px solid #e3e8f0;padding:24px;border-radius:14px;margin-top:23px}
        h2{font-size:19px;margin:0 0 18px}ul{list-style:none;margin:0;padding:0}li{padding:15px 0;border-bottom:1px solid #e3e8f0}
        li:last-child{border-bottom:0}.badge{display:inline-block;border-radius:99px;background:#eaf0ff;color:#3159a8;font-size:11px;font-weight:700;padding:5px 9px;margin-top:8px}
        footer{font-size:11px;color:#8793a9;margin-top:35px}
    </style>
</head>
<body>
<header>
    <strong>imagine · Portal</strong>
    <span>Área de testes — sem acesso público</span>
</header>
<main>
    <div class="notice" role="note"><strong>LABORATÓRIO ISOLADO:</strong> somente informações fictícias do PostgreSQL descartável. Sem login real, MFA, contratação ou operações de clientes.</div>
    <span class="eyebrow">Organização autorizada em sessão de teste</span>
    <h1>{{ $organization['name'] }}</h1>
    <p>Painel experimental de serviços visíveis apenas ao vínculo ativo desta empresa. Nenhum dado é verdadeiro.</p>
    <section aria-labelledby="lab-services-title">
        <h2 id="lab-services-title">Meus serviços — dados sintéticos</h2>
        @if (count($services) === 0)
            <p>Nenhum serviço fictício encontrado para esta empresa.</p>
        @else
            <ul>
                @foreach ($services as $service)
                    <li>
                        <strong>{{ $service['label'] }}</strong>
                        <div><span class="badge">Referência fictícia: {{ $service['id'] }}</span></div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    <footer>Imagine Portal · LAB AUTH-F1 · interface não operacional · Sem conexão com CRM ou demais produtos</footer>
</main>
</body>
</html>
