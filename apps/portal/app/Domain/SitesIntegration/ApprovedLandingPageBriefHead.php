<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;

use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * Immutable *candidate* result from an independent Portal approval ledger.
 * Construction is not customer approval, authenticated custody or publication authority.
 * Briefing bytes must never be automatically serialized to HTTP/logs.
 */
final readonly class ApprovedLandingPageBriefHead implements JsonSerializable
{
    public function __construct(
        public string $tenantRef,
        public string $orderRef,
        public string $siteRef,
        public string $contractRef,
        public int $contractRevision,
        public int $sourceVersion,
        public string $sourceSha256,
        public string $approvalRef,
        public int $approvalEpoch,
        private string $sourceBytes,
    ) {
        foreach ([$tenantRef, $orderRef, $contractRef, $approvalRef] as $ref) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $ref) !== 1) {
                throw new InvalidArgumentException('Invalid Landing head reference.');
            }
        }
        if (preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $siteRef) !== 1 ||
            $contractRevision < 1 || $contractRevision > 9007199254740991 ||
            $sourceVersion < 1 || $sourceVersion > 9007199254740991 ||
            $approvalEpoch < 1 || $approvalEpoch > 9007199254740991 ||
            preg_match('/\A[a-f0-9]{64}\z/D', $sourceSha256) !== 1 ||
            $sourceBytes === '' || strlen($sourceBytes) > 150000 ||
            !hash_equals($sourceSha256, hash('sha256', $sourceBytes))) {
            throw new InvalidArgumentException('Invalid Landing approved head candidate.');
        }
        try {
            $json = json_decode($sourceBytes, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($json) || array_is_list($json)) {
                throw new InvalidArgumentException('Invalid JSON object.');
            }
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid Landing briefing snapshot.');
        }
    }

    /** Internal use only, after independent custody and approval verification. */
    public function sourceBytes(): string { return $this->sourceBytes; }

    /** Never allow accidental publication of private approval or briefing material. */
    public function jsonSerialize(): mixed { throw new RuntimeException('Private Landing head is not a wire response.'); }
    public function __serialize(): array { throw new RuntimeException('Private Landing head cannot be serialized.'); }
    public function __debugInfo(): array { return ['kind' => 'private approved Landing head candidate']; }
}
