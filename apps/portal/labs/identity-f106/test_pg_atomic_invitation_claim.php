<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Identity\Invitations\PortalInvitationSecret;

/**
 * PORTAL-F106: disposable local PG18, never a customer database or endpoint.
 * Tests SQL atomicity of an invitation row; NOT an authenticated actor or
 * a linearizable session/revision decision across other tables.
 */
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F106_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F106 requires disposable PostgreSQL 18 CI.');
}
$adminPassword = getenv('F66_PG_ADMIN_PASSWORD');
$probePassword = getenv('F106_PG_PROBE_PASSWORD');
if (!is_string($adminPassword) || strlen($adminPassword) < 12
    || !is_string($probePassword) || strlen($probePassword) < 12) {
    throw new RuntimeException('F106 requires CI-only ephemeral credentials.');
}
$connect = static fn(string $role, string $password): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$admin = $connect('postgres', $adminPassword);
$probeA = $connect('portal_f106_invitation_probe', $probePassword);
$probeB = $connect('portal_f106_invitation_probe', $probePassword);
$n = 0;
$ok = static function(bool $valid, string $label) use (&$n): void {
    if (!$valid) throw new RuntimeException('PORTAL-F106 FAILED: '.$label);
    ++$n;
};
$isTrue = static fn(mixed $value): bool => in_array($value, [true, 't', '1', 1], true);
$isFalse = static fn(mixed $value): bool => in_array($value, [false, 'f', '0', 0], true);

$roles = $admin->query(
    "SELECT rolname,rolcanlogin,rolsuper,rolbypassrls,rolcreaterole,rolcreatedb
       FROM pg_roles WHERE rolname IN
       ('portal_f106_invitation_definer','portal_f106_invitation_probe')
       ORDER BY rolname"
)->fetchAll();
$ok(count($roles) === 2, 'both dedicated nonprivileged SQL roles exist');
foreach ($roles as $role) {
    $ok($isFalse($role['rolsuper']) && $isFalse($role['rolbypassrls'])
        && $isFalse($role['rolcreaterole']) && $isFalse($role['rolcreatedb']),
        'neither SQL role has admin privileges or BYPASSRLS');
    $ok($isTrue($role['rolcanlogin'])
        === ($role['rolname'] === 'portal_f106_invitation_probe'),
        'only the EXECUTE-only probe can log in');
}
$fn = $admin->query(
    "SELECT pg_get_userbyid(proowner) AS owner, prosecdef, proconfig
       FROM pg_proc
      WHERE oid='public.portal_f106_claim_invitation(text,uuid,uuid)'::regprocedure"
)->fetch();
$ok(is_array($fn) && $fn['owner'] === 'portal_f106_invitation_definer'
    && $isTrue($fn['prosecdef'])
    && str_contains((string)$fn['proconfig'], 'row_security=on')
    && str_contains((string)$fn['proconfig'], 'search_path=pg_catalog, pg_temp'),
    'function owned by NOLOGIN role with restricted search path and FORCE RLS');

$policies = $admin->query(
    "SELECT tablename,policyname,cmd,roles,qual,with_check
       FROM pg_policies WHERE schemaname='public'
         AND policyname LIKE 'portal_f106_%' ORDER BY tablename,policyname"
)->fetchAll();
$ok(count($policies) === 5, 'exactly five temporary restricted RLS policies');
foreach($policies as $policy) {
    $ok($policy['roles'] === '{portal_f106_invitation_definer}'
        && !str_contains($policy['roles'], 'public'),
        'RLS policies grant only to NOLOGIN definer: '.$policy['policyname']);
}
$ok((int)$admin->query(
    "SELECT count(*) FROM pg_policies
       WHERE schemaname='public' AND tablename='portal_auth_sessions'"
)->fetchColumn() === 0, 'invitation claim does not open the session table');
$ok((int)$admin->query(
    "SELECT count(*) FROM pg_policies WHERE schemaname='public'
       AND tablename='portal_membership_grants'"
)->fetchColumn() === 0, 'invitation claim does not open permission grants');
$ok($isFalse($admin->query(
    "SELECT has_table_privilege('portal_f106_invitation_probe',
      'public.portal_access_invitations','UPDATE')"
)->fetchColumn()), 'EXECUTE-only caller cannot UPDATE invite rows');
$ok($isFalse($admin->query(
    "SELECT has_table_privilege('portal_f106_invitation_probe',
      'public.portal_access_invitations','SELECT')"
)->fetchColumn()), 'EXECUTE-only caller cannot SELECT invite rows');

