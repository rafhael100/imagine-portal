<?php
declare(strict_types=1);
namespace PrivateLab;
use App\Domain\Commerce\D0\EntryStore;
use Throwable;

/** Diagnostic only, in the existing explicit buyer request.
 * Session/order come from the native caller; account/environment from trusted
 * ingress metadata. Signal fields are claims, never lookup authority.
 * Result MUST NOT gate or invoke FirstBriefingRelease, ACK, queue or append.
 */
final class BuyerSignalCorrelation
{
    public static function matches(
        EntryStore $store, string $session, string $order,
        string $ingressAccount, string $ingressEnvironment, array $signal
    ): bool {
        try {
            $snapshot = $store->inspect($session, $order);
            if (!is_array($snapshot)
                || !in_array($snapshot['state'] ?? null, ['ready', 'confirmed'], true)
                || $ingressEnvironment !== 'sandbox'
                || ($snapshot['account'] ?? null) !== $ingressAccount
                || ($snapshot['environment'] ?? null) !== $ingressEnvironment) return false;
            $keys = array_keys($signal); sort($keys);
            if ($keys !== ['external_reference', 'payment_id']) return false;
            foreach ($keys as $key) {
                if (!is_string($signal[$key]) || $signal[$key] === ''
                    || ($snapshot['binding'][$key] ?? null) !== $signal[$key]) return false;
            }
            // Conservative readback, not a transaction or a settlement guarantee.
            return $store->inspect($session, $order) === $snapshot;
        } catch (Throwable) {
            return false;
        }
    }
}