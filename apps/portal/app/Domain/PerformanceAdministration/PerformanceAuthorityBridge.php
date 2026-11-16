<?php
declare(strict_types=1);
namespace App\Domain\PerformanceAdministration;
use Closure;
use RuntimeException;
/** Candidate policy over an authoritative server snapshot. No default operational source.
 * Source must load/lock native Portal identity + Performance-specific delegation;
 * never pass Request claims or SEO/laboratory memberships as a source.
 */
final class PerformanceAuthorityBridge
{
 private const ROLES=['superadmin','operations','analyst','ai_supervisor','finance','auditor'];
 private const OPS=['dashboard.read','clients.read','reports.read'];
 public function __construct(private Closure $source,private Closure $clock){}
 public function lease(int $operator,string $company,string $operation,int $sessionEpoch):string
 {
  if($operator<1||$sessionEpoch<1||!preg_match('/\A[A-Za-z0-9_-]{8,96}\z/D',$company)||!in_array($operation,self::OPS,true))throw new RuntimeException('Performance access denied');
  $s=($this->source)($operator,$company);$now=($this->clock)();
  if(!is_array($s)||($s['operator_id']??null)!==$operator||($s['company_id']??null)!==$company
   ||($s['product']??null)!=='performance'||($s['operator_active']??false)!==true||($s['company_active']??false)!==true
   ||($s['contract_status']??null)!=='active'||($s['service_active']??false)!==true
   ||($s['session_epoch']??null)!==$sessionEpoch||($s['revoked_at']??null)!==null
   ||!is_int($s['expires_at']??null)||$s['expires_at']<=$now
   ||!is_int($s['mfa_at']??null)||$s['mfa_at']>$now||$s['mfa_at']<=$now-900
   ||!in_array($s['role']??null,self::ROLES,true)||!in_array('performance.admin.'.$operation,$s['permissions']??[],true)
   ||!is_int($s['grant_version']??null)||$s['grant_version']<1||!is_int($s['contract_version']??null)||$s['contract_version']<1)throw new RuntimeException('Performance access denied');
  // Revision fingerprint binds exact authority state; callers must compare it on every revalidation.
  return hash('sha256',json_encode([$operator,$company,$operation,$sessionEpoch,$s['grant_version'],$s['contract_version'],
   $s['role'],$s['expires_at'],$s['mfa_at']],JSON_THROW_ON_ERROR));
 }
}
