<?php
declare(strict_types=1);

namespace App\Infrastructure\Identity;

use RuntimeException;
use SensitiveParameter;

/** Candidate secret-file reader. Does not connect, register drivers or enable routes. */
final class PortalBrowserSecretFile
{
    // F316: var_export() bypasses __debugInfo() and prints object properties.
    // Keep secret bytes outside the instance so ordinary object dumps and
    // exports never reveal them. WeakMap drops values when the object dies.
    private static ?\WeakMap $privateValues = null;

    private function __construct(#[SensitiveParameter] array $values)
    {
        self::$privateValues ??= new \WeakMap();
        self::$privateValues[$this] = $values;
    }

    public static function load(string $path,string $expectedOrigin):self
    {
        $fail=static fn()=>throw new RuntimeException('Private Portal authentication configuration required.');
        if(!function_exists('posix_geteuid') || !str_starts_with($path,'/'))$fail();
        clearstatcache(true,$path);
        $before=@lstat($path);$parent=@lstat(dirname($path));
        if(!is_array($before)||!is_array($parent)
            ||($before['mode']&0170000)!==0100000||($parent['mode']&0170000)!==0040000
            ||($before['mode']&0077)!==0||($parent['mode']&0022)!==0
            ||$before['uid']!==posix_geteuid()||$before['nlink']!==1
            ||$before['size']<1||$before['size']>8192)$fail();
        // F315: lstat(parent) alone does not reject a symbolic link in
        // any ancestor of that parent (e.g. /private/alias/real/secret).
        // Never traverse an alias while accepting a local secret path.
        $ancestor=dirname($path);
        while(true){
            $entry=@lstat($ancestor);
            if(!is_array($entry)||($entry['mode']&0170000)!==0040000)$fail();
            $next=dirname($ancestor);
            if($next===$ancestor)break;
            $ancestor=$next;
        }
        $handle=@fopen($path,'rb');
        if($handle===false)$fail();
        try{
            $opened=fstat($handle);
            if(!is_array($opened)||$opened['ino']!==$before['ino']||$opened['dev']!==$before['dev']
                ||($opened['mode']&0077)!==0||$opened['uid']!==posix_geteuid()||$opened['nlink']!==1)$fail();
            $raw=stream_get_contents($handle,8193);
        }finally{fclose($handle);}
        if(!is_string($raw)||strlen($raw)>8192)$fail();
        try{$v=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){$fail();}
        $keys=['origin','host','port','database','identity_password','envelope_password','proof_key','totp_key','scope_pepper'];
        if(!is_array($v)||count($v)!==count($keys)||array_diff(array_keys($v),$keys)!==[])$fail();
        foreach($keys as$key)if(!array_key_exists($key,$v))$fail();
        foreach(array_diff($keys,['port'])as$key)if(!is_string($v[$key]))$fail();
        $u=parse_url($expectedOrigin);
        if(!is_array($u)||($u['scheme']??null)!=='https'||empty($u['host'])
            ||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])
            ||isset($u['path'])&&$u['path']!=='/'||strpbrk($expectedOrigin,"\r\n")!==false
            ||rtrim($v['origin'],'/')!==rtrim($expectedOrigin,'/'))$fail();
        if(preg_match('/\A[a-z0-9][a-z0-9.-]{0,252}\z/D',$v['host'])!==1
            ||preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D',$v['database'])!==1
            ||!is_int($v['port'])||$v['port']<1||$v['port']>65535)$fail();
        foreach(['identity_password','envelope_password']as$key)
            if(strlen($v[$key])<16||strlen($v[$key])>128||str_contains($v[$key],"\0"))$fail();
        if(hash_equals($v['identity_password'],$v['envelope_password']))$fail();
        foreach(['proof_key','scope_pepper']as$key)if(preg_match('/\A[a-f0-9]{64}\z/D',$v[$key])!==1)$fail();
        $totp=base64_decode($v['totp_key'],true);
        if(!is_string($totp)||strlen($totp)!==32||base64_encode($totp)!==$v['totp_key']
            ||hash_equals(hex2bin($v['proof_key']),$totp)
            ||hash_equals(hex2bin($v['scope_pepper']),$totp)
            ||hash_equals($v['proof_key'],$v['scope_pepper']))$fail();
        return new self($v);
    }

    public function value(string $key):string|int
    {
        if(self::$privateValues === null || !isset(self::$privateValues[$this])){
            throw new RuntimeException('Private Portal authentication configuration required.');
        }
        $values=self::$privateValues[$this];
        if(!array_key_exists($key,$values)){
            throw new RuntimeException('Unknown Portal configuration field.');
        }
        return $values[$key];
    }
    public function __debugInfo():array{return ['configuration'=>'[redacted]'];}
    public function __serialize():array{throw new RuntimeException('Portal secret configuration cannot be serialized.');}
    // F314: forbidding serialization does not forbid a crafted PHP
    // unserialize() payload from hydrating private fields without load().
    public function __unserialize(array $data):void{
        throw new RuntimeException('Portal secret configuration cannot be unserialized.');
    }
    public function __wakeup():void{
        throw new RuntimeException('Portal secret configuration cannot be unserialized.');
    }
    public static function __set_state(array $properties):self{
        throw new RuntimeException('Portal secret configuration cannot be reconstructed.');
    }
    private function __clone():void{}
}
