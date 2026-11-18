<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>@yield('title', 'Imagine · Performance')</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#faf8f4;color:#242424;font:17px/1.6 system-ui,sans-serif}
main{max-width:1120px;margin:auto;padding:40px 24px}header{display:flex;justify-content:space-between;gap:24px;align-items:center}
a{color:#9c4b00}h1{font-size:clamp(32px,5vw,56px);line-height:1.1}h2{line-height:1.25}small,.muted{color:#6b645d}
section{margin:30px 0;padding:28px;border:1px solid #e5d6c5;background:white;border-radius:20px}strong{color:#9c4b00}
dl{display:grid;grid-template-columns:1fr 2fr;gap:16px}dd{margin:0;overflow-wrap:anywhere}
.modules{display:flex;flex-wrap:wrap;gap:10px;margin:24px 0}.modules a{padding:10px 18px;border:1px solid #dcc5aa;border-radius:12px;text-decoration:none}
.modules a[aria-current="page"]{background:#fff0dc;border-color:#b76300;font-weight:700}
.filters{display:flex;flex-wrap:wrap;align-items:end;gap:18px}.field{flex:1 1 220px;min-width:0}.field label{display:block;margin-bottom:6px;font-weight:600}
input,button{font:inherit;border-radius:10px;padding:10px 12px}input{width:100%;min-width:0;border:1px solid #85796d;background:white;color:inherit}
button{border:1px solid #9c4b00;background:#f9941d;color:#242424;font-weight:700;cursor:pointer}.filter-actions{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
a:focus-visible,input:focus-visible,button:focus-visible{outline:3px solid #9c4b00;outline-offset:3px}.notice{padding:14px 18px;background:#fff0dc;border-left:4px solid #b76300;margin-bottom:20px}
.companies{padding:0;list-style:none}.companies li{padding:22px;margin:16px 0;border:1px solid #e5d6c5;border-radius:16px;background:white;overflow-wrap:anywhere}
@media(max-width:600px){main{padding:28px 20px}section{padding:22px}dl{grid-template-columns:1fr;gap:8px}dd{margin-bottom:12px}header{align-items:start;flex-direction:column;gap:12px}.filter-actions{width:100%}}
</style>
</head>
<body><main>
<header><strong>imagine · Performance AI</strong><a href="{{ route('portal-admin.home') }}">← Voltar ao Portal</a></header>
<p><small>HOMOLOGAÇÃO PRIVADA · SOMENTE LEITURA</small></p>
@yield('content')
@if($laboratoryAvailable)
<p><a href="{{ route('performance-admin.index') }}">Abrir os dez módulos com dados simulados do laboratório →</a></p>
<p><small>O laboratório local usa empresas fictícias próprias. Esses indicadores não são dados retornados pela consulta privada.</small></p>
@endif
</main></body>
</html>
