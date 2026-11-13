<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use Closure;
use Throwable;

/**
 * F371: read-only pre/post witness check. NOT an access-authorizing gate.
 *
 * A matching independent Ed25519 witness is necessary but not sufficient:
 * serving fences, true key custody, epoch atomicity and recovery approval
 * do not exist yet. No Laravel route/container binding and no writes here.
 */
final class OnlineWitnessFenceCandidate
{
    private const MATCH = 'WITNESS_MATCHED_STILL_NO_SERVING';

    public function __construct(
        private readonly PortalOnlineWitnessSource $source,
        private readonly IndependentWitnessCandidateVerifier $verifier,
        private readonly string $pinnedPublicKeyHex,
        private readonly string $organization,
        private readonly string $instance,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * Inspect a non-authoritative, read-only candidate observation. The
     * callback MUST be read-only; this result MUST NEVER be an HTTP decision.
     *
     * Fresh independent source reads before AND after the read-only callback.
     * The exact same statement and epoch vector must be observed twice.
     */
    public function inspectReadOnly(Closure $readOnlyProbe): array
    {
        $before = $this->observe();
        if ($before === null) {
            return self::result('WITNESS_PRECHECK_DENIED');
        }

        try {
            $value = $readOnlyProbe();
            if (!is_bool($value)) {
                return self::result('CANDIDATE_READ_INVALID');
            }
        } catch (Throwable) {
            return self::result('CANDIDATE_READ_UNAVAILABLE');
        }

        $after = $this->observe();
        if ($after === null || $before !== $after) {
            return self::result('WITNESS_POSTCHECK_DENIED');
        }
        return self::result($value
            ? 'LAB_READ_OBSERVED_STILL_NO_SERVING'
            : 'LAB_READ_DENIED_STILL_NO_SERVING');
    }

    private function observe(): ?array
    {
        try {
            if (!self::scope($this->organization) || !self::scope($this->instance)
                || preg_match('/\A[a-f0-9]{64}\z/D', $this->pinnedPublicKeyHex) !== 1) {
                return null;
            }

            // The two calls are intentionally NOT cached, including on
            // repeated invocations. Cache-based serving is not authorized.
            $snapshot = $this->source->currentPortalEpoch();
            $receipt = $this->source->latestSignedWitness();
            if (($snapshot['organization'] ?? null) !== $this->organization
                || ($snapshot['instance'] ?? null) !== $this->instance
                || ($receipt['organization'] ?? null) !== $this->organization
                || ($receipt['instance'] ?? null) !== $this->instance) {
                return null;
            }
            $now = $this->clock === null ? time() : ($this->clock)();
            if (!is_int($now)) {
                return null;
            }
            $evaluation = $this->verifier->verify(
                $snapshot, $receipt, $this->pinnedPublicKeyHex, $now,
            );
            if (($evaluation['reasonCode'] ?? null) !== self::MATCH
                || ($evaluation['canAuthorizeHttp'] ?? null) !== false
                || ($evaluation['canAuthorizeBusiness'] ?? null) !== false
                || ($evaluation['canRecoverFromRestore'] ?? null) !== false) {
                return null;
            }

            // Preserve the complete, canonical, signed statement, not only
            // its sequence. Any change between reads must fail closed.
            return [
                'snapshot' => $snapshot,
                'receipt' => $receipt,
            ];
        } catch (Throwable) {
            // Never emit PDO diagnostics, tokens, signing key, snapshot IDs.
            return null;
        }
    }

    private static function scope(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $value) === 1;
    }

    private static function result(string $status): array
    {
        return [
            'schema' => 'imagine.portal.f371.online-witness-prepost.v1',
            'status' => $status,
            'canAuthorizeHttp' => false,
            'canAuthorizeBusiness' => false,
            'canIssueSession' => false,
            'canReissueCredential' => false,
            'canRecoverFromRestore' => false,
            'canDeploy' => false,
        ];
    }
}
