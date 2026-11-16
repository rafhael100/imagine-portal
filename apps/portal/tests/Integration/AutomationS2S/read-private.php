<?php
// CI entrypoint boots the actual Portal application. No public route or human grant is created.
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(getenv('AUTOMATION_S2S_CI')!=='synthetic-isolated')exit(1);
$client=new App\Domain\AutomationAdministration\AutomationS2SClient('/certs/ca.crt','/certs/portal.crt','/certs/portal.key','/certs/signing.key');
$client=new App\Domain\AutomationAdministration\AutomationS2SClient('/certs/ca.crt',getenv('LAB_CLIENT_CERT')?:'/certs/portal.crt',getenv('LAB_CLIENT_KEY')?:'/certs/portal.key','/certs/signing.key');
$company=$argv[1]??'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
// This explicit lab-only callback is NOT an operational operator permission or MFA claim.
$reader=$client->reader(fn(int $operator,string $scope)=>$operator===1&&$scope==='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
try {
 $state=($argv[2]??'reader')==='transport' ? $client->read($company,$argv[3]??null,$argv[4]??null) : $reader->read(1,$company);
 echo json_encode(['state'=>$state,'human_authorization'=>'SYNTHETIC_CALLBACK_ONLY','paid_ai_calls'=>0],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable) { fwrite(STDERR,"AUTOMATION_READ_DENIED\n");exit(2); }
