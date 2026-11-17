<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * Synthetic early-refresh envelope, prepared from an opaque vault read.
 * This class does not authenticate that read or claim a database version.
 * The caller MUST claim the exact live version under current Portal authority
 * before preparing it; only that durable claim prevents duplicate remote I/O.
 */
final class GoogleSearchConsoleRefreshTokenPostRequest
{
    public const SYNTHETIC_CLIENT_ID='1234567890-f116syntheticonly.apps.googleusercontent.com';
    public const SYNTHETIC_CLIENT_SECRET='synthetic-secret-f116-private-only';

    private SensitiveParameterValue $form;
    private bool $taken=false;

    private function __construct(#[SensitiveParameter] array $form,private readonly int $predecessorExpiresAt)
    {
        $this->form=new SensitiveParameterValue($form);
    }

    public static function prepare(
        #[SensitiveParameter] GoogleSearchConsoleEncryptedTokenSecret $source,
        #[SensitiveParameter] array $config,
        int $trustedNow,
    ): ?self {
        if (!self::enabled() || $trustedNow<1600000000 || $trustedNow>2200000000)return null;
        $keys=array_keys($config);sort($keys);
        if ($keys!==['clientId','clientSecret'] ||
            ($config['clientId']??null)!==self::SYNTHETIC_CLIENT_ID ||
            ($config['clientSecret']??null)!==self::SYNTHETIC_CLIENT_SECRET)return null;
        $data=$source->takeForTrustedBackend();
        $keys=array_keys($data);sort($keys);
        if ($keys!==['accessToken','expiresAt','refreshToken','scope'] ||
            ($data['scope']??null)!==GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE ||
            !is_int($data['expiresAt']??null) || $data['expiresAt']<=$trustedNow ||
            $data['expiresAt']>$trustedNow+3600 ||
            !self::syntheticToken($data['accessToken']??null) ||
            !self::syntheticToken($data['refreshToken']??null))return null;
        return new self([
            'client_id'=>$config['clientId'],'client_secret'=>$config['clientSecret'],
            'refresh_token'=>$data['refreshToken'],'grant_type'=>'refresh_token',
        ],$data['expiresAt']);
    }

    /** Server-only, at most once; no code, verifier, state or redirect field. */
    public function takeForTrustedHttpsTransport(): ?array
    {
        if (!self::enabled() || $this->taken)return null;
        $this->taken=true;
        $form=$this->form->getValue();$this->form=new SensitiveParameterValue([]);
        return [
            'method'=>'POST','url'=>GoogleSearchConsoleOAuthTokenExchangePlan::TOKEN_ENDPOINT,
            'headers'=>['Content-Type'=>'application/x-www-form-urlencoded','Accept'=>'application/json'],
            'body'=>http_build_query($form,'','&',PHP_QUERY_RFC3986),
            'allowRedirects'=>false,'timeoutSeconds'=>10,
            'predecessorExpiresAt'=>$this->predecessorExpiresAt,
        ];
    }

    public function safeReview(): array
    {
        return [
            'contractVersion'=>'portal-gsc-synthetic-refresh-envelope-v1',
            'fieldNames'=>['client_id','client_secret','refresh_token','grant_type'],
            'transportTaken'=>$this->taken,'networkPerformed'=>false,
            'versionClaimVerified'=>false,'providerOriginVerified'=>false,
            'tokenPersistenceAuthorized'=>false,'customerDisplayAuthorized'=>false,
            'productionAuthorized'=>false,
        ];
    }

    private static function enabled(): bool
    {
        return PHP_SAPI==='cli' && getenv('APP_ENV')==='testing' &&
            getenv('PORTAL_GSC_HTTPS_TRANSPORT_LAB')==='1' && getenv('PORTAL_GSC_REFRESH_LAB')==='1' &&
            getenv('GITHUB_ACTIONS')==='true' && getenv('RUNNER_ENVIRONMENT')==='github-hosted';
    }

    private static function syntheticToken(mixed $token): bool
    {
        return is_string($token) && str_starts_with($token,'synthetic-') &&
            preg_match('/\A[\x21-\x7e]{20,4096}\z/D',$token)===1;
    }

    public function __debugInfo(): array {return $this->safeReview();}
    public function __clone(): void {throw new LogicException('Refresh envelope cannot be cloned');}
    public function __serialize(): array {throw new LogicException('Refresh envelope cannot be serialized');}
    public function __unserialize(array $data): void {throw new LogicException('Refresh envelope cannot be deserialized');}
}
