<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Commerce\OnepagePrivateRuntime;
use App\Infrastructure\Identity\PortalBrowserSecretFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OnepagePrivateRuntimeTest extends TestCase
{
    private const ORIGIN = 'https://portal-dev.imaginecomunica.com.br';
    private string $directory;
    private string $path;
    private PortalBrowserSecretFile $identity;
    private array $identityValues;
    private array $values;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/onepage-runtime-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->path = $this->directory.'/onepage.json';
        $this->identityValues = ['origin' => self::ORIGIN, 'host' => '127.0.0.1', 'port' => 5432,
            'database' => 'portal_private', 'identity_password' => bin2hex(random_bytes(20)),
            'envelope_password' => bin2hex(random_bytes(20)), 'proof_key' => bin2hex(random_bytes(32)),
            'totp_key' => base64_encode(random_bytes(32)), 'scope_pepper' => bin2hex(random_bytes(32))];
        $identityPath = $this->directory.'/identity.json';
        file_put_contents($identityPath, json_encode($this->identityValues, JSON_THROW_ON_ERROR)); chmod($identityPath, 0600);
        $this->identity = PortalBrowserSecretFile::load($identityPath, self::ORIGIN);
        $this->values = ['origin' => self::ORIGIN, 'host' => '127.0.0.1', 'port' => 5432,
            'database' => 'portal_private', 'password' => bin2hex(random_bytes(20))];
        $this->write();
    }

    private function write(): void
    {
        file_put_contents($this->path, json_encode($this->values, JSON_THROW_ON_ERROR)); chmod($this->path, 0600);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) unlink($path);
        chmod($this->directory, 0700); rmdir($this->directory);
    }

    private function refuses(?string $path = null): void
    {
        try { OnepagePrivateRuntime::load($path ?? $this->path, $this->identity, self::ORIGIN); }
        catch (RuntimeException $error) {
            self::assertSame('Private Onepage configuration required.', $error->getMessage()); return;
        }
        self::fail('Unsafe runtime configuration was accepted.');
    }

    public function test_independent_private_credential_is_loaded_without_dumps_or_serialization(): void
    {
        $runtime = OnepagePrivateRuntime::load($this->path, $this->identity, self::ORIGIN);
        self::assertSame('portal_private', $runtime->databaseName());
        foreach ([var_export($runtime, true), print_r($runtime, true), json_encode($runtime, JSON_THROW_ON_ERROR)] as $dump) {
            self::assertStringNotContainsString($this->values['password'], $dump);
        }
        $this->expectException(RuntimeException::class); serialize($runtime);
    }

    public function test_connection_cannot_switch_host_database_port_origin_or_role(): void
    {
        $baseline = $this->values;
        foreach (['host' => 'database', 'database' => 'another_database', 'port' => 5433,
            'origin' => 'https://sibling.example.test', 'role' => 'postgres'] as $key => $value) {
            $this->values = $baseline; $this->values[$key] = $value; $this->write(); $this->refuses();
        }
        $this->values = $baseline; $this->values['port'] = '5432'; $this->write(); $this->refuses();
    }

    public function test_password_must_be_independent_of_native_identity_material(): void
    {
        foreach (['identity_password', 'envelope_password', 'proof_key', 'totp_key', 'scope_pepper'] as $key) {
            $this->values['password'] = $this->identityValues[$key]; $this->write(); $this->refuses();
        }
    }

    public function test_permissions_links_and_writable_parent_are_refused(): void
    {
        chmod($this->path, 0644); $this->refuses(); chmod($this->path, 0600);
        symlink($this->path, $this->directory.'/alias'); $this->refuses($this->directory.'/alias');
        link($this->path, $this->directory.'/hardlink'); $this->refuses(); unlink($this->directory.'/hardlink');
        chmod($this->directory, 0777); $this->refuses(); chmod($this->directory, 0700);
    }

    public function test_schema_bounds_and_missing_fields_are_refused(): void
    {
        file_put_contents($this->path, str_repeat('x', 4097)); $this->refuses();
        file_put_contents($this->path, '{broken'); $this->refuses();
        unset($this->values['password']); $this->write(); $this->refuses();
    }

    public function test_unserialization_cannot_forge_a_valid_runtime(): void
    {
        $class = OnepagePrivateRuntime::class;
        $this->expectException(RuntimeException::class);
        unserialize('O:'.strlen($class).':"'.$class.'":0:{}', ['allowed_classes' => [$class]]);
    }
}
