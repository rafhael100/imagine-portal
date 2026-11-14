<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use Throwable;

/**
 * Inert Portal-side internal resolver for Sites F1-388 mTLS POST contract.
 *
 * No route, no listener, no service-provider binding is part of this
 * component. A production controller must obtain an authenticated S2S
 * principal from the verified TLS peer, not from HTTP headers, and hand
 * it to an explicit IAM adapter before invoking this resolver.
 *
 * Both the identity check and the commercial proofs fail closed.
 */
final readonly class PortalSitesEntitlementResolver
{
    private const FIELDS=[
        'schemaVersion','tenantRef','orderRef','contractRef',
        'contractRevision','productCode','operationFingerprint',
    ];

    public function __construct(
        private PortalVerifiedSitesServiceIdentity $identity =
            new DenyingPortalVerifiedSitesService(),
        private PortalEd25519EntitlementIssuer $issuer =
            new PortalEd25519EntitlementIssuer(),
    ) {}

    /**
     * @param array<string,mixed> $request
     * @return array<string,int|string>
     */
    public function resolve(
        array $request,
        int $now,
        string $ed25519SecretKey,
    ): array {
        try {
            if (!$this->identity->isVerifiedSitesService()) {
                throw new DomainException('Internal Portal proof unavailable.');
            }
        } catch (Throwable) {
            throw new DomainException('Internal Portal proof unavailable.');
        }
        if (array_keys($request)!==self::FIELDS ||
            $request['schemaVersion']!=='sites.portal-proof-fetch.v1' ||
            !is_int($request['contractRevision']) ||
            $request['contractRevision']<1 ||
            !is_string($request['productCode']) ||
            !in_array($request['productCode'],['onepage','landing_page'],true) ||
            !is_string($request['operationFingerprint']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$request['operationFingerprint'])) {
            throw new DomainException('Internal Portal proof unavailable.');
        }
        foreach(['tenantRef','orderRef','contractRef'] as $field) {
            if (!is_string($request[$field]) ||
                !preg_match(
                    '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',
                    $request[$field]
                )) {
                throw new DomainException('Internal Portal proof unavailable.');
            }
        }

        // This endpoint only retrieves proof. Operation id and idempotency
        // key are internal canonical inputs, not client-supplied claims.
        // The proof's semanticFingerprint does not include these fields.
        $reference=substr($request['operationFingerprint'],0,40);
        try {
            $proof=$this->issuer->issue(
                $request['orderRef'],
                'proof-query-'.$reference,
                'proof-idem-'.$reference,
                $now,$ed25519SecretKey,60
            );
        } catch (Throwable) {
            throw new DomainException('Internal Portal proof unavailable.');
        }
        foreach([
            'tenantRef','orderRef','contractRef','contractRevision',
            'productCode','operationFingerprint',
        ] as $field) {
            if (!array_key_exists($field,$proof) ||
                $proof[$field]!==$request[$field]) {
                // A perfectly signed document for another contract or
                // tenant is still unauthorized for this request.
                throw new DomainException('Internal Portal proof unavailable.');
            }
        }
        return $proof;
    }

    /**
     * Input parser for a future authenticated internal controller only.
     * Never register an HTTP route without independent mTLS IAM review.
     *
     * @return array<string,mixed>
     */
    public static function parseJsonRequest(string $body):array
    {
        if ($body==='' || strlen($body)>2048) {
            throw new DomainException('Internal Portal proof unavailable.');
        }
        try {
            $data=json_decode($body,true,12,JSON_THROW_ON_ERROR);
        } catch(Throwable) {
            throw new DomainException('Internal Portal proof unavailable.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new DomainException('Internal Portal proof unavailable.');
        }
        return $data;
    }
}
