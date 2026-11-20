<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Homologation\F423PerformanceDecisionStore;
use Illuminate\Http\Request;

/** Authenticated, loopback-only CI S2S rehearsal. Not SQL001 or production IAM. */
final class PerformanceS2SLabController
{
    private function verify(Request $request,string $id,string $company): void
    {
        abort_unless(getenv('GITHUB_ACTIONS')==='true'
            && getenv('PORTAL_PREVIEW_LOOPBACK_TEST')==='1'
            && config('app.env')==='staging'
            && preg_match('/\ATEST-[a-f0-9]{32}\z/D',$id)===1
            && preg_match('/\Ademo-[a-f0-9]{32}\z/D',$company)===1,404);
        $stamp=$request->header('X-F423-Timestamp','');
        $nonce=$request->header('X-F423-Nonce','');
        $signature=$request->header('X-F423-Signature','');
        abort_unless(is_string($stamp)&&preg_match('/\A[0-9]{10}\z/D',$stamp)
            && abs(time()-(int)$stamp)<=60
            && is_string($nonce)&&preg_match('/\A[a-f0-9]{32}\z/D',$nonce)
            && is_string($signature)&&preg_match('/\A[a-f0-9]{64}\z/D',$signature),403);
        $file='/run/secrets/f423-performance-key';
        $stat=@stat($file);
        abort_unless(is_array($stat)&&($stat['mode']&077)===0&&$stat['uid']===82&&$stat['size']===64,503);
        $hex=@file_get_contents($file);
        abort_unless(is_string($hex)&&preg_match('/\A[a-f0-9]{64}\z/D',$hex)===1,503);
        $message=strtoupper($request->method())."\n".'/'.$request->path()."\n"
            .$company."\n".$stamp."\n".$nonce."\n".hash('sha256',$request->getContent());
        $expected=hash_hmac('sha256',$message,hex2bin($hex));
        abort_unless(hash_equals($expected,$signature),403);
        abort_unless($request->header('X-F423-Company')===$company,403);
    }

    public function source(Request $request,string $id)
    {
        $company=$request->header('X-F423-Company','');
        $this->verify($request,$id,(string)$company);
        // Reads the SAME buyer-approved SQL snapshot used by authenticated browser GET.
        return app(PerformanceOrderSnapshotLabController::class)->source((string)$company,$id);
    }

    public function persist(Request $request,string $id)
    {
        $company=$request->header('X-F423-Company','');
        $this->verify($request,$id,(string)$company);
        abort_unless(str_starts_with((string)$request->header('Content-Type',''),'application/json'),415);
        abort_unless(strlen($request->getContent())<=4096,413);
        $payload=json_decode($request->getContent(),true);
        abort_unless(is_array($payload),422);
        try {
            $out=app(F423PerformanceDecisionStore::class)->persist((string)$company,$id,$payload);
        } catch (\Throwable $failure) {
            // CI-only and synthetic. Log a bounded code / hard-coded reason,
            // never SQL details, request bodies, identities or secrets.
            $reason=$failure instanceof \RuntimeException
                && !$failure instanceof \PDOException
                ? preg_replace('/[^a-zA-Z0-9 _-]/','',(string)$failure->getMessage())
                : 'database_policy_or_sql_rejected';
            error_log('F423 CI decision denied: '.substr((string)$reason,0,96));
            abort(409,'Synthetic purchase, approval, or decision conflict.');
        }
        return response()->json(['schema'=>'f423_ci_only_persisted_blocked_decisions_v1',...$out,
            'operational_enabled'=>false,'billable'=>false])->header('Cache-Control','no-store');
    }
}
