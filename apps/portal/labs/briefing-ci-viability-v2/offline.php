<?php
declare(strict_types=1);
require __DIR__.'/probe.php';
$f=['root_pipe'=>true,'init_pid'=>'pid:[1]','child_net'=>'net:[2]','child_mnt'=>'mnt:[2]','child_pid'=>'pid:[1]','github'=>'true','scope'=>'BRIEFING_VIABILITY_ONLY','uid'=>1001,'gid'=>1001,'uids'=>[1001,1001,1001,1001],'gids'=>[1001,1001,1001,1001],
 'caps'=>array_fill(0,5,'0000000000000000'),'no_new_privs'=>'1','groups'=>[],
 'net'=>'net:[2]','mnt'=>'mnt:[2]','pid'=>'pid:[1]','parent_net'=>'net:[1]','parent_mnt'=>'mnt:[1]','parent_pid'=>'pid:[1]','init_net'=>'net:[1]',
 'sys_interfaces'=>['lo'],'proc_interfaces'=>['lo'],'lo_up'=>true,'lo_index'=>1,'routes'=>['lo'],'sockets'=>0,'sysfs_private_readonly'=>true,'php84'=>true];
verifyFacts($f);$n=1;
foreach(['root_pipe'=>false,'init_pid'=>'pid:[9]','child_net'=>'net:[9]','child_mnt'=>'mnt:[9]','child_pid'=>'pid:[9]','parent_pid'=>'pid:[9]','github'=>'false','scope'=>'other','uid'=>0,'uids'=>[0,0,0,0],'gids'=>[0,0,0,0], 'caps'=>array_fill(0,5,'0000000000000001'),
 'no_new_privs'=>'0','groups'=>[1001],'net'=>'net:[1]','mnt'=>'mnt:[1]','pid'=>'pid:[2]',
 'sys_interfaces'=>['lo','eth0'],'proc_interfaces'=>['lo','eth0'],'lo_up'=>false,'lo_index'=>2,'routes'=>['eth0'],'sockets'=>1,'sysfs_private_readonly'=>false,'php84'=>false] as $k=>$v){
 try{verifyFacts(array_replace($f,[$k=>$v]));throw new LogicException('OFFLINE_FAILED');}catch(RuntimeException $e){$n++;}
}
echo 'PASS FACT_PREDICATES '.$n."; NO_KERNEL_PROBE\n";
