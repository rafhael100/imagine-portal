<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Domain\PaymentRouting\C6SandboxCredentialPreflight;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class C6SandboxCredentialPreflightTest extends TestCase
{
    private string $dir;
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/c6-sbx-fixture-'.bin2hex(random_bytes(8));
        mkdir($this->dir,0700);
        chmod($this->dir,0700);
        file_put_contents($this->dir.'/client_id','synthetic-sandbox-client-id');
        file_put_contents($this->dir.'/client_secret','synthetic-sandbox-client-secret');
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
        self::assertNotFalse($key);
        $csr=openssl_csr_new(['commonName'=>'synthetic.local'], $key);
        self::assertNotFalse($csr);
        $crt=openssl_csr_sign($csr,null,$key,1);
        self::assertNotFalse($crt);
        self::assertTrue(openssl_x509_export($crt,$certPem));
        self::assertTrue(openssl_pkey_export($key,$keyPem));
        file_put_contents($this->dir.'/client.crt',$certPem);
        file_put_contents($this->dir.'/client.key',$keyPem);
        foreach (['client_id','client_secret','client.crt','client.key'] as $file) chmod($this->dir.'/'.$file,0600);
    }
    protected function tearDown(): void
    {
        foreach (['client_id','client_secret','client.crt','client.key','client.p12','client_p12_passphrase'] as $file) {
            $p=$this->dir.'/'.$file;
            if (is_file($p)||is_link($p)) unlink($p);
        }
        if (is_dir($this->dir)) rmdir($this->dir);
        parent::tearDown();
    }
    public function test_valid_synthetic_pair_never_authorizes_bank_call(): void
    {
        $r=C6SandboxCredentialPreflight::inspect($this->dir);
        self::assertTrue($r['ready']);
        self::assertFalse($r['can_charge']);
        self::assertFalse($r['can_activate']);
        self::assertFalse($r['network_checked']);
        self::assertArrayNotHasKey('client_secret',$r);
    }
    public function test_world_readable_private_key_is_rejected(): void
    {
        chmod($this->dir.'/client.key',0644);
        $this->expectException(RuntimeException::class);
        C6SandboxCredentialPreflight::inspect($this->dir);
    }
    public function test_symlink_secret_is_rejected(): void
    {
        unlink($this->dir.'/client_secret');
        symlink($this->dir.'/client_id',$this->dir.'/client_secret');
        $this->expectException(RuntimeException::class);
        C6SandboxCredentialPreflight::inspect($this->dir);
    }
    public function test_wrong_private_key_is_rejected(): void
    {
        $other=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
        openssl_pkey_export($other,$pem);
        file_put_contents($this->dir.'/client.key',$pem);
        chmod($this->dir.'/client.key',0600);
        $this->expectException(RuntimeException::class);
        C6SandboxCredentialPreflight::inspect($this->dir);
    }
    public function test_open_directory_is_rejected(): void
    {
        chmod($this->dir,0755);
        $this->expectException(RuntimeException::class);
        C6SandboxCredentialPreflight::inspect($this->dir);
    }
    public function test_unexpected_hidden_backup_is_rejected(): void
    {
        file_put_contents($this->dir.'/.old-client-secret', 'synthetic-backup');
        try {
            $this->expectException(RuntimeException::class);
            C6SandboxCredentialPreflight::inspect($this->dir);
        } finally {
            unlink($this->dir.'/.old-client-secret');
        }
    }

    private function convertFixtureToP12(): void
    {
        $cert = openssl_x509_read(file_get_contents($this->dir.'/client.crt'));
        $key = openssl_pkey_get_private(file_get_contents($this->dir.'/client.key'));
        self::assertNotFalse($cert);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkcs12_export($cert, $binary, $key, 'synthetic-test-p12-password'));
        unlink($this->dir.'/client.crt');
        unlink($this->dir.'/client.key');
        file_put_contents($this->dir.'/client.p12', $binary);
        file_put_contents($this->dir.'/client_p12_passphrase', 'synthetic-test-p12-password');
        chmod($this->dir.'/client.p12', 0600);
        chmod($this->dir.'/client_p12_passphrase', 0600);
    }

    public function test_restricted_p12_is_validated_without_exporting_key(): void
    {
        $this->convertFixtureToP12();
        $result=C6SandboxCredentialPreflight::inspect($this->dir);
        self::assertSame('p12', $result['certificate_format']);
        self::assertFalse($result['network_checked']);
        self::assertFalse($result['can_charge']);
    }

    public function test_wrong_p12_passphrase_fails_closed(): void
    {
        $this->convertFixtureToP12();
        file_put_contents($this->dir.'/client_p12_passphrase', 'incorrect-passphrase');
        $this->expectException(RuntimeException::class);
        C6SandboxCredentialPreflight::inspect($this->dir);
    }

    public function test_mixed_pem_and_p12_fails_closed(): void
    {
        $this->convertFixtureToP12();
        file_put_contents($this->dir.'/client.crt', 'invalid-pem');
        chmod($this->dir.'/client.crt', 0600);
        $this->expectException(RuntimeException::class);
        C6SandboxCredentialPreflight::inspect($this->dir);
    }

}
