<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * PORTAL-SITES F1-12: byte-compatible consumer for Sites F1-50 v2 HMAC.
 *
 * This verifies only synthetic signed REVIEW PROPOSALS. Even a valid envelope
 * plus current fake authority cannot create client acceptance or publish.
 *
 * No HTTP route, identity, secrets manager, real Portal resource source,
 * durable database, payment, customer data, DNS or delivery event exists here.
 */
final class SyntheticSitesReviewEnvelopeConsumer
{
    private const FIELDS=[
        'schemaVersion','issuer','audience','issuedAt','expiresAt','nonce',
        'authorizationRevision','candidate','mac'
    ];
    private const CANDIDATE_FIELDS=[
        'schemaVersion','tenantRef','siteRef','workflowRef',
        'workflowRevision','releaseRef','artifactDigest',
        'portalAuthorityRequired','officialClientAcceptance','publishable'
    ];
    private const JSON_FLAGS=JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;

    /** @param array<string,mixed> $message
     *  @return array<string,mixed> Informational pending receipt; NEVER approval.
     */
    public static function consume(
        array $message,
        string $syntheticKey,
        int $now,
        ?PortalReviewAuthoritySource $source=null,
        ?PortalReviewReplayFence $nonceFence=null,
    ):array {
        if(strlen($syntheticKey)<32)throw new InvalidArgumentException('Weak synthetic HMAC key.');
        if(array_keys($message)!==self::FIELDS ||
            ($message['schemaVersion']??null)!=='sites.synthetic.review-handoff.v2' ||
            ($message['issuer']??null)!=='imagine-sites' ||
            ($message['audience']??null)!=='imagine-portal' ||
            !is_int($message['issuedAt']??null) ||
            !is_int($message['expiresAt']??null) ||
            !is_int($message['authorizationRevision']??null) ||
            $message['authorizationRevision']<1 ||
            !is_string($message['nonce']??null) ||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,63}\z/D',$message['nonce']) ||
            !is_string($message['mac']??null) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$message['mac']) ||
            !is_array($message['candidate']??null)) {
            throw new DomainException('Malformed or misdirected Sites review proposal.');
        }
        if($message['issuedAt']<1 || $message['expiresAt']<=$message['issuedAt'] ||
            $message['expiresAt']-$message['issuedAt']>120 ||
            $message['issuedAt']>$now+5 || $message['expiresAt']<=$now) {
            throw new DomainException('Review proposal has invalid lifetime.');
        }

        $candidate=$message['candidate'];
        if(array_keys($candidate)!==self::CANDIDATE_FIELDS ||
            ($candidate['schemaVersion']??null)!=='sites.synthetic.review-candidate.v1' ||
            !is_int($candidate['workflowRevision']??null) ||
            $candidate['workflowRevision']<1 ||
            ($candidate['portalAuthorityRequired']??null)!==true ||
            ($candidate['officialClientAcceptance']??null)!==false ||
            ($candidate['publishable']??null)!==false ||
            !is_string($candidate['artifactDigest']??null) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$candidate['artifactDigest'])) {
            throw new DomainException('Forbidden or malformed Sites review candidate.');
        }
        foreach(['tenantRef','siteRef','workflowRef','releaseRef'] as $field) {
            if(!is_string($candidate[$field]??null) ||
                !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$candidate[$field])) {
                throw new DomainException('Invalid opaque Sites review reference.');
            }
        }

        $expected=hash_hmac('sha256',json_encode(
            array_slice($message,0,-1,true),self::JSON_FLAGS
        ),$syntheticKey);
        if(!hash_equals($expected,$message['mac'])) {
            throw new DomainException('Invalid Sites synthetic HMAC.');
        }

        $source??=new DenyingPortalReviewAuthoritySource();
        $nonceFence??=new DenyingPortalReviewReplayFence();
        try {
            // Any operational source MUST authenticate service + tenant
            // independently; never treat a tenant in the envelope as access.
            $current=$source->findCurrent($candidate['tenantRef'],$candidate['siteRef']);
            if(!$current instanceof PortalReviewAuthoritySnapshot ||
                $current->status!=='reviewable' ||
                $current->checkedAt>$now+5 ||
                $current->checkedAt<$now-30 ||
                $current->validUntil<=$now ||
                $current->authorizationRevision!==$message['authorizationRevision'] ||
                $current->workflowRevision!==$candidate['workflowRevision']) {
                throw new DomainException('Portal current review authority denied.');
            }
            foreach(['tenantRef','siteRef','workflowRef','releaseRef','artifactDigest'] as $field) {
                if(!hash_equals($current->$field,$candidate[$field])) {
                    throw new DomainException('Portal current resource or digest mismatch.');
                }
            }
        } catch(Throwable) {
            throw new DomainException('Portal current authority unavailable or denied.');
        }

        // A real implementation requires independent durable, atomic nonce
        // consumption and a revocation transaction fence. No such adapter here.
        if(!$nonceFence->rememberOnce('imagine-sites:review-handoff.v2',
            $message['nonce'],$message['expiresAt'])) {
            throw new DomainException('Replay fence missing, stale or denied.');
        }

        return [
            'schemaVersion'=>'portal.synthetic.site-review-receipt.v1',
            'syntheticOnly'=>true,
            'status'=>'proposal_pending_client_review',
            'tenantRef'=>$current->tenantRef,
            'siteRef'=>$current->siteRef,
            'workflowRef'=>$current->workflowRef,
            'workflowRevision'=>$current->workflowRevision,
            'releaseRef'=>$current->releaseRef,
            'artifactDigest'=>$current->artifactDigest,
            'officialClientAcceptance'=>false,
            'publishable'=>false,
        ];
    }
}
