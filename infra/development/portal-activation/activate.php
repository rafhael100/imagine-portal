<?php
declare(strict_types=1);
try{
    if($argc!==1||PHP_SAPI!=='cli'||!stream_isatty(STDIN)||!stream_isatty(STDOUT)||getenv('APP_ENV')!=='staging'||getenv('PORTAL_OPERATOR_ACTIVATION')!=='PRIVATE_AUTH_ONLY')throw new RuntimeException();
    require __DIR__.'/bootstrap.php';
    $email='rafhael@imaginecomunicacao.com.br';$result=$activation->inspect($email);
    echo "Conta: ".$email."\nOrganizacao: ".$result['organization']."\n";
    echo "Ativacao para teste privado de senha e MFA. Sem permissoes de negocio, sessao ou publicacao HTTP.\n";
    echo "Digite ATIVAR para aplicar esta alteracao: ";
    if(rtrim((string)fgets(STDIN,40),"\r\n")!=='ATIVAR')throw new RuntimeException();
    $result=$activation->activate($email);
    echo "PASS conta ativada para teste privado; nenhum grant ou sessao criado; organizacao ".$result['organization']."\n";
}catch(Throwable){fwrite(STDERR,"Ativacao privada nao concluida. Nenhuma nova permissao de negocio foi concedida.\n");exit(1);}
