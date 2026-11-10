<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use App\Domain\SitesIntegration\SyntheticSitesVersionedEnvelopeSigner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SitesVersionedSignedEnvelopeParityTest extends TestCase
{
    private const KEY='DO-NOT-USE-SYNTHETIC-TEST-KEY-IN-PRODUCTION-123456789';

    private function command(): array
    {
        return json_decode(
            (string)file_get_contents(__DIR__.'/../Fixtures/sites-create-project-v1.synthetic.json'),
            true,16,JSON_THROW_ON_ERROR
        );
    }

    public function test_v2_retains_canonical_v1_command_and_authenticates_epoch(): void
    {
        $v1=SyntheticSitesEnvelopeSigner::sign(
            $this->command(),self::KEY,'portal-sites-versioned-01',1790000000,1790000060
        );
        $v2=SyntheticSitesVersionedEnvelopeSigner::sign(
            $this->command(),self::KEY,'portal-sites-versioned-01',1790000000,1790000060,7
        );
        self::assertSame([
            'schemaVersion','issuer','audience','issuedAt','expiresAt',
            'nonce','payload','keyEpoch','mac'
        ],array_keys($v2));
        self::assertSame('2',$v2['schemaVersion']);
        self::assertSame(7,$v2['keyEpoch']);
        self::assertSame($v1['payload'],$v2['payload']);

        $unsigned=$v2;
        unset($unsigned['mac']);
        $canonical=json_encode(
            $unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
        );
        self::assertSame(hash_hmac('sha256',$canonical,self::KEY),$v2['mac']);
        self::assertNotSame($v1['mac'],$v2['mac']);
        self::assertStringNotContainsString(self::KEY,(string)json_encode($v2));
    }

    public function test_new_key_generation_produces_different_mac_even_if_key_bytes_are_equal(): void
    {
        $old=SyntheticSitesVersionedEnvelopeSigner::sign(
            $this->command(),self::KEY,'portal-sites-versioned-02',1790000000,1790000060,2
        );
        $new=SyntheticSitesVersionedEnvelopeSigner::sign(
            $this->command(),self::KEY,'portal-sites-versioned-02',1790000000,1790000060,3
        );
        self::assertSame($old['payload'],$new['payload']);
        self::assertNotSame($old['mac'],$new['mac']);
        self::assertSame(2,$old['keyEpoch']);
        self::assertSame(3,$new['keyEpoch']);
    }

    public function test_invalid_epoch_and_untrusted_payload_are_denied(): void
    {
        foreach ([0,-1] as $epoch) {
            try {
                SyntheticSitesVersionedEnvelopeSigner::sign(
                    $this->command(),self::KEY,'portal-sites-versioned-03',1790000000,1790000060,$epoch
                );
                self::fail('Invalid epoch must not sign.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $bad=$this->command();
        $bad['tenantRef']='../../other-account';
        $this->expectException(InvalidArgumentException::class);
        SyntheticSitesVersionedEnvelopeSigner::sign(
            $bad,self::KEY,'portal-sites-versioned-04',1790000000,1790000060,4
        );
    }
}
