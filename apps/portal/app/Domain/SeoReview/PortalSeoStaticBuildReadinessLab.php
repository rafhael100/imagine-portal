<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * F118 — strict, read-only internal review of SEO Engine F114/F117 output.
 *
 * No HTTP endpoint, no tenant binding, no actual build inspection, and no
 * authorization. The untrusted DTO is a *claim* about a local build, not
 * proof that public content, IBGE, customer identity or Google was verified.
 */
final class PortalSeoStaticBuildReadinessLab
{
    private const TOP = [
        'contractVersion','sourceType','reviewStatus','summary',
        'missingInventoryIndices','wrongLocaleInventoryIndices',
        'technicalErrorTypes','technicalWarningTypes','auditedRealLocalFiles',
        'confirmedPublishedSite','authenticatedIbgeReference',
        'realContentDigestVerified','inventoryCompletenessVerified',
        'editorialApprovalVerified','productionCanonicalVerified',
        'customerDisplayAuthorized','deploymentAuthorized',
        'indexingAuthorized','networkPerformed','releaseStatus','limitations',
    ];
    private const COUNTS = [
        'inventoryEntries','inventoryConflicts','auditedBuildPages',
        'expectedRoutesFound','expectedRoutesMissing',
        'expectedRoutesWrongLocale','omittedMissingRouteIndices',
        'omittedWrongLocaleIndices','otherSitePages',
        'technicalErrors','technicalWarnings','privateEditorialPreviewLeaks',
    ];
    private const TYPE_FIELDS = ['code','count'];
    private const FALSE_FLAGS = [
        'confirmedPublishedSite','authenticatedIbgeReference',
        'realContentDigestVerified','inventoryCompletenessVerified',
        'editorialApprovalVerified','productionCanonicalVerified',
        'customerDisplayAuthorized','deploymentAuthorized',
        'indexingAuthorized','networkPerformed',
    ];

