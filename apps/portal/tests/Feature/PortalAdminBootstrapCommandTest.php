<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\PortalAccess\Security\PortalAdminEnrollmentSecret;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PortalAdminBootstrapCommandTest extends TestCase
{
    private string $privateDir;
    private string|false $oldTestPassword;

    protected function setUp():void
    {
        parent::setUp();
        if(getenv('PORTAL_ADMIN_F133_PG_CI')!=='1')
            $this->markTestSkipped('F133 requires disposable PostgreSQL18 CI');
        DB::beginTransaction();
        $this->privateDir=sys_get_temp_dir().'/portal-f133-'.
            bin2hex(random_bytes(7));
        mkdir($this->privateDir,0700);
        chmod($this->privateDir,0700);
        $this->oldTestPassword=getenv('PORTAL_F133_TEST_PASSWORD');
        putenv('PORTAL_F133_TEST_PASSWORD=StrongTestPassword#133!');
    }

    protected function tearDown():void
    {
        if(getenv('PORTAL_ADMIN_F133_PG_CI')==='1' &&
           DB::transactionLevel()>0)DB::rollBack();
        if(isset($this->privateDir)&&is_dir($this->privateDir)){
            foreach(glob($this->privateDir.'/*')?:[] as $file){
                if(is_file($file))unlink($file);
            }
            rmdir($this->privateDir);
        }
        if(isset($this->oldTestPassword)){
            if($this->oldTestPassword===false)
                putenv('PORTAL_F133_TEST_PASSWORD');
            else
                putenv('PORTAL_F133_TEST_PASSWORD='.
                    $this->oldTestPassword);
        }
        parent::tearDown();
    }

    private function makeEmail():string
    {
        return 'bootstrap-'.bin2hex(random_bytes(4)).'@example.test';
    }

    public function test_bootstrap_writes_private_totp_file_and_inactive_account():void
    {
        $email=$this->makeEmail();
        $file=$this->privateDir.'/new-account.txt';
        $status=Artisan::call('portal-admin:bootstrap',[
            '--email'=>strtoupper($email),
            '--totp-file'=>$file,
        ]);
        self::assertSame(0,$status,Artisan::output());
        self::assertFileExists($file);
        self::assertSame(0600,fileperms($file)&0777);
        $user=DB::table('portal_admin_users')
            ->where('email',$email)->first();
        self::assertNotNull($user);
        self::assertContains($user->is_active,[false,'f','0',0]);
        self::assertTrue(Hash::check(
            'StrongTestPassword#133!',$user->password_hash
        ));
        self::assertNotSame(
            'StrongTestPassword#133!',$user->password_hash
        );

        $hex=Crypt::decryptString($user->totp_secret_ciphertext);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/D',$hex);
        $base32=PortalAdminEnrollmentSecret::base32(hex2bin($hex));
        $contents=file_get_contents($file);
        self::assertStringContainsString('Código Base32: '.$base32,$contents);
        self::assertStringContainsString('otpauth://totp/',$contents);
        self::assertStringNotContainsString(
            $base32,Artisan::output(),
            'CLI must never print a TOTP secret'
        );
        self::assertStringNotContainsString(
            'StrongTestPassword#133!',Artisan::output()
        );
    }

    public function test_explicit_activation_and_duplicate_rejection():void
    {
        $email=$this->makeEmail();
        $file=$this->privateDir.'/activated.txt';
        self::assertSame(0,Artisan::call('portal-admin:bootstrap',[
            '--email'=>$email,'--totp-file'=>$file,
            '--activate'=>true,
        ]));
        $user=DB::table('portal_admin_users')
            ->where('email',$email)->first();
        self::assertNotNull($user);
        self::assertContains($user->is_active,[true,'t','1',1]);

        $otherFile=$this->privateDir.'/should-not-exist.txt';
        self::assertSame(1,Artisan::call('portal-admin:bootstrap',[
            '--email'=>$email,'--totp-file'=>$otherFile,
            '--activate'=>true,
        ]));
        self::assertFileDoesNotExist($otherFile);
        self::assertSame(1,DB::table('portal_admin_users')
            ->where('email',$email)->count());
    }

    public function test_existing_target_or_world_readable_parent_is_denied():void
    {
        $email=$this->makeEmail();
        $file=$this->privateDir.'/existing.txt';
        file_put_contents($file,'DO NOT REPLACE');
        self::assertSame(1,Artisan::call('portal-admin:bootstrap',[
            '--email'=>$email,'--totp-file'=>$file
        ]));
        self::assertSame('DO NOT REPLACE',file_get_contents($file));
        self::assertSame(0,DB::table('portal_admin_users')
            ->where('email',$email)->count());

        $public=$this->privateDir.'/not-private';
        mkdir($public,0755);
        chmod($public,0755);
        try{
            self::assertSame(1,Artisan::call('portal-admin:bootstrap',[
                '--email'=>$email,'--totp-file'=>$public.'/secret.txt'
            ]));
            self::assertFileDoesNotExist($public.'/secret.txt');
        }finally{
            rmdir($public);
        }
    }

    public function test_non_approved_environment_refuses_provisioning():void
    {
        $email=$this->makeEmail();
        $file=$this->privateDir.'/not-created.txt';
        putenv('PORTAL_ADMIN_F133_PG_CI=0');
        try{
            self::assertSame(1,Artisan::call('portal-admin:bootstrap',[
                '--email'=>$email,'--totp-file'=>$file,
            ]));
            self::assertFileDoesNotExist($file);
        }finally{
            putenv('PORTAL_ADMIN_F133_PG_CI=1');
        }
        self::assertSame(0,DB::table('portal_admin_users')
            ->where('email',$email)->count());
    }
}
