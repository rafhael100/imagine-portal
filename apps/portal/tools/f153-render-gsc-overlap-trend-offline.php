<?php
declare(strict_types=1);

// GSC-F153 — zero-network HTML visualization of synthetic aggregate trends.
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_TRENDS_LAB')!=='1'||
    getenv('PORTAL_GSC_TREND_HTML_LAB')!=='1'||
    count($argv)!==2){
    fwrite(STDERR,"GSC-F153: static demo disabled\n");
    exit(2);
}
require_once dirname(__DIR__).'/app/Domain/SeoReview/OfflineGscOverlapTrendPreview.php';
$path=$argv[1];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F153: invalid local synthetic file\n");
    exit(2);
}
try{
    $bytes=file_get_contents($path);
    if(!is_string($bytes))throw new RuntimeException();
    $dto=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    $data=\App\Domain\SeoReview\OfflineGscOverlapTrendPreview::fromDto($dto);
    if($data===null||$data->canPublish()||$data->canQueryGoogle()||
        $data->hasVerifiedTenantGrant())
        throw new RuntimeException();
}catch(Throwable){
    fwrite(STDERR,"GSC-F153: unverified trend data refused\n");
    exit(2);
}
$percent=static fn(?float $share): string=>$share===null?'Sem dados suficientes':
    number_format($share*100,1,',','.').'%';
$points=static fn(?float $delta): string=>$delta===null?'Indisponível':
    ($delta>0?'+':'').number_format($delta*100,1,',','.').' p.p.';
$prior=$percent($data->previous['overlapShare']);
$now=$percent($data->current['overlapShare']);
$delta=$points($data->shareChange);
$complete=$data->coverageComplete;
$status=$complete?'Comparação de amostras disponíveis':'Amostra incompleta: tendências suprimidas';
$review=$data->reviewSignals===['review_overlap_share_increase']?
    'Investigar possível aumento da sobreposição editorial':
    'Sem sinal forte de sobreposição nas amostras fornecidas';
echo <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; connect-src 'none'; img-src 'none'; form-action 'none'; base-uri 'none'">
<title>Imagine Portal — Evolução de sobreposição orgânica (DEMO)</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f4f7fb;color:#192b42}
*{box-sizing:border-box}body{margin:0}main{max-width:960px;margin:auto;padding:30px 18px 60px}
header{display:flex;justify-content:space-between;border-bottom:1px solid #d7e0eb;padding-bottom:18px}
.brand{color:#284f99;font-weight:800;font-size:20px}.eyebrow{font-size:12px;text-transform:uppercase;color:#566d85}
h1{font-size:clamp(28px,4vw,39px);margin:28px 0 12px}h2{font-size:19px}
p{line-height:1.6;color:#536881}.warning{background:#fff2d3;border:2px solid #cba55b;color:#66450e;border-radius:12px;padding:18px;font-weight:700;margin:24px 0}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:22px 0}
.panel{background:white;border:1px solid #d7e0ec;border-radius:13px;padding:22px}
.stat{font-size:32px;display:block;font-weight:800;color:#1e3550}.muted{font-size:13px;color:#60748b}
.status{font-weight:700;color:#705111}footer{border-top:1px solid #d7e0ec;padding-top:20px;font-size:13px;line-height:1.6;color:#586b83}
@media(max-width:620px){.grid{grid-template-columns:1fr}header{flex-wrap:wrap;gap:8px}}
</style>
</head><body><main>
<header><span class="brand">IMAGINE Portal</span><span class="eyebrow">SEO • Visão histórica em laboratório</span></header>
<h1>Sobreposição de buscas entre páginas</h1>
<p>Comparativo ilustrativo de consultas associadas a mais de uma página. Dados totalmente fictícios.</p>
<div role="note" class="warning">DEMONSTRAÇÃO NÃO HOMOLOGADA — sem autorização de acesso a clientes ou Google.</div>
HTML;
echo '<div class="grid" aria-label="Comparativo sintético">'
    .'<section class="panel"><h2>Período anterior (DEMO)</h2>'
    .'<strong class="stat">'.$prior.'</strong><p class="muted">Consultas sobrepostas na amostra.</p></section>'
    .'<section class="panel"><h2>Período atual (DEMO)</h2>'
    .'<strong class="stat">'.$now.'</strong><p class="muted">Consultas sobrepostas na amostra.</p></section>'
    .'</div>';
echo '<section class="panel" aria-label="Avaliação de tendência">'
    .'<h2>Variação da sobreposição</h2>'
    .'<strong class="stat">'.$delta.'</strong>'
    .'<p class="status">'.$status.'</p>'
    .'<p>'.$review.'</p></section>';
echo '<footer>Ter buscas presentes em várias páginas não comprova canibalização SEO. '
    .'A cobertura se limita às linhas fornecidas, não ao tráfego total do Google. '
    .'Esta tela é uma demonstração estática de laboratório: sem links, scripts, formulário, '
    .'tokens, URLs ou dados reais de clientes.</footer></main></body></html>';
