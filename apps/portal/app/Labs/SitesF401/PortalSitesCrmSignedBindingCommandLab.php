<?php

declare(strict_types=1);

namespace App\Labs\SitesF401;

use LogicException;

/**
 * F401: original Portal-side CI-only emitter for the CRM F400 command protocol.
 *
 * Fake HMAC keys are deliberately public; this component is an interoperability
 * fixture and provides NO authentication or provisioning authority in reality.
 * No routes, clients, gateway, session, PSP, real consent or outbound network.
 */
final class PortalSitesCrmSignedBindingCommandLab
{
    private const DOMAIN = 'IMAGINE-F400-PORTAL-CRM-SITES-BINDING-CI-v1';

    // PUBLIC FAKE test keys matched to the independently built CRM F400.
    private const FIXTURES = [
        'lab-portal-crm-provision-a' => 'FAKE-PUBLIC-CI-ONLY-PORTAL-CRM-PROVISION-A-2026-NOT-A-SECRET',
        'lab-portal-crm-provision-b' => 'FAKE-PUBLIC-CI-ONLY-PORTAL-CRM-PROVISION-B-2026-NOT-A-SECRET',
        'lab-portal-crm-revoke-c' => 'FAKE-PUBLIC-CI-ONLY-PORTAL-CRM-REVOKE-C-2026-NOT-A-SECRET',
    ];

    private const FIELDS = [
        'protocol','sourceService','audienceService','keyId',
        'action','organizationReference','orderReference',
        'bindingReference','expectedOrderVersion','nonce',
        'issuedAt','expiresAt',
    ];

    /** @param array<string,mixed> $claim
     *  @return array<string,mixed>
     */
    public static function sign(array $claim, int $now): array
    {
        self::assertCiOnly();
        self::validateClaim($claim, $now);
        $claim['signature'] = hash_hmac(
            'sha256',
            self::canonical($claim),
            self::FIXTURES[$claim['keyId']],
        );
        return $claim;
    }

    /** @param array<string,mixed> $claim */
    public static function canonical(array $claim): string
    {
        $expected = self::FIELDS;
        $withoutSignature = $claim;
        unset($withoutSignature['signature']);
        if (array_diff(array_keys($withoutSignature), $expected) !== []
            || array_diff($expected, array_keys($withoutSignature)) !== []) {
            throw new LogicException('F401 payload fields do not match protocol.');
        }
        return self::DOMAIN . "\n" . implode("\n", array_map(
            static fn (string $field): string => (string)$withoutSignature[$field],
            self::FIELDS,
        ));
    }

    /** @param array<string,mixed> $claim */
    private static function validateClaim(array $claim, int $now): void
    {
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $claim)) {
                throw new LogicException('F401 missing field: '.$field);
            }
        }
        if (array_diff(array_keys($claim), self::FIELDS) !== []
            || $claim['protocol'] !== 'imagine-s2s-f400-ci-v1'
            || $claim['sourceService'] !== 'imagine-portal'
            || $claim['audienceService'] !== 'imagine-crm'
            || !is_string($claim['action'])
            || !in_array($claim['action'], ['provision','revoke'], true)
            || !is_string($claim['keyId'])
            || !array_key_exists($claim['keyId'], self::FIXTURES)
            || !is_string($claim['organizationReference'])
            || !is_string($claim['bindingReference'])
            || !is_string($claim['nonce'])
            || !is_string($claim['orderReference'])
            || preg_match('/\A[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}\z/D', $claim['organizationReference']) !== 1
            || preg_match('/\A[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}\z/D', $claim['bindingReference']) !== 1
            || preg_match('/\A[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}\z/D', $claim['nonce']) !== 1
            || strlen($claim['orderReference']) < 1
            || strlen($claim['orderReference']) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $claim['orderReference']) !== 1
            || !is_int($claim['expectedOrderVersion'])
            || $claim['expectedOrderVersion'] < 1
            || !is_int($claim['issuedAt'])
            || !is_int($claim['expiresAt'])
            || $claim['issuedAt'] <= 0
            || $claim['expiresAt'] <= $claim['issuedAt']
            || $claim['expiresAt'] - $claim['issuedAt'] > 300
            || $claim['issuedAt'] < $now - 300
            || $claim['issuedAt'] > $now + 30
            || $claim['expiresAt'] < $now) {
            throw new LogicException('F401 CI-only Portal envelope denied.');
        }
    }

    private static function assertCiOnly(): void
    {
        if (getenv('CI') !== 'true'
            || getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('PORTAL_SITES_F401_CI') !== '1'
            || !in_array(
                getenv('GITHUB_REPOSITORY'),
                ['rafhael100/imagine-portal', 'rafhael100/imagine-crm'],
                true,
            )) {
            throw new LogicException('F401 Portal fake command cannot run outside GitHub Actions CI.');
        }
    }
}
