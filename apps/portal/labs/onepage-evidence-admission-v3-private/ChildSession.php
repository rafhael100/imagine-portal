<?php
declare(strict_types=1);
/** Owns only its PHP child. Never signals the PostgreSQL cleanup shell/process. */
final class AdmissionChildSession
{
    private $process; private array $pipes=[]; private string $buffer=''; private int $bytes=0;
    public function __construct(array $command, ?array $environment=null)
    {
        $this->process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]],$this->pipes,null,$environment);
        if(!is_resource($this->process))throw new RuntimeException('ADMISSION_CHILD_REFUSED');
        foreach($this->pipes as $p)stream_set_blocking($p,false);
    }
    public function send(array $message): void
    {
        $data=json_encode($message,JSON_THROW_ON_ERROR)."\n";
        if(strlen($data)>16384)throw new RuntimeException('ADMISSION_CHILD_LIMIT');
        $end=microtime(true)+2;
        while($data!==''){
            $n=fwrite($this->pipes[0],$data);if($n===false)throw new RuntimeException('ADMISSION_CHILD_IO');
            $data=substr($data,$n);if(microtime(true)>$end)throw new RuntimeException('ADMISSION_CHILD_TIMEOUT');
            if($n===0)usleep(1000);
        }
    }
    public function receive(float $seconds=3): array
    {
        $end=microtime(true)+$seconds;
        while(!str_contains($this->buffer,"\n")){
            $s=fread($this->pipes[1],4096);if($s===false)throw new RuntimeException('ADMISSION_CHILD_IO');
            $this->bytes+=strlen($s);if($this->bytes>32768)throw new RuntimeException('ADMISSION_CHILD_LIMIT');
            $this->buffer.=$s;
            if(microtime(true)>$end||($s===''&&feof($this->pipes[1])))throw new RuntimeException('ADMISSION_CHILD_TIMEOUT');
            if($s==='')usleep(1000);
        }
        [$line,$this->buffer]=explode("\n",$this->buffer,2);
        $v=json_decode($line,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($v))throw new RuntimeException('ADMISSION_CHILD_PROTOCOL');return $v;
    }
    public function close(): void
    {
        if(!is_resource($this->process))return;
        if(is_resource($this->pipes[0]))fclose($this->pipes[0]);
        $end=microtime(true)+.2;
        while(proc_get_status($this->process)['running']&&microtime(true)<$end)usleep(1000);
        if(proc_get_status($this->process)['running'])proc_terminate($this->process);
        $end=microtime(true)+.3;
        while(proc_get_status($this->process)['running']&&microtime(true)<$end)usleep(1000);
        if(proc_get_status($this->process)['running'])proc_terminate($this->process,9);
        foreach($this->pipes as $p)if(is_resource($p))fclose($p);
        proc_close($this->process);$this->process=null;
    }
    public function __destruct(){$this->close();}
}
