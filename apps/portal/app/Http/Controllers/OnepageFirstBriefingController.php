<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Domain\Commerce\D0\FirstBriefingRelease;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
final readonly class OnepageFirstBriefingController
{
 public function __construct(private FirstBriefingRelease $release){}
 public function attempt(Request $r,string $orderId):JsonResponse{return $this->respond($r,$orderId,true);}
 public function state(Request $r,string $orderId):JsonResponse{return $this->respond($r,$orderId,false);}
 private function respond(Request $r,string $id,bool $write):JsonResponse {
  // Recheck the candidate switch even if routes were registered by a long-lived worker.
  if(config('portal_onepage_d0.enabled')!==true)return response()->json(['error'=>'not_found'],404);
  $s=$r->hasSession()?$r->session()->get('portal.browser_auth'):null;
  if(!is_array($s)||($s['phase']??null)!=='business'||!is_string($s['reference']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$s['reference']))return response()->json(['error'=>'authentication_required'],401);
  if(!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$id)||$r->query->count()!==0)return response()->json(['error'=>'not_found'],404);
  if($write && ($r->method()!=='POST'||!$r->isJson()||trim($r->getContent())!=='{}'))return response()->json(['error'=>'request_refused'],422);
  $result=$write?$this->release->attempt($s['reference'],$id):$this->release->state($s['reference'],$id);
  return response()->json(['next_step'=>$result],$write&&$result==='unavailable'?503:200);
 }
}
