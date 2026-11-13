<?php

declare(strict_types=1);
namespace App\Homologation;
use Illuminate\Http\Request;
use RuntimeException;

/** Dedicated synthetic profiles. Never reads Portal/CRM identities or grants. */
final class PreviewProfiles
{
    public function __construct(private string $file='/run/preview-profiles/users.json') {}
    private function accounts(): array
    {
        $h=@fopen($this->file,'rb');
        if ($h===false) throw new RuntimeException('Preview profiles unavailable.');
        try {
            $s=fstat($h);
            if (!$s||($s['mode']&077)!==0||$s['uid']!==82||$s['size']>4096) throw new RuntimeException('Preview profiles unavailable.');
            $data=json_decode(stream_get_contents($h),true,8,JSON_THROW_ON_ERROR);
        } finally { fclose($h); }
        if (!is_array($data)||!is_array($data['accounts']??null)||count($data['accounts'])!==2) throw new RuntimeException('Configure both preview profiles.');
        foreach (['cliente.teste'=>'client','administrador.teste'=>'admin'] as $id=>$role) {
            $a=$data['accounts'][$id]??null;
            if (!is_array($a)||($a['role']??null)!==$role||!is_string($a['hash']??null)
                ||(password_get_info($a['hash'])['algoName']??'unknown')==='unknown') throw new RuntimeException('Preview profiles unavailable.');
        }
        return $data['accounts'];
    }
    public function ready(): bool { try {$this->accounts();return true;}catch(\Throwable){return false;} }
    public function login(Request $request,string $id,string $password): bool
    {
        $accounts=$this->accounts(); $a=$accounts[$id]??null;
        if (!is_array($a)||!password_verify($password,$a['hash'])) return false;
        $request->session()->invalidate(); $request->session()->regenerateToken();
        $request->session()->put('preview.profile',['id'=>$id,'fingerprint'=>hash('sha256',$a['hash']),'expires'=>time()+3600]);
        return true;
    }
    public function current(Request $request): ?array
    {
        try {$accounts=$this->accounts();}catch(\Throwable){return null;}
        $s=$request->session()->get('preview.profile');
        if (!is_array($s)||!is_string($s['id']??null)||!is_string($s['fingerprint']??null)
            ||!is_int($s['expires']??null)||$s['expires']<=time()) return null;
        $a=$accounts[$s['id']]??null;
        if (!is_array($a)||!hash_equals(hash('sha256',$a['hash']),$s['fingerprint'])) {
            $request->session()->forget('preview.profile'); return null;
        }
        return ['id'=>$s['id'],'role'=>$a['role'],'name'=>$a['role']==='admin'?'Administrador de teste':'Cliente de teste'];
    }
}
