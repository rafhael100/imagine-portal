<?php
declare(strict_types=1);

/**
 * Portal F65: local HTML-only BRL budget allocation preview from F107/F64.
 * NO JavaScript, links, forms, network, media execution or payment controls.
 */
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F64_BUDGET_PREVIEW_LAB') !== '1'
    || getenv('PORTAL_F65_BUDGET_HTML_LAB') !== '1') {
    fwrite(STDERR, "F65 offline budget renderer disabled\n");
    exit(2);
}
$input = $argv[1] ?? '';
if (!is_string($input) || $input === ''
    || !is_file($input) || is_link($input)
    || !is_readable($input) || filesize($input) > 128000) {
    fwrite(STDERR, "F65 invalid local JSON snapshot\n");
    exit(2);
}
require_once dirname(__DIR__)
    .'/app/Domain/PerformanceDashboard/CredentialBoundBudgetPreview.php';

try {
    $raw = file_get_contents($input);
    if (!is_string($raw)) {
        throw new RuntimeException('JSON unavailable');
    }
    $dto = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    $preview = \App\Domain\PerformanceDashboard\CredentialBoundBudgetPreview::fromDto($dto);
    if ($preview === null || $preview->canExecuteMedia()
        || $preview->canBill() || $preview->hasHumanApproval()
        || $preview->isAuthenticatedPortalSession()) {
        throw new RuntimeException('Not a safe offline budget preview');
    }
} catch (Throwable) {
    fwrite(STDERR, "F65 denied an invalid or privileged budget snapshot\n");
    exit(2);
}

$e = static fn(string $value):string =>
    htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5, 'UTF-8');
$group = static function(int $whole):string {
    $text = (string)$whole;
    return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $text) ?? $text;
};
$brl = static function(int $centavos) use($group):string {
    return 'R$ '.$group(intdiv($centavos, 100))
        .','.str_pad((string)($centavos % 100), 2, '0', STR_PAD_LEFT);
};
$percentage = static function(int $basisPoints):string {
    return (string)intdiv($basisPoints, 100).','
        .str_pad((string)($basisPoints % 100), 2, '0', STR_PAD_LEFT).'%';
};
echo <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; script-src 'none'; connect-src 'none'; img-src 'none'; font-src 'none'; object-src 'none'; form-action 'none'; base-uri 'none'">
<title>Imagine Portal — Cenários de orçamento (DEMO)</title>
<style>
:root{color-scheme:light;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f4f7fb;color:#1a304f}
*{box-sizing:border-box}body{margin:0}main{max-width:1100px;margin:auto;padding:26px 18px 56px}
header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:0 0 22px;border-bottom:1px solid #d6dfeb}
.brand{font-size:21px;font-weight:800;letter-spacing:-.04em}.brand span{color:#225ebd}
.eyebrow{font-size:11px;font-weight:750;text-transform:uppercase;letter-spacing:.09em;color:#57708c}
h1{font-size:clamp(28px,5vw,40px);margin:28px 0 10px;letter-spacing:-.04em}
p{line-height:1.6;color:#405674}
.warning{background:#fff2d9;border:1px solid #ce9f50;color:#694300;border-radius:13px;padding:17px;line-height:1.6;font-weight:750;margin:18px 0 24px}
section{border:1px solid #d9e3f0;background:#fff;border-radius:16px;padding:21px;margin:20px 0}
h2{font-size:20px;margin:0 0 6px}h3{font-size:16px;margin:20px 0 12px}
.meta{font-size:13px;color:#5b708b;margin-top:5px}
.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:21px 0}
.kpi{border:1px solid #e1e9f2;border-radius:12px;padding:15px;min-width:0;background:#fafcff}
.kpi b{display:block;font-size:clamp(17px,2.6vw,27px);font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.kpi span{font-size:12px;color:#4d6684}
.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px;min-width:660px}
th{text-align:left;color:#405773;background:#f1f5fa;font-size:11px;letter-spacing:.04em;text-transform:uppercase}
th,td{padding:11px 13px;border-bottom:1px solid #e5edf5}
tr:last-child td{border-bottom:0}td.money{font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap}
footer{color:#58718c;font-size:12px;line-height:1.7;margin-top:28px}
@media(max-width:850px){.kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
</head>
<body><main>
<header><div class="brand">IMAGINE <span>Portal</span></div><div class="eyebrow">Performance AI • orçamento offline</div></header>
<h1>Planejamento de orçamento</h1>
<p>Distribuição matemática de orçamento fictício por dia e canal. Os valores NÃO são gastos realizados nem limites aprovados por anunciantes.</p>
<div class="warning" role="note">DEMONSTRAÇÃO NÃO HOMOLOGADA — Nenhum orçamento foi enviado ao Google Ads ou à Meta. Não existe aprovação humana verificada, execução de mídia ou cobrança. Todos os dados são sintéticos.</div>
HTML;

if ($preview->scenarios === []) {
    echo '<section><h2>Nenhum cenário demonstrativo</h2>'
        .'<p>Esta prévia não contém planejamento para exibir.</p></section>';
}
foreach($preview->scenarios as $index=>$scenario) {
    $ref = $e($scenario['scenario_ref']);
    $total = $brl($scenario['total_centavos']);
    $g = $brl($scenario['channel_totals_centavos']['google_ads']);
    $m = $brl($scenario['channel_totals_centavos']['meta_ads']);
    $googlePct = $percentage($scenario['channel_split_bp']['google_ads']);
    $metaPct = $percentage($scenario['channel_split_bp']['meta_ads']);
    $start = $e($scenario['start_date']);
    $end = $e($scenario['end_date']);
    $days = (int)$scenario['number_of_days'];
    $id = 'scenario-'.(int)$index;

    echo '<section aria-labelledby="'.$id.'"><h2 id="'.$id.'">Cenário '.$ref.'</h2>'
        .'<div class="meta">BRL · '.$start.' até '.$end.' · '.$days.' dia(s) · somente DEMO</div>';
    echo '<div class="kpis">';
    foreach([
        ['Orçamento total fictício',$total],
        ['Google Ads · '.$googlePct,$g],
        ['Meta Ads · '.$metaPct,$m],
        ['Dias planejados',(string)$days],
    ] as [$label,$value]){
        echo '<div class="kpi"><b>'.$e($value).'</b><span>'.$e($label).'</span></div>';
    }
    echo '</div><h3>Calendário de distribuição fictícia</h3>'
        .'<div class="scroll"><table><thead><tr>'
        .'<th>Data</th><th>Google Ads</th><th>Meta Ads</th><th>Total do dia</th>'
        .'</tr></thead><tbody>';
    foreach($scenario['daily'] as $daily){
        echo '<tr><td>'.$e($daily['date']).'</td>'
            .'<td class="money">'.$e($brl($daily['google_ads_centavos'])).'</td>'
            .'<td class="money">'.$e($brl($daily['meta_ads_centavos'])).'</td>'
            .'<td class="money">'.$e($brl($daily['total_centavos'])).'</td></tr>';
    }
    echo '</tbody></table></div></section>';
}
echo <<<'HTML'
<footer>Esta visualização é um arquivo HTML estático criado em testes. Não é a área autenticada do Portal, não possui ações, formulários ou links e não conecta contas publicitárias. A atribuição de centavos usa quotas cumulativas verificadas; valores reais dependem de aprovação humana e integração homologada.</footer>
</main></body></html>
HTML;
