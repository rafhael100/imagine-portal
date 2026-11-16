<?php

declare(strict_types=1);
namespace App\Http\Controllers;
use App\Domain\AutomationAdministration\AutomationPolicyCatalog;
use Illuminate\Http\Request;

final class AutomationAdminController
{
 public function index(Request $request) {
  abort_unless(AutomationPolicyCatalog::available(),404);
  // This page has no tenant-data adapter. Never accept browser scope as authority.
  abort_if(array_diff(array_keys($request->query()),['product','company'])!==[],422);
  $request->validate(['product'=>'nullable|string|in:crm,portal,sites,n8n,gateway','company'=>'nullable|uuid']);
  $policies=AutomationPolicyCatalog::policies();
  $selected=(string)$request->query('product','');
  if($selected!=='')$policies=[$selected=>$policies[$selected]];
  $metadata=null;
  // No implicit provider, shared database, SEO grant, browser token or fallback fixture.
  if($request->filled('company') && app()->bound(\App\Domain\AutomationAdministration\AutomationStateReader::class)){
   try{$metadata=app(\App\Domain\AutomationAdministration\AutomationStateReader::class)->read((int)\Illuminate\Support\Facades\Auth::guard('portal-admin')->id(),$request->string('company')->toString());}
   catch(\Throwable){$metadata=null;}
  }
  return response()->view('automation-admin.index',compact('policies','selected','metadata'));
 }
}
