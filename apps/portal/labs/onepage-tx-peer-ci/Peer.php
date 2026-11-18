<?php
declare(strict_types=1);
namespace TxBoundaryFixture;
use RuntimeException;
final class Peer
{
    private $process;private array $pipes=[];private string $buffer='';private bool $closed=false;
    public readonly int $backend;
    public function __construct(array $identity)
    {
        $ini=php_ini_loaded_file();if(!is_string($ini))throw new RuntimeException('PEER_REFUSED');
        $this->process=proc_open([PHP_BINARY,'-c',$ini,__DIR__.'/peer-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$this->pipes,null,getenv());
        if(!is_resource($this->process))throw new RuntimeException('PEER_REFUSED');
        foreach($this->pipes as $p)stream_set_blocking($p,false);
        try{$this->send(['identity'=>$identity]);$ready=$this->receive();if(array_keys($ready)!==['kind','backend']||$ready['kind']!=='ready'||!is_int($ready['backend'])||$ready['backend']<1)throw new RuntimeException('PEER_REFUSED');$this->backend=$ready['backend'];}
        catch(\Throwable){$this->close();throw new RuntimeException('PEER_REFUSED');}
    }
    private function send(array $data):void
    {
        $line=json_encode($data,JSON_THROW_ON_ERROR)."\n";if(strlen($line)>4096)throw new RuntimeException('PEER_REFUSED');
        $deadline=hrtime(true)+2000000000;
        while($line!==''){
            $left=$deadline-hrtime(true);if($left<=0)throw new RuntimeException('PEER_TIMEOUT');
            $r=null;$w=[$this->pipes[0]];$e=null;if(stream_select($r,$w,$e,0,min(100000,(int)($left/1000)))!==1)continue;
            $n=fwrite($this->pipes[0],$line);if($n===false||$n===0)throw new RuntimeException('PEER_REFUSED');$line=substr($line,$n);
        }
    }
    private function receive():array
    {
        $deadline=hrtime(true)+3000000000;
        while(!str_contains($this->buffer,"\n")){
            $left=$deadline-hrtime(true);if($left<=0||strlen($this->buffer)>4096)throw new RuntimeException('PEER_TIMEOUT');
            $r=[$this->pipes[1]];$w=null;$e=null;if(stream_select($r,$w,$e,0,min(100000,(int)($left/1000)))!==1)continue;
            $part=fread($this->pipes[1],4096);if($part===false||($part===''&&feof($this->pipes[1])))throw new RuntimeException('PEER_REFUSED');$this->buffer.=$part;
        }
        [$line,$this->buffer]=explode("\n",$this->buffer,2);$data=json_decode($line,true,16,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('PEER_REFUSED');return $data;
    }
    public function start(string $command):void{$this->send(['command'=>$command]);if($this->receive()!==['kind'=>'started'])throw new RuntimeException('PEER_REFUSED');}
    public function finish():mixed{$r=$this->receive();if(array_keys($r)!==['kind','value']||$r['kind']!=='result')throw new RuntimeException('PEER_REFUSED');return $r['value'];}
    public function call(string $command):mixed{$this->start($command);return $this->finish();}
    public function close():void
    {
        if($this->closed)return;$this->closed=true;$clean=false;
        try{$this->send(['command'=>'stop']);}catch(\Throwable){}
        if(isset($this->pipes[0])&&is_resource($this->pipes[0]))fclose($this->pipes[0]);
        $deadline=hrtime(true)+2000000000;
        do{
            if(is_resource($this->pipes[1]))fread($this->pipes[1],4096);
            $s=proc_get_status($this->process);if(!$s['running']){$clean=$s['exitcode']===0;break;}usleep(1000);
        }while(hrtime(true)<$deadline);
        if($s['running']){
            proc_terminate($this->process,9);$deadline=hrtime(true)+2000000000;
            do{$s=proc_get_status($this->process);if(!$s['running'])break;usleep(1000);}while(hrtime(true)<$deadline);
        }
        foreach($this->pipes as $p)if(is_resource($p))fclose($p);
        if(!$s['running'])proc_close($this->process);
        if(!$clean)throw new RuntimeException('PEER_CLEANUP_REFUSED');
    }
}
