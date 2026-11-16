<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Domain\PerformanceAdministration\AuthorizedPerformanceRead;
use Illuminate\Support\Str;
final class PerformancePrivateReadController {
 public function index(\App\Domain\PerformanceAdministration\NativePerformanceAuthority $authority){
  $companies=$authority->companies();return response()->view('performance-admin.private-select',compact('companies'))->header('Cache-Control','no-store');
 }
 public function show(string $company,AuthorizedPerformanceRead $reader){
  $correlation=(string)Str::uuid();
  try{$result=$reader->read($company,'dashboard.read',$correlation);}catch(\Throwable){abort(403,'Consulta indisponível ou acesso não autorizado.');}
  return response()->view('performance-admin.private-read',compact('company','correlation','result'))->header('Cache-Control','no-store');
 }
}
