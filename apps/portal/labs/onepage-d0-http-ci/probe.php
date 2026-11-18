<?php
declare(strict_types=1);
// Only process/kernel facts: no PDO, Laravel bootstrap, sockets, or database.
function verifyFacts(array $f):void {
    $need=static function(bool $v):void {if(!$v)throw new RuntimeException('VIABILITY_REFUSED');};
    $need($f['github']==='true' && $f['scope']==='BRIEFING_VIABILITY_ONLY');
    $need($f['uid']>0 && $f['uids']===array_fill(0,4,$f['uid']) && $f['gids']===array_fill(0,4,$f['gid']));
    $need($f['caps']===array_fill(0,5,'0000000000000000') && $f['no_new_privs']==='1' && $f['groups']===[]);
    foreach (['net','mnt','pid'] as $k) $need(is_string($f[$k]) && preg_match('/\A'.$k.':\[[0-9]+\]\z/D',$f[$k])===1);
    $need($f['net']!==$f['parent_net'] && $f['net']!==$f['init_net'] && $f['mnt']!==$f['parent_mnt'] && $f['pid']===$f['parent_pid']);
    $need($f['root_pipe']===true && $f['pid']===$f['init_pid'] && $f['net']===$f['child_net'] && $f['mnt']===$f['child_mnt'] && $f['pid']===$f['child_pid']);
    $need($f['sys_interfaces']===['lo'] && $f['proc_interfaces']===['lo'] && $f['lo_up'] && $f['lo_index']===1);
    $need($f['routes']===[] || $f['routes']===['lo']);
    $need($f['sockets']===0 && $f['sysfs_private_readonly'] && $f['php84']);
}
function collectFacts(array $args):array {
    if(count($args)!==3 || !function_exists('posix_geteuid'))throw new RuntimeException('VIABILITY_REFUSED');
    $pipe=fopen('php://fd/9','rb');
    if($pipe===false)throw new RuntimeException('VIABILITY_REFUSED');
    $meta=fstat($pipe);$raw=stream_get_contents($pipe,2049);fclose($pipe);
    if($meta===false || $meta['uid']!==0 || ($meta['mode']&0170000)!==0010000 || strlen($raw)>=2048)throw new RuntimeException('VIABILITY_REFUSED');
    $topology=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    $keys=array_keys($topology);sort($keys);$expected=['child_mnt','child_net','child_pid','init_net','init_pid','parent_mnt','parent_net','parent_pid'];
    if($keys!==$expected)throw new RuntimeException('VIABILITY_REFUSED');
    $status=file_get_contents('/proc/self/status');$fields=[];
    foreach(explode("\n",$status) as $l){$v=explode(':',$l,2);if(count($v)===2)$fields[$v[0]]=trim($v[1]);}
    $list=static fn(string $s)=>$s===''?[]:array_map('intval',preg_split('/\s+/',$s));
    $proc=[];foreach(array_slice(file('/proc/net/dev'),2) as $l)$proc[]=trim(explode(':',$l,2)[0]);sort($proc);
    $routes=[];
    foreach(['route','ipv6_route'] as $file){$lines=file('/proc/net/'.$file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if($file==='route')array_shift($lines);
        foreach($lines as $l){$v=preg_split('/\s+/',trim($l));$routes[]=$file==='route'?$v[0]:end($v);}}
    $routes=array_values(array_unique($routes));sort($routes);
    $sockets=0;foreach(['tcp','tcp6','udp','udp6'] as $f)$sockets+=count(file('/proc/net/'.$f))-1;
    $mounts=[];foreach(file('/proc/self/mountinfo',FILE_IGNORE_NEW_LINES) as $l){$v=explode(' ',$l);if(($v[4]??null)==='/sys')$mounts[]=$v;}
    $private=false;
    if(count($mounts)===1){$v=$mounts[0];$split=array_search('-',$v,true);$opts=explode(',',$v[5]);
        $private=$split!==false && $v[$split+1]==='sysfs' && count(array_intersect(['ro','nosuid','nodev','noexec'],$opts))===4;
        foreach(array_slice($v,6,$split-6) as $option)if(str_starts_with($option,'shared:')||str_starts_with($option,'master:'))$private=false;}
    return ['github'=>getenv('GITHUB_ACTIONS'),'scope'=>getenv('BRIEFING_SCOPE'),'uid'=>(int)$args[1],'gid'=>(int)$args[2],
        'uids'=>$list($fields['Uid']??''),'gids'=>$list($fields['Gid']??''),'groups'=>$list($fields['Groups']??''),
        'caps'=>array_map(fn($k)=>$fields[$k]??'', ['CapInh','CapPrm','CapEff','CapBnd','CapAmb']), 'no_new_privs'=>$fields['NoNewPrivs']??'',
        'net'=>readlink('/proc/self/ns/net'),'mnt'=>readlink('/proc/self/ns/mnt'),'pid'=>readlink('/proc/self/ns/pid'),
        'root_pipe'=>true,'init_net'=>$topology['init_net'],'init_pid'=>$topology['init_pid'],'parent_net'=>$topology['parent_net'],'parent_mnt'=>$topology['parent_mnt'],'parent_pid'=>$topology['parent_pid'],
        'child_net'=>$topology['child_net'],'child_mnt'=>$topology['child_mnt'],'child_pid'=>$topology['child_pid'],
        'sys_interfaces'=>array_values(array_diff(scandir('/sys/class/net'),['.','..'])),'proc_interfaces'=>$proc,
        'lo_up'=>(hexdec(trim(file_get_contents('/sys/class/net/lo/flags')))&1)===1,
        'lo_index'=>(int)trim(file_get_contents('/sys/class/net/lo/ifindex')),'routes'=>$routes,'sockets'=>$sockets,
        'sysfs_private_readonly'=>$private,'php84'=>PHP_MAJOR_VERSION===8 && PHP_MINOR_VERSION===4];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    set_error_handler(static function(){throw new RuntimeException('VIABILITY_REFUSED');});
    try{verifyFacts(collectFacts($argv));echo "PASS PHP_UID_NAMESPACE_FACTS\n";}
    catch(Throwable){fwrite(STDERR,"PHP_UID_NAMESPACE_REFUSED\n");exit(1);}
}
