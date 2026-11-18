<?php
declare(strict_types=1);
namespace NativeHttpBriefing;
use PrivateSessionPipe\Channel;
use RuntimeException;
final class Pipe
{
    public static function frame(array $draft,array $accept,\Closure $consume):mixed
    {
        $raw=json_encode(['version'=>1,'draft'=>array_intersect_key($draft,array_flip(['status','id','order_created','payment_requested','site_provisioned'])),
            'accept'=>array_intersect_key($accept,array_flip(['status','id','quote_id','payment_requested','site_provisioned']))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        // Existing parser requires canonical key order, not response object insertion order.
        $v=json_decode($raw,true);foreach(['draft'=>['status','id','order_created','payment_requested','site_provisioned'],'accept'=>['status','id','quote_id','payment_requested','site_provisioned']] as $k=>$keys){$out=[];foreach($keys as $key)$out[$key]=$v[$k][$key];$v[$k]=$out;}
        $raw=json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(strlen($raw)>1024)throw new RuntimeException('PIPE_BRIDGE_REFUSED');
        // Anonymous OS pipe only. No socket, shell, service or arbitrary command/input execution.
        $process=proc_open(['/usr/bin/python3','-I','-c','import sys; b=sys.stdin.buffer.read(1029); sys.stdout.buffer.write(b); sys.stdout.buffer.flush()'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,null,['PATH'=>'/usr/bin:/bin','LC_ALL'=>'C']);
        if(!is_resource($process))throw new RuntimeException('PIPE_BRIDGE_REFUSED');
        $clean=false;
        try {
            $frame=pack('N',strlen($raw)).$raw;
            if(fwrite($pipes[0],$frame)!==strlen($frame))throw new RuntimeException('PIPE_BRIDGE_REFUSED');
            fclose($pipes[0]);unset($pipes[0]);$stat=fstat($pipes[1]);$fd=null;
            foreach(glob('/proc/self/fd/*') as $path){$s=@stat($path);if($s&&$s['ino']===$stat['ino']&&$s['dev']===$stat['dev']){
                $info=@file_get_contents('/proc/self/fdinfo/'.basename($path));
                if(is_string($info)&&preg_match('/^flags:[ \t]*([0-7]+)$/m',$info,$m)===1&&(octdec($m[1])&3)===0)$fd=(int)basename($path);
            }}
            if($fd===null)throw new RuntimeException('PIPE_BRIDGE_REFUSED');
            return $consume(new Channel($fd,posix_geteuid(),1000));
        } finally {
            foreach($pipes as $p)if(is_resource($p))fclose($p);
            $until=hrtime(true)+1000000000;
            do{$state=proc_get_status($process);if(!$state['running']){$clean=$state['exitcode']===0;break;}usleep(1000);}while(hrtime(true)<$until);
            if($state['running']){proc_terminate($process,9);$until=hrtime(true)+1000000000;do{$state=proc_get_status($process);if(!$state['running'])break;usleep(1000);}while(hrtime(true)<$until);}
            if(!$state['running'])proc_close($process);
            if(!$clean)throw new RuntimeException('PIPE_CHILD_CLEANUP_REFUSED');
        }
    }
}
