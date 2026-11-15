@php
    // F120: illustrative, non-routable view. Only accept the F118 lab's
    // already-sanitized counters. It is NOT a customer login or an admin API.
    $trustedShape = isset($readiness) && is_array($readiness)
        && ($readiness['contractVersion']??null)==='portal-seo-f118-static-build-lab-v1'
        && ($readiness['sourceType']??null)==='UNVERIFIED_OFFLINE_BUILD_CLAIM'
        && ($readiness['releaseStatus']??null)==='HOLD_REAL_SOURCE_AUTHENTICATION_AND_STAGING'
        && ($readiness['customerDisplayAuthorized']??null)===false
        && ($readiness['publicationAuthorized']??null)===false
        && ($readiness['realSiteVerified']??null)===false
        && in_array($readiness['reviewStatus']??null,
            ['REVIEW_BLOCKED','HUMAN_REVIEW_REQUIRED'],true);
    $rawCounts=$trustedShape && isset($readiness['counts']) &&
        is_array($readiness['counts']) ? $readiness['counts'] : [];
    $safeCount=static function(string $name)use($rawCounts,$trustedShape):string {
        $value=$rawCounts[$name]??null;
        return $trustedShape && is_int($value)&&$value>=0&&$value<=200000
            ?number_format($value,0,',','.'):'N/D';
    };
    $status=$trustedShape?($readiness['reviewStatus']==='REVIEW_BLOCKED'
        ?'Revisão técnica bloqueada':'Revisão humana necessária')
        :'Sem relatório verificado';
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Imagine SEO · Auditoria de build · Prévia interna</title>
<style>
:root{color-scheme:light;--orange:#F9941D;--ink:#172538;--muted:#55677d;--nav:#121e30;--line:#e0e6ee;--bg:#f5f7fa}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink)}
header{background:var(--nav);color:#fff;padding:25px clamp(20px,5vw,60px)}
.brand{font-size:27px;font-weight:850;letter-spacing:-.07em}.brand span{color:var(--orange)}
main{max-width:1040px;margin:auto;padding:34px 20px 55px}
.eyebrow{text-transform:uppercase;font-size:11px;color:#9b580b;letter-spacing:.11em;font-weight:800}
h1{font-size:clamp(27px,4vw,39px);line-height:1.15;margin:8px 0 10px;letter-spacing:-.04em}
h2{font-size:18px;margin:0 0 13px}p{color:var(--muted)}
.notice{background:#fff4e4;border:1px solid #f1c482;border-radius:13px;padding:17px 20px;margin:26px 0;color:#854c08}
.notice strong{display:block}.notice p{margin:5px 0 0;color:#845927;font-size:13px}
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin:18px 0 28px}
.metric{background:white;border:1px solid var(--line);border-radius:13px;padding:20px}
.label{color:var(--muted);font-size:13px}.value{font-size:32px;font-weight:850;letter-spacing:-.045em}
.status{border-top:3px solid var(--orange);background:#fff;border-radius:13px;padding:20px;border-inline:1px solid var(--line);border-bottom:1px solid var(--line)}
.pill{display:inline-block;border-radius:99px;background:#f5ecdf;padding:6px 13px;font-size:12px;font-weight:800;color:#865112}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin-top:16px}
.step{background:#fff;padding:19px;border:1px solid var(--line);border-radius:12px}
.step b{display:block}.step p{font-size:13px;margin:6px 0 0}
footer{margin-top:31px;border-top:1px solid var(--line);padding-top:15px;font-size:12px;color:var(--muted)}
@media(max-width:700px){.grid,.steps{grid-template-columns:1fr}}
</style>
</head>
<body>
<header><div class="brand">imagine<span>.</span></div><small>SEO Engine · Portal do Cliente · Prévia técnica interna</small></header>
<main>
<div class="eyebrow">Qualidade de publicação · Laboratório</div>
<h1>Auditoria técnica das páginas</h1>
<p>Acompanhamento ilustrativo de consistência do inventário municipal e do HTML compilado. Este painel não está disponível para clientes.</p>
<section class="notice" role="note">
<strong>DEMONSTRAÇÃO INTERNA — sem acesso a produção</strong>
<p>O relatório offline não comprova implantação, autorização do cliente, origem oficial IBGE, nem indexação Google. Nenhum conteúdo é publicado aqui.</p>
</section>
<section class="status" aria-label="Situação da revisão técnica">
<div class="eyebrow">Situação atual</div>
<h2>{{ $status }}</h2>
<span class="pill">Publicação não autorizada</span>
</section>
<section aria-label="Indicadores do build estático">
<div class="grid">
<div class="metric"><div class="label">Páginas HTML analisadas</div><div class="value">{{ $safeCount('localBuildPages') }}</div></div>
<div class="metric"><div class="label">Páginas municipais encontradas</div><div class="value">{{ $safeCount('municipalPagesPresent') }}</div></div>
<div class="metric"><div class="label">Páginas municipais ausentes</div><div class="value">{{ $safeCount('municipalPagesMissing') }}</div></div>
<div class="metric"><div class="label">Idiomas divergentes</div><div class="value">{{ $safeCount('wrongLanguagePages') }}</div></div>
<div class="metric"><div class="label">Problemas técnicos</div><div class="value">{{ $safeCount('technicalErrors') }}</div></div>
<div class="metric"><div class="label">Vazamentos de prévias privadas</div><div class="value">{{ $safeCount('privateDraftLeaks') }}</div></div>
</div>
</section>
<section aria-labelledby="next">
<h2 id="next">O que falta para a publicação real</h2>
<div class="steps">
<div class="step"><b>01 · Conferir a origem</b><p>Comprovar a referência do IBGE e a qualidade editorial das páginas.</p></div>
<div class="step"><b>02 · Autorizar o acesso</b><p>Autenticação Portal, permissões por empresa e trilha de aprovação.</p></div>
<div class="step"><b>03 · Homologar a implantação</b><p>VPS, segurança, backups, SEO técnico e reversão testada.</p></div>
</div>
</section>
<footer>Imagine SEO Engine · F120 · Laboratório privado sem rota HTTP · Visitas → Leads → Clientes requer Analytics e CRM reais</footer>
</main>
</body>
</html>
