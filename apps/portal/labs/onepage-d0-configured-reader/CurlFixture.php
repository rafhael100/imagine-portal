<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;
/** Test-only namespace interception of every cURL call used by the unchanged canonical reader. No socket. */
final class D0CurlFixture {
 public static int $calls=0;public static array $options=[];public static string $url='';public static ?\Closure $during=null;
 public static array $response=['id'=>'pay_ConfigFixture1','customer'=>'cus_ConfigFixture1','externalReference'=>'fixture-reference','billingType'=>'PIX','status'=>'RECEIVED','value'=>'330.00'];
 public static function reset():void{self::$calls=0;self::$options=[];self::$url='';self::$during=null;}
}
function curl_init(?string $url=null):object{D0CurlFixture::$url=$url??'';return new \stdClass();}
function curl_setopt_array(object $h,array $options):bool{D0CurlFixture::$options=$options;return true;}
function curl_exec(object $h):bool{D0CurlFixture::$calls++;if(D0CurlFixture::$during)(D0CurlFixture::$during)();$body=json_encode(D0CurlFixture::$response,JSON_THROW_ON_ERROR);return (D0CurlFixture::$options[CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);}
function curl_getinfo(object $h,int $option):mixed{return match($option){CURLINFO_RESPONSE_CODE=>200,CURLINFO_CONTENT_TYPE=>'application/json',default=>throw new \RuntimeException('FIXTURE_OPTION_REFUSED')};}
function curl_close(object $h):void{}
