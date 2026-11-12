<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Explicit, unregistered composition for a co-located private PostgreSQL.
 * Never derives a role, origin or connection option from an HTTP request.
 */
final class PortalBrowserPrivateRuntime
{
    public function __construct(
        #[SensitiveParameter] private readonly PortalBrowserSecretFile $configuration,
    ) {
        // This candidate deliberately supports only same-namespace loopback.
        // Remote PostgreSQL requires a separate verified TLS configuration.
        if ($configuration->value('host') !== '127.0.0.1') {
            throw new RuntimeException('Private loopback Portal database required.');
        }
    }

    public function authentication(): PostgresPortalBrowserAuthentication
    {
        try {
            return new PostgresPortalBrowserAuthentication(
                $this->connect('portal_browser_runtime', 'identity_password'),
                $this->configuration->value('database'),
                $this->configuration->value('proof_key'),
                base64_decode($this->configuration->value('totp_key'), true),
                $this->configuration->value('scope_pepper'),
            );
        } catch (Throwable) {
            // Do not chain a PDO exception: it can contain endpoint diagnostics.
            throw new RuntimeException('Private Portal authentication unavailable.');
        }
    }

    public function sessionCreator(Encrypter $cipher, int $lockWaitMilliseconds = 2000): Closure
    {
        return EncryptedPostgresPortalSessionFactory::creator(
            fn (): PDO => $this->connect('portal_browser_envelope_runtime', 'envelope_password'),
            $this->configuration->value('database'),
            $cipher,
            $lockWaitMilliseconds,
        );
    }

    private function connect(string $role, string $passwordField): PDO
    {
        try {
            // Password stays out of the DSN; role and all options are fixed.
            // sslmode=disable is confined to same-namespace 127.0.0.1.
            return new PDO(
                'pgsql:host=127.0.0.1;port='.$this->configuration->value('port')
                    .';dbname='.$this->configuration->value('database')
                    .';connect_timeout=3;sslmode=disable'
                    .';options=--statement_timeout=5000 --lock_timeout=2000 --idle_in_transaction_session_timeout=30000',
                $role,
                $this->configuration->value($passwordField),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                ],
            );
        } catch (Throwable) {
            throw new RuntimeException('Private Portal database unavailable.');
        }
    }

    public function __debugInfo(): array
    {
        return ['runtime' => 'Portal private loopback', 'configuration' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new RuntimeException('Private Portal runtime cannot be serialized.');
    }

    private function __clone(): void {}
}
