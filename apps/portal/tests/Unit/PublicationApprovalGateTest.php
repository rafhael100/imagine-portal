<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\CommercialPublishing\ApprovedPublicCatalogIssuer;
use App\Domain\CommercialPublishing\PublicationApprovalGate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PublicationApprovalGateTest extends TestCase
{
    private function fixture(): array
    {
        $snapshot = [
            'schemaVersion' => '1.0', 'releaseId' => 'release_20261109_03',
            'publishedAt' => '2026-11-09T03:00:00Z',
            'items' => [[
                'id' => 'service_landing_1', 'slug' => 'landing-pages',
                'title' => 'Landing Pages profissionais para empresas',
                'description' => 'Página comercial para apresentar serviços, organizar informações e facilitar novos contatos.',
                'path' => '/servicos/landing-pages/', 'status' => 'published',
                'version' => 1,
            ]],
        ];
        $digest = hash('sha256', json_encode(
            $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        $scope = [
            'requestId' => 'request_20261109_03', 'tenantId' => 'tenant_imagine_01',
            'releaseId' => $snapshot['releaseId'], 'payloadSha256' => $digest,
        ];
        return [
            $snapshot,
            $scope + ['actorId'=>'editor_imagine_1','roles'=>['editor'],
                'requestedAt'=>'2026-11-09T03:00:01Z','status'=>'submitted'],
            $scope + ['actorId'=>'reviewer_imagine_1','roles'=>['content_approver'],
                'approvedAt'=>'2026-11-09T03:05:00Z','decision'=>'approved'],
            $scope + ['actorId'=>'publisher_imagine_1','roles'=>['publisher'],
                'dispatchedAt'=>'2026-11-09T03:06:00Z','status'=>'pending'],
        ];
    }

    private function denied(array $snapshot, array $request, array $approval, array $dispatch, string $message): void
    {
        try {
            PublicationApprovalGate::evaluate($request,$approval,$dispatch,$snapshot);
            self::fail('Gate accepted invalid publication: '.$message);
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_three_distinct_roles_produce_only_laboratory_receipt(): void
    {
        [$s,$r,$a,$d] = $this->fixture();
        $result = PublicationApprovalGate::evaluate($r,$a,$d,$s);
        self::assertSame('validated_for_laboratory_signing',$result['status']);
        self::assertSame($s['releaseId'],$result['releaseId']);
        self::assertArrayNotHasKey('secretKey',$result);
    }

    public function test_approved_issuer_can_sign_only_after_gate(): void
    {
        [$s,$r,$a,$d] = $this->fixture();
        $pair = sodium_crypto_sign_keypair();
        $envelope = ApprovedPublicCatalogIssuer::issue(
            $s,$r,$a,$d,'portal_test_01',sodium_crypto_sign_secretkey($pair)
        );
        $payload = base64_decode($envelope['payloadBase64'],true);
        self::assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($envelope['signatureBase64'],true),
            "imagine-commercial-catalog-v1\0".$payload,
            sodium_crypto_sign_publickey($pair)
        ));
    }

    public function test_distinct_reviewers_and_publisher_are_required(): void
    {
        [$s,$r,$a,$d] = $this->fixture();
        $a['actorId'] = $r['actorId'];
        $this->denied($s,$r,$a,$d,'must be distinct');
    }

    public function test_unapproved_rejected_and_unknown_roles_blocked(): void
    {
        [$s,$r,$a,$d] = $this->fixture();
        $a['decision'] = 'rejected';
        $this->denied($s,$r,$a,$d,'submitted, approved and pending');
        [$s,$r,$a,$d] = $this->fixture();
        $a['roles'] = ['viewer'];
        $this->denied($s,$r,$a,$d,'Required trusted role');
    }

    public function test_tenant_request_release_and_hash_must_match(): void
    {
        foreach (['tenantId','requestId','releaseId','payloadSha256'] as $field) {
            [$s,$r,$a,$d] = $this->fixture();
            $a[$field] = $field === 'payloadSha256' ? str_repeat('a',64) : 'different_123';
            $this->denied($s,$r,$a,$d,$field==='payloadSha256'?'digest mismatch':'scope mismatch');
        }
    }

    public function test_changed_snapshot_cannot_be_issued_with_original_approval(): void
    {
        [$s,$r,$a,$d] = $this->fixture();
        $s['items'][0]['title'] = 'Alteração editorial não aprovada';
        $this->denied($s,$r,$a,$d,'changed after editorial approval');
    }

    public function test_review_must_precede_dispatch_and_obey_time_window(): void
    {
        [$s,$r,$a,$d] = $this->fixture();
        $d['dispatchedAt'] = '2026-11-09T03:00:02Z';
        $this->denied($s,$r,$a,$d,'chronology');
        [$s,$r,$a,$d] = $this->fixture();
        $a['approvedAt'] = '2026-11-11T03:00:00Z';
        $d['dispatchedAt'] = '2026-11-11T03:01:00Z';
        $this->denied($s,$r,$a,$d,'24-hour');
    }

    public function test_replayed_release_and_request_are_denied_when_history_is_provided(): void
    {
        [$s,$r,$a,$d] = $this->fixture();
        foreach ([ [[$r['requestId']],[]], [[],[$r['releaseId']]] ] as [$requests,$releases]) {
            try {
                PublicationApprovalGate::evaluate($r,$a,$d,$s,$requests,$releases);
                self::fail('Replay accepted');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('already consumed',$exception->getMessage());
            }
        }
    }

    public function test_extra_fields_and_malformed_time_are_denied(): void
    {
        [$s,$r,$a,$d] = $this->fixture();
        $r['isAdmin'] = true;
        $this->denied($s,$r,$a,$d,'Unexpected request field');
        [$s,$r,$a,$d] = $this->fixture();
        $a['approvedAt'] = '2026-02-30T03:00:00Z';
        $this->denied($s,$r,$a,$d,'calendar date');
    }
}
