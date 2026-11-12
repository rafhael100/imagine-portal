<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use PDO;
use RuntimeException;

/**
 * A candidate Laravel session driver creator. It is NOT registered in config,
 * providers, routes or bootstrap. No provider credentials are read here.
 *
 * SessionManager::extend() rebinds the creator's $this to SessionManager.
 * Capture dependencies lexically: never look up a test/application object via
 * $this from the returned callback (Manager::__call may recurse into driver()).
 */
final class EncryptedPostgresPortalSessionFactory
{
    public static function creator(
        Closure $connect,
        string $database,
        Encrypter $cipher,
        int $lockWaitMilliseconds = 2000,
    ): Closure {
        if ($database === '' || strlen($database) > 63 || preg_match('/\\A[a-z][a-z0-9_]*\\z/D', $database) !== 1) {
            throw new RuntimeException('Explicit Portal session database required.');
        }
        if ($lockWaitMilliseconds < 100 || $lockWaitMilliseconds > 5000) {
            throw new RuntimeException('Bounded session lock wait required.');
        }

        // Intentionally not static: Laravel binds the callback to SessionManager.
        // The closure uses *only* captured arguments and can be rebound safely.
        return function () use ($connect, $database, $cipher, $lockWaitMilliseconds): EncryptedPostgresPortalSessionHandler {
            $connection = $connect();
            if (!$connection instanceof PDO) {
                throw new RuntimeException('Dedicated Portal session PDO required.');
            }
            return new EncryptedPostgresPortalSessionHandler(
                $connection,
                $database,
                $cipher,
                $lockWaitMilliseconds,
            );
        };
    }
}
