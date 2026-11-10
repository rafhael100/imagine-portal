<?php

declare(strict_types=1);

namespace App\Labs\AuthPerfF61;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * PORTAL-AUTH-PERF-08: actual PostgreSQL SELECTs of SYNTHETIC authority rows.
 *
 * No real Portal authenticated session store, real MFA event, membership,
 * commercial contract or customer exists in this lab. Credentials and
 * authority rows are CI-only; this is NOT production authorization.
 */
final class PortalPerfSqlAuthorityResolver
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function resolve(
        string $opaqueSessionBearer,
        string $requestedService,
        int $nowEpoch,
    ): ?PortalPerfAuthorityFacts {
        if (PHP_SAPI!=='cli'
            || getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_PERF_PG_AUTH_LAB')!=='1'
            || getenv('PGHOST')!=='127.0.0.1'
            || getenv('PGPORT')!=='5432'
            || getenv('PGDATABASE')!=='portal_perf_auth_lab'
            || $nowEpoch<=0
            || $nowEpoch>2147483627
            || $requestedService!=='svc-demo-a'
            || !preg_match('/^[A-Za-z0-9_-]{32,128}$/D',$opaqueSessionBearer)) {
            return null;
        }
        try {
            $role=$this->db->query(
                'SELECT current_database() AS db,current_user AS role'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($role)
                || $role['db']!=='portal_perf_auth_lab'
                || $role['role']!=='portal_f08_reader') {
                return null;
            }
            $hash=hash('sha256',$opaqueSessionBearer);
            $this->db->beginTransaction();
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $setSession=$this->db->prepare(
                "SELECT set_config('app.portal_session_hash', ?, true)"
            );
            $setSession->execute([$hash]);
            $sessionQuery=$this->db->prepare(
                'SELECT session_hash,session_ref,actor_ref,organization_ref,
                        status,mfa_verified_at,issued_at,expires_at,
                        account_revision,session_account_revision
                 FROM portal_perf_authority_sessions
                 WHERE session_hash=?'
            );
            $sessionQuery->execute([$hash]);
            $session=$sessionQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($session)
                || $session['session_hash']!==$hash
                || $session['status']!=='active'
                || !is_string($session['session_ref'])
                || !preg_match('/^session-demo-[a-z0-9-]{1,70}$/D',$session['session_ref'])
                || $session['actor_ref']!=='actor-demo-a'
                || $session['organization_ref']!=='org-demo-a'
                || (int)$session['account_revision']<1
                || (int)$session['session_account_revision']!== (int)$session['account_revision']
                || !self::validTimestamp($session['issued_at'],$nowEpoch,true)
                || !self::validTimestamp($session['expires_at'],$nowEpoch,false)
                || !self::freshMfa($session['mfa_verified_at'],$nowEpoch)) {
                $this->db->rollBack();
                return null;
            }

            // Organization comes from server-owned session row, never a
            // caller-supplied org, role, actor, tenant, or HTTP header.
            $setOrg=$this->db->prepare(
                "SELECT set_config('app.portal_organization_ref', ?, true)"
            );
            $setOrg->execute([$session['organization_ref']]);

            $membershipQuery=$this->db->prepare(
                'SELECT actor_ref,organization_ref,status,permission,
                        membership_revision
                 FROM portal_perf_authority_memberships
                 WHERE actor_ref=? AND organization_ref=?'
            );
            $membershipQuery->execute([
                $session['actor_ref'],$session['organization_ref']
            ]);
            $membership=$membershipQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($membership)
                || $membership['actor_ref']!==$session['actor_ref']
                || $membership['organization_ref']!==$session['organization_ref']
                || $membership['status']!=='active'
                || $membership['permission']!=='campaigns:read'
                || (int)$membership['membership_revision']<1) {
                $this->db->rollBack();
                return null;
            }

            $serviceQuery=$this->db->prepare(
                'SELECT organization_ref,tenant_ref,service_ref,
                        contract_state,service_revision,recovery_epoch
                 FROM portal_perf_authority_services
                 WHERE organization_ref=? AND service_ref=?'
            );
            $serviceQuery->execute([
                $session['organization_ref'],$requestedService
            ]);
            $service=$serviceQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($service)
                || $service['organization_ref']!==$session['organization_ref']
                || $service['service_ref']!==$requestedService
                || $service['tenant_ref']!=='perf-demo-a'
                || $service['contract_state']!=='active_mock'
                || (int)$service['service_revision']<1
                || (int)$service['recovery_epoch']<1) {
                $this->db->rollBack();
                return null;
            }

            $facts=new PortalPerfAuthorityFacts(
                organizationRef:$session['organization_ref'],
                tenantRef:$service['tenant_ref'],
                serviceRef:$service['service_ref'],
                actorRef:$session['actor_ref'],
                sessionRef:$session['session_ref'],
                permission:$membership['permission'],
                membershipRevision:(int)$membership['membership_revision'],
                serviceRevision:(int)$service['service_revision'],
                sessionRevision:(int)$session['account_revision'],
                recoveryEpoch:(int)$service['recovery_epoch'],
                sourceObservedAt:$nowEpoch,
            );
            $this->db->commit();
            return $facts;
        }catch(Throwable){
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return null;
        }
    }

    private static function validTimestamp(mixed $raw,int $now,bool $issued): bool
    {
        if (!is_string($raw) || $raw==='') {
            return false;
        }
        try {
            $seconds=(new DateTimeImmutable($raw))->getTimestamp();
        } catch(Throwable) {
            return false;
        }
        // Issued at most 1 day ago, expires in the future, and a fixture
        // session lease may not be kept active beyond one day.
        return $issued
            ? $seconds<=$now && $seconds>=$now-86400
            : $seconds>$now && $seconds<=$now+86400;
    }

    private static function freshMfa(mixed $raw,int $now): bool
    {
        if (!is_string($raw) || $raw==='') {
            return false;
        }
        try {
            $timestamp=(new DateTimeImmutable($raw))->getTimestamp();
        } catch(Throwable) {
            return false;
        }
        return $timestamp<=$now && $timestamp>=$now-600;
    }
}
