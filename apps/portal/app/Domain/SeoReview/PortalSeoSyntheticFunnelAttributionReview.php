<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use DateTimeImmutable;
use DateTimeZone;

/**
 * SEO F117 — purely synthetic Visitas -> Leads -> Clientes contract.
 *
 * Accepts only fixed, fully labeled synthetic Analytics + CRM fixtures.
 * Does NOT verify any provider, authenticate a user, join real datasets,
 * establish attribution or authorize display to customers.
 */
final class PortalSeoSyntheticFunnelAttributionReview
{
    private const REF='/\A[a-z0-9][a-z0-9_-]{2,63}\z/D';
    private const DATE='/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D';

    public function compare(mixed $input):?array
    {
        if(PHP_SAPI!=='cli' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('CI')!=='true' ||
            getenv('PORTAL_SEO_F117_LAB')!=='1' ||
            !self::exact($input,['tenantRef','period','analytics','crm']) ||
            !self::validRef($input['tenantRef']) ||
            !self::validPeriod($input['period']))
            return null;

        $tenant=$input['tenantRef'];
        $period=$input['period'];
        $a=$input['analytics'];
        $c=$input['crm'];

        if(!self::exact($a,[
            'source','tenantRef','period','sessions','providerVerified'
        ]) || $a['source']!=='synthetic_analytics_ci' ||
            $a['tenantRef']!==$tenant ||
            $a['period']!==$period ||
            $a['providerVerified']!==false ||
            !self::count($a['sessions']))
            return null;

        if(!self::exact($c,[
            'source','tenantRef','period','attributedLeads',
            'attributedCustomers','providerVerified','attributionVerified'
        ]) || $c['source']!=='synthetic_crm_ci' ||
            $c['tenantRef']!==$tenant ||
            $c['period']!==$period ||
            $c['providerVerified']!==false ||
            $c['attributionVerified']!==false ||
            !self::count($c['attributedLeads']) ||
            !self::count($c['attributedCustomers']))
            return null;

        // This proof uses a deliberately restrictive *toy* assumption:
        // at most one attributed lead per session and one customer per lead.
        // Real CRM attribution must define events, sessions, duplicates and
        // time windows independently. Never silently apply this in production.
        $visits=$a['sessions'];
        $leads=$c['attributedLeads'];
        $customers=$c['attributedCustomers'];
        if($customers>$leads || $leads>$visits)return null;

        return [
            'contractVersion'=>'portal-seo-synthetic-funnel-attribution-lab-v1',
            'methodology'=>'VISITAS_LEADS_CLIENTES',
            'syntheticAssumption'=>'one_attributed_lead_per_session',
            'sourceType'=>'SYNTHETIC_OFFLINE_LAB_NOT_ACTUAL_ANALYTICS_OR_CRM',
            'period'=>$period,
            'stages'=>[
                ['name'=>'VISITAS','value'=>$visits,
                    'source'=>'synthetic_analytics_ci'],
                ['name'=>'LEADS','value'=>$leads,
                    'source'=>'synthetic_crm_ci'],
                ['name'=>'CLIENTES','value'=>$customers,
                    'source'=>'synthetic_crm_ci']
            ],
            'conversionRatesPercent'=>[
                'visitsToLeads'=>$visits>0
                    ?round(100*$leads/$visits,2):null,
                'leadsToCustomers'=>$leads>0
                    ?round(100*$customers/$leads,2):null,
                'visitsToCustomers'=>$visits>0
                    ?round(100*$customers/$visits,2):null,
            ],
            'realAttributionVerified'=>false,
            'analyticsOriginVerified'=>false,
            'crmOriginVerified'=>false,
            'portalSessionVerified'=>false,
            'portalTenantGrantVerified'=>false,
            'customerDisplayAuthorized'=>false,
            'productionReadAuthorized'=>false,
            'publicationAuthorized'=>false,
            'networkPerformed'=>false,
            'releaseStatus'=>'HOLD_AUTHENTICATED_ANALYTICS_CRM_ATTRIBUTION'
        ];
    }

    private static function exact(mixed $input,array $keys):bool
    {
        if(!is_array($input)||array_is_list($input)||
            count($input)!==count($keys))return false;
        $current=array_keys($input);
        sort($current);sort($keys);
        return $current===$keys;
    }

    private static function count(mixed $value):bool
    {
        return is_int($value) && $value>=0 && $value<=1000000000;
    }

    private static function validRef(mixed $value):bool
    {
        return is_string($value) &&
            preg_match(self::REF,$value)===1;
    }

    private static function validPeriod(mixed $period):bool
    {
        if(!self::exact($period,['startDate','endDate']))return false;
        foreach(['startDate','endDate'] as $key){
            $date=$period[$key];
            if(!is_string($date) ||
                preg_match(self::DATE,$date)!==1)return false;
            [$year,$month,$day]=array_map('intval',explode('-',$date));
            if(!checkdate($month,$day,$year))return false;
        }
        if($period['endDate']<$period['startDate'])return false;
        $tz=new DateTimeZone('UTC');
        $from=DateTimeImmutable::createFromFormat(
            '!Y-m-d',$period['startDate'],$tz
        );
        $to=DateTimeImmutable::createFromFormat(
            '!Y-m-d',$period['endDate'],$tz
        );
        if($from===false||$to===false)return false;
        $days=$from->diff($to)->days+1;
        return $days>=1 && $days<=92;
    }

    public static function authorizesCustomerDisplay():bool{return false;}
    public static function authorizesRealAttribution():bool{return false;}
    public static function authorizesGoogleHttp():bool{return false;}
}
