<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use InvalidArgumentException;

/**
 * F1-48: test-only incoming Sites->Portal review PROPOSAL consumer.
 * It cannot authenticate the network peer or verify client acceptance.
 * HMAC integrity is NOT entitlement, identity or publication permission.
 */
final readonly class SyntheticSitesReviewHandoffConsumer
{
    private const FIELDS=[
        'schemaVersion','issuer','audience','issuedAt','expiresAt','nonce','candidate','mac',
    ];
    private const CANDIDATE_FIELDS=[
        'schemaVersion','tenantRef','siteRef','workflowRef','workflowRevision',
        'releaseRef','artifactDigest','portalAuthorityRequired',
        'officialClientAcceptance','publishable',
    ];
    private const FLAGS=JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;

    public function __construct(
        private ReviewHandoffAuthoritySource $authority =
            new DenyingReviewHandoffAuthoritySource(),
        private ReviewHandoffNonceFence $nonces =
            new DenyingReviewHandoffNonceFence(),
    ) {}

    /**
     * @param array<string,mixed> $wire
     * @return array<string,string|int|bool> never official/accepted/publishable
     */
    public function inspect(array $wire,string $fictionalKey,int $now):array
    {
        if (strlen($fictionalKey)<32) {
            throw new InvalidArgumentException('Short laboratory HMAC key.');
        }
        if (array_keys($wire)!==self::FIELDS ||
            $wire['schemaVersion']!=='sites.synthetic.review-handoff.v1' ||
            $wire['issuer']!=='imagine-sites' ||
            $wire['audience']!=='imagine-portal' ||
            !is_int($wire['issuedAt']) || !is_int($wire['expiresAt']) ||
            $wire['issuedAt']<1 || $wire['issuedAt']>$now+5 ||
            $wire['expiresAt']<=$now || $wire['expiresAt']<=$wire['issuedAt'] ||
            $wire['expiresAt']-$wire['issuedAt']>120 ||
            !is_string($wire['nonce']) ||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,63}\z/D',$wire['nonce']) ||
            !is_string($wire['mac']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$wire['mac']) ||
            !is_array($wire['candidate'])) {
            throw new DomainException('Invalid Sites review envelope.');
        }
        $c=$wire['candidate'];
        if (array_keys($c)!==self::CANDIDATE_FIELDS ||
            $c['schemaVersion']!=='sites.synthetic.review-candidate.v1' ||
            !is_int($c['workflowRevision']) || $c['workflowRevision']<1 ||
            $c['portalAuthorityRequired']!==true ||
            $c['officialClientAcceptance']!==false ||
            $c['publishable']!==false ||
            !is_string($c['artifactDigest']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$c['artifactDigest'])) {
            throw new DomainException('Unsupported or privileged review proposal.');
        }
        foreach (['tenantRef','siteRef','workflowRef','releaseRef'] as $field) {
            if (!is_string($c[$field]) ||
                !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$c[$field])) {
                throw new DomainException('Invalid review resource reference.');
            }
        }
        $unsigned=array_slice($wire,0,-1,true);
        $signedBytes=json_encode($unsigned,self::FLAGS);
        if (!hash_equals(hash_hmac('sha256',$signedBytes,$fictionalKey),$wire['mac'])) {
            throw new DomainException('Review proposal MAC mismatch.');
        }

        // Re-read independent current Portal data on EVERY attempt. A current
        // authoritative revision and eligibility are mandatory and must match
        // the SAME tenant, site, workflow, revision, release and exact bytes.
        $official=$this->authority->currentFor(
            $c['tenantRef'],$c['siteRef'],$c['workflowRef']
        );
        if ($official===null || !$official->currentlyEligible ||
            $official->tenantRef!==$c['tenantRef'] ||
            $official->siteRef!==$c['siteRef'] ||
            $official->workflowRef!==$c['workflowRef'] ||
            $official->workflowRevision!==$c['workflowRevision'] ||
            $official->authorityRevision<1 ||
            !hash_equals($official->releaseRef,$c['releaseRef']) ||
            !hash_equals($official->artifactDigest,$c['artifactDigest'])) {
            throw new DomainException('No current authorized Portal review resource.');
        }

        // Consume AFTER signature/authority, so failed attempts cannot exhaust
        // legitimate nonce. No nonce service means DENY by default.
        if (!$this->nonces->consumeOnce(
            'imagine-sites:review-handoff.v1',$wire['nonce'],$wire['expiresAt']
        )) {
            throw new DomainException('Review proposal already used or nonce store unavailable.');
        }

        return $c; // INFORMATIVE ONLY. No official decision, no side effects.
    }
}
