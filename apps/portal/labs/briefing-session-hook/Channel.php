<?php
declare(strict_types=1);
namespace PrivateSessionPipe;
use PrivateSessionObserver\ObservedIds;
use RuntimeException;
use Throwable;
/** One anonymous pipe, one bounded frame, then EOF. No session material. */
final class Channel
{
    private bool $spent=false;
    public function __construct(private int $fd,private int $ownerUid,private int $timeoutMs=1000) {}
    public function consume():ObservedIds
    {
        if($this->spent)throw new RuntimeException('CHANNEL_REFUSED');
        $this->spent=true;$stream=null;
        try {
            if($this->fd<3||$this->fd>1024||$this->ownerUid<0||$this->timeoutMs<10||$this->timeoutMs>5000)throw new RuntimeException();
            $info=@file_get_contents('/proc/self/fdinfo/'.$this->fd);
            if(!is_string($info)||preg_match('/^flags:[ \t]*([0-7]+)$/m',$info,$match)!==1||(octdec($match[1])&3)!==0)throw new RuntimeException();
            $stream=@fopen('php://fd/'.$this->fd,'rb');
            if(!is_resource($stream))throw new RuntimeException();
            $stat=fstat($stream);
            if($stat===false||$stat['uid']!==$this->ownerUid||($stat['mode']&0170000)!==0010000)throw new RuntimeException();
            if(!stream_set_blocking($stream,false))throw new RuntimeException();
            $deadline=hrtime(true)+$this->timeoutMs*1000000;
            $read=static function(int $length)use($stream,$deadline):string {
                $data='';
                while(strlen($data)<$length){
                    $left=$deadline-hrtime(true);if($left<=0)throw new RuntimeException();
                    $r=[$stream];$w=null;$e=null;
                    $ready=@stream_select($r,$w,$e,intdiv($left,1000000000),intdiv($left%1000000000,1000));
                    if($ready===false||$ready===0)throw new RuntimeException();
                    $part=fread($stream,$length-strlen($data));
                    if($part===false||($part===''&&feof($stream)))throw new RuntimeException();
                    $data.=$part;
                }
                return $data;
            };
            $length=unpack('Nlength',$read(4))['length'];
            if($length<1||$length>1024)throw new RuntimeException();
            $raw=$read($length);
            // A frame is accepted only when its sole writer closes: no trailer, replay or hanging writer.
            $left=$deadline-hrtime(true);if($left<=0)throw new RuntimeException();
            $r=[$stream];$w=null;$e=null;
            if(@stream_select($r,$w,$e,intdiv($left,1000000000),intdiv($left%1000000000,1000))!==1)throw new RuntimeException();
            if(fread($stream,1)!==''||!feof($stream))throw new RuntimeException();
            $data=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            if(!is_array($data)||array_keys($data)!==['version','draft','accept']||$data['version']!==1)throw new RuntimeException();
            foreach(['draft'=>['status','id','order_created','payment_requested','site_provisioned'],
                'accept'=>['status','id','quote_id','payment_requested','site_provisioned']] as $name=>$keys)
                if(!is_array($data[$name])||array_keys($data[$name])!==$keys)throw new RuntimeException();
            // Canonical wire form rejects duplicate keys and hidden whitespace/alternate encodings.
            if(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)!==$raw)throw new RuntimeException();
            return ObservedIds::fromResponses($data['draft'],$data['accept']);
        } catch(Throwable) {throw new RuntimeException('CHANNEL_REFUSED');}
        finally {if(is_resource($stream))fclose($stream);}
    }
    public function __debugInfo():array {return ['scope'=>'private one-shot pipe'];}
    public function __serialize():array {throw new RuntimeException('CHANNEL_REFUSED');}
}
