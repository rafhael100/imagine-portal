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
        $owner = $profile['customer_id'];
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
        $response = [
            'schema' => 'portal_f423_ci_authenticated_order_source_v1',
            'source' => 'ci_only_portal_customer_purchase_pg18',
            'order_id' => $order['id'],
            'company_id' => $owner,
            'order_revision' => $order['revision'],
            'purchase_state' => $confirmed ? 'synthetic_confirmed' : 'pending_or_unverified',
            'purchase_evidence' => $confirmed ? 'fake_provider_callback_only' : null,
            'onboarding_state' => $submitted ? 'briefing_submitted_unreviewed' : 'awaiting_briefing',
            'briefing_submission_revision' => $submitted ? $order['revision'] : null,
            'briefing_submission_sha256' => $submitted
                ? hash('sha256', json_encode($briefing, JSON_THROW_ON_ERROR))
                : null,
            'briefing_review_state' => 'not_approved',
            'approved_briefing_revision' => null,
            'channels' => $channels,
            'ready_channels_count' => 0,
            'decision_persisted_by_performance' => false,
            'performance_s2s_proven' => false,
            'operational_enabled' => false,
            'ads_write_enabled' => false,
            'billable' => false,
        ];
        return response()->json($response)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
