<?php

declare(strict_types=1);

// PORTAL-F79: strictly offline DEMO renderer. No HTTP routes, Google Search
// Console, customer consent, external APIs, cookies or server authentication.
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_SEO_HANDOFF_LAB') !== '1'
    || getenv('PORTAL_F79_HTML_LAB') !== '1') {
    fwrite(STDERR, "F79 offline SEO preview is disabled.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/app/Domain/SeoReview/OfflineSeoHandoffPreview.php';

$input = $argv[1] ?? null;
if (!is_string($input) || $input === '' || !is_file($input)
    || is_link($input) || !is_readable($input)
    || filesize($input) === false || filesize($input) > 65536) {
    fwrite(STDERR, "F79 refuses missing, symlink or oversized JSON fixture.\n");
    exit(2);
}

try {
    $bytes = file_get_contents($input);
    if (!is_string($bytes)) {
        throw new RuntimeException('Cannot read local fixture.');
    }
    $dto = json_decode($bytes, true, 24, JSON_THROW_ON_ERROR);
    $preview = \App\Domain\SeoReview\OfflineSeoHandoffPreview::fromDto($dto);
    if ($preview === null
        || $preview->isCustomerDisplayAuthorized()
        || $preview->hasVerifiedTenantGrant()
        || $preview->hasVerifiedGoogleProperty()
        || $preview->canPublish()
        || $preview->canDispatch()) {
        throw new RuntimeException('Unverified or operational DTO refused.');
    }
} catch (Throwable) {
    fwrite(STDERR, "F79 refused untrusted SEO data.\n");
    exit(2);
}

$escape = static fn(string $text): string => htmlspecialchars(
    $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8',
);
$percent = static fn(?float $fraction): string => $fraction === null
    ? 'Sem base anterior'
    : sprintf('%+.1f%%', $fraction * 100);
$labels = [
    'review_indexation' => 'Revisar indexação',
    'repair_canonical' => 'Verificar URLs canônicas',
    'fix_sitemap' => 'Corrigir sitemap',
    'review_robots' => 'Revisar robots.txt',
    'repair_internal_links' => 'Corrigir links internos',
    'improve_internal_navigation' => 'Melhorar navegação',
    'review_page_metadata' => 'Revisar metadados',
    'review_page_structure' => 'Revisar estrutura das páginas',
    'review_structured_data' => 'Revisar dados estruturados',
    'review_other_technical_findings' => 'Revisar achados técnicos',
    'review_impression_drop' => 'Revisar queda de impressões',
    'review_click_drop' => 'Revisar queda de cliques',
    'review_ctr_drop' => 'Revisar queda de CTR',
    'review_average_position' => 'Revisar posição média',
    'review_new_search_theme' => 'Revisar novo tema de pesquisa',
];
$priority = [
    'critical' => 'Crítica',
    'high' => 'Alta',
    'medium' => 'Média',
    'low' => 'Baixa',
];

echo <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; connect-src 'none'; img-src 'none'">
<meta name="robots" content="noindex,nofollow">
<title>Imagine Portal — Prévia de SEO (DEMO)</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#192840;background:#f4f7fc}
*{box-sizing:border-box}body{margin:0}main{max-width:1000px;margin:auto;padding:32px 18px 60px}
header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #dce4ee;gap:12px;flex-wrap:wrap}
.brand{font-weight:800;font-size:22px;color:#214eaa}.eyebrow{font-size:12px;text-transform:uppercase;color:#64768e}
h1{font-size:clamp(28px,4vw,42px);margin:30px 0 8px}h2{font-size:19px;margin:0 0 14px}
.lede{color:#465971;line-height:1.6}.warning{background:#fff0d4;color:#643b05;border:1px solid #dfac54;padding:18px;border-radius:12px;margin:22px 0;font-weight:650}
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:24px 0}
.metric{border:1px solid #d9e3ed;border-radius:14px;background:white;padding:19px}.metric strong{font-size:29px;display:block}.metric span{font-size:12px;color:#5b6f83}
section{background:#fff;border:1px solid #dce5ef;border-radius:14px;padding:22px;margin:21px 0}
.summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.summary div{padding:13px;border:1px solid #e4e9ef;border-radius:8px}
.summary strong{display:block;font-size:19px}.summary span{color:#63748a;font-size:12px}
table{width:100%;border-collapse:collapse}th,td{padding:12px;text-align:left;border-bottom:1px solid #ecf0f4;font-size:14px}th{color:#617086;font-size:12px;text-transform:uppercase}
footer{color:#5c6d80;font-size:12px;line-height:1.7;margin-top:22px}
@media(max-width:700px){.grid{grid-template-columns:1fr 1fr}.summary{grid-template-columns:1fr}}
</style>
</head>
<body>
<main>
<header><div class="brand">IMAGINE Portal</div><div class="eyebrow">Módulo SEO • Prévia local</div></header>
<h1>Visão geral de SEO</h1>
<p class="lede">Modelo ilustrativo de acompanhamento técnico e evolução da busca orgânica, exclusivamente com números fictícios para validar o formato do Portal.</p>
<div class="warning" role="note">DEMONSTRAÇÃO NÃO HOMOLOGADA — Sem acesso a propriedade Google Search Console, sem consentimento de cliente, sem publicação e sem autorização de exibição externa.</div>
HTML;

$technical = $preview->technical;
echo '<div class="grid" aria-label="Indicadores fictícios">'
    . '<div class="metric"><strong>' . (int)$technical['pages'] . '</strong><span>Páginas analisadas (DEMO)</span></div>'
    . '<div class="metric"><strong>' . (int)$technical['errors'] . '</strong><span>Erros técnicos (DEMO)</span></div>'
    . '<div class="metric"><strong>' . (int)$technical['warnings'] . '</strong><span>Avisos técnicos (DEMO)</span></div>'
    . '</div>';

echo '<section aria-labelledby="organic-title"><h2 id="organic-title">Busca orgânica (dados de exemplo)</h2>';
$organic = $preview->organic;
if ($organic === null) {
    echo '<p class="lede">Nenhuma métrica orgânica foi incluída no conjunto ilustrativo.</p>';
} else {
    echo '<p class="lede">Janela ilustrativa de ' . (int)$organic['days'] . ' dias, sem consulta ao Google.</p>';
    echo '<div class="summary">'
        . '<div><span>Cliques fictícios</span><strong>' . (int)$organic['current']['clicks']
        . '</strong><span>Variação: ' . $escape($percent($organic['clicksChangeFraction'])) . '</span></div>'
        . '<div><span>Impressões fictícias</span><strong>' . (int)$organic['current']['impressions']
        . '</strong><span>Variação: ' . $escape($percent($organic['impressionsChangeFraction'])) . '</span></div>'
        . '</div>';
}
echo '</section><section aria-labelledby="review-title"><h2 id="review-title">Revisões sugeridas para análise humana</h2>'
    . '<table><thead><tr><th>Ação ilustrativa</th><th>Prioridade</th><th>Ocorrências</th></tr></thead><tbody>';
foreach ($preview->suggestedReviews as $item) {
    echo '<tr><td>' . $escape($labels[$item['code']] ?? $item['code']) . '</td>'
        . '<td>' . $escape($priority[$item['priority']]) . '</td>'
        . '<td>' . (int)$item['count'] . '</td></tr>';
}
echo '</tbody></table></section>';
echo '<footer>Prévia HTML offline, sem JavaScript, formulários ou links. '
    . 'A ausência de verificação do tenant e da propriedade Google impede uso com dados de clientes. '
    . 'Sugestões são somente revisão, nunca execução automática.</footer>'
    . '</main></body></html>';
