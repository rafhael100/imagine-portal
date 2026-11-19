<?php
declare(strict_types=1);
/** No PDO, process execution, shell, network or stage file input. */
use PrivateInboxProtocol\Audit;
function need(bool $ok):void { if(!$ok)throw new RuntimeException('INBOX_TRANSPORT_REFUSED'); }
function jsonLine(int $max):array {
    $line=fgets(STDIN,$max+2);
    need(is_string($line)&&strlen($line)<=$max&&str_ends_with($line,"\n")&&!str_contains($line,"\r"));
    $data=json_decode($line,true,64,JSON_THROW_ON_ERROR);
    need(is_array($data));return $data;
}
function send(string $type,string $data):void {
    $line=json_encode(['type'=>$type,'bytes'=>base64_encode($data)],JSON_THROW_ON_ERROR)."\n";
    need(fwrite(STDOUT,$line)===strlen($line)&&fflush(STDOUT));
}
try {
    foreach(['DualDbGate.php','InboxInstall.php','Compare.php','Audit.php'] as $file) require __DIR__.'/'.$file;
    $v=jsonLine(262144);
    need(array_keys($v)===['anchor','baseline','phaseAnchors','globalExpected']);
    $s=(new Audit(file_get_contents(__DIR__.'/catalog.sql'),[
        'F391'=>file_get_contents(__DIR__.'/F391.semantic.json'),
        'F392'=>file_get_contents(__DIR__.'/F392.semantic.json'),
    ],$v['baseline'],$v['phaseAnchors'],$v['globalExpected']))->session($v['anchor']);
    send('begin',$s->begin());
    foreach(['include_inbox','include_lease','final'] as $type) {
        $frame=jsonLine(355000);
        need(array_keys($frame)===['frame']&&is_string($frame['frame']));
        $bytes=base64_decode($frame['frame'],true);need(is_string($bytes)&&strlen($bytes)<=262144);
        send($type,$s->continueAfter($bytes));
    }
    need(fgetc(STDIN)===false);
    exit(0);
} catch(Throwable) {exit(1);}