    public function review(mixed $dto): ?array
    {
        if (PHP_SAPI !== 'cli' ||
            getenv('CI') !== 'true' ||
            getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_SEO_F118_LAB') !== '1' ||
            !self::exact($dto, self::TOP) ||
            $dto['contractVersion'] !==
                'seo-municipal-static-build-reconciliation-offline-v1' ||
            $dto['sourceType'] !==
                'operator_supplied_inventory_and_local_build_unverified' ||
            !in_array($dto['reviewStatus'], [
                'blocked','human_independent_review_required',
            ], true) ||
            $dto['releaseStatus'] !==
                'HOLD_INDEPENDENT_EDITORIAL_SOURCE_AND_STAGING_GATES' ||
            $dto['auditedRealLocalFiles'] !== true)
            return null;

        foreach (self::FALSE_FLAGS as $flag) {
            if ($dto[$flag] !== false) return null;
        }

        $s=$dto['summary'];
        if (!self::exact($s,self::COUNTS)) return null;
        foreach (self::COUNTS as $key) {
            if (!self::unsigned($s[$key],200000)) return null;
        }

        if ($s['inventoryEntries'] < 1 ||
            $s['inventoryEntries'] > 20000 ||
            $s['auditedBuildPages'] > 20000 ||
            $s['expectedRoutesFound'] + $s['expectedRoutesMissing']
                !== $s['inventoryEntries'] ||
            $s['expectedRoutesWrongLocale'] > $s['expectedRoutesFound'] ||
            $s['privateEditorialPreviewLeaks'] > $s['technicalErrors'] ||
            $s['omittedMissingRouteIndices'] > $s['expectedRoutesMissing'] ||
            $s['omittedWrongLocaleIndices'] >
                $s['expectedRoutesWrongLocale'])
            return null;

        $missing=$dto['missingInventoryIndices'];
        $wrong=$dto['wrongLocaleInventoryIndices'];
        if (!self::indices($missing,$s['inventoryEntries']) ||
            !self::indices($wrong,$s['inventoryEntries']) ||
            count($missing) + $s['omittedMissingRouteIndices'] !==
                $s['expectedRoutesMissing'] ||
            count($wrong) + $s['omittedWrongLocaleIndices'] !==
                $s['expectedRoutesWrongLocale'] ||
            array_intersect($missing,$wrong) !== [])
            return null;

        if (!self::types($dto['technicalErrorTypes'],$s['technicalErrors']) ||
            !self::types($dto['technicalWarningTypes'],$s['technicalWarnings']) ||
            !is_array($dto['limitations']) ||
            !array_is_list($dto['limitations']) ||
            count($dto['limitations']) !== 4)
            return null;
        foreach ($dto['limitations'] as $limitation) {
            if (!is_string($limitation) ||
                strlen($limitation)>800) return null;
        }

        $shouldBlock=(
            $s['inventoryConflicts']>0 ||
            $s['expectedRoutesMissing']>0 ||
            $s['expectedRoutesWrongLocale']>0 ||
            $s['technicalErrors']>0
        );
        if ($dto['reviewStatus'] !==
            ($shouldBlock?'blocked':'human_independent_review_required'))
            return null;

        // Discard ALL supplied prose, locations, URL inventory, filenames,
        // indices and raw error descriptions. Return only bounded counts and
        // technical categories. The source is still UNVERIFIED.
        return [
            'contractVersion'=>'portal-seo-f118-static-build-lab-v1',
            'sourceType'=>'UNVERIFIED_OFFLINE_BUILD_CLAIM',
            'reviewStatus'=>$shouldBlock?'REVIEW_BLOCKED':'HUMAN_REVIEW_REQUIRED',
            'counts'=>[
                'municipalInventoryRows'=>$s['inventoryEntries'],
                'localBuildPages'=>$s['auditedBuildPages'],
                'municipalPagesPresent'=>$s['expectedRoutesFound'],
                'municipalPagesMissing'=>$s['expectedRoutesMissing'],
                'wrongLanguagePages'=>$s['expectedRoutesWrongLocale'],
                'inventoryCollisions'=>$s['inventoryConflicts'],
                'technicalErrors'=>$s['technicalErrors'],
                'technicalWarnings'=>$s['technicalWarnings'],
                'privateDraftLeaks'=>$s['privateEditorialPreviewLeaks'],
            ],
            'technicalIssueCategories'=>array_values(array_map(
                static fn(array $entry):string=>$entry['code'],
                $dto['technicalErrorTypes']
            )),
            'realSiteVerified'=>false,
            'officialIbgeVerified'=>false,
            'portalIdentityVerified'=>false,
            'tenantAccessVerified'=>false,
            'realAnalyticsVerified'=>false,
            'customerDisplayAuthorized'=>false,
            'productionReadAuthorized'=>false,
            'publicationAuthorized'=>false,
            'networkPerformed'=>false,
            'releaseStatus'=>'HOLD_REAL_SOURCE_AUTHENTICATION_AND_STAGING',
        ];
    }

    private static function unsigned(mixed $v,int $max):bool
    {
        return is_int($v) && $v>=0 && $v<=$max;
    }

    private static function exact(mixed $v,array $fields):bool
    {
        if (!is_array($v)||array_is_list($v)||
            count($v)!==count($fields))return false;
        $actual=array_keys($v);
        sort($actual);sort($fields);
        return $actual===$fields;
    }

    private static function indices(mixed $v,int $total):bool
    {
        if (!is_array($v)||!array_is_list($v)||
            count($v)>200)return false;
        $seen=[];
        foreach ($v as $index) {
            if (!self::unsigned($index,$total-1) ||
                isset($seen[$index]))return false;
            $seen[$index]=true;
        }
        return true;
    }

    private static function types(mixed $types,int $total):bool
    {
        if (!is_array($types)||!array_is_list($types)||
            count($types)>120)return false;
        $seen=[];$sum=0;
        foreach ($types as $entry) {
            if (!self::exact($entry,self::TYPE_FIELDS) ||
                !is_string($entry['code']) ||
                !preg_match('/\A[a-z][a-z0-9_]{0,79}\z/D',$entry['code']) ||
                isset($seen[$entry['code']]) ||
                !self::unsigned($entry['count'],$total) ||
                $entry['count']<1)
                return false;
            $seen[$entry['code']]=true;
            $sum+=$entry['count'];
            if($sum>$total)return false;
        }
        if ($total===0 && $types!==[])return false;
        if ($total>0 && $types===[])return false;
        return true;
    }

    public static function authorizesDisplay():bool{return false;}
    public static function authorizesDeployment():bool{return false;}
}
