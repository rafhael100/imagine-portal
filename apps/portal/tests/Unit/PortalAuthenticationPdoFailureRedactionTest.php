<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PortalAuthenticationPdoFailureRedactionTest extends TestCase
{
    private static function denied(PDO $pdo): void
    {
        try {
            new PostgresPortalBrowserAuthentication(
                $pdo,'portal_browser_lab',str_repeat('a',64),random_bytes(32),str_repeat('b',64)
            );
            self::fail('Unhealthy PDO was accepted by Portal authentication.');
        } catch (RuntimeException $error) {
            self::assertSame('Portal authentication database unavailable.',$error->getMessage());
            self::assertStringNotContainsString('PRIVATE_F321',$error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function test_broken_driver_metadata_never_leaks_provider_details():void
    {
        $pdo=new class extends PDO {
            public function __construct() {}
            public function getAttribute(int $attribute):mixed {
                throw new PDOException('PRIVATE_F321_DRIVER_DIAGNOSTIC');
            }
        };
        self::denied($pdo);
    }

    public function test_broken_transaction_probe_never_leaks_provider_details():void
    {
        $pdo=new class extends PDO {
            public function __construct() {}
            public function getAttribute(int $attribute):mixed {return 'pgsql';}
            public function inTransaction():bool {
                throw new PDOException('PRIVATE_F321_TRANSACTION_DIAGNOSTIC');
            }
        };
        self::denied($pdo);
    }

    public function test_role_query_outage_stays_redacted_by_existing_executor():void
    {
        $pdo=new class extends PDO {
            public function __construct() {}
            public function getAttribute(int $attribute):mixed {return 'pgsql';}
            public function inTransaction():bool {return false;}
            public function prepare(string $query, array $options=[]):PDOStatement|false {
                throw new PDOException('PRIVATE_F321_ROLE_SQL_DIAGNOSTIC');
            }
        };
        self::denied($pdo);
    }
}
