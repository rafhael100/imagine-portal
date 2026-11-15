<?php
declare(strict_types=1);

namespace App\Labs\PerformanceV7Pg18;

use PDO;
use Throwable;

/**
 * Disposable PostgreSQL18 CI ONLY: short-lived receipt linking an exact
 * accepted plan/terms fingerprint to the same verified session/organization.
 *
 * A CLI test "registering presentation" does NOT prove HTML rendering,
 * customer understanding, clicking, contractual assent or real IAM.
 * Never connect this to Laravel routes without AppSec/DBA/LGPD review.
 */
final class PortalV7SqlTermsPresentationReceiptLab
{
    public function __construct(private readonly PDO $db) {}

    public function register(
        #[\SensitiveParameter] string $opaqueSession,
        string $tenant, string $agreement, string $period,
        string $planHash, string $termsHash,
        int $agreementRevision, int $previousConsentRevision
    ): ?string {
        if (!$this->ready() || !$this->valid(
            $opaqueSession,$tenant,$agreement,$period,$planHash,$termsHash,
            $agreementRevision
        ) || $previousConsentRevision<0) return null;
        try {
            $st=$this->db->prepare(
                'SELECT public.portal_v7_terms_register_presentation_lab(
                  :session,:tenant,:agreement,:period,:plan,:terms,
                  CAST(:agreement_rev AS bigint),
                  CAST(:previous_rev AS bigint))'
            );
            $st->execute([
                'session'=>hash('sha256',$opaqueSession),
                'tenant'=>$tenant,'agreement'=>$agreement,'period'=>$period,
                'plan'=>$planHash,'terms'=>$termsHash,
                'agreement_rev'=>$agreementRevision,
                'previous_rev'=>$previousConsentRevision,
            ]);
            $id=$st->fetchColumn();
            return is_string($id) &&
                preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D',$id)
                ? $id:null;
        }catch(Throwable){return null;}
    }

    public function accept(
        #[\SensitiveParameter] string $opaqueSession,
        string $challenge,
        string $tenant, string $agreement, string $period,
        string $planHash, string $termsHash,
        int $agreementRevision,int $previousConsentRevision,
        bool $expressConfirmation
    ):int {
        if (!$this->ready() || !$this->valid(
            $opaqueSession,$tenant,$agreement,$period,$planHash,$termsHash,
            $agreementRevision
        ) || $previousConsentRevision<0 || !$expressConfirmation
            || !preg_match(
                '/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D',
                $challenge
            ))return 0;
        try{
            $st=$this->db->prepare(
                'SELECT public.portal_v7_terms_accept_with_receipt_lab(
                  :session,CAST(:challenge AS uuid),
                  :tenant,:agreement,:period,:plan,:terms,
                  CAST(:agreement_rev AS bigint),
                  CAST(:previous_rev AS bigint),
                  CAST(:confirmed AS boolean))'
            );
            $st->execute([
                'session'=>hash('sha256',$opaqueSession),
                'challenge'=>$challenge,
                'tenant'=>$tenant,'agreement'=>$agreement,'period'=>$period,
                'plan'=>$planHash,'terms'=>$termsHash,
                'agreement_rev'=>$agreementRevision,
                'previous_rev'=>$previousConsentRevision,
                'confirmed'=>'true',
            ]);
            $value=$st->fetchColumn();
            return is_numeric($value)&& (int)$value>0?(int)$value:0;
        }catch(Throwable){return 0;}
    }

    public function isCurrent(
        #[\SensitiveParameter] string $opaqueSession,
        string $tenant, string $agreement, string $period,
        string $planHash, string $termsHash,
        int $agreementRevision,int $expectedConsentRevision
    ):bool {
        if(!$this->ready() || !$this->valid(
            $opaqueSession,$tenant,$agreement,$period,$planHash,$termsHash,
            $agreementRevision
        ) || $expectedConsentRevision<1)return false;
        try{
            $st=$this->db->prepare(
                'SELECT public.portal_v7_terms_current_with_receipt_lab(
                  :session,:tenant,:agreement,:period,:plan,:terms,
                  CAST(:agreement_rev AS bigint),
                  CAST(:consent_rev AS bigint))'
            );
            $st->execute([
                'session'=>hash('sha256',$opaqueSession),
                'tenant'=>$tenant,'agreement'=>$agreement,'period'=>$period,
                'plan'=>$planHash,'terms'=>$termsHash,
                'agreement_rev'=>$agreementRevision,
                'consent_rev'=>$expectedConsentRevision,
            ]);
            return in_array($st->fetchColumn(),[true,1,'1','t'],true);
        }catch(Throwable){return false;}
    }

    private function ready():bool{
        if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
          || getenv('PORTAL_PERF_V7_PG18_IDENTITY_LAB')!=='1'
          || getenv('PORTAL_PERF_V7_EXPRESS_CONSENT_LAB')!=='1'
          || getenv('PORTAL_PERF_V7_TERMS_PRESENTATION_LAB')!=='1'
          || getenv('PGHOST')!=='127.0.0.1'
          || getenv('PGPORT')!=='5432'
          || getenv('PGDATABASE')!=='portal_identity_lab')return false;
        try{
            if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql'
                ||$this->db->inTransaction())return false;
            $id=$this->db->query(
                'SELECT session_user AS role,current_database() AS database'
            )->fetch(PDO::FETCH_ASSOC);
            return is_array($id)
                && $id['role']==='portal_v7_lab_reader'
                && $id['database']==='portal_identity_lab';
        }catch(Throwable){return false;}
    }

    private function valid(
        string $session,string $tenant,string $agreement,string $period,
        string $planHash,string $termsHash,int $revision
    ):bool{
        return $revision>0
            && preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D',$session)===1
            && preg_match('/\Atenant_[A-Za-z0-9_-]{8,64}\z/D',$tenant)===1
            && preg_match('/\Aagreement_[A-Za-z0-9_-]{8,64}\z/D',$agreement)===1
            && preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])\z/D',$period)===1
            && preg_match('/\A[a-f0-9]{64}\z/D',$planHash)===1
            && preg_match('/\A[a-f0-9]{64}\z/D',$termsHash)===1;
    }

    /** @return array<string,bool> */
    public static function limits():array{
        return [
            'synthetic_terms_registration_and_acceptance_joined_in_pg18'=>true,
            'actual_html_rendering_proven'=>false,
            'actual_customer_approval_proven'=>false,
            'real_iam_authenticated'=>false,
            'legal_contract_accepted'=>false,
            'billable'=>false,
            'ads_write_enabled'=>false,
        ];
    }
}
