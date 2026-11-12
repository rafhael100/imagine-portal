<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SiteIntake\SiteEngineSyntheticEnvelopePreview;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

final class SiteEngineSyntheticEnvelopePreviewTest extends TestCase
{
    private static function canonical():string
    {
        return json_encode([
            'schemaVersion'=>'1',
            'operationId'=>'OP123',
            'tenantRef'=>'TEN-1',
            'orderRef'=>'ORD-1',
            'contractRef'=>'CON-1',
            'contractRevision'=>1,
            'productCode'=>'onepage',
            'idempotencyKey'=>'IDEMP-1',
        ],JSON_THROW_ON_ERROR);
    }

    public function test_exact_cross_repo_sites_f1_11_synthetic_hmac_vector():void
    {
        $key='synthetic-32-byte-key-example-only!';
        $json=SiteEngineSyntheticEnvelopePreview::compose(
            self::canonical(),$key,'NONCE-ABC-123',1700000000,1700000060
        );
        $env=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        self::assertSame([
            'schemaVersion','issuer','audience','issuedAt','expiresAt','nonce','payload','mac'
        ],array_keys($env));
        self::assertSame('imagine-portal',$env['issuer']);
        self::assertSame('imagine-sites',$env['audience']);
        self::assertSame(60,$env['expiresAt']-$env['issuedAt']);
        self::assertSame(
            'f13c615d17965f0bfdf0b09ffe92ad336658a8223ef6dcae531694dc09210b2c',
            $env['mac'],
            'Golden HMAC matches imagine-sites F1-11 JSON wire encoding.'
        );
        $unsigned=array_slice($env,0,-1,true);
        self::assertSame($env['mac'],hash_hmac('sha256',json_encode(
            $unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
        ),$key));
        self::assertSame($json,json_encode($env,
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    public function test_noncanonical_payload_short_key_nonce_and_ttl_are_denied():void
    {
        $valid=self::canonical();
        foreach([
            [str_replace('"operationId":"OP123",','', $valid),
                'synthetic-32-byte-key-example-only!','NONCE-ABC-123',1700000000,1700000060],
            [$valid,'weak-key','NONCE-ABC-123',1700000000,1700000060],
            [$valid,'synthetic-32-byte-key-example-only!','short',1700000000,1700000060],
            [$valid,'synthetic-32-byte-key-example-only!','NONCE-ABC-123',1700000000,1700000121],
            [$valid,'synthetic-32-byte-key-example-only!','NONCE-ABC-123',1700000000,1700000000]
        ] as $case) {
            try {
                SiteEngineSyntheticEnvelopePreview::compose(...$case);
                self::fail('Invalid synthetic envelope configuration accepted.');
            } catch(InvalidArgumentException $e) {
                self::assertNotSame('',$e->getMessage());
            }
        }
    }

    public function test_synthetic_hmac_never_grants_contract_approval_or_dispatch():void
    {
        self::assertFalse(SiteEngineSyntheticEnvelopePreview::mayDispatch());
        self::assertFalse(SiteEngineSyntheticEnvelopePreview::provesAuthority());
        self::assertFalse(SiteEngineSyntheticEnvelopePreview::mayRegisterOwnership());
        $method=new \ReflectionMethod(SiteEngineSyntheticEnvelopePreview::class,'compose');
        self::assertCount(1,$method->getParameters()[1]->getAttributes(SensitiveParameter::class));
    }
}
