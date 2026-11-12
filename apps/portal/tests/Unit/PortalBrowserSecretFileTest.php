<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Infrastructure\Identity\PortalBrowserSecretFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PortalBrowserSecretFileTest extends TestCase
{
    private string $directory;
    private string $path;
    private array $values;
    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/portal-secrets-'.bin2hex(random_bytes(12));
        mkdir($this->directory,0700);$this->path=$this->directory.'/runtime.json';
        $this->values=['origin'=>'https://portal.example.test','host'=>'127.0.0.1','port'=>5432,
            'database'=>'portal_development','identity_password'=>bin2hex(random_bytes(20)),
            'envelope_password'=>bin2hex(random_bytes(20)),'proof_key'=>bin2hex(random_bytes(32)),
            'totp_key'=>base64_encode(random_bytes(32)),'scope_pepper'=>bin2hex(random_bytes(32))];
        $this->write();
    }
    private function write():void
    {
        file_put_contents($this->path,json_encode($this->values,JSON_THROW_ON_ERROR));
        chmod($this->path,0600);
    }
    protected function tearDown():void
    {
        foreach(glob($this->directory.'/*')?:[]as$p)unlink($p);
        chmod($this->directory,0700);rmdir($this->directory);
    }
    private function refused(?string $path=null):void
    {
        try{PortalBrowserSecretFile::load($path??$this->path,'https://portal.example.test');}
        catch(RuntimeException $e){self::assertSame('Private Portal authentication configuration required.',$e->getMessage());return;}
        self::fail('Unsafe secret configuration accepted.');
    }
    public function test_private_configuration_loads_without_serializing_secrets():void
    {
        $v=PortalBrowserSecretFile::load($this->path,'https://portal.example.test');
        self::assertSame(5432,$v->value('port'));
        self::assertSame($this->values['database'],$v->value('database'));
        ob_start();var_dump($v);$debug=ob_get_clean();
        self::assertStringNotContainsString($this->values['proof_key'],$debug);
        $this->expectException(RuntimeException::class);serialize($v);
    }
    public function test_readable_file_and_writable_parent_are_refused():void
    {
        chmod($this->path,0644);$this->refused();
        chmod($this->path,0600);chmod($this->directory,0777);$this->refused();
    }
    public function test_symlinks_and_hardlinks_are_refused():void
    {
        symlink($this->path,$this->directory.'/alias');$this->refused($this->directory.'/alias');
        link($this->path,$this->directory.'/hardlink');$this->refused();
    }
    public function test_schema_and_origin_are_strict():void
    {
        $this->values['origin']='https://crm.example.test';$this->write();$this->refused();
        $this->values['origin']='https://portal.example.test';$this->values['role']='postgres';
        $this->write();$this->refused();
    }
    public function test_keys_and_role_passwords_cannot_be_reused():void
    {
        $original=$this->values;
        $this->values['envelope_password']=$this->values['identity_password'];$this->write();$this->refused();
        $this->values=$original;$this->values['scope_pepper']=$this->values['proof_key'];$this->write();$this->refused();
        $this->values=$original;$this->values['totp_key']=base64_encode(hex2bin($this->values['proof_key']));
        $this->write();$this->refused();
    }
    public function test_oversized_malformed_and_missing_fields_are_refused():void
    {
        file_put_contents($this->path,str_repeat('x',8193));$this->refused();
        file_put_contents($this->path,'{invalid-json');$this->refused();
        unset($this->values['proof_key']);$this->write();$this->refused();
    }
    public function test_connection_components_cannot_inject_dsn_options():void
    {
        $this->values['host']='127.0.0.1;user=postgres';$this->write();$this->refused();
        $this->values['host']='127.0.0.1';$this->values['port']='5432';$this->write();$this->refused();
    }
}
