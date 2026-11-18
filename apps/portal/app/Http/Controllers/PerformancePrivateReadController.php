<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Domain\PerformanceAdministration\AdministrativeLaboratory;
use App\Domain\PerformanceAdministration\AuthorizedPerformanceRead;
use App\Domain\PerformanceAdministration\NativePerformanceAuthority;
use App\Domain\PerformanceAdministration\PerformanceSecurityEvents;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
final class PerformancePrivateReadController
{
 private const MODULES=[
  'dashboard'=>['label'=>'Visão geral','operation'=>'dashboard.read','heading'=>'Visão autorizada da sua operação.'],
  'clients'=>['label'=>'Clientes','operation'=>'clients.read','heading'=>'Consulta de clientes.'],
  'reports'=>['label'=>'Relatórios','operation'=>'reports.read','heading'=>'Consulta de relatórios.'],
 ];
 private function laboratoryAvailable():bool {
  try{return Route::has('performance-admin.index')&&(new AdministrativeLaboratory)->memberships()!==[];}
  catch(\Throwable){return false;}
 }
 private function deny():never {
  try{PerformanceSecurityEvents::record('authority_denied',(string)Str::uuid());}catch(\Throwable){}
  abort(403,'Consulta indisponível ou acesso não autorizado.');
 }
 private function mfaRenewalResponse(Request $request):?Response {
  // PortalAdminMfaGuard has already verified this actor and session epoch.
  // Explain the native authority's existing 900-second limit without renewing
  // a session, loading grants or changing either authentication policy.
  $at=$request->session()->get('portal_admin_mfa_at');
  if(!is_int($at)||$at<=0||$at>time()-900)return null;
  try{PerformanceSecurityEvents::record('authority_denied',(string)Str::uuid());}catch(\Throwable){}
  return response()->view('performance-admin.private-reauth',['laboratoryAvailable'=>false],403)->header('Cache-Control','private, no-store');
 }
 public function index(Request $request,NativePerformanceAuthority $authority){
  if($renewal=$this->mfaRenewalResponse($request))return $renewal;
  $companies=$authority->companies();$laboratoryAvailable=$this->laboratoryAvailable();
  return response()->view('performance-admin.private-select',compact('companies','laboratoryAvailable'))->header('Cache-Control','private, no-store');
 }
 public function show(Request $request,NativePerformanceAuthority $authority,AuthorizedPerformanceRead $reader,string $company,?string $module=null){
  if($renewal=$this->mfaRenewalResponse($request))return $renewal;
  $operations=$authority->operations($company);
  $modules=array_filter(self::MODULES,static fn(array $candidate)=>in_array($candidate['operation'],$operations,true));
  if($modules===[])$this->deny();
  $module??=array_key_first($modules);
  if(!isset($modules[$module]))$this->deny();

  // Only requested filters enter the signed service body. Identity, company and operation remain server-owned.
  $submitted=$request->query();
  $validator=Validator::make($submitted,[
   'period'=>['nullable','string','regex:/\A[0-9]{4}-(0[1-9]|1[0-2])\z/D'],
   'page'=>['nullable','integer','min:1','max:10000'],
  ],[
   'period.string'=>'Informe o período no formato AAAA-MM.',
   'period.regex'=>'Informe um mês válido no formato AAAA-MM.',
   'page.integer'=>'Informe uma página inteira entre 1 e 10000.',
   'page.min'=>'Informe uma página inteira entre 1 e 10000.',
   'page.max'=>'Informe uma página inteira entre 1 e 10000.',
  ]);
  $validator->after(function($validator)use($submitted){
   if(array_diff(array_keys($submitted),['period','page'])!==[])$validator->errors()->add('query','Use somente os filtros de período e página.');
  });
  $validationErrors=$validator->errors()->all();
  $filters=['period'=>is_string($submitted['period']??null)?$submitted['period']:'','page'=>is_scalar($submitted['page']??null)?(string)$submitted['page']:'1'];
  $result=null;$correlation=null;
  if($validationErrors===[]){
   $query=array_filter($validator->validated(),static fn($value)=>$value!==null&&$value!=='');
   $query['page']=(int)($query['page']??1);
   $filters=['period'=>$query['period']??'','page'=>(string)($query['page']??1)];
   $correlation=(string)Str::uuid();
   try{$result=$reader->read($company,$modules[$module]['operation'],$correlation,$query);}
   catch(\Throwable){abort(403,'Consulta indisponível ou acesso não autorizado.');}
  }
  $laboratoryAvailable=$this->laboratoryAvailable();
  return response()->view('performance-admin.private-read',compact('company','module','modules','filters','validationErrors','correlation','result','laboratoryAvailable'),$validationErrors===[]?200:422)
   ->header('Cache-Control','private, no-store');
 }
}
