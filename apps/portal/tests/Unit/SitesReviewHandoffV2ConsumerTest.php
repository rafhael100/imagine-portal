<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SitesIntegration\PortalReviewAuthoritySnapshot as Snapshot;
use App\Domain\SitesIntegration\PortalReviewAuthoritySource as Source;
use App\Domain\SitesIntegration\PortalReviewReplayFence as Fence;
use App\Domain\SitesIntegration\SyntheticSitesReviewEnvelopeConsumer as Consumer;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SitesReviewHandoffV2ConsumerTest extends TestCase
{
    private const KEY='IMAGINE-PORTAL-SITES-F1-12-DEMO-KEY-NOT-A-SECRET-00000';
    private const NOW=1790000030;

    private function signed():array
    {
        return json_decode(file_get_contents(
            __DIR__.'/../Fixtures/sites-review-handoff-v2.signed.synthetic.json'
        ),true,16,JSON_THROW_ON_ERROR);
    }

    private function source(?Snapshot $snapshot):Source
    {
        return new class($snapshot) implements Source {
            public bool $offline=false;
            public function __construct(public ?Snapshot $current) {}
            public function findCurrent(string $tenantRef,string $siteRef):?Snapshot
            {
                if($this->offline)throw new \RuntimeException('Portal unavailable');
                return $this->current;
            }
        };
    }

    private function snapshot(array $patch=[]):Snapshot
    {
        $base=[
            'tenantRef'=>'tenant-demo-sites-12',
            'siteRef'=>'site-demo-sites-12',
            'workflowRef'=>'workflow-demo-sites-12',
            'workflowRevision'=>3,
            'releaseRef'=>'release-demo-sites-12',
            'artifactDigest'=>str_repeat('a',64),
            'authorizationRevision'=>9,
            'status'=>'reviewable',
            'checkedAt'=>self::NOW-5,
            'validUntil'=>self::NOW+20
        ];
        return new Snapshot(...array_replace($base,$patch));
    }

    private function fence():Fence
    {
        return new class implements Fence {
            public array $used=[];
            public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
            {
                if($issuer!=='imagine-sites:review-handoff.v2')return false;
                $key=$issuer.':'.$nonce;
                if(isset($this->used[$key]))return false;
                $this->used[$key]=true;
                return true;
            }
        };
    }

    private function resign(array $wire):array
    {
        $wire['mac']=hash_hmac('sha256',json_encode(
            array_slice($wire,0,-1,true),
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
        ),self::KEY);
        return $wire;
    }

    public function test_exact_sites_v2_fixture_is_byte_compatible_with_portal_consumer():void
    {
        $wire=$this->signed();
        self::assertSame('9dffa7b0a93f2f10289c0f306cf6a913d70abd5741bb318cd4b4509397eacb6a',
            $wire['mac']);
        self::assertSame($wire['mac'],$this->resign($wire)['mac']);
        $fence=$this->fence();
        $receipt=Consumer::consume($wire,self::KEY,self::NOW,
            $this->source($this->snapshot()),$fence);
        self::assertSame('proposal_pending_client_review',$receipt['status']);
        self::assertSame($wire['candidate']['artifactDigest'],$receipt['artifactDigest']);
        self::assertSame($wire['candidate']['releaseRef'],$receipt['releaseRef']);
        self::assertSame(9,$wire['authorizationRevision']);
        self::assertFalse($receipt['officialClientAcceptance']);
        self::assertFalse($receipt['publishable']);
        self::assertTrue($receipt['syntheticOnly']);
        self::assertArrayNotHasKey('delivered',$receipt);
        $this->expectException(DomainException::class);
        Consumer::consume($wire,self::KEY,self::NOW,$this->source($this->snapshot()),$fence);
    }

    public function test_without_trusted_portal_authority_or_nonce_is_denied():void
    {
        $wire=$this->signed();
        foreach([
            fn()=>Consumer::consume($wire,self::KEY,self::NOW),
            fn()=>Consumer::consume($wire,self::KEY,self::NOW,null,$this->fence()),
            fn()=>Consumer::consume($wire,self::KEY,self::NOW,$this->source($this->snapshot())),
            fn()=>Consumer::consume($wire,'not-a-real-key',self::NOW,$this->source($this->snapshot()),$this->fence()),
        ] as $action) {
            try{$action();self::fail('Must deny without current trustworthy authority and replay.');}
            catch(DomainException|InvalidArgumentException){}
        }
    }

    public function test_valid_mac_does_not_override_revoked_or_changed_resource():void
    {
        $wire=$this->signed();
        foreach([
            ['tenantRef'=>'tenant-other'],
            ['siteRef'=>'site-other'],
            ['workflowRef'=>'workflow-other'],
            ['releaseRef'=>'release-other'],
            ['artifactDigest'=>str_repeat('b',64)],
            ['workflowRevision'=>4],
            ['authorizationRevision'=>10],
            ['status'=>'suspended'],
            ['status'=>'revoked'],
            ['validUntil'=>self::NOW],
            ['checkedAt'=>self::NOW+6,'validUntil'=>self::NOW+20],
        ] as $patch) {
            $fence=$this->fence();
            try{Consumer::consume($wire,self::KEY,self::NOW,
                $this->source($this->snapshot($patch)),$fence);
                self::fail('Current Portal state must deny: '.json_encode($patch));
            } catch(DomainException) {
                self::assertSame([], $fence->used, 'Authorization refusal cannot consume nonce.');
            }
        }
    }

    public function test_old_epoch_does_not_revive_after_revocation_and_reactivation():void
    {
        $wire=$this->signed();
        $revoked=$this->snapshot(['authorizationRevision'=>10,'status'=>'revoked']);
        $restored=$this->snapshot(['authorizationRevision'=>11,'status'=>'reviewable']);
        foreach([$revoked,$restored] as $current) {
            $fence=$this->fence();
            try{Consumer::consume($wire,self::KEY,self::NOW,$this->source($current),$fence);
                self::fail('Epoch 9 cannot be accepted after epoch 10/11.');}
            catch(DomainException){self::assertSame([],$fence->used);}
        }
        $new=$wire;
        $new['authorizationRevision']=11;
        $new['nonce']='portal-receive-nonce-epoch-11';
        $new=$this->resign($new);
        $receipt=Consumer::consume($new,self::KEY,self::NOW,
            $this->source($restored),$this->fence());
        self::assertSame('proposal_pending_client_review',$receipt['status']);
        self::assertFalse($receipt['officialClientAcceptance']);
    }

    public function test_modifying_even_one_signed_field_denies():void
    {
        $wire=$this->signed();
        $modifications=[
            ['issuer'=>'imagine-portal'],
            ['audience'=>'imagine-sites'],
            ['schemaVersion'=>'sites.synthetic.review-handoff.v1'],
            ['issuedAt'=>self::NOW+9],
            ['expiresAt'=>self::NOW+121],
            ['authorizationRevision'=>10],
            ['nonce'=>'nonce-changed'],
        ];
        foreach($modifications as $patch) {
            $changed=array_replace($wire,$patch);
            try{Consumer::consume($changed,self::KEY,self::NOW,
                $this->source($this->snapshot()),$this->fence());
                self::fail('Modified envelope must be denied.');}
            catch(DomainException){}
        }
        foreach([
            ['tenantRef'=>'tenant-other'],
            ['artifactDigest'=>str_repeat('b',64)],
            ['officialClientAcceptance'=>true],
            ['publishable'=>true],
            ['workflowRevision'=>'3'],
            ['paymentApproved'=>true],
        ] as $patch) {
            $changed=$wire;
            $changed['candidate']=array_replace($changed['candidate'],$patch);
            $changed=$this->resign($changed);
            try{Consumer::consume($changed,self::KEY,self::NOW,
                $this->source($this->snapshot()),$this->fence());
                self::fail('Even a MAC-valid privilege or resource mutation must fail.');}
            catch(DomainException){}
        }
    }

    public function test_expiry_wrong_order_and_untrusted_portal_down_denies():void
    {
        $wire=$this->signed();
        foreach([self::NOW+30,$wire['issuedAt']-7] as $time) {
            try{Consumer::consume($wire,self::KEY,$time,
                $this->source($this->snapshot()),$this->fence());
                self::fail('Expired or not-yet-issued tokens must fail.');}
            catch(DomainException){}
        }
        $reordered=['mac'=>$wire['mac']]+array_diff_key($wire,['mac'=>true]);
        try{Consumer::consume($reordered,self::KEY,self::NOW,
            $this->source($this->snapshot()),$this->fence());
            self::fail('Field order is part of canonical contract.');}
        catch(DomainException){}
        $source=$this->source($this->snapshot());
        $source->offline=true;
        try{Consumer::consume($wire,self::KEY,self::NOW,
            $source,$this->fence());
            self::fail('Unreachable Portal source must fail closed.');}
        catch(DomainException){}
    }
}
