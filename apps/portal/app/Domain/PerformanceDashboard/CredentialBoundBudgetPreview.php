<?php
declare(strict_types=1);

namespace App\Domain\PerformanceDashboard;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Portal F64: validate offline F107 PostgreSQL budget DTO independently of
 * the Performance planner, including every centavo in every day/channel.
 * CLI test only. Never a source of budget/spend authority or customer IAM.
 */
final readonly class CredentialBoundBudgetPreview
{
    private const SCHEMA = 'perf.portal.credential-bound-budgets.synthetic.v1';
    private const PLAN = 'perf.planning.offline-budget.synthetic.v1';

    private function __construct(
        public array $scenarios,
    ) {}

    public static function fromDto(mixed $data): ?self
    {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F64_BUDGET_PREVIEW_LAB') !== '1') {
            return null;
        }
        if (!is_array($data)
            || array_keys($data) !== [
                'schema_version','status','simulation_only',
                'credential_binding_confirmed','portal_identity_authenticated',
                'advertiser_approval_verified','provider_connected',
                'media_execution_allowed','billing_allowed',
                'ready_for_production','scenarios'
            ]
            || $data['schema_version'] !== self::SCHEMA
            || $data['status'] !== 'credential_scoped_demo_budget_preview_only'
            || $data['simulation_only'] !== true
            || $data['credential_binding_confirmed'] !== true
            || $data['portal_identity_authenticated'] !== false
            || $data['advertiser_approval_verified'] !== false
            || $data['provider_connected'] !== false
            || $data['media_execution_allowed'] !== false
            || $data['billing_allowed'] !== false
            || $data['ready_for_production'] !== false
            || !is_array($data['scenarios'])
            || !array_is_list($data['scenarios'])
            || count($data['scenarios']) > 20) {
            return null;
        }
        $seen=[];
        foreach ($data['scenarios'] as $scenario) {
            if (!self::validScenario($scenario)) {
                return null;
            }
            if (isset($seen[$scenario['scenario_ref']])) {
                return null;
            }
            $seen[$scenario['scenario_ref']]=true;
        }
        return new self($data['scenarios']);
    }

    private static function validScenario(mixed $scenario): bool
    {
        if (!is_array($scenario)
            || array_keys($scenario) !== [
                'schema_version','scenario_ref','currency','start_date','end_date',
                'number_of_days','total_centavos','channel_split_bp',
                'channel_totals_centavos','daily','simulation_only',
                'advertiser_approval_verified','provider_connected',
                'media_execution_allowed','billing_allowed','ready_for_production'
            ]
            || $scenario['schema_version'] !== self::PLAN
            || !is_string($scenario['scenario_ref'])
            || preg_match('/^scenario-demo-[a-z0-9-]{1,50}$/D',
                $scenario['scenario_ref']) !== 1
            || $scenario['currency'] !== 'BRL'
            || $scenario['simulation_only'] !== true
            || $scenario['advertiser_approval_verified'] !== false
            || $scenario['provider_connected'] !== false
            || $scenario['media_execution_allowed'] !== false
            || $scenario['billing_allowed'] !== false
            || $scenario['ready_for_production'] !== false
            || !is_int($scenario['number_of_days'])
            || $scenario['number_of_days'] < 1
            || $scenario['number_of_days'] > 366
            || !is_int($scenario['total_centavos'])
            || $scenario['total_centavos'] < 1
            || $scenario['total_centavos'] > 2_000_000_000
            || !is_array($scenario['channel_split_bp'])
            || array_keys($scenario['channel_split_bp']) !== ['google_ads','meta_ads']
            || !is_array($scenario['channel_totals_centavos'])
            || array_keys($scenario['channel_totals_centavos'])
                !== ['google_ads','meta_ads']
            || !is_array($scenario['daily'])
            || !array_is_list($scenario['daily'])) {
            return false;
        }
        $split=$scenario['channel_split_bp'];
        if (!is_int($split['google_ads']) || !is_int($split['meta_ads'])
            || $split['google_ads'] < 0 || $split['google_ads'] > 10_000
            || $split['meta_ads'] < 0 || $split['meta_ads'] > 10_000
            || $split['google_ads']+$split['meta_ads'] !== 10_000) {
            return false;
        }
        $total=$scenario['total_centavos'];
        $googleTotal=intdiv($total*$split['google_ads'],10_000);
        if (!is_int($scenario['channel_totals_centavos']['google_ads'])
            || !is_int($scenario['channel_totals_centavos']['meta_ads'])
            || $scenario['channel_totals_centavos']['google_ads'] !== $googleTotal
            || $scenario['channel_totals_centavos']['meta_ads'] !== $total-$googleTotal) {
            return false;
        }
        $start=self::parseDate($scenario['start_date']);
        $end=self::parseDate($scenario['end_date']);
        if ($start===null || $end===null || $start>$end) {
            return false;
        }
        $days=(int)$start->diff($end)->days+1;
        if ($days !== $scenario['number_of_days']
            || count($scenario['daily']) !== $days) {
            return false;
        }
        $base=intdiv($total,$days);
        $extras=$total%$days;
        $cumulative=0;
        $previousGoogle=0;
        foreach($scenario['daily'] as $day=>$row) {
            if (!is_array($row)
                || array_keys($row) !== [
                    'date','total_centavos','google_ads_centavos','meta_ads_centavos'
                ]
                || !is_string($row['date'])
                || $row['date'] !== $start->modify("+$day days")->format('Y-m-d')
                || !is_int($row['total_centavos'])
                || !is_int($row['google_ads_centavos'])
                || !is_int($row['meta_ads_centavos'])) {
                return false;
            }
            $today=$base+($day<$extras?1:0);
            $cumulative+=$today;
            $expectedGoogle=intdiv($cumulative*$split['google_ads'],10_000);
            $google=$expectedGoogle-$previousGoogle;
            if($row['total_centavos']!==$today
                || $row['google_ads_centavos']!==$google
                || $row['meta_ads_centavos']!==$today-$google
                || $row['google_ads_centavos']<0
                || $row['meta_ads_centavos']<0) {
                return false;
            }
            $previousGoogle=$expectedGoogle;
        }
        return $cumulative===$total && $previousGoogle===$googleTotal;
    }

    private static function parseDate(mixed $date): ?DateTimeImmutable
    {
        if (!is_string($date)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)!==1
            || substr($date,0,4)==='0000') {
            return null;
        }
        $result=DateTimeImmutable::createFromFormat(
            '!Y-m-d',$date,new DateTimeZone('UTC')
        );
        $warnings=DateTimeImmutable::getLastErrors();
        if($result===false || $result->format('Y-m-d')!==$date
            || ($warnings!==false
                && ($warnings['warning_count']>0 || $warnings['error_count']>0))) {
            return null;
        }
        return $result;
    }

    public function canExecuteMedia(): bool { return false; }
    public function canBill(): bool { return false; }
    public function hasHumanApproval(): bool { return false; }
    public function isAuthenticatedPortalSession(): bool { return false; }
}
