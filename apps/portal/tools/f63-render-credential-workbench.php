<?php
declare(strict_types=1);

/**
 * Portal F63: standalone static, offline HTML renderer of a PostgreSQL-backed
 * Performance F100 synthetic DTO validated by real Portal F62.
 * Never a web route, customer page, publishing control or Ads connection.
 */
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'
    ||getenv('PORTAL_F62_CREDENTIAL_WORKBENCH_LAB')!=='1'
    ||getenv('PORTAL_F63_STATIC_HTML_LAB')!=='1'){
    fwrite(STDERR,"F63 static preview disabled\n");
    exit(2);
}
$path=$argv[1]??'';
if(!is_string($path) || $path==='' || !is_file($path)
    || is_link($path) || !is_readable($path) || filesize($path)>48000) {
    fwrite(STDERR,"F63 invalid local snapshot file\n");
    exit(2);
}
$base=dirname(__DIR__).'/app/Domain/';
require_once $base.'Catalog/PerformanceChannel.php';
require_once $base.'PerformanceDashboard/PerformanceCampaignObservationState.php';
require_once $base.'PerformanceDashboard/PerformanceReviewObservationState.php';
require_once $base.'PerformanceDashboard/CredentialBoundPerformanceWorkbenchPreview.php';
try {
    $raw=file_get_contents($path);
    if(!is_string($raw)) throw new RuntimeException('snapshot unavailable');
    $payload=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
    $snapshot=\App\Domain\PerformanceDashboard\CredentialBoundPerformanceWorkbenchPreview::fromDto($payload);
    if($snapshot===null || $snapshot->hasPortalIdentity()
        ||$snapshot->canPublishAds() ||$snapshot->canBill() ||$snapshot->isRealTime()) {
        throw new RuntimeException('not a trusted synthetic snapshot');
    }
} catch(Throwable) {
    fwrite(STDERR,"F63 denied invalid synthetic snapshot\n");
    exit(2);
}
$e=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');
$channelLabels=['google_ads'=>'Google Ads','meta_ads'=>'Meta (Facebook e Instagram)'];
$operationLabels=[
    'campaign.create'=>'Criar campanha (DEMO)',
    'campaign.pause'=>'Pausar campanha (DEMO)',
    'campaign.replace'=>'Alterar campanha (DEMO)',
    'campaign.release'=>'Liberar campanha (DEMO — sem execução)'
];
echo <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; script-src 'none'; img-src 'none'; connect-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'">
<title>Imagine Portal — Performance AI (prévia DEMO)</title>
<style>
:root{color-scheme:light;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f3f6fa;color:#172b4d}
*{box-sizing:border-box}body{margin:0}main{max-width:1080px;margin:auto;padding:28px 18px 52px}
header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;padding-bottom:20px;border-bottom:1px solid #d5deea}
.brand{font-weight:800;font-size:21px;letter-spacing:-.035em}.brand span{color:#275fc5}
.kicker{text-transform:uppercase;letter-spacing:.09em;font-size:11px;font-weight:700;color:#526881}
h1{font-size:clamp(28px,5vw,41px);letter-spacing:-.035em;margin:28px 0 10px}
p{line-height:1.6;color:#445775;margin:10px 0 20px}
.notice{background:#fff4dc;border:1px solid #bd8b35;color:#674200;border-radius:12px;padding:17px;font-weight:750;line-height:1.55}
.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:24px 0}
.card,section{background:white;border:1px solid #dce5f0;border-radius:14px;padding:17px;min-width:0}
.card b{display:block;font-size:29px;font-variant-numeric:tabular-nums}
.card span{font-size:13px;color:#51647e}
section{margin:20px 0;padding:22px}
h2{font-size:19px;margin:0 0 15px}
.scroller{overflow-x:auto}
table{border-collapse:collapse;width:100%;min-width:650px;font-size:13px}
th{text-align:left;background:#f0f4fa;color:#344a68;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
th,td{padding:12px;border-bottom:1px solid #e7edf5;vertical-align:top}
tr:last-child td{border-bottom:0}
small{color:#5d708c}
footer{font-size:12px;color:#526984;line-height:1.65;margin-top:26px}
@media(max-width:740px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
</head>
<body><main>
<header><div class="brand">IMAGINE <span>Portal</span></div><div class="kicker">Performance AI | Dados fictícios offline</div></header>
<h1>Painel de campanhas</h1>
<p>Prévia estática baseada em dados sintéticos de um banco PostgreSQL de testes, com vínculo de leitura a uma identidade SQL de laboratório. Nenhuma conta publicitária real está conectada.</p>
<div class="notice" role="note">DEMONSTRAÇÃO NÃO HOMOLOGADA — Sem login de cliente, aprovação humana verificada, publicação de anúncios ou faturamento. Nenhuma ação está disponível.</div>
HTML;
echo '<div class="cards" aria-label="Indicadores fictícios">';
foreach([
    ['Contas candidatas DEMO',$snapshot->counts['account_candidates']],
    ['Campanhas vinculadas DEMO',$snapshot->counts['linked_campaigns']],
    ['Revisões observadas',$snapshot->counts['visible_reviews']],
    ['Revisões pendentes',$snapshot->counts['review_pending']],
    ['Aprovações internas sem execução',$snapshot->counts['review_internal_no_execution']],
    ['Revisões recusadas',$snapshot->counts['review_rejected']],
] as [$name,$value]) {
    echo '<div class="card"><b>'.(int)$value.'</b><span>'.$e($name).'</span></div>';
}
echo '</div>';
echo '<section aria-label="Contas publicitárias fictícias"><h2>Contas candidatas fictícias</h2><div class="scroller"><table><thead>'
    .'<tr><th>Referência</th><th>Canal</th><th>Situação</th></tr></thead><tbody>';
foreach($snapshot->accounts as $a) {
    echo '<tr><td>'.$e($a['account_ref']).'</td><td>'.$e($channelLabels[$a['portal_channel']]).'</td>'
        .'<td>Não verificada (DEMO)</td></tr>';
}
echo '</tbody></table></div></section>';
echo '<section aria-label="Campanhas fictícias"><h2>Campanhas vinculadas fictícias</h2><div class="scroller"><table><thead>'
    .'<tr><th>Campanha</th><th>Canal</th><th>Conta fictícia</th><th>Observação</th></tr></thead><tbody>';
foreach($snapshot->campaigns as $c) {
    $state=\App\Domain\PerformanceDashboard\PerformanceCampaignObservationState::from(
        $c['campaign_observation_state']
    );
    echo '<tr><td>'.$e($c['campaign_title']).'<small> · '.$e($c['campaign_ref']).'</small></td>'
        .'<td>'.$e($channelLabels[$c['portal_channel']]).'</td>'
        .'<td>'.$e($c['account_ref']).'</td>'
        .'<td>'.$e($state->label()).'</td></tr>';
}
echo '</tbody></table></div></section>';
echo '<section aria-label="Histórico de revisões fictícias"><h2>Histórico de revisões DEMO</h2><div class="scroller"><table><thead>'
    .'<tr><th>Solicitação</th><th>Operação</th><th>Campanha</th><th>Revisão interna</th></tr></thead><tbody>';
foreach($snapshot->reviews as $r) {
    $state=\App\Domain\PerformanceDashboard\PerformanceReviewObservationState::from(
        $r['review_observation_state']
    );
    echo '<tr><td>'.$e($r['request_ref']).'</td>'
        .'<td>'.$e($operationLabels[$r['operation']]).'</td>'
        .'<td>'.$e($r['campaign_ref']).'</td>'
        .'<td>'.$e($state->label()).'</td></tr>';
}
echo '</tbody></table></div></section>';
echo <<<'HTML'
<footer>Prévia offline somente para validação de contrato de software. O vínculo de login PostgreSQL não prova autenticação ou autorização de usuário no Portal. Revisões DEMO não autorizam anúncios. Integrações Google Ads/Meta, cobranças e operações reais não estão habilitadas.</footer>
</main></body></html>
HTML;
