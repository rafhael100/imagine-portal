<?php

declare(strict_types=1);

namespace App\Homologation;

use InvalidArgumentException;
use RuntimeException;

/**
 * P0 SQL owner capability issuer: CI-only architecture experiment.
 *
 * NOT a customer principal, membership verifier, revocation fence or session.
 * The $organization/$customer inputs MUST eventually come from an independent
 * verified IAM principal and never from HTTP input. No production binding.
 */
final readonly class SyntheticOwnerSqlCapability
{
    public function __construct(#[\SensitiveParameter] private string $key)
    {
        if (getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('PORTAL_CAPABILITY_CI_ONLY') !== '1'
            || strlen($key) < 32) {
            throw new RuntimeException('Synthetic SQL capability issuer is CI-only.');
        }
    }

    /** @return array{expiry:int,mac:string} */
    public function issue(
        string $action,
        string $organization,
        string $customer,
        string $order,
    ): array {
        if (!in_array($action, ['create', 'list', 'approve'], true)
            || !in_array($organization, ['test-org-a', 'test-org-b'], true)
            || preg_match('/\Ademo-[a-f0-9]{32}\z/D', $customer) !== 1
            || (($action === 'list') !== ($order === '-'))
            || ($action !== 'list'
                && preg_match('/\ATEST-[a-f0-9]{32}\z/D', $order) !== 1)) {
            throw new InvalidArgumentException('Unknown or unscoped synthetic SQL capability.');
        }

        $expiry = time() + 60;
        $message = implode('|', [$action, $organization, $customer, $order, (string)$expiry]);
        return [
            'expiry' => $expiry,
            'mac' => hash_hmac('sha256', $message, $this->key),
        ];
    }
}
