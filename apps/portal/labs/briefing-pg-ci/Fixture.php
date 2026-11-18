<?php
declare(strict_types=1);
namespace NativeBriefingFixture;
use PDO;
use RuntimeException;
final class Fixture
{
    public static function render(array $binding, string $before): array
    {
        $keys=['tenant_ref','order_ref','contract_ref','contract_revision','product_code','payment_id','customer_id','external_reference','amount_cents','billing_type'];
        if (array_diff($keys,array_keys($binding)) || array_diff(array_keys($binding),$keys)) throw new RuntimeException('FIXTURE_REFUSED');
        if (($binding['product_code']??null)!=='onepage' || ($binding['customer_id']??null)!=='cus_LABBriefing941'
            || ($binding['billing_type']??null)!=='CREDIT_CARD' || ($binding['tenant_ref']??null)!=='native-order-company'
            || ($binding['payment_id']??null)!=='pay_LABBriefing941'
            || ($binding['amount_cents']??null)!==33000
            || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$before)
            || !is_string($binding['order_ref']??null)) throw new RuntimeException('FIXTURE_REFUSED');
        $a=['version'=>'native-briefing-fixture-v1','source'=>'trusted-bootstrap-fixture',
            'event'=>'LAB941-entry-A','line'=>'setup-1','account'=>'native-entry-sandbox',
            'environment'=>'sandbox','binding'=>$binding,'fixture_before'=>$before];
        $a['digest']=hash('sha256',json_encode($a,JSON_THROW_ON_ERROR));
        $literal="convert_from(decode('".bin2hex(json_encode($a,JSON_THROW_ON_ERROR))."','hex'),'UTF8')::jsonb";
        return [$a,str_replace('@ANCHOR@',$literal,file_get_contents(__DIR__.'/store.sql.in'))];
    }
    public static function next(PDO $db,string $session,string $order): ?array
    {
        if ($db->inTransaction()) return null;
        $q=$db->prepare('SELECT native_briefing_fixture.next_step(?,?::uuid)');
        $q->execute([hash('sha256',$session),$order]);$v=$q->fetchColumn();
        if ($v===false || $v===null) return null;
        $result=json_decode($v,true,512,JSON_THROW_ON_ERROR);
        return $result===['next_step'=>'briefing']?$result:null;
    }
}
