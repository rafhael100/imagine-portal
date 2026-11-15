<?php
declare(strict_types=1);

namespace App\Labs\PerformanceV7Pg18;

use PDO;
use Throwable;

/**
 * Strictly CLI/testing laboratory. Reads the Portal F66/F76/F113 PostgreSQL
 * session+MFA+permission data through an EXECUTE-ONLY stored function, and an
 * independently seeded synthetic tenant/agreement/plan/terms association.
 *
 * The bearer is high-entropy and must come from first-party Portal session
 * transport. We use only synthetic fixtures here. No HTTP registration.
 */
final class PortalV7SqlAgreementAuthorityLab
{
    public function __construct(private readonly PDO $db) {}

    public function permits(
        #[\SensitiveParameter] string $opaqueSession,
        string $tenant,
        string $agreement,
        string $period,
        string $planSha256,
        string $termsSha256,
        int $expectedApprovalRevision
    ): bool {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_PERF_V7_PG18_IDENTITY_LAB') !== '1'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_identity_lab'
            || $expectedApprovalRevision < 1
            || !preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $opaqueSession)
            || !preg_match('/\Atenant_[A-Za-z0-9_-]{8,64}\z/D', $tenant)
            || !preg_match('/\Aagreement_[A-Za-z0-9_-]{8,64}\z/D', $agreement)
            || !preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])\z/D', $period)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $planSha256)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $termsSha256)) {
            return false;
        }
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
                || $this->db->inTransaction()) {
                return false;
            }
            $identity = $this->db->query(
                'SELECT current_database() AS db,session_user AS login'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identity)
                || $identity['db'] !== 'portal_identity_lab'
                || $identity['login'] !== 'portal_v7_lab_reader') {
                return false;
            }
            $stmt = $this->db->prepare(
                'SELECT public.portal_v7_agreement_preflight_lab(
                    :digest,:tenant,:agreement,:period,:plan,
                    :terms,CAST(:revision AS bigint))'
            );
            $stmt->execute([
                'digest' => hash('sha256', $opaqueSession),
                'tenant' => $tenant,
                'agreement' => $agreement,
                'period' => $period,
                'plan' => $planSha256,
                'terms' => $termsSha256,
                'revision' => $expectedApprovalRevision,
            ]);
            return in_array($stmt->fetchColumn(), [true, 1, '1', 't'], true);
        } catch (Throwable) {
            // Never log database diagnostics or sensitive bearer/plan details.
            return false;
        }
    }

    /** @return array<string,bool> */
    public static function limits():array
    {
        return [
            'portal_pg18_session_mfa_and_permission_checked_in_ci'=>true,
            'portal_real_customer_authenticated'=>false,
            'real_customer_acceptance_proven'=>false,
            'real_customer_consent_proven'=>false,
            'can_authorize_http'=>false,
            'can_charge'=>false,
            'can_activate_ads'=>false,
        ];
    }
}
