<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use Throwable;

/**
 * F1-396 private Portal candidate endpoint logic for Sites F1-395.
 * A future controller may call only AFTER an independently authenticated
 * mTLS service principal is passed through the trusted IAM adapter.
 *
 * No route or provider binding. No client acceptance can be created here.
 * The issuer's default authoritative consent source is DENY.
 */
final readonly class PortalSitesClientAcceptanceResolver
{
    private const FIELDS=[
        'schemaVersion','tenantRef','siteRef','releaseRef',
        'artifactDigest','editorialRevision',
    ];

    public function __construct(
        private PortalVerifiedSitesServiceIdentity $identity =
            new DenyingPortalVerifiedSitesService(),
        private PortalEd25519ClientAcceptanceIssuer $issuer =
            new PortalEd25519ClientAcceptanceIssuer(),
    ) {}

    /**
     * @param array<string,mixed> $request
     * @return array<string,int|string>
     */
    public function resolve(array $request,int $now,string $ed25519SecretKey):array
    {
        try {
            if (!$this->identity->isVerifiedSitesService()) {
                throw new DomainException('Private Portal client approval unavailable.');
            }
        } catch (Throwable) {
            throw new DomainException('Private Portal client approval unavailable.');
        }
        if (array_keys($request)!==self::FIELDS ||
            $request['schemaVersion']!=='sites.portal-client-approval-fetch.v1' ||
            !is_int($request['editorialRevision']) ||
            $request['editorialRevision']<1 ||
            !is_string($request['artifactDigest']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$request['artifactDigest'])) {
            throw new DomainException('Private Portal client approval unavailable.');
        }
        foreach(['tenantRef','siteRef','releaseRef'] as $field) {
            if (!is_string($request[$field]) ||
                !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',
                    $request[$field])) {
                throw new DomainException('Private Portal client approval unavailable.');
            }
        }

        try {
            $receipt=$this->issuer->issue(
                $request['siteRef'],$request['releaseRef'],
                $request['editorialRevision'],$now,$ed25519SecretKey,60
            );
        } catch (Throwable) {
            throw new DomainException('Private Portal client approval unavailable.');
        }
        // A signed receipt for another company, revision or digest can never
        // answer this lookup. Request fields are not trusted evidence.
        foreach ([
            'tenantRef','siteRef','releaseRef','artifactDigest','editorialRevision',
        ] as $field) {
            if (!array_key_exists($field,$receipt) ||
                $receipt[$field]!==$request[$field]) {
                throw new DomainException('Private Portal client approval unavailable.');
            }
        }
        return $receipt;
    }

    /** @return array<string,mixed> */
    public static function parseJsonRequest(string $body):array
    {
        if ($body==='' || strlen($body)>2048) {
            throw new DomainException('Private Portal client approval unavailable.');
        }
        try {
            $decoded=json_decode($body,true,12,JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DomainException('Private Portal client approval unavailable.');
        }
        if(!is_array($decoded) || array_is_list($decoded)) {
            throw new DomainException('Private Portal client approval unavailable.');
        }
        return $decoded;
    }
}
