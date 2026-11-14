<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/PaymentRouting/C6SandboxCredentialPreflight.php';
use App\Domain\PaymentRouting\C6SandboxCredentialPreflight;
try {
    $status=C6SandboxCredentialPreflight::inspect('/etc/imagine/secrets/c6-sandbox');
    echo "C6 SANDBOX: arquivos privados e par PEM verificados localmente.\n";
    echo "Certificado válido até UTC: ".$status['certificate_expires_utc']."\n";
    echo "Nenhuma conexão de rede foi efetuada.\n";
} catch (Throwable) {
    fwrite(STDERR,"C6 SANDBOX: pré-validação local indisponível; verifique formato PEM e permissões.\n");
    exit(1);
}
