<?php
declare(strict_types=1);

// GSC-F142 — accessible, offline-only visualization for aggregate query/page
// overlap. No route, JavaScript, links, forms or raw search strings.
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_OVERLAP_LAB')!=='1'||
    getenv('PORTAL_GSC_OVERLAP_HTML_LAB')!=='1'||count($argv)!==2){
    fwrite(STDERR,"GSC-F142: test-only rendering required\n");
    exit(2);
}
require_once dirname(__DIR__).'/app/Domain/SeoReview/OfflineGscQueryPageOverlapPreview.php';
$path=$argv[1];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F142: invalid fixture\n");
    exit(2);
}
try {
    $content=file_get_contents($path);
    if(!is_string($content))throw new RuntimeException();
    $dto=json_decode($content,true,64,JSON_THROW_ON_ERROR);
    $preview=\App\Domain\SeoReview\OfflineGscQueryPageOverlapPreview::fromDto($dto);
    if($preview===null||$preview->canPublish()||$preview->canReadGoogle()||
        $preview->hasVerifiedTenantGrant()||$preview->canStoreTokens())
        throw new RuntimeException();
}catch(Throwable){
    fwrite(STDERR,"GSC-F142: unverified evidence refused\n");
    exit(2);
}
$fmt=static fn(int $value): string=>number_format($value,0,',','.');
$summary=$preview->summary;
$coverage=$summary['possibleTruncation'];
$labels=[
    'PARTIAL_COVERAGE_REVIEW_ONLY'=>'Cobertura parcial: revisar antes de interpretar',
    'POSSIBLE_QUERY_PAGE_OVERLAP_REVIEW'=>'Possível sobreposição: revisão editorial recomendada',
    'NO_OVERLAP_IN_SUPPLIED_ROWS'=>'Nenhuma sobreposição detectada nas linhas fornecidas',
];
echo <<<'HTML'
<!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; connect-src 'none'; form-action 'none'; base-uri 'none'">
<title>Imagine Portal — Sobreposição de buscas (DEMO)</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f5f7fb;color:#1c2e44}
*{box-sizing:border-box}body{margin:0}main{max-width:980px;padding:32px 20px 64px;margin:auto}
header{display:flex;justify-content:space-between;gap:16px;align-items:center;border-bottom:1px solid #dbe3ee;padding-bottom:17px}
.brand{color:#28549d;font-size:21px;font-weight:800}.eyebrow{text-transform:uppercase;letter-spacing:.06em;font-size:12px;color:#566d82}
h1{font-size:clamp(27px,4vw,40px);margin:28px 0 7px}h2{font-size:19px}
p{line-height:1.6;color:#475e75}
.notice{background:#fff2d5;border:2px solid #e1b76b;border-radius:13px;color:#68410c;padding:20px;margin:22px 0;font-weight:650}
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin:24px 0}
.card{background:white;border:1px solid #dae3ee;border-radius:13px;padding:20px}
.card strong{display:block;font-size:30px;color:#1b2e4b}.card span{font-size:13px;color:#576d81}
section{background:white;border:1px solid #dae3ee;border-radius:14px;padding:21px;margin:20px 0}
.status{font-weight:700;color:#784e12}.muted{font-size:13px;color:#586c80}
footer{font-size:12px;line-height:1.7;color:#5e7082;border-top:1px solid #dce4ee;padding-top:15px;margin-top:27px}
@media(max-width:700px){.grid{grid-template-columns:1fr}header{flex-wrap:wrap}}
</style></head><body><main>
<header><span class="brand">IMAGINE Portal</span><span class="eyebrow">SEO • Laboratório</span></header>
<h1>Consultas e páginas</h1>
<p>Exemplo de revisão de buscas associadas a diferentes páginas, utilizando apenas contadores fictícios.</p>
<div class="notice" role="note">DEMONSTRAÇÃO NÃO HOMOLOGADA — sem autorização de clientes, acesso direto ao Google ou publicação.</div>
HTML;
echo '<div class="grid" aria-label="Indicadores sintéticos">'
    .'<div class="card"><strong>'.$fmt($summary['distinctQueries']).'</strong><span>Consultas distintas fornecidas (DEMO)</span></div>'
    .'<div class="card"><strong>'.$fmt($summary['queriesOnMultiplePages']).'</strong><span>Consultas associadas a mais de uma página (DEMO)</span></div>'
    .'<div class="card"><strong>'.$fmt($summary['redactedRows']).'</strong><span>Linhas omitidas por privacidade (DEMO)</span></div>'
    .'</div>';
echo '<section aria-labelledby="review-title"><h2 id="review-title">Situação de revisão</h2>'
    .'<p class="status">'.$labels[$preview->reviewStatus].'</p>'
    .'<p>Total de linhas recebidas: <strong>'.$fmt($summary['receivedRows']).'</strong>.</p>'
    .'<p>Impressões associadas a consultas com páginas múltiplas: <strong>'
    .$fmt($summary['impressionsOnOverlappingQueries']).'</strong> (DEMO).</p>'
    .'<p class="muted">'.($coverage?
        'O limite da exportação pode ter sido atingido; não extrapolar este resultado.':
        'Não há confirmação de cobertura integral do Search Console, apenas das linhas fornecidas.')
    .'</p></section>';
echo '<footer>Uma mesma busca aparecer em várias páginas não comprova canibalização ou falha de SEO. '
    .'Revise intenção, relevância e canônicos antes de tomar decisões. '
    .'Esta demonstração não contém termos, URLs ou dados reais e não realiza nenhuma alteração no site.</footer>'
    .'</main></body></html>';
