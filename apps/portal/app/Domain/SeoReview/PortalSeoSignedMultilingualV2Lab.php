<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * Portal SEO F138: strict verification of Engine F126's DOMAIN-SEPARATED v2
 * signed municipal multilingual HTML evidence. CI lab ONLY.
 *
 * This class never changes the v1 verifier and never establishes any real
 * identity, production key custody, replay protection, or publishing right.
 */
final class PortalSeoSignedMultilingualV2Lab
{
    private const PREFIX="IMAGINE-SEO-F126-SIGNED-MULTILINGUAL-REVIEW\n";
    private const ENVELOPE=['contractVersion','payloadBase64','signatureBase64'];
    private const PAYLOAD=[
        'issuer','audience','tenantRef','runRef','engineCommitSha',
        'issuedAt','expiresAt','nonce','reportSha256','reportBase64'
    ];
    private const REPORT=[
        'contractVersion','reviewStatus','summary','inventoryParityStatus',
        'actualBuildStatus','hreflangReviewStatus','auditedRealLocalFiles',
        'realPublishedSiteVerified','officialIbgeSourceVerified',
        'editorialApprovalVerified','publicationAuthorized',
        'indexingAuthorized','customerDisplayAuthorized'
    ];
    private const SUMMARY=[
        'inspectedEntries','serviceMunicipalityGroups','incompleteLocaleGroups',
        'missingLocaleSlots','stateMismatchGroups','wrongLocalePrefixCount',
        'inventoryConflicts','auditedBuildPages','expectedRoutesMissing',
        'expectedRoutesWrongLocale','technicalErrors','privateEditorialPreviewLeaks',
        'missingHreflangLinks','wrongHreflangTargets','ambiguousHreflangAliases'
    ];

