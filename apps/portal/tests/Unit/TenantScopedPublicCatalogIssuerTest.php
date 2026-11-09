<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Authorization\Permission;
use App\Domain\CommercialPublishing\TenantScopedPublicCatalogIssuer;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** In-memory synthetic identities: NOT a Portal-authenticated session. */
final class TenantScopedPublicCatalogIssuerTest extends TestCase
{
    private function fixture(): array
    {
        $snapshot=[
            'schemaVersion'=>'1.0','releaseId'=>'release_f10_test_01',
            'publishedAt'=>'2026-11-09T03:00:00Z',
            'items'=>[[
                'id'=>'service_landing_1','slug'=>'landing-pages',
                'title'=>'Criação de Landing Pages profissionais',
                'description'=>'Metadados publicáveis de teste para validar regras editoriais e integridade criptográfica.',
                'path'=>'/servicos/landing-pages/','status'=>'published','version'=>1
            ]]
        ];
        $hash=hash('sha256',json_encode($snapshot,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $scope=['requestId'=>'request_f10_test_01','tenantId'=>'tenant_imagine_01',
            'releaseId'=>$snapshot['releaseId'],'payloadSha256'=>$hash];
        $request=$scope+['actorId'=>'editor_demo_01','roles'=>['malicious_role'],
            'requestedAt'=>'2026-11-09T02:50:00Z','status'=>'submitted'];
        $approval=$scope+['actorId'=>'reviewer_demo_01','roles'=>['owner'],
            'approvedAt'=>'2026-11-09T02:55:00Z','decision'=>'approved'];
        $dispatch=$scope+['actorId'=>'publisher_demo_01','roles'=>['all'],
            'dispatchedAt'=>'2026-11-09T02:58:00Z','status'=>'pending'];
        $org=new OrganizationId('tenant_imagine_01');
        return [$snapshot,$request,$approval,$dispatch,$org,[
            $this->member('editor_demo_01',$org,Permission::CommercialEditCatalog),
            $this->member('reviewer_demo_01',$org,Permission::CommercialApproveCatalog),
            $this->member('publisher_demo_01',$org,Permission::CommercialReservePublish)
        ]];
    }

    private function member(string $id,OrganizationId $org,Permission $permission,
        MembershipStatus $status=MembershipStatus::Active): OrganizationMembership
    {
        return new OrganizationMembership(new PortalUserId($id),$org,
            OrganizationRole::Member,$status,[$permission]);
    }
    private function sign(array $fixture): array
    {
        [$snapshot,$request,$approval,$dispatch,$org,$members]=$fixture;
        return TenantScopedPublicCatalogIssuer::issue(
            $snapshot,$request,$approval,$dispatch,$org,
            $members[0],$members[1],$members[2],
            'portal_test_01',sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())
        );
    }

    public function test_trusted_scoped_grants_override_untrusted_descriptive_role_labels(): void
    {
        $fixture=$this->fixture();
        $result=$this->sign($fixture);
        self::assertSame('imagine-catalog-v1',$result['protocol']);
        self::assertSame('portal_test_01',$result['keyId']);
        $payload=json_decode(base64_decode($result['payloadBase64'],true),true);
        self::assertSame('landing-pages',$payload['items'][0]['slug']);
        self::assertArrayNotHasKey('tenantId',$payload);
    }
    public function test_wrong_tenant_or_substituted_actors_are_denied(): void
    {
        $fixture=$this->fixture();
        $fixture[1]['tenantId']='tenant_other_01';
        try{$this->sign($fixture);self::fail('Foreign tenant accepted');}
        catch(InvalidArgumentException $e){
            self::assertStringContainsString('tenant scope',$e->getMessage());
        }
        $fixture=$this->fixture();
        $fixture[2]['actorId']='reviewer_wrong_01';
        try{$this->sign($fixture);self::fail('Actor substitution accepted');}
        catch(InvalidArgumentException $e){
            self::assertStringContainsString('actor or tenant',$e->getMessage());
        }
    }
    public function test_missing_or_revoked_grant_rejects_before_signing(): void
    {
        foreach([0,1,2] as $i){
            $fixture=$this->fixture();
            $fixture[5][$i]=null;
            try{$this->sign($fixture);self::fail('Missing member accepted');}
            catch(InvalidArgumentException $e){
                self::assertStringContainsString('scoped editorial permission',$e->getMessage());
            }
            $fixture=$this->fixture();
            $member=$fixture[5][$i];
            $permission=[Permission::CommercialEditCatalog,
                Permission::CommercialApproveCatalog,Permission::CommercialReservePublish][$i];
            $fixture[5][$i]=$this->member($member->actorId->value,
                new OrganizationId('tenant_imagine_01'),$permission,MembershipStatus::Revoked);
            try{$this->sign($fixture);self::fail('Revoked member accepted');}
            catch(InvalidArgumentException $e){
                self::assertStringContainsString('scoped editorial permission',$e->getMessage());
            }
        }
    }
    public function test_changed_payload_after_approval_rejected_by_cryptographic_gate(): void
    {
        $fixture=$this->fixture();
        $fixture[0]['items'][0]['title']='Conteúdo não aprovado depois da revisão';
        $this->expectException(InvalidArgumentException::class);
        TenantScopedPublicCatalogIssuer::issue(
            $fixture[0],$fixture[1],$fixture[2],$fixture[3],$fixture[4],
            ...$fixture[5],
            keyId:'portal_test_01',
            secretKey:sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())
        );
    }
}
