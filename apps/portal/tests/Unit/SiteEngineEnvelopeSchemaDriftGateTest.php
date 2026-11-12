<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SiteIntake\SiteEngineEnvelopeSchemaDriftGate;
use PHPUnit\Framework\TestCase;

final class SiteEngineEnvelopeSchemaDriftGateTest extends TestCase
{
    private static function snapshot(): string
    {
        return dirname(__DIR__,2).'/labs/sites-intake-f311/candidate-envelope.schema.snapshot.json';
    }

    private static function temp(string $content): string
    {
        $path=tempnam(sys_get_temp_dir(),'portal-f311-');
        self::assertIsString($path);
        file_put_contents($path,$content);
        return $path;
    }

    public function test_exact_pinned_envelope_schema_and_current_protocol_terms():void
    {
        $gate=new SiteEngineEnvelopeSchemaDriftGate();
        $snapshot=self::snapshot();
        self::assertSame(
            ['matched'=>true,'reason'=>'pinned_candidate_envelope_only'],
            $gate->compare($snapshot,$snapshot)
        );
        $raw=file_get_contents($snapshot);
        self::assertIsString($raw);
        self::assertSame(
            SiteEngineEnvelopeSchemaDriftGate::PINNED_GIT_BLOB_SHA1,
            sha1('blob '.strlen($raw)."\0".$raw)
        );
        $value=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        self::assertSame('https://json-schema.org/draft/2020-12/schema',$value['$schema']);
        self::assertSame('object',$value['type']);
        self::assertFalse($value['additionalProperties']);
        self::assertSame(
            ['schemaVersion','issuer','audience','issuedAt','expiresAt','nonce','payload','mac'],
            $value['required']
        );
        self::assertSame($value['required'],array_keys($value['properties']));
        self::assertSame('imagine-portal',$value['properties']['issuer']['const']);
        self::assertSame('imagine-sites',$value['properties']['audience']['const']);
        self::assertSame('./create-project.schema.json',$value['properties']['payload']['$ref']);
        self::assertSame('^[a-f0-9]{64}$',$value['properties']['mac']['pattern']);
        self::assertContains('atomic durable nonce fence',$value['x-imagine-requirements']);
        self::assertContains('TTL maximum 120 seconds',$value['x-imagine-requirements']);
    }

    public function test_upstream_change_cannot_be_masked_by_local_snapshot_edit():void
    {
        $gate=new SiteEngineEnvelopeSchemaDriftGate();
        $raw=file_get_contents(self::snapshot());
        self::assertIsString($raw);
        $changed=self::temp(str_replace('imagine-sites','imagine-sites-changed',$raw));
        try {
            self::assertSame('upstream_envelope_schema_drift',
                $gate->compare($changed,self::snapshot())['reason']);
            self::assertSame('portal_snapshot_not_pinned',
                $gate->compare($changed,$changed)['reason']);
        } finally {unlink($changed);}
    }

    public function test_missing_empty_and_oversize_are_denied_and_parity_never_grants_access():void
    {
        $gate=new SiteEngineEnvelopeSchemaDriftGate();
        $empty=self::temp('');
        $large=self::temp(str_repeat('x',65537));
        try {
            foreach([$empty,$large,'/not-present/f311-contract.json'] as $path){
                self::assertSame('file_unavailable_or_oversize',
                    $gate->compare($path,self::snapshot())['reason']);
            }
        }finally{unlink($empty);unlink($large);}
        self::assertFalse(SiteEngineEnvelopeSchemaDriftGate::verifiesRemoteIssuer());
        self::assertFalse(SiteEngineEnvelopeSchemaDriftGate::mayReceiveServiceOwnership());
        self::assertFalse(SiteEngineEnvelopeSchemaDriftGate::authorizesSitesRequest());
    }
}
