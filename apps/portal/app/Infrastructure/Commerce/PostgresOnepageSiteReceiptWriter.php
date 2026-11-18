<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Domain\SitesIntegration\PortalSitesPendingReceiptValidator;
use DomainException;
use PDO;
use Throwable;

/**
 * Private, writer-only storage. The transport must first independently
 * authenticate the real Sites receiver. This class is NOT an HTTP route.
 */
final readonly class PostgresOnepageSiteReceiptWriter
{
    public function __construct(private PDO $database, private string $expectedDatabase)
    {
        if (!preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D',$expectedDatabase)) {
            throw new DomainException('Private Site intake storage unavailable.');
        }
    }

    public function record(array $originalCommand, array $actualReceipt): array
    {
        $receipt=PortalSitesPendingReceiptValidator::validate($originalCommand,$actualReceipt);
        $this->assertRestrictedWriter();
        try {
            $q=$this->database->prepare('SELECT portal_onepage.record_pending_site_intake(?::jsonb)');
            $q->execute([json_encode($receipt,JSON_THROW_ON_ERROR)]);
            $data=$q->fetchColumn();
            if (!is_string($data)) throw new DomainException('Private Site intake storage unavailable.');
            $result=json_decode($data,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($result) || $result === [] || !is_string($result['status'] ?? null)) {
                throw new DomainException('Private Site intake storage unavailable.');
            }
            if (in_array($result['status'],['denied','conflict'],true)) {
                throw new DomainException('Private Site intake storage unavailable.');
            }
            // Validation has strict type checks; compare sorted keys to account
            // for PostgreSQL JSONB canonical object key ordering.
            PortalSitesPendingReceiptValidator::validate($originalCommand,$result);
            $left=$result;$right=$receipt;ksort($left);ksort($right);
            if ($left !== $right) throw new DomainException('Private Site intake storage unavailable.');
            // Return the source's validated wire-order representation, not JSONB's key order.
            return $receipt;
        } catch (Throwable) {
            throw new DomainException('Private Site intake storage unavailable.');
        }
    }

    private function assertRestrictedWriter(): void
    {
        try {
            if ($this->database->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql'
                || $this->database->inTransaction()) {
                throw new DomainException('Private Site intake storage unavailable.');
            }
            $row=$this->database->query("SELECT current_database() db,session_user login,current_user actor,
                rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
                OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) unsafe
                FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['db']!==$this->expectedDatabase
                || $row['login']!=='portal_onepage_intake_recorder'
                || $row['actor']!=='portal_onepage_intake_recorder'
                || !in_array($row['unsafe'],[false,'f',0,'0'],true)) {
                throw new DomainException('Private Site intake storage unavailable.');
            }
        } catch (Throwable) {
            throw new DomainException('Private Site intake storage unavailable.');
        }
    }
}
