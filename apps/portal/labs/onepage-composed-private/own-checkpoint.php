<?php
declare(strict_types=1);

use App\Labs\OnepageBuyer\Guard;
use App\Labs\OnepageNativeAdmission\OwnReview;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use function App\Labs\OnepageNativeAdmission\verifyPreparedOwnReview;

require_once __DIR__.'/../../app/Homologation/Commerce/CommercialSelectionIntent.php';
require_once __DIR__.'/../onepage-native-admission/OwnReview.php';
require_once __DIR__.'/../onepage-native-admission/pg-increment.php';

function checkComposedOwnReview(Connection $db,PDO $runtime,PDO $bootstrap,array $identity,Closure $barrier,
    Request $own,Request $other,string $quote,array $existingReview): void
{
    if (getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY' || $db->getPdo()!==$bootstrap) {
        throw new RuntimeException('COMPOSED_LAB_IDENTITY_REQUIRED');
    }
    $snapshot=static function()use($db,$runtime,$bootstrap,$identity,$barrier,$quote): array {
        $barrier();
        Guard::runtime($runtime,$bootstrap,$identity,'onepage_buyer_runtime');
        // Privileged fixture equality check only, never a source of a payment ID.
        $rows=$db->select("SELECT t.contract_revision,
          (SELECT count(*) FROM onepage_buyer_lab.pending_orders p WHERE p.quote_id=d.id) order_count,
          (SELECT md5(to_jsonb(t)::text)) terms_fingerprint, md5(to_jsonb(d)::text) draft_fingerprint
          FROM onepage_buyer_lab.drafts d JOIN onepage_buyer_lab.order_terms t ON t.quote_id=d.id
          WHERE d.id=CAST(? AS uuid)",[$quote]);
        $barrier();
        if (count($rows)!==1 || (int)$rows[0]->order_count!==0) throw new RuntimeException('EXPECTED_PRE_ACCEPT_QUOTE');
        return (array)$rows[0];
    };
    verifyPreparedOwnReview(OwnReview::guarded($runtime,$bootstrap,$identity,$barrier),
        $own,$other,$quote,$existingReview,$snapshot);
}
