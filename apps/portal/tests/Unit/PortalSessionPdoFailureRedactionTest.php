<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Identity\EncryptedPostgresPortalSessionHandler;
use Illuminate\Encryption\Encrypter;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PortalSessionPdoFailureRedactionTest extends TestCase
{
    private static function denied(PDO $pdo): void
    {
        $cipher=new Encrypter(random_bytes(32),'AES-256-CBC');
        try {
            new EncryptedPostgresPortalSessionHandler($pdo,'portal_browser_lab',$cipher);
            self::fail('Unhealthy PDO was accepted for an encrypted session.');
        } catch(RuntimeException $error) {
            self::assertSame('Portal session storage unavailable.',$error->getMessage());
            self::assertStringNotContainsString('PRIVATE_F320',$error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function test_driver_metadata_outage_is_redacted(): void
    {
        $pdo=new class extends PDO {
            public function __construct() {}
            public function getAttribute(int $attribute):mixed {
                throw new PDOException('PRIVATE_F320_DRIVER_DIAGNOSTIC');
            }
        };
        self::denied($pdo);
    }

    public function test_transaction_probe_outage_is_redacted(): void
    {
        $pdo=new class extends PDO {
            public function __construct() {}
            public function getAttribute(int $attribute):mixed {return 'pgsql';}
            public function inTransaction():bool {
                throw new PDOException('PRIVATE_F320_TRANSACTION_DIAGNOSTIC');
            }
        };
        self::denied($pdo);
    }

    public function test_role_query_outage_is_redacted():void
    {
        $pdo=new class extends PDO {
            public function __construct() {}
            public function getAttribute(int $attribute):mixed {return 'pgsql';}
            public function inTransaction():bool {return false;}
            public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs):PDOStatement|false {
                throw new PDOException('PRIVATE_F320_ROLE_QUERY_DIAGNOSTIC');
            }
        };
        self::denied($pdo);
    }
}
