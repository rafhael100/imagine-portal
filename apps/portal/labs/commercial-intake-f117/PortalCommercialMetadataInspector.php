<?php

declare(strict_types=1);

namespace Imagine\Portal\Labs\CommercialIntakeF117;

/**
 * F117 — Pure structural contract for Comercial F78/F95/F96.
 *
 * This class is NOT Laravel-routed or autoloaded, does not authenticate a
 * service, load an authoritative binding, receive PII, persist a lead, issue
 * a receipt, or call CRM. Calling inspect() can NEVER authorize an operation.
 */
final class PortalCommercialMetadataInspector
{
    public const SCHEMA = 'imagine.portal.commercial-intake-boundary.v1';

    private const REQUEST_KEYS = [
        'protocol', 'schema_version',
        'organization_reference', 'binding_reference',
        'submission_reference', 'site_reference',
        'form_reference', 'destination_reference',
    ];

    private const BINDING_KEYS = [
        'organization_reference', 'binding_reference', 'site_reference',
        'form_reference', 'destination_reference',
    ];

    private const UUID_PATTERN =
        '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD';

    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * $binding is a synthetic TEST FIXTURE, not an authenticated source.
     *
     * This signature intentionally does not accept arbitrary personal data,
     * consent assertions, an HTTP request, credentials or a delivery flag.
     *
     * @param array<string,mixed> $envelope
     * @param array<string,mixed> $binding
     * @return array<string,mixed>
     */
    public static function inspect(array $envelope, array $binding): array
    {
        if (!self::hasExactKeys($envelope, self::REQUEST_KEYS)) {
            return self::deny('ENVELOPE_STRUCTURE_INVALID');
        }

        if ($envelope['protocol'] !== 'imagine.lead-intake') {
            return self::deny('PROTOCOL_UNSUPPORTED');
        }

        if ($envelope['schema_version'] !== 1) {
            return self::deny('VERSION_UNSUPPORTED');
        }

        foreach (array_slice(self::REQUEST_KEYS, 2) as $key) {
            if (!self::validUuid($envelope[$key])) {
                return self::deny('REFERENCE_FORMAT_INVALID');
            }
        }

        if (!self::hasExactKeys($binding, self::BINDING_KEYS)) {
            return self::deny('FIXTURE_BINDING_INVALID');
        }

        foreach (self::BINDING_KEYS as $key) {
            if (!self::validUuid($binding[$key])) {
                return self::deny('FIXTURE_BINDING_INVALID');
            }
            // Format-compatible UUIDs asserted in a request do not confer
            // tenancy or service authority. They may only be compared with
            // an independently supplied synthetic fixture here.
            if (strtolower($envelope[$key]) !== strtolower($binding[$key])) {
                return self::deny('PINNED_BINDING_CONFLICT');
            }
        }

        return self::deny('STRUCTURE_MATCH_UNTRUSTED');
    }

    /** @param list<string> $keys */
    private static function hasExactKeys(array $value, array $keys): bool
    {
        if (count($value) !== count($keys)) {
            return false;
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                return false;
            }
        }
        return true;
    }

    private static function validUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match(self::UUID_PATTERN, $value) === 1
            && strtolower($value) !== self::NIL_UUID;
    }

    /** @return array<string,mixed> */
    private static function deny(string $code): array
    {
        return [
            'schema' => self::SCHEMA,
            'reasonCode' => $code,
            'evidenceClass' => 'SYNTHETIC_STRUCTURE_ONLY',
            'canAcceptVisitorData' => false,
            'tenantAuthorized' => false,
            'canCreateLead' => false,
            'canQueueOutbox' => false,
            'canDeliverToCrm' => false,
            'crmReceiptProven' => false,
            'canAcknowledgeVisitor' => false,
            'productionAuthorized' => false,
        ];
    }
}
