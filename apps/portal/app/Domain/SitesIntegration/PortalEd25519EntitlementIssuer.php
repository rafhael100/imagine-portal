<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use Throwable;

/**
 * F1-387 Portal-side canonical Ed25519 commercial entitlement issuer.
 *
 * Three independent authoritative sources MUST agree at issuance time:
 * contract source, current final settlement, current revocation epoch.
 * This issuer is an inert library. No operational sources, private keys,
 * service-provider bindings or routes are supplied by this PR.
 *
 * Sites MUST independently re-check PSP settlement and revocation when
 * receiving this token. A valid signature alone never permits a project.
 */
final readonly class PortalEd25519EntitlementIssuer
{
    private const CANONICAL_FLAGS=JSON_THROW_ON_ERROR|
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;

    public function __construct(
        private PortalSiteContractSource $contracts = new DenyingPortalSiteContractSource(),
        private PortalCurrentSettlementSource $settlements = new DenyingPortalSettlementSource(),
        private PortalCurrentRevocationEpochSource $revocations = new DenyingPortalRevocationEpochSource(),
    ) {}

    /**
     * The binary Ed25519 secret key must be obtained by the CALLER from a
     * secret manager, never from the order body, git, .env in this library,
     * a database column or an unverified public network response.
     *
     * @return array<string,int|string>
     */
    public function issue(
        string $orderRef,
        string $operationId,
        string $idempotencyKey,
        int $now,
        string $ed25519PrivateKey,
        int $ttlSeconds=60,
    ): array {
        if (!function_exists('sodium_crypto_sign_detached') ||
            strlen($ed25519PrivateKey)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new DomainException('Portal signing identity unavailable.');
        }
        if ($now<=0 || $ttlSeconds<1 || $ttlSeconds>120 ||
            $now>PHP_INT_MAX-$ttlSeconds) {
            throw new DomainException('Portal proof validity unavailable.');
        }

        // The encoder reads an independently verified contract source.
        // No manually supplied tenantRef/contractRef/productCode accepted.
        try {
            $command=(new SitesCreateProjectEncoder($this->contracts))->build(
                $orderRef,$operationId,$idempotencyKey
            );
        } catch (Throwable) {
            throw new DomainException('Portal contract verification unavailable.');
        }
        try {
            $settlement=$this->settlements->findCurrentSettledOrder($orderRef);
        } catch (Throwable) {
            throw new DomainException('Portal settlement verification unavailable.');
        }
        if (!$settlement instanceof PortalSettlementEvidence ||
            $settlement->tenantRef!==$command['tenantRef'] ||
            $settlement->orderRef!==$command['orderRef'] ||
            $settlement->contractRef!==$command['contractRef'] ||
            $settlement->contractRevision!==$command['contractRevision'] ||
            $settlement->productCode!==$command['productCode']) {
            throw new DomainException('Portal settlement verification unavailable.');
        }
        try {
            $epoch=$this->revocations->readCurrentActiveEpoch(
                $command['tenantRef'],$command['orderRef'],
                $command['contractRef'],$command['contractRevision']
            );
        } catch (Throwable) {
            throw new DomainException('Portal revocation verification unavailable.');
        }
        if (!is_int($epoch) || $epoch<1) {
            throw new DomainException('Portal revocation verification unavailable.');
        }

        // MUST match sites/src/Integration/SiteProjectCommand.php
        // semanticFingerprint(), including PHP JSON ordering and flags.
        $fingerprint=hash('sha256',json_encode([
            $command['schemaVersion'],$command['tenantRef'],
            $command['orderRef'],$command['contractRef'],
            $command['contractRevision'],$command['productCode'],
        ],JSON_THROW_ON_ERROR));

        // MUST match Sites F1-386 IndependentPortalCommercialGateway's
        // exact field order. No payment-state bool is present on the wire.
        $proof=[
            'schemaVersion'=>'sites.portal-entitlement.ed25519.v1',
            'issuer'=>'imagine-portal',
            'audience'=>'imagine-sites',
            'issuedAt'=>$now,
            'expiresAt'=>$now+$ttlSeconds,
            'tenantRef'=>$command['tenantRef'],
            'orderRef'=>$command['orderRef'],
            'contractRef'=>$command['contractRef'],
            'contractRevision'=>$command['contractRevision'],
            'productCode'=>$command['productCode'],
            'operationFingerprint'=>$fingerprint,
            'settlementRef'=>$settlement->settlementRef,
            'settlementSha256'=>$settlement->settlementSha256,
            'revocationEpoch'=>$epoch,
        ];
        try {
            $canonical=json_encode($proof,self::CANONICAL_FLAGS);
            $proof['signature']=bin2hex(
                sodium_crypto_sign_detached($canonical,$ed25519PrivateKey)
            );
            return $proof;
        } catch (Throwable) {
            throw new DomainException('Portal proof signing unavailable.');
        }
    }
}