$id = 'd1111111-1111-4111-8111-111111111111';
$org = 'd2222222-2222-4222-8222-222222222222';
$foreign = 'd3333333-3333-4333-8333-333333333333';
$membership = 'd4444444-4444-4444-8444-444444444444';
$admin->prepare(
    "INSERT INTO portal_identities
       (id,email_normalized,status,security_revision,created_at,updated_at)
      VALUES(?,?,'invited',1,now(),now())"
)->execute([$id, 'f106-synthetic@example.invalid']);
$insertOrg = $admin->prepare(
    "INSERT INTO portal_organizations
       (id,reference,display_name,status,security_revision,created_at,updated_at)
      VALUES(?,?,?,'active',1,now(),now())"
);
$insertOrg->execute([$org, 'f106-org', 'F106 Synthetic Organization']);
$insertOrg->execute([$foreign, 'f106-foreign', 'F106 Unrelated Organization']);
$admin->prepare(
    "INSERT INTO portal_memberships
      (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
      VALUES(?,?,?,'member','invited',1,now(),now())"
)->execute([$membership, $id, $org]);

$invitation = 0;
$issue = static function (string $clock = 'valid') use ($admin, $id, $org, &$invitation): array {
    $secret = PortalInvitationSecret::mint();
    $bearer = $secret->takeDeliverySecretOnce();
    if (!is_string($bearer)) throw new RuntimeException('Synthetic delivery secret not created');
    ++$invitation;
    $suffix = str_pad((string)$invitation, 12, '0', STR_PAD_LEFT);
    $uuid = 'd5555555-5555-4555-8555-'.$suffix;
    $times = match($clock) {
        'expired' => [
            "now()-interval '48 hours'",
            "now()-interval '24 hours'",
        ],
        'future' => [
            "now()+interval '1 hour'",
            "now()+interval '2 hours'",
        ],
        default => [
            "now()-interval '1 minute'",
            "now()+interval '1 day'",
        ],
    };
    $stmt = $admin->prepare(
        "INSERT INTO portal_access_invitations
         (id,token_sha256,identity_id,organization_id,
          identity_revision,membership_revision,organization_revision,
          status,issued_at,expires_at,created_at,updated_at)
         VALUES(?,?,?,?,1,1,1,'pending',{$times[0]},{$times[1]},now(),now())"
    );
    $stmt->execute([$uuid,$secret->digest(),$id,$org]);
    return [$uuid, $secret->digest(), $bearer];
};

$call = static function (
    PDO $client, string $digest, string $identity, string $tenant
) use ($isTrue): bool {
    $stmt = $client->prepare(
        'SELECT public.portal_f106_claim_invitation(:digest,'
        .'CAST(:identity AS uuid),CAST(:organization AS uuid))'
    );
    $stmt->execute(['digest'=>$digest,'identity'=>$identity,'organization'=>$tenant]);
    return $isTrue($stmt->fetchColumn());
};

[$firstUuid,$firstDigest,$firstBearer] = $issue();
$ok(!$call($probeA,$firstDigest,$id,$org),
    'unverified invited identity denied');
$admin->exec("UPDATE portal_identities
                 SET status='active',email_verified_at=now() WHERE id='$id'");
$ok(!$call($probeA,$firstDigest,$id,$org),
    'email verification alone without MFA still denied');
$admin->exec("UPDATE portal_identities SET mfa_confirmed_at=now() WHERE id='$id'");
$ok(!$call($probeA,$firstDigest,$id,$foreign), 'wrong tenant denied');
$ok(!$call($probeA,$firstDigest,$foreign,$org), 'wrong identity denied');
$ok(!$call($probeA,hash('sha256','wrong-secret'),$id,$org),
    'wrong digest denied');
$ok(!$call($probeA,'bad-hash',$id,$org), 'noncanonical digest denied');
$ok($call($probeA,$firstDigest,$id,$org),
    'synthetic verified identity can claim first valid invitation once');
$ok(!$call($probeB,$firstDigest,$id,$org),
    'second client cannot reuse consumed token');
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_access_invitations
       WHERE id='$firstUuid' AND status='consumed' AND consumed_at IS NOT NULL"
)->fetchColumn()===1, 'one consumed marker persisted');
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_auth_sessions WHERE identity_id='$id'"
)->fetchColumn()===0, 'invitation claim never issued a session');
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_memberships
       WHERE identity_id='$id' AND organization_id='$org' AND status='active'"
)->fetchColumn()===0, 'claim never activated membership');

[$expiredUuid,$expiredDigest] = $issue('expired');
$ok(!$call($probeA,$expiredDigest,$id,$org),
    'expired invitation rejected despite correct identity');
