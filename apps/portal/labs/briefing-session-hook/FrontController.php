<?php
declare(strict_types=1);
namespace PrivateSessionPipe;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use PrivateSessionObserver\Observer;
use RuntimeException;
/** Manually invoked only by an isolated test front controller; no route/provider registration. */
function handleNative(#[\SensitiveParameter] Kernel $kernel,#[\SensitiveParameter] Request $request,
    #[\SensitiveParameter] Observer $observer,#[\SensitiveParameter] Channel $channel):Response
{
    if(!in_array(PHP_SAPI,['cli','cli-server'],true))throw new RuntimeException('HARNESS_REFUSED');
    $response=$kernel->handle($request);
    if($request->method()==='GET' && str_starts_with($request->getPathInfo(),'/_development/commerce/onepage/orders/')){
        if(!$response instanceof JsonResponse)throw new RuntimeException('HARNESS_REFUSED');
        $ids=$channel->consume();
        $observer->inspect($request,$response,$ids);
    }
    return $response; // Caller sends exactly this response, then kernel->terminate; no synthetic response.
}
