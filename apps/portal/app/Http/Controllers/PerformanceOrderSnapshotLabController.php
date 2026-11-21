<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Homologation\CustomerPreviewStore;
use App\Homologation\PreviewProfiles;
use Illuminate\Http\Request;

/**
 * F423 CI-only source readback. This is a REAL authenticated Portal HTTP
 * read of an RLS-scoped PostgreSQL purchase, NOT an S2S mandate issuer.
 * No consent approval, Ads connection, campaign or payment is created.
 * This endpoint is not registered outside the loopback CI preview.
 */
final class PerformanceOrderSnapshotLabController
{
    /**
     * Browser POST by the authenticated fake buyer. Approval is NOT an Ads
     * mandate or the later approval of a Performance plan.
     */
    public function approve(Request $request, string $id)
    {
        abort_unless(
            getenv('GITHUB_ACTIONS') === 'true'
            && getenv('PORTAL_PREVIEW_LOOPBACK_TEST') === '1'
            && config('app.env') === 'staging',404
        );
        abort_unless(preg_match('/\ATEST-[a-f0-9]{32}\z/D',$id)===1,404);
        abort_unless(array_diff(array_keys($request->all()),
            ['_token','revision','briefing_sha256','review_ack'])===[],422);
        $profile=app(PreviewProfiles::class)->current($request);
        abort_unless(is_array($profile) && ($profile['role']??null)==='client'
            && is_string($profile['customer_id']??null)
            && is_string($profile['id']??null),403);
        $v=$request->validate([
            'revision'=>'required|integer|min:1|max:100000',
            'briefing_sha256'=>['required','string','regex:/\A[a-f0-9]{64}\z/D'],
            'review_ack'=>'accepted',
        ]);
        $store=app(CustomerPreviewStore::class);
        abort_unless(is_array($store->read($profile['customer_id'],$id)),404);
        abort_unless($store->approveF423Briefing(
            $profile['customer_id'],$id,$profile['id'],(int)$v['revision'],$v['briefing_sha256']
        ),409,'A revisão mudou ou a confirmação fictícia não é válida.');
        return redirect('/_preview/portal/shop/orders/'.$id);
    }

    public function show(Request $request, string $id)
    {
        abort_unless(
            getenv('GITHUB_ACTIONS') === 'true'
            && getenv('PORTAL_PREVIEW_LOOPBACK_TEST') === '1'
            && config('app.env') === 'staging',
            404
        );
        abort_unless(preg_match('/\ATEST-[a-f0-9]{32}\z/D', $id) === 1, 404);
        $profile = app(PreviewProfiles::class)->current($request);
        abort_unless(
            is_array($profile)
            && ($profile['role'] ?? null) === 'client'
            && is_string($profile['customer_id'] ?? null),
            403
        );
        return $this->source($profile['customer_id'],$id);
    }

