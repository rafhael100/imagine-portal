<?php
declare(strict_types=1);
namespace NativeHttpBriefing;
use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel;
use RuntimeException;
/** In-process client. Only application-issued encrypted cookies; never sets a session. */
final class Client
{
    private ?string $cookie=null;
    public function __construct(private $app,private Kernel $kernel){}
    public function request(string $method,string $path,array $body=[],?string $csrf=null):Request
    {
        if(!preg_match('~\A/_development/(auth|commerce/onepage)/[A-Za-z0-9/_-]+\z~D',$path))throw new RuntimeException('HTTP_CASE_REFUSED');
        $this->app['session']->forgetDrivers();$this->app->forgetInstance('session.store');
        $headers=['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','HTTPS'=>'on',
            'HTTP_ORIGIN'=>'https://portal-dev.imaginecomunica.com.br','HTTP_SEC_FETCH_SITE'=>'same-origin','REMOTE_ADDR'=>'192.0.2.81'];
        if($csrf!==null)$headers['HTTP_X_CSRF_TOKEN']=$csrf;
        return Request::create('https://portal-dev.imaginecomunica.com.br'.$path,$method,[],
            $this->cookie===null?[]:['__Host-imagine-portal-session'=>$this->cookie],[],$headers,json_encode($body,JSON_THROW_ON_ERROR));
    }
    public function collect($response):array
    {
        foreach($response->headers->getCookies() as $c)if($c->getName()==='__Host-imagine-portal-session'){
            if(!$c->isSecure()||!$c->isHttpOnly()||$c->getPath()!=='/'||$c->getDomain()!==null)throw new RuntimeException('HTTP_CASE_REFUSED');
            $this->cookie=$c->getValue();
        }
        if($response->getStatusCode()===204 && $response->getContent()==='')return [];
        return json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR)??[];
    }
    public function call(string $method,string $path,array $body=[],?string $csrf=null):array
    {
        $r=$this->request($method,$path,$body,$csrf);$response=$this->kernel->handle($r);
        try{$data=$this->collect($response);}finally{$this->kernel->terminate($r,$response);}
        return [$response->getStatusCode(),$data,$r];
    }
    public function token():string
    {
        [$status,$data]=$this->call('GET','/_development/auth/csrf');
        if($status!==200||$this->cookie===null||!is_string($data['csrf_token']??null))throw new RuntimeException('HTTP_CASE_REFUSED');
        return $data['csrf_token'];
    }
    public function post(string $path,array $body):array{return $this->call('POST',$path,$body,$this->token());}
    public function __debugInfo():array{return ['scope'=>'synthetic in-process client'];}
    public function __serialize():array{throw new RuntimeException('HTTP_CASE_REFUSED');}
}
