<?php

declare(strict_types=1);

// F163 is a non-operational PostgreSQL18 CI lab with only synthetic rows.
// Environment guard prevents connecting a customer's/shared database.
if (PHP_SAPI !== 'cli'
    || getenv('PORTAL_F163_PG_LAB') !== 'NON_OPERATIONAL_F163'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGDATABASE') !== 'portal_f163_lab'
    || getenv('PGUSER') !== 'postgres'
    || getenv('PGPASSWORD') !== 'f163-review-admin-only') {
    throw new RuntimeException('F163 refuses non-disposable PostgreSQL.');
}

$db = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_f163_lab;connect_timeout=3;sslmode=disable',
    'postgres',
    'f163-review-admin-only',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);

function f163Assert(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException('F163 SECURITY FAIL: '.$reason);
    }
}

function f163One(PDO $db, string $sql): string
{
    $value = $db->query($sql)?->fetchColumn();
    f163Assert($value !== false && is_scalar($value), 'missing SQL result');
    return (string) $value;
}

function f163Denied(PDO $db, string $sql, string $state, string $why): void
{
    try {
        $db->exec($sql);
    } catch (PDOException $e) {
        f163Assert((string) $e->getCode() === $state,
            $why.': unexpected SQLSTATE '.$e->getCode());
        return;
    }
    throw new RuntimeException('F163 SECURITY FAIL: allowed '. $why);
}

f163Assert(f163One($db, "SELECT count(*) FROM public.portal_auth_sessions") === '0',
    'temporary session table starts empty');

foreach (['portal_identities','portal_organizations','portal_memberships','portal_auth_sessions'] as $table) {
    f163Assert(in_array(
        f163One($db,
            "SELECT relforcerowsecurity FROM pg_class WHERE oid=('public.".$table."')::regclass"
        ),
        ['t','1'], true,
    ), 'FORCE RLS must remain active: '.$table);
    f163Assert(f163One($db,
        "SELECT count(*) FROM pg_policies WHERE schemaname='public' AND tablename='".$table."'"
    ) === '0', 'application allow policy was added: '.$table);
}
f163Assert(f163One($db,
    "SELECT count(*) FROM pg_proc WHERE proname='portal_f163_guard_session_issuance' AND prosecdef"
) === '0', 'issuance guard must not elevate privileges');

