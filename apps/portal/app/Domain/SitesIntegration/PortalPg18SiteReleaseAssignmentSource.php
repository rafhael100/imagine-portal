<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use PDO;
use Throwable;

/**
 * F1-398: PostgreSQL18, read-only, tenant-scoped release-binding source.
 *
 * This class is deliberately NOT registered in Laravel/HTTP/service-provider.
 * It does not assign Sites, edit projects or authorize deployment.
 *
 * An operational caller must derive tenantRef AND a signed, current tenant MAC
 * from its authenticated S2S/IAM context outside user input. Never accept
 * either from browser, payment/webhook JSON or an unverified Portal claim.
 */
final readonly class PortalPg18SiteReleaseAssignmentSource
    implements PortalCurrentSiteReleaseAssignmentSource
{
    public function __construct(
        private PDO $db,
        private string $tenantRef,
        #[\SensitiveParameter] private string $tenantMac,
    ) {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' ||
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$tenantRef)!==1 ||
            preg_match('/\A[a-f0-9]{64}\z/D',$tenantMac)!==1) {
            throw new DomainException('Trusted Sites assignment context unavailable.');
        }
        try {
            $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
            $q=$db->query(
                "SELECT r.rolsuper,r.rolbypassrls,
                        c.relrowsecurity,c.relforcerowsecurity
                   FROM pg_roles r
                   JOIN pg_class c ON c.oid='portal_release.binding_history'::regclass
                  WHERE r.rolname=session_user AND current_user=session_user
                    AND session_user='portal_release_reader'"
            );
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) ||
                self::isTrue($row['rolsuper']??null) ||
                self::isTrue($row['rolbypassrls']??null) ||
                !self::isTrue($row['relrowsecurity']??null) ||
                !self::isTrue($row['relforcerowsecurity']??null)) {
                throw new DomainException('Restricted reader and FORCE RLS required.');
            }
        } catch (Throwable) {
            throw new DomainException('Restricted reader and FORCE RLS required.');
        }
    }

    public function readCurrent(
        string $orderRef,string $siteRef,string $releaseRef,int $editorialRevision
    ): ?PortalSitesReleaseAssignment {
        foreach ([$orderRef,$siteRef,$releaseRef] as $value) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$value)!==1) {
                return null;
            }
        }
        if ($editorialRevision<1 || $this->db->inTransaction()) return null;
        try {
            $this->db->beginTransaction();
            $scope=$this->db->prepare(
                "SELECT set_config('imagine.release_tenant',?,true),
                        set_config('imagine.release_mac',?,true)"
            );
            $scope->execute([$this->tenantRef,$this->tenantMac]);
            // Select latest version for the ORDER, not merely the latest
            // matching release. Replaced/revoked versions must stay denied.
            $stmt=$this->db->prepare(
                "SELECT tenant_ref,order_ref,contract_ref,contract_revision,
                        product_code,site_ref,release_ref,artifact_digest,
                        editorial_revision,binding_revision,binding_state
                   FROM portal_release.binding_history
                  WHERE tenant_ref=? AND order_ref=?
                  ORDER BY binding_revision DESC LIMIT 1"
            );
            $stmt->execute([$this->tenantRef,$orderRef]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            $this->db->commit();
            if (!is_array($row) ||
                $row['binding_state']!=='active' ||
                $row['tenant_ref']!==$this->tenantRef ||
                $row['order_ref']!==$orderRef ||
                $row['site_ref']!==$siteRef ||
                $row['release_ref']!==$releaseRef ||
                (int)$row['editorial_revision']!==$editorialRevision) {
                return null;
            }
            return new PortalSitesReleaseAssignment(
                (string)$row['tenant_ref'],(string)$row['order_ref'],
                (string)$row['contract_ref'],(int)$row['contract_revision'],
                (string)$row['product_code'],(string)$row['site_ref'],
                (string)$row['release_ref'],(string)$row['artifact_digest'],
                (int)$row['editorial_revision'],(int)$row['binding_revision']
            );
        } catch (Throwable) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return null;
        }
    }

    private static function isTrue(mixed $value):bool
    {
        return in_array($value,[true,1,'1','t'],true);
    }
}
