<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Identity\EncryptedPostgresPortalSessionFactory;
use Illuminate\Encryption\Encrypter;
use Illuminate\Session\SessionManager;
use RuntimeException;
use Tests\TestCase;

final class EncryptedPostgresPortalSessionFactoryTest extends TestCase
{
    public function test_manager_rebinding_cannot_recurse_into_driver_while_resolving_factory(): void
    {
        $calls = 0;
        $createConnection = function () use (&$calls): object {
            $calls++;
            // Fails before any network operation; never tries to fetch a PDO.
            return new \stdClass();
        };
        $manager = new SessionManager($this->app);
        $manager->extend('portal_pg_lab_only', EncryptedPostgresPortalSessionFactory::creator(
            $createConnection,
            'portal_browser_lab',
            new Encrypter(random_bytes(32), 'AES-256-CBC'),
        ));
        try {
            $manager->driver('portal_pg_lab_only');
            self::fail('Invalid Portal session connection was accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('Dedicated Portal session PDO required.', $error->getMessage());
        }
        // An accidental $this->other() in the creator would recurse through
        // SessionManager::__call and never reach this assertion.
        self::assertSame(1, $calls);
        self::assertSame([], $manager->getDrivers());
    }

    public function test_factory_rejects_implicit_database_and_unbounded_wait(): void
    {
        $connection = static fn(): object => new \stdClass();
        $cipher = new Encrypter(random_bytes(32), 'AES-256-CBC');
        foreach (['', 'portal-browser', str_repeat('x', 64), 'portal_browser_lab;drop'] as $name) {
            try {
                EncryptedPostgresPortalSessionFactory::creator($connection, $name, $cipher);
                self::fail('Unsafe database name accepted.');
            } catch (RuntimeException $error) {
                self::assertSame('Explicit Portal session database required.', $error->getMessage());
            }
        }
        foreach ([0, 99, 5001, 100000] as $wait) {
            try {
                EncryptedPostgresPortalSessionFactory::creator($connection, 'portal_browser_lab', $cipher, $wait);
                self::fail('Unbounded session wait accepted.');
            } catch (RuntimeException $error) {
                self::assertSame('Bounded session lock wait required.', $error->getMessage());
            }
        }
    }
}
