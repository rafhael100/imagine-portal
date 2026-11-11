<?php

declare(strict_types=1);

/**
 * INT-F157: PostgreSQL18 cross-tenant revocation integration rehearsal.
 * No credentials, customer data, production RLS allow, or real sessions.
 */
if (getenv('PORTAL_F157_PG_LAB') !== 'NON_OPERATIONAL_F157'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGDATABASE') !== 'portal_f157_lab'
    || getenv('PGUSER') !== 'postgres'
    || getenv('PGPASSWORD') !== 'f157-review-admin-only') {
    throw new RuntimeException('F157 refuses non-disposable PostgreSQL environments.');
}
$db = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_f157_lab;connect_timeout=3;sslmode=disable',
    'postgres', 'f157-review-admin-only',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false],
);
function f157Assert(bool $truth,string $message): void {
    if (!$truth) throw new RuntimeException('F157 SECURITY FAIL: '.$message);
}
function f157State(PDO $db, string $table, string $field, string $id): int {
    $allowed=[
        'portal_memberships'=>['membership_revision'],
        'portal_organizations'=>['security_revision'],
        'portal_auth_sessions'=>['membership_revision','organization_revision'],
    ];
    f157Assert(in_array($field,$allowed[$table]??[],true),
        'Unexpected state field');
    $query=$db->prepare("SELECT ".$field." FROM public.".$table." WHERE id=:id");
    $query->execute([':id'=>$id]);
    $value=$query->fetchColumn();
    f157Assert($value !== false && ctype_digit((string)$value),
        'State revision missing');
    return (int)$value;
}
const IDENTITY='11111111-1111-4111-8111-111111111111';
const ORG_A='22222222-2222-4222-8222-222222222221';
const ORG_B='22222222-2222-4222-8222-222222222222';
const MEMBER_A='33333333-3333-4333-8333-333333333331';
const MEMBER_B='33333333-3333-4333-8333-333333333332';
const SESSION_A='55555555-5555-4555-8555-555555555551';
const SESSION_B='55555555-5555-4555-8555-555555555552';

$db->exec(<<<'SQL'
INSERT INTO public.portal_identities
    (id,email_normalized,status,security_revision,created_at,updated_at)
VALUES ('11111111-1111-4111-8111-111111111111','f157@example.invalid','active',1,now(),now());

INSERT INTO public.portal_organizations
    (id,reference,display_name,status,security_revision,created_at,updated_at)
VALUES
    ('22222222-2222-4222-8222-222222222221','f157-org-a','Org A — synthetic','active',1,now(),now()),
    ('22222222-2222-4222-8222-222222222222','f157-org-b','Org B — synthetic','active',1,now(),now());

INSERT INTO public.portal_memberships
    (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
VALUES
    ('33333333-3333-4333-8333-333333333331',
     '11111111-1111-4111-8111-111111111111',
     '22222222-2222-4222-8222-222222222221','member','active',1,now(),now()),
    ('33333333-3333-4333-8333-333333333332',
     '11111111-1111-4111-8111-111111111111',
     '22222222-2222-4222-8222-222222222222','member','active',1,now(),now());

INSERT INTO public.portal_auth_sessions
    (id,session_sha256,identity_id,organization_id,status,identity_revision,
     membership_revision,organization_revision,issued_at,expires_at,created_at,updated_at)
VALUES
    ('55555555-5555-4555-8555-555555555551',
     'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
     '11111111-1111-4111-8111-111111111111',
     '22222222-2222-4222-8222-222222222221',
     'pending_mfa',1,1,1,now(),now()+interval '1 hour',now(),now()),
    ('55555555-5555-4555-8555-555555555552',
     'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
     '11111111-1111-4111-8111-111111111111',
     '22222222-2222-4222-8222-222222222222',
     'pending_mfa',1,1,1,now(),now()+interval '1 hour',now(),now());
SQL);

f157Assert(f157State($db,'portal_memberships','membership_revision',MEMBER_A)===1,
    'org A membership initial revision');
f157Assert(f157State($db,'portal_memberships','membership_revision',MEMBER_B)===1,
    'org B membership initial revision');

$db->exec(<<<'SQL'
INSERT INTO public.portal_membership_grants
    (id,membership_id,permission_code,created_at,updated_at)
VALUES ('44444444-4444-4444-8444-444444444441',
        '33333333-3333-4333-8333-333333333331','services.view',now(),now());
SQL);
f157Assert(f157State($db,'portal_memberships','membership_revision',MEMBER_A)===2,
    'A grant must increment A revision');
f157Assert(f157State($db,'portal_memberships','membership_revision',MEMBER_B)===1,
    'B must not inherit A grant revision');

$db->exec(
    "UPDATE public.portal_organizations SET status='suspended'
     WHERE id='".ORG_A."'"
);
f157Assert(f157State($db,'portal_organizations','security_revision',ORG_A)===2,
    'A suspension must increment security revision');
f157Assert(f157State($db,'portal_organizations','security_revision',ORG_B)===1,
    'B organization revision must remain unchanged');

$read = <<<'SQL'
SELECT s.organization_id,
       s.membership_revision AS session_member,
       m.membership_revision AS current_member,
       s.organization_revision AS session_org,
       o.security_revision AS current_org,
       o.status AS organization_status,
       m.status AS member_status
FROM public.portal_auth_sessions s
JOIN public.portal_memberships m
  ON m.identity_id=s.identity_id AND m.organization_id=s.organization_id
JOIN public.portal_organizations o ON o.id=s.organization_id
ORDER BY s.organization_id
SQL;
$rows=$db->query($read)->fetchAll();
f157Assert(count($rows)===2,'expected two distinct synthetic tenant session records');
f157Assert($rows[0]['organization_id']===ORG_A,'A first sorted tenant');
f157Assert((int)$rows[0]['session_member']===1 && (int)$rows[0]['current_member']===2
    && (int)$rows[0]['session_org']===1 && (int)$rows[0]['current_org']===2
    && $rows[0]['organization_status']==='suspended',
    'A session must become stale and org suspended');
f157Assert($rows[1]['organization_id']===ORG_B,'B second sorted tenant');
f157Assert((int)$rows[1]['session_member']===1 && (int)$rows[1]['current_member']===1
    && (int)$rows[1]['session_org']===1 && (int)$rows[1]['current_org']===1
    && $rows[1]['organization_status']==='active',
    'B must remain unaffected by A changes');

$db->exec(
    "UPDATE public.portal_memberships SET status='suspended'
     WHERE id='".MEMBER_B."'"
);
f157Assert(f157State($db,'portal_memberships','membership_revision',MEMBER_B)===2,
    'membership B status change must invalidate only B session');
f157Assert(f157State($db,'portal_organizations','security_revision',ORG_A)===2,
    'B member change must not affect A org revision');
f157Assert(f157State($db,'portal_memberships','membership_revision',MEMBER_A)===2,
    'B member change must not affect A member revision');

foreach (['portal_organizations','portal_memberships','portal_membership_grants',
    'portal_auth_sessions'] as $table) {
    $forced=$db->query(
        "SELECT relrowsecurity AND relforcerowsecurity
         FROM pg_class WHERE oid=('public.".$table."')::regclass"
    )->fetchColumn();
    f157Assert(in_array($forced,[true,'t','1',1],true),
        'FORCE RLS required for '.$table);
    f157Assert((int)$db->query(
        "SELECT count(*) FROM pg_policies
         WHERE schemaname='public' AND tablename='".$table."'"
    )->fetchColumn()===0,'No application allow policy on '.$table);
}

echo "F157 PASS: A and B session/member/org revisions isolated and fail closed.\n";
