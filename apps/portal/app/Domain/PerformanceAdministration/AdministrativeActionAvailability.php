<?php
declare(strict_types=1);
namespace App\Domain\PerformanceAdministration;
/** Presentation policy only. Backend engine remains authoritative on every POST. */
final class AdministrativeActionAvailability
{
    public static function actions(string $module,array $record,callable $can,int $actor,?int $mfaAt,int $now):array
    {
        if($actor<1||$mfaAt===null||$mfaAt<$now-900||$mfaAt>$now+30)return [];
        $actions=[];
        if($module==='agents'){
            if($record['status']==='active')$actions=['agent.pause'=>'Pausar agente interno'];
            if($record['status']==='paused')$actions=['agent.resume'=>'Retomar agente interno'];
        }
        if($module==='approvals'){
            $valid=is_int($record['expires_at']??null)&&$record['expires_at']>$now
                &&is_int($record['version']??null)&&$record['version']>0
                &&preg_match('/\A[a-f0-9]{64}\z/D',$record['digest']??'')===1;
            if($valid&&$record['status']==='pending'&&$record['requested_by']!==$actor)
                $actions=['approval.approve'=>'Aprovar proposta sintética','approval.reject'=>'Rejeitar proposta'];
            if($valid&&$record['status']==='approved'){
                $actions=['approval.revoke'=>'Revogar aprovação'];
                if($record['kind']==='internal_note'&&is_int($record['approved_by'])&&$record['approved_by']>0&&$record['approved_by']!==$actor)
                    $actions['approval.consume']='Consumir recibo interno';
            }
        }
        if($module==='incidents'){
            if($record['status']==='open')$actions=['incident.ack'=>'Reconhecer incidente'];
            if($record['status']==='acknowledged'&&($record['resolution_evidence']??null)==='synthetic_verified')
                $actions=['incident.resolve'=>'Resolver com evidência'];
        }
        return array_filter($actions,fn($label,$action)=>$can($action),ARRAY_FILTER_USE_BOTH);
    }
}
