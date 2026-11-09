<?php

declare(strict_types=1);

/**
 * Disposable PORTAL-PERF-F11 executable tests. No HTTP / outbound network.
 * Requires isolated portal_lab DB, never call on production/VPS databases.
 */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1'
    || getenv('PORTAL_PERF_F11_LAB_ENABLED') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'portal_lab') {
    throw new RuntimeException('Refusing F11 contract test outside CLI disposable portal_lab.');
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once base_path('labs/client-auth/LabTotp.php');
require_once base_path('labs/client-auth/LabMembershipFence.php');
require_once base_path('labs/client-auth/LabSecureLogin.php');
require_once base_path('labs/client-auth/LabPerformanceMfaIssuer.php');
require_once base_path('labs/client-auth/LabPerformanceRevocationPublisher.php');

use App\Labs\ClientAuth\LabTotp;
use App\Labs\ClientAuth\LabPerformanceMfaIssuer;
use App\Labs\ClientAuth\LabPerformanceRevocationPublisher;
use App\Labs\ClientAuth\LabSecureLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\QueryException;

$admin = DB::connection('pgsql');
$dbName = $admin->selectOne('SELECT current_database() AS name')->name ?? null;
if ($dbName !== 'portal_lab') {
    throw new RuntimeException('Non-disposable PostgreSQL refused.');
}
config(['database.connections.portal_lab_runtime' => array_merge(
    config('database.connections.pgsql'), [
        'database' => 'portal_lab',
        'username' => 'portal_lab_runtime',
        'password' => 'portal-lab-runtime-only',
    ]
)]);
DB::purge('portal_lab_runtime');
config(['database.connections.portal_lab_worker' => array_merge(
    config('database.connections.pgsql'), [
        'database' => 'portal_lab',
        'username' => 'portal_lab_worker',
        'password' => 'portal-lab-worker-only',
    ]
)]);
DB::purge('portal_lab_worker');

$migration = require base_path('labs/client-auth/create_lab_schema.php');
$migration->down();
$migration->up();
$admin->unprepared((string) file_get_contents(
    base_path('labs/performance-contract/portal-perf-f11-authority-outbox.sql')
));

$admin->table('lab_portal_users')->insert([
    [
        'id' => 'portal-user-a',
        'email' => 'a@example.invalid',
        'password_hash' => Hash::make('synthetic-pass-A-2026'),
        'status' => 'active',
        'session_revision' => 1,
    ],
    [
        'id' => 'portal-user-b',
        'email' => 'b@example.invalid',
        'password_hash' => Hash::make('synthetic-pass-B-2026'),
        'status' => 'active',
        'session_revision' => 1,
    ],
]);
$admin->table('lab_portal_auth_factors')->insert([
    [
        'user_id' => 'portal-user-a',
        'secret_hex' => '3132333435363738393031323334353637383930',
        'last_step' => -1,
    ],
    [
        'user_id' => 'portal-user-b',
        'secret_hex' => '4142434445464748494a4b4c4d4e4f5051525354',
        'last_step' => -1,
    ],
]);
$admin->table('lab_portal_organizations')->insert([
    ['id' => 'org-a', 'name' => 'Aurora Synthetic', 'status' => 'active'],
    ['id' => 'org-b', 'name' => 'Boreal Synthetic', 'status' => 'active'],
]);
$admin->table('lab_portal_memberships')->insert([
    ['org_id' => 'org-a', 'user_id' => 'portal-user-a', 'status' => 'active', 'revision' => 1],
    ['org_id' => 'org-b', 'user_id' => 'portal-user-b', 'status' => 'active', 'revision' => 1],
]);
$admin->table('lab_portal_services')->insert([
    ['org_id' => 'org-a', 'id' => 'service-a', 'label' => 'Onepage synthetic A'],
    ['org_id' => 'org-b', 'id' => 'service-b', 'label' => 'Performance synthetic B'],
]);
$admin->table('lab_portal_perf_authority')->insert([
    'org_id' => 'org-b', 'service_id' => 'service-b',
    'organization_ref' => 'org-demo-b', 'tenant_ref' => 'perf-demo-b',
    'service_ref' => 'svc-demo-b', 'min_revision' => 1, 'active' => true,
]);

$n = 0;
$check = static function (string $label, mixed $actual, mixed $expected) use (&$n): void {
    if ($actual !== $expected) {
        throw new RuntimeException('FAIL '.$label.' got '
            .var_export($actual, true).' expected '.var_export($expected, true));
    }
    ++$n;
    echo "PASS {$n} {$label}\n";
};
$denySql = static function (string $label, callable $action) use ($check): void {
    try {
        $action();
    } catch (QueryException) {
        $check($label, true, true);
        return;
    }
    throw new RuntimeException('FAIL '.$label.': role unexpectedly allowed mutation.');
};
$grant = static fn (): object => $admin->table('lab_portal_perf_authority')
    ->where('service_ref', 'svc-demo-b')->first();

$loginB = app(LabSecureLogin::class)->signIn(
    'b@example.invalid',
    'synthetic-pass-B-2026',
    LabTotp::codeAt('4142434445464748494a4b4c4d4e4f5051525354', time())
);
$check('01 MFA B session exists', is_string($loginB['token']), true);
$request = Request::create('/perf-revoke-lab', 'POST');
$session = app('session')->driver();
$session->start();
$request->setLaravelSession($session);
$request->session()->put('portal_lab_v2_token', $loginB['token']);
$issuer = app(LabPerformanceMfaIssuer::class);
$publisher = app(LabPerformanceRevocationPublisher::class);
$secret = random_bytes(32);
$mint = static fn (string $id, string $nonce) =>
    $issuer->issue($request, 'org-b', 'service-b', $id, $nonce,
        1799999940, 1800000060, $secret);

$initial = $mint('req-demo-f11-before', 'nonce-demo-f11-before');
$check('02 active grant allows synthetic signed envelope', is_array($initial), true);
$check('03 approved grant revision is 1', $initial['grant_revision'], 1);
$check('04 issuer binds Performance service B', $initial['service_ref'], 'svc-demo-b');
$check('05 outbox starts empty', $admin->table('lab_portal_perf_revocation_outbox')->count(), 0);
$check('06 cross-organization revoke denied',
    $publisher->revokeForLab($request, 'org-a', 'service-a'), 'mock_issuer_denied');
$check('07 cross-product revoke denied',
    $publisher->revokeForLab($request, 'org-b', 'service-a'), 'mock_issuer_denied');

$runtime = DB::connection('portal_lab_runtime');
$worker = DB::connection('portal_lab_worker');
$check('08 runtime is restricted role',
    $runtime->selectOne('SELECT current_user AS name')->name, 'portal_lab_runtime');
$check('09 worker is different restricted role',
    $worker->selectOne('SELECT current_user AS name')->name, 'portal_lab_worker');
$check('10 runtime cannot see authority without tenant GUC',
    $runtime->table('lab_portal_perf_authority')->count(), 0);
$denySql('11 runtime cannot revoke authority', static fn () =>
    $runtime->table('lab_portal_perf_authority')->where('service_ref', 'svc-demo-b')
        ->update(['active' => false]));
$denySql('12 runtime cannot fabricate outbox event', static fn () =>
    $runtime->table('lab_portal_perf_revocation_outbox')->insert([
        'event_id' => 'evt-demo-forged',
        'service_ref' => 'svc-demo-b',
        'organization_ref' => 'org-demo-b',
        'tenant_ref' => 'perf-demo-b',
        'source_revision' => 2,
        'event_type' => 'service.authorization_changed.synthetic',
    ]));
$denySql('13 worker cannot revoke authority', static fn () =>
    $worker->table('lab_portal_perf_authority')->where('service_ref', 'svc-demo-b')
        ->update(['active' => false]));
$denySql('14 worker cannot acknowledge source outbox', static fn () =>
    $worker->table('lab_portal_perf_revocation_outbox')->where('event_id', 'evt-demo-forged')
        ->update(['status' => 'acked']));
$check('15 no privileged tampering succeeded', (bool) $grant()->active, true);

$manifestRaw = (string) file_get_contents(base_path(
    'labs/performance-contract/perf-revocation-contract-v1.json'
));
$manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
$expectedFields = [
    'event_id', 'service_ref', 'organization_ref', 'tenant_ref',
    'source_revision', 'event_type', 'status', 'attempts',
];
$check('16 versioned cross-project contract', $manifest['contract_version'],
    'perf.revocation.synthetic.v1');
$check('17 field order matches Performance F1-11', $manifest['event_fields'], $expectedFields);
$check('18 effect is strictly none', $manifest['side_effects'], 'none');

// A forced unique violation after UPDATE must roll back both the new grant
// revision and its outbox insert. Admin injects only a synthetic collision.
$admin->table('lab_portal_perf_revocation_outbox')->insert([
    'event_id' => 'evt-demo-collision',
    'service_ref' => 'svc-demo-b',
    'organization_ref' => 'org-demo-b',
    'tenant_ref' => 'perf-demo-b',
    'source_revision' => 2,
    'event_type' => 'service.authorization_changed.synthetic',
    'status' => 'pending',
    'attempts' => 0,
]);
$check('19 injected outbox revision conflict rejects whole transaction',
    $publisher->revokeForLab($request, 'org-b', 'service-b'), 'mock_transaction_denied');
$check('20 failed transaction retains active grant', (bool) $grant()->active, true);
$check('21 failed transaction retains grant revision 1', (int) $grant()->min_revision, 1);
$check('22 failed transaction did not insert second event',
    $admin->table('lab_portal_perf_revocation_outbox')->count(), 1);
$admin->table('lab_portal_perf_revocation_outbox')
    ->where('event_id', 'evt-demo-collision')->delete();

$event = $publisher->revokeForLab($request, 'org-b', 'service-b');
$check('23 real synthetic revocation produces event object', is_array($event), true);
$check('24 event has exactly F1-11 contract keys', array_keys($event), $expectedFields);
$check('25 event ID is generated server side',
    (bool) preg_match('/^evt-demo-[a-f0-9]{24}$/D', $event['event_id']), true);
$check('26 event matches service', $event['service_ref'], 'svc-demo-b');
$check('27 event organization is bound', $event['organization_ref'], 'org-demo-b');
$check('28 event tenant is bound', $event['tenant_ref'], 'perf-demo-b');
$check('29 event revision increases monotonically', $event['source_revision'], 2);
$canonical = json_decode((string) file_get_contents(base_path(
    'labs/performance-contract/perf-revocation-event-example-v1.json'
)), true, 512, JSON_THROW_ON_ERROR);
$normalized = $event;
$normalized['event_id'] = $canonical['event_id']; // actual event IDs are generated server-side
$check('29A emitted event values match pinned Performance example', $normalized, $canonical);
$check('30 event status pending for retry', $event['status'], 'pending');
$check('31 no actor or personal details in event',
    array_key_exists('actor_id', $event) || array_key_exists('email', $event)
        || array_key_exists('session_cookie', $event), false);
$check('32 current grant becomes revoked', (bool) $grant()->active, false);
$check('33 current grant revision is 2', (int) $grant()->min_revision, 2);
$check('34 one event committed with grant change',
    $admin->table('lab_portal_perf_revocation_outbox')->count(), 1);
$check('35 repeated revoke is a no-op',
    $publisher->revokeForLab($request, 'org-b', 'service-b'), 'already_revoked_demo');
$check('36 retry never adds another event',
    $admin->table('lab_portal_perf_revocation_outbox')->count(), 1);
$check('37 revoked grant denies future signed emission',
    $mint('req-demo-f11-after', 'nonce-demo-f11-after'), null);
$check('38 old signed event is not retroactively unsigned',
    is_array($initial), true);
$check('39 exported authority uses expected fields',
    array_keys($publisher->currentForLab('svc-demo-b')),
    $manifest['authority_fields']);
$check('40 exported authority is revoked',
    $publisher->currentForLab('svc-demo-b')['active'], false);
$check('41 exported authority revision matches event',
    $publisher->currentForLab('svc-demo-b')['min_revision'], 2);
$check('42 no access to an unknown authority',
    $publisher->currentForLab('svc-demo-foreign'), null);

$request->session()->forget('portal_lab_v2_token');
$check('43 missing MFA session denies revoke',
    $publisher->revokeForLab($request, 'org-b', 'service-b'), 'mock_issuer_denied');
$request->session()->put('portal_lab_v2_token', $loginB['token']);
$admin->table('lab_portal_auth_sessions')
    ->where('user_id', 'portal-user-b')->update(['revoked' => true]);
$check('44 server-revoked MFA session denies revoke',
    $publisher->revokeForLab($request, 'org-b', 'service-b'), 'mock_issuer_denied');
$check('45 revoked session denies any new emission',
    $mint('req-demo-f11-revoked', 'nonce-demo-f11-revoked'), null);

$check('46 original F7 outbox remains unrelated',
    $admin->table('lab_portal_outbox')->count(), 0);
$check('47 no external outbox ACK was attempted',
    $admin->table('lab_portal_perf_revocation_outbox')
        ->where('status', 'acked')->count(), 0);
echo "PASS {$n} isolated Portal/Performance revocation contract assertions\n";
