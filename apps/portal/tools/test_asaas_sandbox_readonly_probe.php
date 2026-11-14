<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true') {
    fwrite(STDERR,"NO-GO: only offline CI may execute these test fixtures.\n");
    exit(2);
}
require dirname(__DIR__).'/app/Homologation/Commerce/AsaasSandboxReadinessProbe.php';
use App\Homologation\Commerce\AsaasSandboxReadinessProbe;

$checks=0;
function yes(bool $ok,string $why):void {
    global $checks; ++$checks;
    if (!$ok)throw new RuntimeException('Readonly Sandbox probe assertion '.$checks.': '.$why);
}
function mustDeny(callable $f):void {
    $blocked=false;
    try { $f(); } catch (InvalidArgumentException) {$blocked=true;}
    yes($blocked,'unreviewed or production credential accepted');
}

$key='$aact_hmlg_'.str_repeat('F',48);
$calls=0;
$service=new AsaasSandboxReadinessProbe(
    static function(string $method,string $url,array $headers) use (&$calls,$key):array {
        ++$calls;
        yes($method==='GET','method must remain GET');
        yes($url==='https://api-sandbox.asaas.com/v3/payments?offset=0&limit=1',
            'origin and read-only resource must be pinned');
        yes(in_array('access_token: '.$key,$headers,true),'token header not supplied');
        yes(in_array('User-Agent: ImaginePortal-ReadOnlySandboxProbe/1.0',$headers,true),
            'provider-required User-Agent missing');
        yes(in_array('Accept: application/json',$headers,true),'JSON read expected');
        return ['status'=>200,'body'=>json_encode([
            'data'=>[],'hasMore'=>false,'totalCount'=>0,'limit'=>1,'offset'=>0
        ],JSON_THROW_ON_ERROR)];
    }
);
yes($service->inspect($key),'read-only authorized empty sandbox list must pass');
yes($calls===1,'should make only one GET');
mustDeny(static fn()=>$service->inspect('$aact_prod_'.str_repeat('P',48)));
mustDeny(static fn()=>$service->inspect('not-a-real-sandbox-token'));
mustDeny(static fn()=>$service->inspect($key."\r\nHeader: forged"));
yes($calls===1,'denied credential must never reach network');
foreach ([
    ['status'=>401,'body'=>'{}'],
    ['status'=>403,'body'=>'{}'],
    ['status'=>429,'body'=>'{}'],
    ['status'=>302,'body'=>'{}'],
    ['status'=>500,'body'=>'{}'],
    ['status'=>200,'body'=>'{"errors":[{"code":"not-authorized"}]}'],
    ['status'=>200,'body'=>'{"data":{},"hasMore":false}'],
    ['status'=>200,'body'=>'{"data":[],"hasMore":"false"}'],
    ['status'=>200,'body'=>'{"data":[{},{}],"hasMore":true}'],
    ['status'=>200,'body'=>'not JSON'],
    ['status'=>200,'body'=>str_repeat('x',65537)],
] as $response) {
    $probe=new AsaasSandboxReadinessProbe(static fn()=> $response);
    yes(!$probe->inspect($key),'HTTP or JSON error must not pass');
}
$unavailable=new AsaasSandboxReadinessProbe(static function(){throw new RuntimeException('upstream private details');});
yes(!$unavailable->inspect($key),'exception fails closed without printing private data');
echo "PASS {$checks} strictly offline read-only Sandbox auth, fixed GET, no charge, error/redirect/prod-key refusal checks.\n";
