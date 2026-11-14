<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use Throwable;

/**
 * F1-394: Portal candidate Ed25519 issuer for exact client-approved release.
 *
 * Does not accept tenant, digest, client ID, acceptance token or approval
 * state supplied by the request. Its record source is deny-by-default;
 * no live source, IAM user, key material or public route is bound.
 *
 * A signed receipt ALONE never authorizes Sites publication: Sites F1-391
 * separately rechecks independent current approval/revocation evidence.
 */
final readonly class PortalEd25519ClientAcceptanceIssuer
{
    public function __construct(
        private PortalCurrentClientAcceptanceSource $source =
            new DenyingPortalCurrentClientAcceptanceSource(),
    ) {}

    /** @return array<string,int|string> */
    public function issue(
        string $siteRef,
        string $releaseRef,
        int $editorialRevision,
        int $now,
        string $ed25519SecretKey,
        int $ttlSeconds = 60,
    ): array {
        if (!function_exists('sodium_crypto_sign_detached') ||
            strlen($ed25519SecretKey)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new DomainException('Portal client approval signing unavailable.');
        }
        foreach([$siteRef,$releaseRef] as $ref) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$ref)) {
                throw new DomainException('Portal client approval unavailable.');
            }
        }
        if ($editorialRevision<1 || $now<1 || $ttlSeconds<1 ||
            $ttlSeconds>60 || $now>PHP_INT_MAX-$ttlSeconds) {
            throw new DomainException('Portal client approval unavailable.');
        }

        try {
            $record=$this->source->readCurrent(
                $siteRef,$releaseRef,$editorialRevision
            );
        } catch (Throwable) {
            throw new DomainException('Portal client approval unavailable.');
        }
        if (!$record instanceof PortalClientAcceptanceSnapshot ||
            !$record->clientMfaIdentityVerified ||
            !$record->expressConsentRecorded ||
            !$record->acceptanceCurrent ||
            $record->siteRef!==$siteRef ||
            $record->releaseRef!==$releaseRef ||
            $record->editorialRevision!==$editorialRevision ||
            !preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',
                $record->tenantRef
            ) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$record->artifactDigest) ||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D',
                $record->acceptanceRef) ||
            $record->acceptanceEpoch<1 ||
            $record->acceptedAt<1 ||
            $record->acceptedAt>$now) {
            throw new DomainException('Portal client approval unavailable.');
        }

        $proof=[
            'schemaVersion'=>'sites.portal-client-acceptance.ed25519.v1',
            'issuer'=>'imagine-portal',
            'audience'=>'imagine-sites',
            'issuedAt'=>$now,
            'expiresAt'=>$now+$ttlSeconds,
            'acceptedAt'=>$record->acceptedAt,
            'tenantRef'=>$record->tenantRef,
            'siteRef'=>$record->siteRef,
            'releaseRef'=>$record->releaseRef,
            'artifactDigest'=>$record->artifactDigest,
            'editorialRevision'=>$record->editorialRevision,
            'acceptanceRef'=>$record->acceptanceRef,
            'acceptanceEpoch'=>$record->acceptanceEpoch,
            'actorContext'=>'portal_client_authenticated_mfa',
            'decision'=>'accepted_explicitly',
        ];
        try {
            $canonical=json_encode($proof, JSON_THROW_ON_ERROR |
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $proof['signature']=bin2hex(sodium_crypto_sign_detached(
                $canonical,$ed25519SecretKey
            ));
        } catch (Throwable) {
            throw new DomainException('Portal client approval signing unavailable.');
        }
        return $proof;
    }
}
