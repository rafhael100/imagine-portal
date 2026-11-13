<?php
declare(strict_types=1);

/** Operator TTY only. Reads the DB, no changing invitation or account state. */
try {
    if($argc!==1||PHP_SAPI!=='cli'||!stream_isatty(STDIN)
        ||!stream_isatty(STDOUT)
        ||getenv('APP_ENV')!=='staging'
        ||getenv('PORTAL_OPERATOR_ACTIVATION')!=='PRIVATE_AUTH_ONLY') {
        throw new RuntimeException();
    }
    require __DIR__.'/bootstrap.php';
    require __DIR__.'/OperatorActivationReadback.php';
    $status=(new \Imagine\Development\OperatorActivationReadback(
        $db,$config->value('proof_key')
    ))->inspect('rafhael@imaginecomunicacao.com.br');
    if($status['status']==='NO_GO')throw new RuntimeException();
    if($status['status']==='INVITED_VERIFIED_PRIVATE_ONLY') {
        echo "PASS: conta privada ainda convidada; email/MFA cadastrados; ativacao nao realizada.\n";
    }elseif($status['status']==='ACTIVE_PRIVATE_STATUS_ONLY'){
        echo "PASS: status de conta privada ativa; sem grants e sem sessao nao revogada.\n";
    }else{
        throw new RuntimeException();
    }
    echo "Somente leitura: senha e MFA nao foram autenticados por esta consulta.\n";
    echo "Permissoes de negocio, login publico, restore e deploy continuam bloqueados.\n";
}catch(Throwable){
    fwrite(STDERR,"NO-GO: status privado nao comprovado; nenhuma alteracao aplicada.\n");
    exit(1);
}
