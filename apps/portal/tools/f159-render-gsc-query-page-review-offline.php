<?php
declare(strict_types=1);

// GSC-F159 — static, synthetic HTML, never a customer-facing route.
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_QUERY_PAGE_REVIEW_LAB')!=='1'||
    getenv('PORTAL_GSC_REVIEW_HTML_LAB')!=='1'||count($argv)!==2){
    fwrite(STDERR,"GSC-F159: private HTML laboratory disabled\n");
    exit(2);
}
require_once dirname(__DIR__).'/app/Domain/SeoReview/OfflineGscQueryPageReviewPreview.php';
$path=$argv[1];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F159: invalid synthetic input\n");
    exit(2);
}
try{
    $bytes=file_get_contents($path);
    if(!is_string($bytes))throw new RuntimeException();
    $dto=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
    $report=\App\Domain\SeoReview\OfflineGscQueryPageReviewPreview::fromDto($dto);
    if($report===null||$report->canQueryGoogle()||
        $report->canPublish()||$report->hasVerifiedTenantGrant())
        throw new RuntimeException();
}catch(Throwable){
    fwrite(STDERR,"GSC-F159: unverified SEO evidence refused\n");
    exit(2);
}
$number=static fn(int $value): string=>number_format($value,0,',','.');
$rows=$number($report->summary['receivedRows']);
// F74 suppresses review suggestions for redaction/partial coverage. The
// static demo must also suppress query/overlap indicators in those cases.
$suppliedBatchOnly=$report->coverageStatus==='SUPPLIED_BATCH_ONLY_UNVERIFIED';
$queries=$suppliedBatchOnly?$number($report->summary['distinctQueries']):'Indisponível';
$overlap=$suppliedBatchOnly?$number($report->summary['queriesOnMultiplePages']):'Indisponível';
$redacted=$number($report->summary['redactedRows']);
$status=match($report->coverageStatus){
    'REDACTED_ROWS_NO_SIGNALS'=>'Linhas privadas ocultadas: sugestões desativadas',
    'PARTIAL_BATCH_NO_SIGNALS'=>'Amostra possivelmente incompleta: sugestões desativadas',
    default=>'Amostra local fornecida: cobertura Google não comprovada'
};
$action=$report->suggestedReviews===['review_query_page_overlap']?
    'Possível sobreposição: revisão editorial humana sugerida':
    'Nenhuma sugestão de revisão nesta amostra';
echo <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; connect-src 'none'; img-src 'none'; form-action 'none'; base-uri 'none'">
<title>Imagine Portal — Revisão de sobreposição (DEMO)</title>
<style>
:root{font-family:system-ui,sans-serif;color:#192b42;background:#f5f7fb}
*{box-sizing:border-box}body{margin:0}main{max-width:880px;margin:0 auto;padding:30px 20px}
h1{font-size:clamp(24px,4vw,36px)}p{line-height:1.6;color:#455972}
.warning{padding:18px;border:2px solid #b78b35;border-radius:10px;background:#fff4d7;color:#60430a;font-weight:700}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-top:20px}
.card{background:white;border:1px solid #d5deeb;border-radius:12px;padding:18px}
.stat{display:block;font-size:32px;font-weight:800}
.small{font-size:13px;color:#586c82}.status{font-weight:700;margin:24px 0}
@media(max-width:600px){.grid{grid-template-columns:1fr}}
</style>
</head><body><main>
<header><strong>IMAGINE Portal</strong> <span class="small">SEO — laboratório offline</span></header>
<h1>Revisão de buscas compartilhadas entre páginas</h1>
<p>Dados sintéticos de Search Analytics, fornecidos apenas para teste de contrato.</p>
<div class="warning" role="note">DEMONSTRAÇÃO NÃO HOMOLOGADA — nenhuma autorização Google ou de clientes.</div>
HTML;
echo '<div class="grid" aria-label="Indicadores sintéticos">'
    .'<section class="card"><h2>Linhas recebidas</h2><strong class="stat">'.$rows.'</strong></section>'
    .'<section class="card"><h2>Consultas visíveis</h2><strong class="stat">'.$queries.'</strong></section>'
    .'<section class="card"><h2>Consultas em múltiplas páginas</h2><strong class="stat">'.$overlap.'</strong></section>'
    .'<section class="card"><h2>Linhas ocultadas</h2><strong class="stat">'.$redacted.'</strong></section>'
    .'</div>';
echo '<section class="card" aria-label="Revisão e cobertura">'
    .'<h2>Situação da amostra</h2>'
    .'<p class="status">'.$status.'</p><p>'.$action.'</p></section>';
echo '<footer><p class="small">Uma consulta presente em várias páginas não comprova canibalização. '
    .'Os dados não são autenticados, a cobertura é desconhecida e nenhuma alteração, '
    .'publicação, redirecionamento ou acesso a clientes é autorizado.</p>'
    .'</footer></main></body></html>';
