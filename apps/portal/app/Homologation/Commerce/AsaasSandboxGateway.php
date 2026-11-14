<?php
declare(strict_types=1);
namespace App\Homologation\Commerce;

use Closure;
use RuntimeException;
use InvalidArgumentException;

/** Sandbox only. Callers must persist intent and lock the order before any write.
 * A write timeout has uncertain outcome: reconcile, never automatically repeat.
 * This adapter is deliberately not attached to synthetic login/payment routes.
 */
final class AsaasSandboxGateway
{
    private const BASE='https://api-sandbox.asaas.com/v3';
    public function __construct(#[\SensitiveParameter] private string $key,private ?Closure $transport=null)
    {
        if ($key===''||preg_match('/[\r\n]/',$key)) throw new InvalidArgumentException('Sandbox credential required');
    }
    private function call(string $method,string $path,?array $payload=null): array
    {
        $headers=['Content-Type: application/json','User-Agent: ImaginePortal-Sandbox/1.0','access_token: '.$this->key];
        try {
            if ($this->transport) $r=($this->transport)($method,self::BASE.$path,$headers,$payload);
            else {
                $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$payload===null?'':json_encode($payload,JSON_THROW_ON_ERROR),'timeout'=>15,'follow_location'=>0,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
                $body=@file_get_contents(self::BASE.$path,false,$ctx,0,1048577);
                $status=0;
                foreach ($http_response_header??[] as $header) if (preg_match('/^HTTP\/\S+ (\d{3})/',$header,$m)) $status=(int)$m[1];
                if ($body===false||strlen($body)>1048576) throw new RuntimeException('Transport failed');
                $r=['status'=>$status,'body'=>json_decode($body,true,512,JSON_THROW_ON_ERROR)];
            }
        } catch (\Throwable) { throw new RuntimeException($method==='GET'?'Sandbox read unavailable':'Sandbox write uncertain; reconcile before retry'); }
        if (!is_array($r)||!is_int($r['status']??null)||$r['status']<200||$r['status']>=300||!is_array($r['body']??null)) {
            throw new RuntimeException($method==='GET'?'Sandbox read rejected':'Sandbox write unconfirmed; reconcile before retry');
        }
        return $r['body'];
    }
    public function createPayment(string $customer,string $reference,int $cents,string $dueDate): array
    {
        $this->customer($customer); $this->reference($reference);
        if ($cents<1||$cents>100000000||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$dueDate)||!checkdate((int)substr($dueDate,5,2),(int)substr($dueDate,8,2),(int)substr($dueDate,0,4))) throw new InvalidArgumentException('Invalid charge terms');
        // One due charge only, not installmentCount: approved setup uses D0/D30/D60.
        $p=$this->call('POST','/payments',['customer'=>$customer,'billingType'=>'UNDEFINED','value'=>$cents/100,'dueDate'=>$dueDate,'externalReference'=>$reference,'description'=>'Imagine · homologação de contratação']);
        $this->verify($p,$customer,$reference,$cents);
        return $p;
    }
    public function payment(string $id,string $customer,string $reference,int $cents): array
    {
        if (!preg_match('/\Apay_[a-zA-Z0-9]+\z/D',$id)) throw new InvalidArgumentException('Invalid payment id');
        $this->customer($customer); $this->reference($reference);
        $p=$this->call('GET','/payments/'.$id);
        if (($p['id']??null)!==$id) throw new RuntimeException('Payment mismatch');
        $this->verify($p,$customer,$reference,$cents); return $p;
    }
    private function verify(array $p,string $customer,string $reference,int $cents): void
    {
        $value=$p['value']??null;
        if ((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||abs($value*100-round($value*100))>0.00001||
            (int)round($value*100)!==$cents||($p['customer']??null)!==$customer||($p['externalReference']??null)!==$reference||
            !preg_match('/\Apay_[a-zA-Z0-9]+\z/D',$p['id']??'')||!is_string($p['status']??null)) throw new RuntimeException('Payment financial mismatch');
    }
    public static function settled(array $verifiedPayment): bool
    {
        if (($verifiedPayment['deleted']??false)!==false) return false;
        return ($verifiedPayment['status']??null)==='RECEIVED'||(($verifiedPayment['status']??null)==='CONFIRMED'&&($verifiedPayment['billingType']??null)==='CREDIT_CARD');
    }
    public static function verifyWebhookToken(#[\SensitiveParameter] string $expected,#[\SensitiveParameter] string $received): bool
    {
        return strlen($expected)>=32&&hash_equals($expected,$received);
    }
    private function customer(string $id): void { if (!preg_match('/\Acus_[a-zA-Z0-9]+\z/D',$id)) throw new InvalidArgumentException('Invalid customer'); }
    private function reference(string $ref): void { if (!preg_match('/\Aimagine:[a-zA-Z0-9:-]{1,150}\z/D',$ref)) throw new InvalidArgumentException('Invalid reference'); }
}
