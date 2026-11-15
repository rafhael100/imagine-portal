<?php
declare(strict_types=1);

namespace App\Labs\PerformanceV7Pg18;

use PDO;
use Throwable;

/**
 * F830: CI-only, user-action-shaped acceptance/revocation with append-only
 * PostgreSQL18 events. The event actor comes from the SQL verified Portal
 * session, never an actor_id supplied by PHP/HTTP/CRM.
 *
 * These are synthetic fixtures. An explicit boolean passed in CI is NOT
 * proof of actual customer assent, legal terms presentation or production IAM.
 */
final class PortalV7SqlExpressConsentLab
{
    public function __construct(private readonly PDO $db) {}

    public function record(
        #[\SensitiveParameter] string $opaqueSession,
        string $tenant,
        string $agreement,
        string $period,
        string $planSha256,
        string $termsSha256,
        int $agreementRevision,
        int $expectedEventRevision,
        string $action,
        bool $expresslyConfirmed
    ): int {
        if (!$this->ready()
            || !$this->parametersValid(
                $opaqueSession,$tenant,$agreement,$period,
                $planSha256,$termsSha256,$agreementRevision
            )
            || $expectedEventRevision < 0
            || !in_array($action, ['synthetic_accepted','synthetic_revoked'], true)
            || !$expresslyConfirmed) {
            return 0;
        }
        try {
            $stmt=$this->db->prepare(
                'SELECT public.portal_v7_consent_record_lab(
                    :session,:tenant,:agreement,:period,:plan,:terms,
                    CAST(:agreement_revision AS bigint),
                    CAST(:event_revision AS bigint),:action,:express)'
            );
            $stmt->execute([
                'session'=>hash('sha256',$opaqueSession),
                'tenant'=>$tenant,'agreement'=>$agreement,'period'=>$period,
                'plan'=>$planSha256,'terms'=>$termsSha256,
                'agreement_revision'=>$agreementRevision,
                'event_revision'=>$expectedEventRevision,
                'action'=>$action,'express'=>'true',
            ]);
            $revision=$stmt->fetchColumn();
            return is_numeric($revision) && (int)$revision>0
                ?(int)$revision:0;
        }catch(Throwable) {
            return 0;
        }
    }

    public function current(
        #[\SensitiveParameter] string $opaqueSession,
        string $tenant,
        string $agreement,
        string $period,
        string $planSha256,
        string $termsSha256,
        int $agreementRevision,
        int $expectedEventRevision
    ): bool {
        if (!$this->ready()
            || !$this->parametersValid(
                $opaqueSession,$tenant,$agreement,$period,
                $planSha256,$termsSha256,$agreementRevision
            )
            || $expectedEventRevision < 1) {
            return false;
        }
        try {
            $stmt=$this->db->prepare(
                'SELECT public.portal_v7_consent_current_lab(
                    :session,:tenant,:agreement,:period,:plan,:terms,
                    CAST(:agreement_revision AS bigint),
                    CAST(:event_revision AS bigint))'
            );
            $stmt->execute([
                'session'=>hash('sha256',$opaqueSession),
                'tenant'=>$tenant,'agreement'=>$agreement,'period'=>$period,
                'plan'=>$planSha256,'terms'=>$termsSha256,
                'agreement_revision'=>$agreementRevision,
                'event_revision'=>$expectedEventRevision,
            ]);
            return in_array($stmt->fetchColumn(),[true,1,'1','t'],true);
        }catch(Throwable) {
            return false;
        }
    }

    private function ready(): bool
    {
        if(PHP_SAPI!=='cli'
            || getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_PERF_V7_PG18_IDENTITY_LAB')!=='1'
            || getenv('PORTAL_PERF_V7_EXPRESS_CONSENT_LAB')!=='1'
            || getenv('PGHOST')!=='127.0.0.1'
            || getenv('PGPORT')!=='5432'
            || getenv('PGDATABASE')!=='portal_identity_lab') {
            return false;
        }
        try {
            if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql'
                || $this->db->inTransaction())return false;
            $role=$this->db->query(
                'SELECT session_user AS login,current_database() AS db'
            )->fetch(PDO::FETCH_ASSOC);
            return is_array($role)
                && $role['login']==='portal_v7_lab_reader'
                && $role['db']==='portal_identity_lab';
        }catch(Throwable){
            return false;
        }
    }

    private function parametersValid(
        string $session,string $tenant,string $agreement,string $period,
        string $plan,string $terms,int $agreementRevision
    ):bool {
        return preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D',$session)===1
            && preg_match('/\Atenant_[A-Za-z0-9_-]{8,64}\z/D',$tenant)===1
            && preg_match('/\Aagreement_[A-Za-z0-9_-]{8,64}\z/D',$agreement)===1
            && preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])\z/D',$period)===1
            && preg_match('/\A[a-f0-9]{64}\z/D',$plan)===1
            && preg_match('/\A[a-f0-9]{64}\z/D',$terms)===1
            && $agreementRevision>0;
    }

    /** @return array<string,bool> */
    public static function limits():array
    {
        return [
            'database_actor_bound_to_verified_session_in_ci'=>true,
            'append_only_revisioned_consent_in_ci'=>true,
            'real_portal_customer_authenticated'=>false,
            'actual_customer_click_proven'=>false,
            'legal_customer_acceptance_proven'=>false,
            'billing_enabled'=>false,
            'ads_write_enabled'=>false,
        ];
    }
}
