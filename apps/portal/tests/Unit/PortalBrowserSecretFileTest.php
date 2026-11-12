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
    public function test_crafted_php_object_cannot_skip_private_file_validation():void
    {
        // A hand-crafted object stream would hydrate even a private
        // constructor unless __unserialize explicitly rejects it.
        $type=PortalBrowserSecretFile::class;
        $wire='O:'.strlen($type).':"'.$type.'":0:{}';
        try {
            unserialize($wire,['allowed_classes'=>[$type]]);
            self::fail('Crafted object bypassed secure file loading.');
        } catch(RuntimeException $error) {
            self::assertSame(
                'Portal secret configuration cannot be unserialized.',
                $error->getMessage()
            );
        }
    }

    public function test_export_reconstruction_and_wakeup_are_explicitly_denied():void
    {
        $loaded=PortalBrowserSecretFile::load($this->path,'https://portal.example.test');
        try {
            $loaded->__wakeup();
            self::fail('Deserialization wakeup bypassed the loader.');
        } catch(RuntimeException $error) {
            self::assertSame(
                'Portal secret configuration cannot be unserialized.',
                $error->getMessage()
            );
        }
        try {
            PortalBrowserSecretFile::__set_state(['values'=>$this->values]);
            self::fail('Exported object was reconstructed.');
        } catch(RuntimeException $error) {
            self::assertSame(
                'Portal secret configuration cannot be reconstructed.',
                $error->getMessage()
            );
        }
        self::assertSame($this->values['database'],$loaded->value('database'));
    }

    public function test_symlink_in_any_parent_segment_is_refused():void
    {
        $real=$this->directory.'/real';
        $private=$real.'/private';
        $alias=$this->directory.'/alias';
        mkdir($real,0700);
        mkdir($private,0700);
        $nested=$private.'/runtime.json';
        file_put_contents($nested,json_encode($this->values,JSON_THROW_ON_ERROR));
        chmod($nested,0600);
        symlink($real,$alias);
        try {
            self::assertSame($this->values['database'],
                PortalBrowserSecretFile::load($nested,'https://portal.example.test')->value('database'));
            $this->refused($alias.'/private/runtime.json');
        }finally{
            unlink($alias);
            unlink($nested);
            rmdir($private);
            rmdir($real);
        }
    }

    public function test_var_export_print_r_and_json_encoding_never_contain_private_values():void
    {
        $v=PortalBrowserSecretFile::load($this->path,'https://portal.example.test');
        foreach([var_export($v,true),print_r($v,true),json_encode($v,JSON_THROW_ON_ERROR)] as $dump){
            self::assertIsString($dump);
            foreach(['identity_password','envelope_password','proof_key','totp_key','scope_pepper'] as $name){
                self::assertStringNotContainsString($this->values[$name],$dump,
                    'F316 secret value appeared in an ordinary object dump: '.$name);
                self::assertStringNotContainsString("'".$name."'",$dump);
            }
        }
        self::assertSame($this->values['identity_password'],$v->value('identity_password'));
        self::assertSame($this->values['proof_key'],$v->value('proof_key'));
        $forged=(new \ReflectionClass(PortalBrowserSecretFile::class))->newInstanceWithoutConstructor();
        try{
            $forged->value('proof_key');
            self::fail('A non-loader object accessed the secret registry.');
        }catch(RuntimeException $error){
            self::assertSame('Private Portal authentication configuration required.',$error->getMessage());
        }
    }

    public function test_sql_role_password_must_not_equal_any_signing_or_totp_material():void
    {
        $baseline=$this->values;
        foreach(['identity_password','envelope_password'] as $role){
            foreach(['proof_key','scope_pepper','totp_key'] as $material){
                $this->values=$baseline;
                $this->values[$role]=$this->values[$material];
                $this->write();
                $this->refused();
            }
        }
        $this->values=$baseline;
        $this->write();
        self::assertSame($baseline['identity_password'],
            PortalBrowserSecretFile::load($this->path,'https://portal.example.test')
                ->value('identity_password'));
    }

    public function test_connection_components_cannot_inject_dsn_options():void
    {
        $this->values['host']='127.0.0.1;user=postgres';$this->write();$this->refused();
        $this->values['host']='127.0.0.1';$this->values['port']='5432';$this->write();$this->refused();
    }
}