    public function review(
        mixed $envelope,
        string $trustedPublicKey,
        string $trustedTenantRef,
        string $trustedRunRef,
        string $expectedEngineSha,
        int $trustedNow
    ): ?array {
        if (PHP_SAPI!=='cli' ||
            getenv('CI')!=='true' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('PORTAL_SEO_F138_LAB')!=='1' ||
            !extension_loaded('sodium') ||
            strlen($trustedPublicKey)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
            preg_match('/\A[a-z0-9][a-z0-9_-]{1,79}\z/D',$trustedTenantRef)!==1 ||
            preg_match('/\A[a-zA-Z0-9:_-]{3,100}\z/D',$trustedRunRef)!==1 ||
            preg_match('/\A[a-f0-9]{40}\z/D',$expectedEngineSha)!==1 ||
            $trustedNow<1700000000 || $trustedNow>4102444800 ||
            !self::exact($envelope,self::ENVELOPE) ||
            $envelope['contractVersion']!=='seo-f126-signed-multilingual-review-v2')
            return null;

        $raw=self::base64($envelope['payloadBase64'],25000);
        $signature=self::base64($envelope['signatureBase64'],64);
        if ($raw===null || $signature===null ||
            strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES) return null;

        try {
            if (!sodium_crypto_sign_verify_detached(
                $signature,self::PREFIX.$raw,$trustedPublicKey)) return null;
            $claims=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        } catch (\Throwable) {return null;}

        if (!self::exact($claims,self::PAYLOAD) ||
            $claims['issuer']!=='imagine-seo-engine-f126-ci-lab' ||
            $claims['audience']!=='imagine-portal-f126-ci-lab' ||
            !is_string($claims['tenantRef']) ||
            !hash_equals($trustedTenantRef,$claims['tenantRef']) ||
            !is_string($claims['runRef']) ||
            !hash_equals($trustedRunRef,$claims['runRef']) ||
            !is_string($claims['engineCommitSha']) ||
            !hash_equals($expectedEngineSha,$claims['engineCommitSha']) ||
            !is_int($claims['issuedAt']) ||
            !is_int($claims['expiresAt']) ||
            $claims['issuedAt']>$trustedNow+30 ||
            $claims['issuedAt']<$trustedNow-600 ||
            $claims['expiresAt']!==$claims['issuedAt']+240 ||
            $claims['expiresAt']<$trustedNow ||
            !is_string($claims['nonce']) ||
            preg_match('/\A[A-Za-z0-9_-]{32}\z/D',$claims['nonce'])!==1 ||
            !is_string($claims['reportSha256']) ||
            preg_match('/\A[a-f0-9]{64}\z/D',$claims['reportSha256'])!==1)
            return null;

        $reportBytes=self::base64($claims['reportBase64'],16000);
        if ($reportBytes===null || strlen($reportBytes)<250 ||
            !hash_equals(hash('sha256',$reportBytes),$claims['reportSha256']))
            return null;

        try {
            $report=json_decode($reportBytes,true,32,JSON_THROW_ON_ERROR);
        } catch (\Throwable) {return null;}
        if (!self::exact($report,self::REPORT) ||
            $report['contractVersion']!=='seo-municipal-multilingual-review-dto-v2' ||
            !in_array($report['reviewStatus'],['blocked','human_independent_review_required'],true) ||
            !in_array($report['inventoryParityStatus'],
                ['corrections_required','human_multilingual_editorial_review_required'],true) ||
            !in_array($report['actualBuildStatus'],
                ['blocked','human_independent_review_required'],true) ||
            !in_array($report['hreflangReviewStatus'],
                ['blocked','human_independent_review_required'],true) ||
            $report['auditedRealLocalFiles']!==true)
            return null;

        foreach (['realPublishedSiteVerified','officialIbgeSourceVerified',
                  'editorialApprovalVerified','publicationAuthorized',
                  'indexingAuthorized','customerDisplayAuthorized'] as $flag)
            if ($report[$flag]!==false) return null;

        $s=$report['summary'];
        if (!self::exact($s,self::SUMMARY)) return null;
        foreach (self::SUMMARY as $key)
            if (!is_int($s[$key])||$s[$key]<0||$s[$key]>1000000000) return null;
        if ($s['inspectedEntries']<1 || $s['inspectedEntries']>20000 ||
            $s['serviceMunicipalityGroups']<1 ||
            $s['serviceMunicipalityGroups']>$s['inspectedEntries'] ||
            $s['auditedBuildPages']<1 ||
            $s['incompleteLocaleGroups']>$s['serviceMunicipalityGroups'])
            return null;

        $mustBlock=$report['inventoryParityStatus']==='corrections_required' ||
            $report['actualBuildStatus']==='blocked' ||
            $report['hreflangReviewStatus']==='blocked';
        foreach ([
            'incompleteLocaleGroups','missingLocaleSlots','stateMismatchGroups',
            'wrongLocalePrefixCount','inventoryConflicts','expectedRoutesMissing',
            'expectedRoutesWrongLocale','technicalErrors',
            'privateEditorialPreviewLeaks','missingHreflangLinks',
            'wrongHreflangTargets','ambiguousHreflangAliases'
        ] as $metric)
            if ($s[$metric]>0) $mustBlock=true;
        if (($report['reviewStatus']==='blocked')!==$mustBlock) return null;

        // The signature authenticates bytes against this *synthetic* trusted
        // caller only. Never echo payload, identifiers or route information.
        return [
            'contractVersion'=>'portal-seo-f138-verified-multilingual-v2-lab-v1',
            'reviewStatus'=>$report['reviewStatus'],
            'summary'=>$s,
            'ed25519SignatureVerifiedInLab'=>true,
            'engineCommitMatched'=>true,
            'tenantAndRunMatchedSyntheticCaller'=>true,
            'realTenantAuthenticated'=>false,
            'productionSignerCustodyVerified'=>false,
            'replayLedgerLinked'=>false,
            'publicationAuthorized'=>false,
            'indexingAuthorized'=>false,
            'customerDisplayAuthorized'=>false,
            'releaseStatus'=>'HOLD_REAL_SIGNER_LEDGER_IDENTITY_AND_STAGING'
        ];
    }

    private static function base64(mixed $encoded,int $max):?string
    {
        if(!is_string($encoded)||$encoded===''||
           strlen($encoded)>4*(int)ceil($max/3)+8) return null;
        $raw=base64_decode($encoded,true);
        if($raw===false||strlen($raw)>$max||
           base64_encode($raw)!==$encoded) return null;
        return $raw;
    }

    private static function exact(mixed $obj,array $keys):bool
    {
        if (!is_array($obj)||array_is_list($obj)||
            count($obj)!==count($keys)) return false;
        $actual=array_keys($obj);
        sort($actual);sort($keys);
        return $actual===$keys;
    }

    public static function allowsCustomerDisplay():bool{return false;}
    public static function allowsProductionPublish():bool{return false;}
}
