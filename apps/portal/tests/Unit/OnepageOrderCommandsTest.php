<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Infrastructure\Commerce\OnepageOrderCommands;
use PHPUnit\Framework\TestCase;

final class OnepageOrderCommandsTest extends TestCase
{
    public function test_identity_and_prices_are_not_accepted_as_selection_fields():void
    {
        $calls=[];$commands=new OnepageOrderCommands(function(...$args)use(&$calls){$calls[]=$args;return '{"status":"denied"}';});
        $selection=['site'=>'onepage','channels'=>[],'tier'=>'minimum'];
        foreach (['company'=>'other','buyer'=>'other','price'=>1] as $key=>$value) {
            self::assertSame(['status'=>'invalid_selection'],$commands->createDraft(str_repeat('a',64),'own-key',$selection+[$key=>$value]));
        }
        self::assertSame(['status'=>'denied'],$commands->createDraft('invalid','own-key',$selection));
        self::assertSame([],$calls);
        $commands->createDraft(str_repeat('a',64),'own-key',$selection);
        self::assertSame('command',$calls[0][0]);
        self::assertSame(hash('sha256',str_repeat('a',64)),$calls[0][1][0]);
        self::assertSame(hash('sha256','own-key'),$calls[0][1][2]);
        $quote=json_decode($calls[0][1][5],true,512,JSON_THROW_ON_ERROR);
        self::assertSame(99000,$quote['site_setup_cents']);
        self::assertSame([33000,33000,33000],$quote['illustrative_three_parts_cents']);
        self::assertFalse($quote['real_charge_enabled']);
    }

    public function test_acceptance_is_exact_and_write_revalidates_after_review():void
    {
        $review=['quote_id'=>'11111111-1111-4111-8111-111111111111','offer_digest'=>str_repeat('a',64),
            'terms_digest'=>str_repeat('b',64),'contract_ref'=>'onepage-agreement','contract_revision'=>1,'contract_digest'=>str_repeat('c',64)];
        $calls=[];
        $commands=new OnepageOrderCommands(function($function,$args)use(&$calls,$review){
            $calls[]=$function;
            return json_encode($function==='review_order'?$review:['status'=>'denied'],JSON_THROW_ON_ERROR);
        });
        $good=['accepted'=>true]+$review;
        foreach ([$good+['company'=>'forged'],array_replace($good,['contract_revision'=>'1']),array_replace($good,['accepted'=>'true'])] as $bad) {
            self::assertSame(['status'=>'invalid_acceptance'],$commands->accept(str_repeat('d',64),'key',$review['quote_id'],$bad));
        }
        self::assertNotContains('accept_order',$calls);
        self::assertSame(['status'=>'denied'],$commands->accept(str_repeat('d',64),'key',$review['quote_id'],$good));
        self::assertSame(['review_order','accept_order'],array_slice($calls,-2));
    }
}