$admin->exec("UPDATE portal_access_invitations SET status='revoked',revoked_at=now()
              WHERE id='$expiredUuid'");
[$futureUuid,$futureDigest] = $issue('future');
$ok(!$call($probeA,$futureDigest,$id,$org),
    'not-yet-issued invitation cannot be claimed');
$admin->exec("UPDATE portal_access_invitations SET status='revoked',revoked_at=issued_at
              WHERE id='$futureUuid'");

[$revUuid,$revDigest] = $issue();
$admin->exec("UPDATE portal_identities SET security_revision=2 WHERE id='$id'");
$ok(!$call($probeA,$revDigest,$id,$org), 'identity revision mismatch denied');
$admin->exec("UPDATE portal_identities SET security_revision=1 WHERE id='$id'");
$admin->exec("UPDATE portal_memberships SET membership_revision=2
              WHERE id='$membership'");
$ok(!$call($probeA,$revDigest,$id,$org), 'membership revision mismatch denied');
$admin->exec("UPDATE portal_memberships SET membership_revision=1
              WHERE id='$membership'");
$admin->exec("UPDATE portal_organizations SET security_revision=2
              WHERE id='$org'");
$ok(!$call($probeA,$revDigest,$id,$org), 'company security revision mismatch denied');
$admin->exec("UPDATE portal_organizations SET security_revision=1
              WHERE id='$org'");
$admin->exec("UPDATE portal_organizations SET status='suspended' WHERE id='$org'");
$ok(!$call($probeA,$revDigest,$id,$org), 'suspended organization denied');
$admin->exec("UPDATE portal_organizations SET status='active' WHERE id='$org'");
$admin->exec("UPDATE portal_memberships SET status='suspended'
               WHERE id='$membership'");
$ok(!$call($probeA,$revDigest,$id,$org), 'suspended membership denied');
$admin->exec("UPDATE portal_memberships SET status='invited'
              WHERE id='$membership'");
$admin->exec("UPDATE portal_access_invitations SET status='revoked',revoked_at=now()
              WHERE id='$revUuid'");
$ok(!$call($probeA,$revDigest,$id,$org), 'revoked invitation denied');
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_auth_sessions WHERE identity_id='$id'"
)->fetchColumn()===0, 'no session created during revocation trials');

// A row-level concurrency test. Probe A holds the UPDATE lock but does not
// commit; Probe B must NOT obtain a second acceptance, even if it times out.
// After A commits, B must reject the same digest as consumed.
[$raceUuid,$raceDigest] = $issue();
$probeA->beginTransaction();
$ok($call($probeA,$raceDigest,$id,$org),
    'first concurrent claimant holds the row update lock');
$probeB->exec("SET lock_timeout = '250ms'");
$ok(!$call($probeB,$raceDigest,$id,$org),
    'competing claimant is rejected while first row is locked');
$probeA->commit();
$ok(!$call($probeB,$raceDigest,$id,$org),
    'competing claimant still rejected after first COMMIT');
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_access_invitations
      WHERE id='$raceUuid' AND status='consumed'"
)->fetchColumn()===1, 'single consumption after competing attempts');
$probeB->exec("RESET lock_timeout");

// A rolled-back claim has not consumed the token. Another connection may
// then win the row and consume exactly once.
[$rollbackUuid,$rollbackDigest] = $issue();
$probeA->beginTransaction();
$ok($call($probeA,$rollbackDigest,$id,$org),
    'synthetic claimant updated row within uncommitted transaction');
$probeA->rollBack();
$ok($call($probeB,$rollbackDigest,$id,$org),
    'claim can succeed after original transaction rolls back');
$ok(!$call($probeA,$rollbackDigest,$id,$org),
    'claim after alternate winner is rejected');
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_access_invitations
       WHERE status='consumed' AND identity_id='$id' AND organization_id='$org'"
)->fetchColumn()===3, 'exactly three committed single-use claims');

// Additional RLS denial: a caller may not read or update rows even if a
// normal table SELECT is granted temporarily, because no policy targets it.
$admin->exec(
    'GRANT SELECT (id,token_sha256) ON portal_access_invitations
      TO portal_f106_invitation_probe'
);
$ok((int)$probeB->query('SELECT count(*) FROM portal_access_invitations')
    ->fetchColumn()===0, 'direct SELECT yields zero rows due to FORCE RLS');
$admin->exec(
    'REVOKE SELECT (id,token_sha256) ON portal_access_invitations
      FROM portal_f106_invitation_probe'
);
$ok($isFalse($admin->query(
    "SELECT has_table_privilege('portal_f106_invitation_probe',
      'public.portal_auth_sessions','INSERT')"
)->fetchColumn()), 'claim role cannot create sessions');
$ok($isFalse($admin->query(
    "SELECT has_table_privilege('portal_f106_invitation_probe',
      'public.portal_memberships','UPDATE')"
)->fetchColumn()), 'claim role cannot activate membership');

echo "PASS PORTAL-F106 {$n} atomic invitation claims, replay, expiry, revision, RLS and row-lock tests\n";
