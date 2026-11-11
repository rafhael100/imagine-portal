<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\GoogleSearchConsoleProviderResponsePolicy as Policy;
use PHPUnit\Framework\TestCase;

final class GoogleSearchConsoleProviderResponsePolicyTest extends TestCase
{
    public function test_http_success_cannot_claim_google_identity_or_display_permission(): void
    {
        $result=(new Policy())->inspect(200,[],1);
        self::assertSame('HTTP_OK_CONTENT_UNVERIFIED',$result['category']);
        self::assertFalse($result['retryAllowed']);
        self::assertNull($result['retryAfterSeconds']);
        self::assertFalse($result['providerAuthenticated']);
        self::assertFalse($result['publicationAuthorized']);
        self::assertFalse(Policy::authorizesOutboundHttp());
        self::assertFalse(Policy::authorizesTokenStorage());
        self::assertFalse(Policy::authorizesCustomerDisplay());
    }

    public function test_401_and_403_never_retry_or_infer_permission(): void
    {
        $policy=new Policy();
        foreach([
            401=>'REAUTH_REQUIRED_HOLD',
            403=>'PROPERTY_PERMISSION_HOLD',
            404=>'PROPERTY_NOT_FOUND_HOLD',
            400=>'REVIEW_HTTP_ERROR'
        ] as $status=>$code){
            $result=$policy->inspect($status,[],1);
            self::assertSame($code,$result['category']);
            self::assertFalse($result['retryAllowed']);
            self::assertNull($result['retryAfterSeconds']);
            self::assertFalse($result['publicationAuthorized']);
        }
    }

    public function test_bounded_retries_for_rate_limits_and_temporary_errors(): void
    {
        $policy=new Policy();
        self::assertSame([
            'category'=>'QUOTA_REVIEW',
            'retryAllowed'=>true,
            'retryAfterSeconds'=>4,
            'publicationAuthorized'=>false,
            'providerAuthenticated'=>false,
        ],$policy->inspect(429,[],1));
        self::assertSame(8,$policy->inspect(429,[],2)['retryAfterSeconds']);
        self::assertSame(30,$policy->inspect(503,['Retry-After'=>'30'],2)['retryAfterSeconds']);
        self::assertSame(120,$policy->inspect(429,['Retry-After'=>'120'],1)['retryAfterSeconds']);
        foreach([429,500,502,503,504] as $status){
            $last=$policy->inspect($status,[],3);
            self::assertFalse($last['retryAllowed']);
            self::assertNull($last['retryAfterSeconds']);
        }
    }

    public function test_never_accepts_sensitive_headers_or_arbitrary_provider_body(): void
    {
        $policy=new Policy();
        foreach([
            ['Authorization'=>'Bearer PERSON-SECRET'],
            ['Set-Cookie'=>'session=PERSON-SECRET'],
            ['Retry-After'=>'999'],
            ['Retry-After'=>'1'."\n".'Token'],
            ['Retry-After'=>'1.5'],
            ['Retry-After'=>'-1'],
            ['Retry-After'=>5],
            ['Retry-After'=>'1','Another'=>'secret'],
            ['Response-Body'=>'{"token":"PERSON-SECRET"}']
        ] as $headers){
            self::assertNull($policy->inspect(429,$headers,1));
        }
        $safe=$policy->inspect(503,['Retry-After'=>'10'],1);
        self::assertStringNotContainsString('PERSON-SECRET',json_encode($safe,JSON_THROW_ON_ERROR));
        self::assertNull($policy->inspect(200,['Retry-After'=>'10'],1));
    }

    public function test_invalid_attempt_and_status_are_fail_closed(): void
    {
        $policy=new Policy();
        foreach([0,4,-1,'1',1.0] as $attempt)
            self::assertNull($policy->inspect(429,[],$attempt));
        foreach([-1,0,600,'429',false,null] as $status)
            self::assertNull($policy->inspect($status,[],1));
        foreach([null,false,'',new \stdClass()] as $headers)
            self::assertNull($policy->inspect(429,$headers,1));
    }
}
