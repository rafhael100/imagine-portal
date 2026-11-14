<?php
declare(strict_types=1);
namespace App\Homologation;
use InvalidArgumentException;

/** Synthetic ownership decision only. Inputs must come from trusted server records.
 * This object does not authenticate actors or authorize production HTTP.
 */
final readonly class SyntheticPurchaseOwnership
{
    public function __construct(
        public string $orderId,
        public string $organizationId,
        public string $customerId,
    ) {
        if (!preg_match('/\ATEST-[a-zA-Z0-9-]{8,64}\z/D', $orderId)
            || !in_array($organizationId, ['test-org-a', 'test-org-b'], true)
            || !preg_match('/\Atest-customer-[a-z0-9-]{1,40}\z/D', $customerId)) {
            throw new InvalidArgumentException('Synthetic ownership record required.');
        }
    }

    /** Deliberately excludes admin shortcuts and browser-supplied ownership. */
    public function belongsTo(string $organizationId, string $customerId): bool
    {
        return hash_equals($this->organizationId, $organizationId)
            && hash_equals($this->customerId, $customerId);
    }
}
