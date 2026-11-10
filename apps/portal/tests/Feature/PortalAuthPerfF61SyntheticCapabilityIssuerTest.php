<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Labs\AuthPerfF61\SyntheticCapabilityIssuer;
use Tests\TestCase;

final class PortalAuthPerfF61SyntheticCapabilityIssuerTest extends TestCase
{
    private function labFlag(bool $enabled): void
    {
        putenv('PORTAL_PERF_SIGNED_LAB='.($enabled?'1':'0'));
    }

    public function test_signs_short_lived_real_ed25519_envelope_only_in_cli_testing(): void
    {
        $this->labFlag(true);
        $keyPair=sodium_crypto_sign_keypair();
        $secret=sodium_crypto_sign_secretkey($keyPair);
        $public=sodium_crypto_sign_publickey($keyPair);
        $now=1800000000;
        $packet=SyntheticCapabilityIssuer::issueSynthetic($secret,$now);
        $this->assertIsString($packet);
        $message=json_decode($packet,true,16,JSON_THROW_ON_ERROR);
        $this->assertSame([
            'version','key_id','payload','signature'
        ],array_keys($message));
        $this->assertSame('portal-capability-envelope.v1',$message['version']);
        $this->assertSame('ephemeral-lab-key-1',$message['key_id']);

        $decode=static fn(string $text):string=>(string)base64_decode(
            strtr($text,'-_','+/'),true
        );
        $rawPayload=$decode($message['payload']);
        $signature=$decode($message['signature']);
        $this->assertSame(SODIUM_CRYPTO_SIGN_BYTES,strlen($signature));
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            $signature,SyntheticCapabilityIssuer::DOMAIN_SEPARATOR.$rawPayload,$public
        ));

        $claim=json_decode($rawPayload,true,32,JSON_THROW_ON_ERROR);
        $this->assertSame('imagine.portal',$claim['issuer']);
        $this->assertSame('imagine.performance-ai',$claim['audience']);
        $this->assertSame('portal.capability.synthetic.v1',$claim['profile']);
        $this->assertSame('org-demo-a',$claim['organization_ref']);
        $this->assertSame('perf-demo-a',$claim['performance_tenant_ref']);
        $this->assertSame('svc-demo-a',$claim['portal_service_ref']);
        $this->assertSame('actor-demo-a',$claim['actor_ref']);
        $this->assertSame('session-demo-a',$claim['session_ref']);
        $this->assertSame('campaigns:read',$claim['permission']);
        $this->assertTrue($claim['session_mfa_verified']);
        $this->assertSame(20,$claim['expires_at']-$claim['issued_at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D',$claim['nonce']);
        $this->assertArrayNotHasKey('email',$claim);
        $this->assertArrayNotHasKey('private_key',$message);
        $this->assertArrayNotHasKey('access_token',$claim);
        $this->assertArrayNotHasKey('password',$claim);
        sodium_memzero($secret);
        sodium_memzero($keyPair);
    }

    public function test_issuer_defaults_to_closed_for_non_lab_and_wrong_secret_key(): void
    {
        putenv('APP_ENV=testing');
        $pair=sodium_crypto_sign_keypair();
        $key=sodium_crypto_sign_secretkey($pair);
        $this->labFlag(false);
        $this->assertNull(SyntheticCapabilityIssuer::issueSynthetic($key,1800000000));
        $this->assertNull(SyntheticCapabilityIssuer::claims(1800000000));
        $this->labFlag(true);
        $this->assertNull(SyntheticCapabilityIssuer::issueSynthetic('not-a-key',1800000000));
        $this->assertNull(SyntheticCapabilityIssuer::issueSynthetic($key,0));
        $this->assertNull(SyntheticCapabilityIssuer::issueSynthetic($key,2147483648));
        putenv('APP_ENV=production');
        $this->assertNull(SyntheticCapabilityIssuer::issueSynthetic($key,1800000000));
        putenv('APP_ENV=testing');
        $this->labFlag(false);
        sodium_memzero($key);
        sodium_memzero($pair);
    }

    public function test_every_signature_uses_fresh_nonce_and_separate_ephemeral_key(): void
    {
        putenv('APP_ENV=testing');
        $this->labFlag(true);
        $pair1=sodium_crypto_sign_keypair();
        $pair2=sodium_crypto_sign_keypair();
        $secret1=sodium_crypto_sign_secretkey($pair1);
        $secret2=sodium_crypto_sign_secretkey($pair2);
        $a=json_decode((string)SyntheticCapabilityIssuer::issueSynthetic($secret1,1800000000),true,16,JSON_THROW_ON_ERROR);
        $b=json_decode((string)SyntheticCapabilityIssuer::issueSynthetic($secret1,1800000000),true,16,JSON_THROW_ON_ERROR);
        $c=json_decode((string)SyntheticCapabilityIssuer::issueSynthetic($secret2,1800000000),true,16,JSON_THROW_ON_ERROR);
        $this->assertNotSame($a['payload'],$b['payload']);
        $this->assertNotSame($a['signature'],$b['signature']);
        $this->assertNotSame($a['signature'],$c['signature']);
        $this->assertNotSame(
            sodium_crypto_sign_publickey($pair1),
            sodium_crypto_sign_publickey($pair2)
        );
        $this->labFlag(false);
        sodium_memzero($secret1);
        sodium_memzero($secret2);
        sodium_memzero($pair1);
        sodium_memzero($pair2);
    }

    public function test_portal_preview_still_has_no_active_auth_endpoints(): void
    {
        $this->get('/api/performance/authorize')->assertNotFound();
        $this->post('/api/performance/authorize')->assertNotFound();
        $this->post('/api/performance/campaigns')->assertNotFound();
    }
}
