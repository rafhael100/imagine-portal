<?php
declare(strict_types=1);

namespace App\Domain\PerformanceDashboard;

/**
 * PORTAL F62: strictly typed, offline-only consumer of a composed
 * Performance F100 credential-bound PostgreSQL DEMO snapshot.
 *
 * "credential_binding_confirmed" is a DB-login test fact, NOT Portal IAM,
 * user permission, advertiser consent or operational authorization.
 */
final readonly class CredentialBoundPerformanceWorkbenchPreview
{
    private function __construct(
        public array $counts,
        public array $accounts,
        public array $campaigns,
        public array $reviews,
    ) {}

    public static function fromDto(mixed $dto):?self
    {
        if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_F62_CREDENTIAL_WORKBENCH_LAB')!=='1') {
            return null;
        }
        if (!is_array($dto)
            || array_keys($dto)!==[
                'schema_version','status','synthetic_only','read_only',
                'credential_binding_confirmed','portal_identity_authenticated',
                'real_human_approval_verified','provider_connected',
                'authorization_eligible','ready_for_production',
                'dispatch_enabled','billing_enabled',
                'counts','accounts','campaigns','reviews'
            ]
            || $dto['schema_version']!=='perf.portal.credential-bound-workbench.synthetic.v1'
            || $dto['status']!=='credential_scoped_demo_workbench_only'
            || $dto['synthetic_only']!==true
            || $dto['read_only']!==true
            || $dto['credential_binding_confirmed']!==true
            || $dto['portal_identity_authenticated']!==false
            || $dto['real_human_approval_verified']!==false
            || $dto['provider_connected']!==false
            || $dto['authorization_eligible']!==false
            || $dto['ready_for_production']!==false
            || $dto['dispatch_enabled']!==false
            || $dto['billing_enabled']!==false
            || !is_array($dto['counts'])
            || array_keys($dto['counts'])!==[
                'account_candidates','linked_campaigns','visible_reviews',
                'review_pending','review_internal_no_execution','review_rejected'
            ]
            || !is_array($dto['accounts']) || !array_is_list($dto['accounts'])
            || !is_array($dto['campaigns']) || !array_is_list($dto['campaigns'])
            || !is_array($dto['reviews']) || !array_is_list($dto['reviews'])
            || count($dto['accounts'])>20 || count($dto['campaigns'])>20
            || count($dto['reviews'])>20) {
            return null;
        }
        foreach($dto['counts'] as $number){
            if(!is_int($number) || $number<0 || $number>20) return null;
        }
        $accounts=[];
        foreach($dto['accounts'] as $account) {
            if(!is_array($account)
                || array_keys($account)!==[
                    'portal_channel','account_ref','connection_state'
                ]
                || !is_string($account['portal_channel'])
                || \App\Domain\Catalog\PerformanceChannel::tryFrom($account['portal_channel'])===null
                || !is_string($account['account_ref'])
                || preg_match('/^demo_[a-z0-9][a-z0-9_-]{0,63}$/D',$account['account_ref'])!==1
                || $account['connection_state']!=='unverified_demo') return null;
            $key=$account['portal_channel'].':'.$account['account_ref'];
            if(isset($accounts[$key]))return null;
            $accounts[$key]=true;
        }
        $campaigns=[];
        foreach($dto['campaigns'] as $item) {
            if(!is_array($item)
                || array_keys($item)!==[
                    'campaign_ref','campaign_title','portal_channel',
                    'campaign_observation_state','account_ref','account_state',
                    'portal_stage'
                ]
                || !is_string($item['campaign_ref'])
                || preg_match('/^cam-demo-[a-z0-9-]{1,70}$/D',$item['campaign_ref'])!==1
                || !is_string($item['campaign_title'])
                || preg_match('/^[a-zA-Z0-9 ()-]{1,120}$/D',$item['campaign_title'])!==1
                || !is_string($item['portal_channel'])
                || \App\Domain\Catalog\PerformanceChannel::tryFrom($item['portal_channel'])===null
                || !is_string($item['campaign_observation_state'])
                || PerformanceCampaignObservationState::tryFrom($item['campaign_observation_state'])===null
                || !is_string($item['account_ref'])
                || !isset($accounts[$item['portal_channel'].':'.$item['account_ref']])
                || $item['account_state']!=='unverified_demo'
                || $item['portal_stage']!==null
                || isset($campaigns[$item['campaign_ref']])) return null;
            $campaigns[$item['campaign_ref']]=$item['portal_channel'];
        }
        $seenReviews=[];$counts=[
            'review_pending'=>0,
            'review_internal_no_execution'=>0,
            'review_rejected'=>0
        ];
        foreach($dto['reviews'] as $review) {
            if(!is_array($review)
                || array_keys($review)!==[
                    'campaign_ref','request_ref','operation','portal_channel',
                    'review_observation_state','journaled','portal_stage'
                ]
                || !is_string($review['campaign_ref'])
                || !isset($campaigns[$review['campaign_ref']])
                || !is_string($review['request_ref'])
                || preg_match('/^req-demo-[a-z0-9-]{1,70}$/D',$review['request_ref'])!==1
                || isset($seenReviews[$review['request_ref']])
                || !in_array($review['operation'],[
                    'campaign.create','campaign.pause',
                    'campaign.replace','campaign.release'
                ],true)
                || $campaigns[$review['campaign_ref']]!==$review['portal_channel']
                || !is_string($review['review_observation_state'])
                || PerformanceReviewObservationState::tryFrom($review['review_observation_state'])===null
                || !is_bool($review['journaled'])
                || $review['journaled']!==($review['review_observation_state']!=='pending_human_demo')
                || $review['portal_stage']!==null) return null;
            $seenReviews[$review['request_ref']]=true;
            $name=match($review['review_observation_state']){
                'pending_human_demo'=>'review_pending',
                'approved_demo_no_execution'=>'review_internal_no_execution',
                'rejected_demo'=>'review_rejected',
            };
            ++$counts[$name];
        }
        if($dto['counts']['account_candidates']!==count($accounts)
            || $dto['counts']['linked_campaigns']!==count($campaigns)
            || $dto['counts']['visible_reviews']!==count($seenReviews)
            || $dto['counts']['review_pending']!==$counts['review_pending']
            || $dto['counts']['review_internal_no_execution']!==$counts['review_internal_no_execution']
            || $dto['counts']['review_rejected']!==$counts['review_rejected']) return null;

        return new self(
            $dto['counts'],$dto['accounts'],$dto['campaigns'],$dto['reviews']
        );
    }

    public function hasPortalIdentity():bool { return false; }
    public function canPublishAds():bool { return false; }
    public function canBill():bool { return false; }
    public function isRealTime():bool { return false; }
}