$db->exec(<<<'SQL'
INSERT INTO public.portal_identities
  (id,email_normalized,status,security_revision,created_at,updated_at)
VALUES ('11111111-1111-4111-8111-111111111111','f163@example.invalid','active',1,now(),now());

INSERT INTO public.portal_organizations
  (id,reference,display_name,status,security_revision,created_at,updated_at)
VALUES ('22222222-2222-4222-8222-222222222222','f163-only','F163 synthetic tenant','active',1,now(),now());

INSERT INTO public.portal_memberships
  (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
VALUES ('33333333-3333-4333-8333-333333333333',
  '11111111-1111-4111-8111-111111111111',
  '22222222-2222-4222-8222-222222222222',
  'member','active',1,now(),now());

INSERT INTO public.portal_auth_sessions
  (id,session_sha256,identity_id,organization_id,status,identity_revision,
   membership_revision,organization_revision,session_purpose,
   issued_at,expires_at,created_at,updated_at)
VALUES
  ('55555555-5555-4555-8555-555555555551',
   'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
   '11111111-1111-4111-8111-111111111111',
   '22222222-2222-4222-8222-222222222222',
   'pending_mfa',1,1,1,'business',now()-interval '10 minutes',
   now()+interval '50 minutes',now(),now()),
  ('55555555-5555-4555-8555-555555555552',
   'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
   '11111111-1111-4111-8111-111111111111',
   '22222222-2222-4222-8222-222222222222',
   'pending_mfa',1,1,NULL,NULL,now()-interval '5 minutes',
   now()+interval '10 minutes',now(),now());
SQL);

const SESSION = '55555555-5555-4555-8555-555555555551';
const LEGACY = '55555555-5555-4555-8555-555555555552';

foreach ([
    ["UPDATE public.portal_auth_sessions SET id='55555555-5555-4555-8555-555555555559' WHERE id='".SESSION."'", 'session primary key'],
    ["UPDATE public.portal_auth_sessions SET session_sha256=repeat('c',64) WHERE id='".SESSION."'", 'bearer digest'],
    ["UPDATE public.portal_auth_sessions SET identity_id='11111111-1111-4111-8111-111111111119' WHERE id='".SESSION."'", 'actor identity'],
    ["UPDATE public.portal_auth_sessions SET organization_id='22222222-2222-4222-8222-222222222229' WHERE id='".SESSION."'", 'tenant organization'],
    ["UPDATE public.portal_auth_sessions SET identity_revision=2 WHERE id='".SESSION."'", 'identity security revision'],
    ["UPDATE public.portal_auth_sessions SET membership_revision=2 WHERE id='".SESSION."'", 'membership revision'],
    ["UPDATE public.portal_auth_sessions SET organization_revision=2 WHERE id='".SESSION."'", 'tenant security revision'],
    ["UPDATE public.portal_auth_sessions SET issued_at=issued_at-interval '5 minutes' WHERE id='".SESSION."'", 'session issuance clock'],
    ["UPDATE public.portal_auth_sessions SET expires_at=expires_at+interval '1 minute' WHERE id='".SESSION."'", 'extension of session expiry'],
    ["UPDATE public.portal_auth_sessions SET organization_revision=1 WHERE id='".LEGACY."'", 'legacy NULL org revision upgrade'],
] as [$sql, $reason]) {
    f163Denied($db, $sql, '42501', $reason);
}

f163Denied($db, "UPDATE public.portal_auth_sessions SET session_purpose='business' WHERE id='".LEGACY."'",
    '23514', 'legacy NULL purpose escalation');
f163Denied($db, "UPDATE public.portal_auth_sessions SET status='revoked' WHERE id='".SESSION."'",
    '23514', 'revocation missing timestamp (existing CHECK)');

$db->exec(
    "UPDATE public.portal_auth_sessions SET status='active',mfa_verified_at=now() ".
    "WHERE id='".SESSION."'"
);
f163Assert(f163One($db,
    "SELECT status FROM public.portal_auth_sessions WHERE id='".SESSION."'"
) === 'active', 'legitimate synthetic pending-to-active state transition');

f163Denied($db,
    "UPDATE public.portal_auth_sessions SET mfa_verified_at=now()+interval '5 seconds' WHERE id='".SESSION."'",
    '42501', 'MFA evidence replacement');
f163Denied($db,
    "UPDATE public.portal_auth_sessions SET status='pending_mfa',mfa_verified_at=NULL WHERE id='".SESSION."'",
    '42501', 'active to pending_mfa downgrade');

$db->exec(
    "UPDATE public.portal_auth_sessions SET status='revoked',revoked_at=now() ".
    "WHERE id='".SESSION."'"
);
f163Assert(f163One($db,
    "SELECT status FROM public.portal_auth_sessions WHERE id='".SESSION."'"
) === 'revoked', 'terminal revoked state');
f163Denied($db,
    "UPDATE public.portal_auth_sessions SET status='active',revoked_at=NULL WHERE id='".SESSION."'",
    '42501', 'revoked session reactivation');
f163Denied($db,
    "UPDATE public.portal_auth_sessions SET revoked_at=now()+interval '3 seconds' WHERE id='".SESSION."'",
    '42501', 'revocation timestamp replacement');
f163Denied($db,
    "UPDATE public.portal_auth_sessions SET session_purpose='invitation_only' WHERE id='".SESSION."'",
    '23514', 'active business purpose mutation');

f163Assert(f163One($db, "SELECT count(*) FROM public.portal_auth_sessions") === '2',
    'no denied operation inserted or moved session');
f163Assert(f163One($db,
    "SELECT count(*) FROM public.portal_auth_sessions WHERE id='".LEGACY."'".
    " AND session_purpose IS NULL AND organization_revision IS NULL"
) === '1', 'legacy session remains unclassified and untrusted');

echo "F163 PASS: persisted session issuance immutable, MFA transition controlled, revocation terminal, legacy NULL denied, RLS unchanged.\n";
