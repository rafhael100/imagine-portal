<?php
declare(strict_types=1);

/**
 * PORTAL-F60. Offline-only, static HTML preview of F84 SQL-backed data.
 * Zero HTTP routes, ads, authorization, network or billing.
 */
if(PHP_SAPI!=='cli'
    ||getenv('APP_ENV')!=='testing'
    ||getenv('PORTAL_F59_WORKBENCH_LAB')!=='1'
    ||getenv('PORTAL_F60_HTML_LAB')!=='1') {
    fwrite(STDERR,"F60 offline preview disabled\n");
    exit(2);
}
require_once dirname(__DIR__).'/app/Domain/PerformanceDashboard/PerformanceCampaignObservationState.php';
require_once dirname(__DIR__).'/app/Domain/PerformanceDashboard/PerformanceReviewObservationState.php';
require_once dirname(__DIR__).'/app/Domain/PerformanceDashboard/OfflinePerformanceWorkbenchPreview.php';

$path=$argv[1]??'';
if(!is_string($path)||$path===''||!is_file($path)
   ||is_link($path)||!is_readable($path)
   ||filesize($path)>50000) {
    fwrite(STDERR,"F60 invalid local fixture\n");exit(2);
}
try{
    $raw=file_get_contents($path);
    if(!is_string($raw))throw new RuntimeException('missing input');
    $obj=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
    $snapshot=\App\Domain\PerformanceDashboard\OfflinePerformanceWorkbenchPreview::fromDto($obj);
    if($snapshot===null || $snapshot->canExecuteActions()
       || $snapshot->isRealTime() || $snapshot->isAuthenticated()
       || $snapshot->isForSale()) {
        throw new RuntimeException('invalid synthetic snapshot');
    }
}catch(Throwable){
    fwrite(STDERR,"F60 refused untrusted or invalid JSON\n");exit(2);
}
$e=static fn(string $text):string=>htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$labels=[
    'google_ads'=>'Google Ads',
    'meta_ads'=>'Meta (Instagram e/ou Facebook)',
];
$countCampaign=$snapshot->counts['managed_campaigns'];
$countPaused=$snapshot->counts['paused_campaigns'];
$countPending=$snapshot->counts['approvals_pending'];
$countApproved=$snapshot->counts['approvals_approved_internal'];
$countRejected=$snapshot->counts['approvals_rejected'];

echo <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">
<title>Imagine Portal — Gestão de campanhas (DEMO)</title>
<style>
:root{color-scheme:light;font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#16263d;background:#f4f7fa}
*{box-sizing:border-box}body{margin:0}main{max-width:1080px;margin:auto;padding:28px 18px 48px}
header{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding-bottom:24px;border-bottom:1px solid #d3dce7}
.brand{font-size:20px;font-weight:800;letter-spacing:-.4px}.brand em{font-style:normal;color:#225cce}
.eyebrow{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#586b80;font-weight:700}
h1{font-size:clamp(28px,4vw,41px);letter-spacing:-.6px;margin:27px 0 10px}
p{line-height:1.6;color:#405268}
.notice{background:#fff1d7;border:1px solid #dca64b;border-radius:11px;padding:15px 17px;font-weight:750;line-height:1.45;margin:18px 0 24px}
.grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:24px 0}
.stat{background:white;border:1px solid #d8e2ee;border-radius:12px;padding:16px;min-width:0}
.stat b{display:block;font-size:28px;font-variant-numeric:tabular-nums}.stat span{font-size:12px;color:#4a5f77}
section{background:white;border-radius:14px;border:1px solid #d8e2ee;margin:22px 0;padding:20px}
h2{font-size:19px;margin:0 0 14px}.scroll{overflow-x:auto}
table{border-collapse:collapse;width:100%;font-size:14px;min-width:640px}th{text-align:left;background:#f3f7fc;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
th,td{padding:11px 12px;border-bottom:1px solid #e3e9f2}td{vertical-align:top}
td small{color:#63748a}tr:last-child td{border-bottom:0}
footer{color:#52657a;font-size:12px;margin-top:24px;line-height:1.7}
@media(max-width:800px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
</head>
<body>
<main>
<header><div class="brand">IMAGINE <em>Portal</em></div><div class="eyebrow">Performance AI • Prévia offline</div></header>
<h1>Gestão de campanhas</h1>
<p>Visualização de demonstração, baseada em dados fictícios de um banco de testes PostgreSQL. Nenhuma conta Google Ads ou Meta foi conectada.</p>
<div class="notice" role="note">DEMONSTRAÇÃO NÃO HOMOLOGADA — Nenhuma campanha publicada. Ações, aprovações operacionais e faturamento estão bloqueados.</div>
HTML;

echo '<div class="grid" aria-label="Resumo de teste">';
foreach ([
    ['Campanhas gerenciadas',$countCampaign],
    ['Campanhas pausadas',$countPaused],
    ['Revisões pendentes',$countPending],
    ['Revisões internas',$countApproved],
    ['Solicitações recusadas',$countRejected],
] as [$label,$number]) {
    echo '<div class="stat"><b>'.(int)$number.'</b><span>'.$e($label).'</span></div>';
}
echo '</div><section aria-labelledby="campaign-title"><h2 id="campaign-title">Campanhas fictícias</h2>'
   .'<div class="scroll"><table><thead><tr><th>Campanha</th><th>Canal</th><th>Estado interno</th></tr></thead><tbody>';
foreach($snapshot->campaigns as $item) {
    echo '<tr><td>'.$e($item['title']).'<small> · '.$e($item['ref']).'</small></td>'
       .'<td>'.$e($labels[$item['portal_channel']]).'</td>'
       .'<td>'.$e(\App\Domain\PerformanceDashboard\PerformanceCampaignObservationState::from($item['performance_state'])->label()).'</td></tr>';
}
echo '</tbody></table></div></section>'
   .'<section aria-labelledby="requests-title"><h2 id="requests-title">Solicitações de revisão fictícias</h2>'
   .'<div class="scroll"><table><thead><tr><th>Solicitação</th><th>Operação</th><th>Situação</th></tr></thead><tbody>';
foreach($snapshot->requests as $item) {
    echo '<tr><td>'.$e($item['ref']).'</td><td>'.$e($item['operation']).'</td>'
       .'<td>'.$e(\App\Domain\PerformanceDashboard\PerformanceReviewObservationState::from($item['performance_status'])->label()).'</td></tr>';
}
echo '</tbody></table></div></section>';
echo <<<'HTML'
<footer>Visualização exclusivamente estática gerada em CLI. Os status internos ainda não foram homologados como etapas do Portal. O conteúdo não é um contrato comercial, resultado real de mídia ou autorização para publicação.</footer>
</main></body></html>
HTML;
