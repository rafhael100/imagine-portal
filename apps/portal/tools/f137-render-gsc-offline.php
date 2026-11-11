<?php
declare(strict_types=1);

// GSC-F137: only an offline static HTML DEMO. Not a controller or a route.
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_GSC_WORKFLOW_LAB')!=='1'
    || getenv('PORTAL_GSC_HTML_LAB')!=='1'
    || count($argv)!==2) {
    fwrite(STDERR,"GSC-F137: private demo only\n");
    exit(2);
}

require_once dirname(__DIR__).'/app/Domain/SeoReview/OfflineGscWorkflowPreview.php';
$path=$argv[1];
if (!is_file($path) || is_link($path) || !is_readable($path)
    || filesize($path)===false || filesize($path)>32768) {
    fwrite(STDERR,"GSC-F137: invalid fixture\n");
    exit(2);
}
try {
    $bytes=file_get_contents($path);
    if (!is_string($bytes)) throw new RuntimeException();
    $dto=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    $preview=\App\Domain\SeoReview\OfflineGscWorkflowPreview::fromDto($dto);
    if ($preview===null || $preview->isCustomerDisplayAuthorized()
        || $preview->canQueryGoogle() || $preview->canStoreTokens()
        || $preview->canPublish()) throw new RuntimeException();
} catch (Throwable) {
    fwrite(STDERR,"GSC-F137: DTO refused\n");
    exit(2);
}
$summary=$preview->summary;
$pagination=$preview->pagination;
$fmt=static fn(int $value): string=>number_format($value,0,',','.');
echo <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; connect-src 'none'; img-src 'none'">
<meta name="robots" content="noindex,nofollow">
<title>Imagine Portal — Busca orgânica (laboratório)</title>
<style>
:root{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#14263d;background:#f5f7fb}
*{box-sizing:border-box}body{margin:0}main{max-width:990px;margin:0 auto;padding:32px 18px 60px}
header{display:flex;gap:12px;justify-content:space-between;align-items:center;border-bottom:1px solid #d9e2ed;padding-bottom:16px}
.brand{font-weight:800;color:#2755a1;font-size:22px}.label{font-size:12px;letter-spacing:.07em;text-transform:uppercase;color:#596f87}
h1{font-size:clamp(28px,4vw,42px);margin:30px 0 10px}h2{font-size:20px}
p{line-height:1.6}.muted{color:#526882}
.warning{background:#fff3d8;border:2px solid #dfb85c;color:#6d4306;border-radius:12px;padding:18px;margin:24px 0;font-weight:650}
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.metric{background:#fff;border:1px solid #d9e2ed;border-radius:12px;padding:20px}
.metric strong{font-size:30px;display:block}.metric span{font-size:13px;color:#50677c}
section{background:white;border:1px solid #d9e2ed;border-radius:14px;padding:23px;margin:20px 0}
footer{font-size:13px;color:#526882;line-height:1.7;margin-top:26px}
@media(max-width:650px){.grid{grid-template-columns:1fr}header{flex-wrap:wrap}}
</style>
</head>
<body><main>
<header><span class="brand">IMAGINE Portal</span><span class="label">SEO • Prévia GSC offline</span></header>
<h1>Busca orgânica</h1>
<p class="muted">Prévia de interface para dados sintéticos do motor Imagine SEO, sem conexão com a conta Google.</p>
<div class="warning" role="note">DEMONSTRAÇÃO NÃO HOMOLOGADA — dados fictícios. Sem conexão Google, sem autorização de clientes, sem publicação e sem uso em produção.</div>
HTML;
if ($summary===null) {
    echo '<section><h2>Propriedade não elegível</h2><p>O inventário declarado não permite elaborar esta revisão offline.</p></section>';
} else {
    echo '<div class="grid" aria-label="Resumo fictício do GSC">'
        .'<div class="metric"><strong>'.$fmt($summary['clicks']).'</strong><span>Cliques analisados (DEMO)</span></div>'
        .'<div class="metric"><strong>'.$fmt($summary['impressions']).'</strong><span>Impressões analisadas (DEMO)</span></div>'
        .'<div class="metric"><strong>'.$fmt($summary['analyzedRows']).'</strong><span>Consultas analisadas (DEMO)</span></div>'
        .'</div>';
    echo '<section><h2>Qualidade e privacidade do relatório</h2>'
        .'<p>Total de linhas fornecidas: <strong>'.$fmt($summary['inputRows']).'</strong>. '
        .'Linhas excluídas por segurança: <strong>'.$fmt($summary['redactedRows']).'</strong>.</p>'
        .'<p>Páginas de resposta revisadas: <strong>'.$fmt($pagination['pagesReceived']).'</strong>. '
        .'Possível continuidade de paginação: <strong>'.($pagination['mayHaveMore']?'Sim':'Não').'</strong>.</p>'
        .'<p class="muted">Esses totais abrangem somente linhas fornecidas; não medem o tráfego total da propriedade.</p></section>';
}
echo '<footer>Visualização exclusivamente estática, sem scripts, formulários ou links. '
    .'As métricas não foram confirmadas pelo Google Search Console. '
    .'Autenticação, MFA, consentimento, grants por empresa e revisão independente são pré-requisitos obrigatórios.</footer>'
    .'</main></body></html>';
