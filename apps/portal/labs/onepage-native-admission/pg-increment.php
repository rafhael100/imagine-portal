<?php
declare(strict_types=1);
namespace App\Labs\OnepageNativeAdmission;

use App\Homologation\Commerce\CommercialSelectionIntent as Intent;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Unwired checkpoint for the existing #923 setup, after order_terms preparation
 * and before accept_order. Supply only guarded composition and native requests.
 * The runner's snapshot function must attest before/after its narrow diagnostics.
 */
function verifyPreparedOwnReview(
    OwnReview $source,
    Request $owner,
    Request $otherBuyerSameCompany,
    string $quoteId,
    array $existingServerReview,
    Closure $snapshot,
):void {
    $intent=['site'=>'onepage','channels'=>'','tier'=>'minimum'];
    // Intentionally give B a correct local binding too: the native review must
    // deny on ownership even when every local precondition passes.
    $bind=static function(Request $r,string $id)use($intent):void{
        $state=$r->session()->get('portal.browser_auth');
        if (!is_array($state)||($state['phase']??null)!=='business'||!is_string($state['reference']??null)) {
            throw new RuntimeException('ATTESTED_NATIVE_REQUESTS_REQUIRED');
        }
        Intent::replace($r->session(),$intent);
        $r->session()->put(OwnReview::KEY,['quote_id'=>$id,'selection_digest'=>OwnReview::selectionDigest($intent),
            'business_digest'=>hash('sha256',$state['reference'])]);
    };
    $before=$snapshot();
    $bind($owner,$quoteId);
    $bind($otherBuyerSameCompany,$quoteId);
    $own=$source->read($owner);
    $other=$source->read($otherBuyerSameCompany);
    $bind($otherBuyerSameCompany,'00000000-0000-4000-8000-000000000000');
    $absent=$source->read($otherBuyerSameCompany);
    if (($own['status']??null)!=='review'||($own['quote_id']??null)!==$quoteId
        ||array_intersect_key($own,array_flip(['buyer','company','terms','contract_text']))!==[]
        ||$own['payment_link']!==null||$own['settled']!==false
        ||$other!==OwnReview::DENIED||$absent!==$other) throw new RuntimeException('OWN_REVIEW_BOUNDARY_FAILED');
    foreach (['offer_digest','terms_digest','contract_digest','contract_ref','contract_revision'] as $key) {
        if ($own[$key]!==($existingServerReview[$key]??null)) throw new RuntimeException('NATIVE_REVIEW_PROJECTION_DRIFT');
    }
    if ($snapshot()!==$before) throw new RuntimeException('READ_CHANGED_PROTECTED_ROWS');
    echo "PASS_OWN_REVIEW_PG: own snapshot, second buyer vs absent, no writes or billing\n";
}
