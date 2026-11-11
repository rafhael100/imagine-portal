<?php
declare(strict_types=1);

// GSC-F139 — accessible static workbench for synthetic SEO F54 evidence.
// Not a route, controller, OAuth callback or customer dashboard.
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_GSC_COMPARISON_LAB')!=='1'
    || getenv('PORTAL_GSC_COMPARISON_HTML_LAB')!=='1'
    || count($argv)!==2) {
    fwrite(STDERR,"GSC-F139: offline demo disabled\n");
    exit(2);
}
require_once dirname(__DIR__).'/app/Domain/SeoReview/OfflineGscDailyComparisonPreview.php';
$path=$argv[1];
if (!is_file($path) || is_link($path) || !is_readable($path)
    || filesize($path)===false || filesize($path)>65536) {
    fwrite(STDERR,"GSC-F139: invalid local fixture\n");
    exit(2);
}
try {
    $bytes=file_get_contents($path);
    if(!is_string($bytes))throw new RuntimeException();
    $dto=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    $preview=\App\Domain\SeoReview\OfflineGscDailyComparisonPreview::fromDto($dto);
    if($preview===null || $preview->canPublish() || $preview->canQueryGoogle()
        || $preview->canStoreTokens() || $preview->isCustomerDisplayAuthorized())
        throw new RuntimeException();
}catch(Throwable){
    fwrite(STDERR,"GSC-F139: unverified DTO refused\n");
    exit(2);
}
$escape=static fn(string $s): string =>
    htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$numeric=static fn(int $n): string => number_format($n,0,',','.');
$percent=static fn(?float $n): string => $n===null ?
    'Sem base comparável' : sprintf('%+.1f%%',$n*100);
$signals=[
    'review_impression_drop'=>'Revisar redução de impressões',
    'review_click_drop'=>'Revisar redução de cliques',
    'review_ctr_drop'=>'Revisar redução da taxa de cliques',
    'review_average_position_decline'=>'Revisar piora da posição média',
];
$coverage=$preview->coverageComplete;
echo <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; form-action 'none'; connect-src 'none'; img-src 'none'; base-uri 'none'">
<title>Imagine Portal — Comparativo SEO (demonstração)</title>
<style>
:root{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#1b2940;background:#f5f7fb}
*{box-sizing:border-box}body{margin:0}main{max-width:980px;margin:auto;padding:30px 18px 64px}
header{display:flex;justify-content:space-between;align-items:center;gap:16px;border-bottom:1px solid #dce5ee;padding-bottom:15px}
.brand{font-weight:800;color:#2953a3;font-size:21px}.label{color:#5e7186;font-size:12px;text-transform:uppercase;letter-spacing:.07em}
h1{font-size:clamp(28px,4vw,42px);margin:29px 0 10px}h2{font-size:20px;margin:0 0 14px}
p{line-height:1.55}.muted{color:#57697e}.warning{border:2px solid #d6ae5e;background:#fff1d4;color:#603c08;border-radius:12px;padding:18px;margin:22px 0;font-weight:650}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:20px 0}
.panel{background:#fff;border:1px solid #dce5ee;border-radius:13px;padding:21px;margin:22px 0}
.metric{background:#fff;border:1px solid #dce5ee;border-radius:13px;padding:21px}
.metric strong{font-size:30px;display:block}.metric span{font-size:13px;color:#51657a}
.bar{height:12px;background:#e6ecf4;border-radius:99px;overflow:hidden;margin:9px 0 16px}
.bar span{display:block;height:100%;background:#3767ba;border-radius:99px}
.bar.current span{background:#51798d}
.sample{font-size:12px;color:#4e627b}.status{font-weight:700;color:#805c22}
ul{padding-left:20px}li{line-height:1.8}
footer{border-top:1px solid #dae2ec;color:#57697e;font-size:12px;padding-top:17px;line-height:1.6}
@media(max-width:650px){.grid{grid-template-columns:1fr}header{flex-wrap:wrap}}
</style>
</head>
<body><main>
<header><span class="brand">IMAGINE Portal</span><span class="label">SEO • Comparativo em laboratório</span></header>
<h1>Evolução da busca orgânica</h1>
<p class="muted">Acompanhamento demonstrativo de dois períodos com números fictícios. As métricas apresentadas não foram consultadas ao Google por este Portal.</p>
<div class="warning" role="note">DEMONSTRAÇÃO NÃO HOMOLOGADA — sem permissão de cliente, OAuth operacional ou liberação de publicação.</div>
HTML;
$before=$preview->previous;
$after=$preview->current;
$changes=$preview->change;
echo '<section class="panel"><h2>Períodos comparados</h2>'
    .'<p><strong>Anterior:</strong> '.$escape($preview->periods['previous']['startDate'])
    .' a '.$escape($preview->periods['previous']['endDate']).'</p>'
    .'<p><strong>Atual:</strong> '.$escape($preview->periods['current']['startDate'])
    .' a '.$escape($preview->periods['current']['endDate']).'</p>'
    .'<p class="status">'.($coverage?
        'Cobertura de dias fornecidos: completa para comparação ilustrativa':
        'Dados parciais: sinais de tendência indisponíveis').'</p></section>';
echo '<div class="grid" aria-label="Indicadores comparativos fictícios">';
foreach([
  ['Cliques','clicks','clicksFraction'],
  ['Impressões','impressions','impressionsFraction']
] as [$label,$key,$delta]){
    $older=$before[$key];$newer=$after[$key];
    $maximum=max(1,$older,$newer);
    $oldWidth=(int)round($older/$maximum*100);
    $newWidth=(int)round($newer/$maximum*100);
    echo '<div class="metric"><h2>'.$escape($label).' (DEMO)</h2>'
      .'<strong>'.$numeric($newer).'</strong>'
      .'<span>Comparação: '.$escape($percent($changes[$delta])).'</span>'
      .'<p class="sample">Anterior: '.$numeric($older).'</p>'
      .'<div class="bar" aria-hidden="true"><span style="width:'.$oldWidth.'%"></span></div>'
      .'<p class="sample">Atual: '.$numeric($newer).'</p>'
      .'<div class="bar current" aria-hidden="true"><span style="width:'.$newWidth.'%"></span></div>'
      .'</div>';
}
echo '</div><section class="panel"><h2>Revisões sugeridas</h2>';
if(!$coverage){
    echo '<p>Não há alertas porque as janelas fornecidas podem estar incompletas.</p>';
}elseif($preview->reviewSignals===[]){
    echo '<p>Nenhum sinal superou os limiares de revisão nas janelas de demonstração.</p>';
}else{
    echo '<ul>';
    foreach($preview->reviewSignals as $signal)
        echo '<li>'.$escape($signals[$signal]).'</li>';
    echo '</ul>';
}
echo '</section><footer>Exibição estática de laboratório, sem links, scripts, formulários ou dados de pesquisa brutos. '
    .'Cobertura de datas não comprova dados completos do Search Console; variações não demonstram causalidade. '
    .'Autenticação, autorização do tenant e da propriedade Google, privacidade e revisão independente permanecem obrigatórias.'
    .'</footer></main></body></html>';
