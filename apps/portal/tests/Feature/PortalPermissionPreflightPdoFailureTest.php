<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Identity\PostgresPortalPermissionPreflight;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/** F308: PDO connection checks are also untrusted and must never leak diagnostics. */
final class PortalPermissionPreflightPdoFailureTest extends TestCase
{
    public function test_driver_metadata_exception_is_redacted_before_any_sql_query():void
    {
        $pdo=new class extends PDO {
            public function __construct() {}
            public function getAttribute(int $attribute):mixed
            {
                throw new PDOException('PRIVATE_F308_DRIVER_DIAGNOSTIC');
            }
        };
        try {
            new PostgresPortalPermissionPreflight($pdo,'portal_browser_lab');
            self::fail('Broken driver metadata unexpectedly accepted.');
        } catch(RuntimeException $e) {
            self::assertSame('Portal permission preflight unavailable.',$e->getMessage());
            self::assertStringNotContainsString('PRIVATE_F308',$e->getMessage());
        }
    }

    public function test_transaction_probe_exception_is_redacted_before_any_sql_query():void
    {
        $pdo=new class extends PDO {
            public function __construct() {}
            public function getAttribute(int $attribute):mixed {return 'pgsql';}
            public function inTransaction():bool
            {
                throw new PDOException('PRIVATE_F308_TRANSACTION_DIAGNOSTIC');
            }
        };
        try {
            new PostgresPortalPermissionPreflight($pdo,'portal_browser_lab');
            self::fail('Broken transaction probe unexpectedly accepted.');
        } catch(RuntimeException $e) {
            self::assertSame('Portal permission preflight unavailable.',$e->getMessage());
            self::assertStringNotContainsString('PRIVATE_F308',$e->getMessage());
        }
    }
}
