<?php

declare(strict_types=1);

require __DIR__.'/private-preview-route-gate.php';

$checks=0;
function routeCheck(bool $test,string $why): void {
    global $checks;
    if (!$test) throw new RuntimeException('Unsafe private preview route: '.$why);
    ++$checks;
}
$valid=[
    '/_preview/portal',
    '/_preview/portal/',
    '/_preview/portal/login',
    '/_preview/portal/register',
    '/_preview/portal/client/overview',
    '/_preview/portal/admin/orders',
    '/_preview/portal/shop/orders/TEST-00000000000000000000000000000000',
];
$ciOnly=[
    '/_rehearsal/purchase',
    '/_rehearsal/purchase/',
    '/_rehearsal/purchase/orders/TEST-00000000000000000000000000000000',
];
$denied=[
    '/', '/up', '/_rehearsal', '/_rehearsal/purchase-other',
    '/_rehearsal/purchaser', '/_preview', '/_preview/portal-invalid',
    '/_preview/portalX', '/_preview/admin',
    '/api/customers', '/login', '/.env', '//_rehearsal/purchase',
    '/_preview/portal/../_rehearsal/purchase',
    '/_preview/portal/./admin/orders',
    '/_preview/portal/%2e%2e/_rehearsal/purchase',
    '/_preview/portal/%252e%252e/_rehearsal/purchase',
    '/_preview/portal/%2Fadmin/orders',
    '/_preview/portal//admin/orders',
    '/_preview/portal\\\\admin/orders',
    '/_rehearsal/purchase/../preview',
];
foreach($valid as $path) {
    routeCheck(PrivatePreviewRouteGate::allows($path,false),
        'private staging should permit only customer/admin preview: '.$path);
    routeCheck(PrivatePreviewRouteGate::allows($path,true),
        'private CI must retain preview routes: '.$path);
}
foreach($ciOnly as $path) {
    routeCheck(!PrivatePreviewRouteGate::allows($path,false),
        'legacy shared-tenant purchase must be DENIED on VPS: '.$path);
    routeCheck(PrivatePreviewRouteGate::allows($path,true),
        'CI regression routes must be available only within runner: '.$path);
}
foreach($denied as $path) {
    routeCheck(!PrivatePreviewRouteGate::allows($path,false),
        'unknown route exposed on private VPS: '.$path);
    routeCheck(!PrivatePreviewRouteGate::allows($path,true),
        'unknown route exposed even in runner: '.$path);
}
echo "PASS: {$checks} exact route checks. CI legacy checkout allowed; private VPS legacy checkout denied.\n";
echo "NO-GO: this is a path boundary, not a production identity or deployment approval.\n";
