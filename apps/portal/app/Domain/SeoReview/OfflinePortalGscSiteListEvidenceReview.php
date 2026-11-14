<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

use SensitiveParameter;
use Throwable;

/**
 * F117: strict OFFLINE interpretation of a *synthetic* Search Console
 * sites.list response. NOT an authenticated Google HTTP response.
 *
 * The provider origin, bearer provenance, client consent, Portal session
 * and property grants must be independently verified BEFORE any real
 * property access or customer display. This class grants nothing.
 */
final readonly class OfflinePortalGscSiteListEvidenceReview
{
    private const MAX_BODY_BYTES=32768;
    private const MAX_SITE_ENTRIES=200;
    private const LEVELS=[
        'siteOwner','siteFullUser','siteRestrictedUser',
        'siteUnverifiedUser',
    ];
    private const REVIEW_LEVELS=['siteOwner','siteFullUser'];

    public function inspect(
        mixed $httpStatus,
        #[SensitiveParameter] mixed $rawProviderBody,
        mixed $requestedExactSiteUrl,
        mixed $trustedNow,
    ): ?array {
        if (PHP_SAPI!=='cli' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('PORTAL_GSC_F117_PROPERTY_LAB')!=='1' ||
            $httpStatus!==200 ||
            !is_int($trustedNow) ||
            $trustedNow<1600000000 || $trustedNow>2200000000 ||
            !is_string($rawProviderBody) ||
            $rawProviderBody==='' ||
            strlen($rawProviderBody)>self::MAX_BODY_BYTES)
            return null;

        $exact=GoogleSearchConsolePropertyIdentifier::exactSiteUrl(
            $requestedExactSiteUrl
        );
        if($exact===null)return null;
        try{
            $doc=json_decode($rawProviderBody,true,16,JSON_THROW_ON_ERROR);
        }catch(Throwable){
            return null;
        }
        if(!is_array($doc) || array_is_list($doc) ||
            count($doc)!==1 || !array_key_exists('siteEntry',$doc) ||
            !is_array($doc['siteEntry']) ||
            !array_is_list($doc['siteEntry']) ||
            count($doc['siteEntry'])>self::MAX_SITE_ENTRIES)
            return null;

        $seen=[];
        $matchedLevel=null;
        foreach($doc['siteEntry'] as $entry){
            if(!is_array($entry) ||
                array_is_list($entry) ||
                count($entry)!==2 ||
                !array_key_exists('siteUrl',$entry) ||
                !array_key_exists('permissionLevel',$entry) ||
                !is_string($entry['permissionLevel']) ||
                !in_array($entry['permissionLevel'],self::LEVELS,true))
                return null;
            $site=GoogleSearchConsolePropertyIdentifier::exactSiteUrl(
                $entry['siteUrl']
            );
            if($site===null || isset($seen[$site]))return null;
            $seen[$site]=true;
            if(hash_equals($exact,$site))$matchedLevel=$entry['permissionLevel'];
        }
        if($matchedLevel===null ||
            !in_array($matchedLevel,self::REVIEW_LEVELS,true))
            return null;

        // This is a proof of a matching *untrusted JSON field* only.
        // Do not turn it into a Portal GSC permission or tenant grant.
        return [
            'contractVersion'=>'portal-gsc-f117-site-list-evidence-lab-v1',
            'status'=>'SYNTHETIC_SITE_MATCH_NOT_GOOGLE_AUTHORITY',
            'siteIdentifierFingerprint'=>GoogleSearchConsolePropertyIdentifier::fingerprint(
                $exact
            ),
            'exactSiteUrlMatched'=>true,
            'candidatePermissionLevel'=>$matchedLevel,
            'minimumCandidateLevel'=>'siteFullUser',
            'siteEntryCount'=>count($doc['siteEntry']),
            'providerOriginVerified'=>false,
            'googleAccessTokenVerified'=>false,
            'googleAccountOwnershipVerified'=>false,
            'googleSiteAccessCheckedNow'=>false,
            'consentVerified'=>false,
            'portalTenantMembershipAuthorized'=>false,
            'recordedGrantWritten'=>false,
            'customerDisplayAuthorized'=>false,
            'productionReadAuthorized'=>false,
            'googleNetworkPerformed'=>false,
            'releaseStatus'=>'HOLD_AUTHENTICATED_GOOGLE_RESPONSE_AND_APPSEC',
        ];
    }

    public static function canProvisionGrant():bool{return false;}
    public static function canAuthorizeCustomer():bool{return false;}
    public static function canContactGoogle():bool{return false;}
}