    /** Shared *verified-source* read: buyer session or authenticated CI S2S only. */
    public function source(string $owner, string $id)
    {
        abort_unless(getenv('GITHUB_ACTIONS')==='true'
            && getenv('PORTAL_PREVIEW_LOOPBACK_TEST')==='1'
            && config('app.env')==='staging'
            && preg_match('/\ATEST-[a-f0-9]{32}\z/D',$id)===1
            && preg_match('/\Ademo-[a-f0-9]{32}\z/D',$owner)===1,404);
        $store = app(CustomerPreviewStore::class);
        $order = $store->read($owner, $id);
        abort_unless(is_array($order) && ($order['product'] ?? null) === 'performance', 404);

        $offer = $order['offer_snapshot'] ?? null;
        abort_unless(is_array($offer), 409);
        $media = $offer['media_by_channel_cents'] ?? null;
        abort_unless(is_array($media), 409);
        $sandbox = $store->sandboxStatus($owner, $id);
        $confirmed = ($order['payment'] ?? null) === 'approved'
            && ($order['payment_source'] ?? null) === 'asaas_sandbox'
            && ($sandbox['state'] ?? null) === 'approved'
            && ($order['payment_hold'] ?? false) === false;

        // Saving a briefing is NOT review or approval of that revision.
        // Preserve the submission revision and digest as distinct fields.
        $briefing = $order['briefing'] ?? null;
        $submitted = is_array($briefing);
        $digest = $submitted ? hash('sha256', json_encode($briefing, JSON_THROW_ON_ERROR)) : null;
        $approval = $submitted
            ? $store->f423BriefingApproval($owner, $id, (int)$order['revision'], $digest)
            : null;
        $buyerApproved = is_array($approval)
            && ($approval['approval_kind'] ?? null) === 'ci_buyer_briefing_review';
        $channels = [];
        foreach (['google_ads', 'meta_ads'] as $provider) {
            if (!array_key_exists($provider, $media)) {
                continue;
            }
            abort_unless(is_int($media[$provider]) && $media[$provider] >= 0, 409);
            $channels[$provider] = [
                'media_cents' => $media[$provider],
                'activation_cents' => $offer['activation_per_channel_cents'] ?? null,
                'connection_state' => 'not_connected',
                'campaign_state' => 'not_created',
                'decision_state' => 'blocked',
                'decision_reason' => 'no_approved_briefing_or_verified_account_mandate',
            ];
        }
        abort_unless($channels !== [], 409);
        // Real PostgreSQL decisions by an authenticated LAB S2S consumer.
        // This is not a campaign draft and cannot imply operational readiness.
        $stored=$submitted && $buyerApproved
            ? app(\App\Homologation\F423PerformanceDecisionStore::class)->read(
                $owner,$id,(int)$order['revision'],$digest
              ) : [];
        $complete=count($stored)===count($channels);
        if($complete) {
            foreach($channels as $provider=>&$channel) {
                if(!isset($stored[$provider])
                    || $stored[$provider]['media_cents']!==$channel['media_cents']) {
                    $complete=false; break;
                }
                $channel['decision_state']=$stored[$provider]['state'];
                $channel['decision_reason']=$stored[$provider]['reason'];
            }
            unset($channel);
        }
        if(!$complete) {
            foreach($channels as &$channel) {
                $channel['decision_state']='blocked';
                $channel['decision_reason']='incomplete_or_unverified_performance_decision';
            }
            unset($channel);
        }
        $ready=0;
        if($complete) foreach($channels as $channel)
            if($channel['decision_state']==='internal_review_only') ++$ready;
        // CI-only versioned diagnostic: never a draft, buyer plan approval
        // or Ads operation. Only from the already persisted Google decision.
        $googleOnly=count($channels)===1 && isset($channels['google_ads']);
        $plan=$confirmed && $buyerApproved && $complete && $googleOnly
            && $channels['google_ads']['decision_state']==='internal_review_only'
            ? app(\App\Homologation\F423GoogleDiagnosticPlanStore::class)
                ->read($owner,$id,(int)$order['revision'],$digest)
            : null;
        $response = [
            'diagnostic_plan' => $plan,
            'plan_approved' => is_array($plan) && ($plan['buyer_plan_approved']??false)===true,
            'campaign_drafts_created' => 0,
            'schema' => 'portal_f423_ci_authenticated_order_source_v1',
            'source' => 'ci_only_portal_customer_purchase_pg18',
            'order_id' => $order['id'],
            'company_id' => $owner,
            'order_revision' => $order['revision'],
            'purchase_state' => $confirmed ? 'synthetic_confirmed' : 'pending_or_unverified',
            'purchase_evidence' => $confirmed ? 'fake_provider_callback_only' : null,
            'onboarding_state' => $buyerApproved ? 'briefing_approved_synthetic' : ($submitted ? 'briefing_submitted_unreviewed' : 'awaiting_briefing'),
            'briefing_submission_revision' => $submitted ? $order['revision'] : null,
            'briefing_submission_sha256' => $submitted
                ? $digest
                : null,
            'briefing_review_state' => $buyerApproved ? 'synthetic_buyer_approved' : 'not_approved',
            'approved_briefing_revision' => $buyerApproved ? $order['revision'] : null,
            'approved_briefing_sha256' => $buyerApproved ? $digest : null,
            'briefing_review_actor' => $buyerApproved ? $approval['approved_by'] : null,
            'channels' => $channels,
            'ready_channels_count' => $ready,
            'decision_persisted_by_performance' => $complete,
            'performance_s2s_proven' => $complete,
            'operational_enabled' => false,
            'ads_write_enabled' => false,
            'billable' => false,
        ];
        return response()->json($response)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** The buyer sees the exact immutable lab plan in the browser, separate from briefing. */
    public function planReviewForm(Request $request,string $id)
    {
        abort_unless(getenv('GITHUB_ACTIONS')==='true'
          && getenv('PORTAL_PREVIEW_LOOPBACK_TEST')==='1'
          && config('app.env')==='staging',404);
        $profile=app(PreviewProfiles::class)->current($request);
        abort_unless(is_array($profile)&&($profile['role']??null)==='client'
          && is_string($profile['customer_id']??null),403);
        $snapshot=$this->source($profile['customer_id'],$id)->getData(true);
        $plan=$snapshot['diagnostic_plan']??null;
        abort_unless(is_array($plan) && count($snapshot['channels'])===1
          && isset($snapshot['channels']['google_ads'])
          && ($snapshot['purchase_state']??null)==='synthetic_confirmed'
          && ($snapshot['briefing_review_state']??null)==='synthetic_buyer_approved'
          && $plan['plan_version']===1,404);
        return response()->view('portal.preview.f423-google-plan-review',[
            'id'=>$id,'revision'=>$snapshot['order_revision'],'plan'=>$plan
        ])->header('Cache-Control','no-store, private');
    }

    /** Explicit CSRF-protected buyer approval, does NOT grant account consent or create campaign. */
    public function approvePlan(Request $request,string $id)
    {
        abort_unless(getenv('GITHUB_ACTIONS')==='true'
          && getenv('PORTAL_PREVIEW_LOOPBACK_TEST')==='1'
          && config('app.env')==='staging',404);
        abort_unless(array_diff(array_keys($request->all()),
          ['_token','revision','plan_version','plan_fingerprint','approval_ack'])===[],422);
        $profile=app(PreviewProfiles::class)->current($request);
        abort_unless(is_array($profile)&&($profile['role']??null)==='client'
          && is_string($profile['customer_id']??null)
          && is_string($profile['id']??null),403);
        $v=$request->validate([
          'revision'=>'required|integer|min:1|max:100000',
          'plan_version'=>'required|integer|in:1',
          'plan_fingerprint'=>['required','string','regex:/\A[a-f0-9]{64}\z/D'],
          'approval_ack'=>'accepted'
        ]);
        $source=$this->source($profile['customer_id'],$id)->getData(true);
        abort_unless(is_array($source['diagnostic_plan']??null)
          && count($source['channels']??[])===1
          && isset($source['channels']['google_ads'])
          && $source['purchase_state']==='synthetic_confirmed'
          && $source['briefing_review_state']==='synthetic_buyer_approved'
          && (int)$source['order_revision']===(int)$v['revision']
          && (int)$source['diagnostic_plan']['plan_version']===(int)$v['plan_version']
          && hash_equals($source['diagnostic_plan']['plan_fingerprint'],$v['plan_fingerprint']),409);
        abort_unless(app(\App\Homologation\F423GoogleDiagnosticPlanStore::class)->approve(
            $profile['customer_id'],$id,$profile['id'],
            (int)$v['revision'],(int)$v['plan_version'],$v['plan_fingerprint']
        ),409,'O plano mudou ou o comprador não tem autorização.');
        return redirect('/_preview/portal/shop/orders/'.$id.'/plan-review');
    }
}
