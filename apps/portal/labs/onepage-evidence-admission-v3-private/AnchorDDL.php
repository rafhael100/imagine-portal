<?php
declare(strict_types=1);
/** Only called by the isolated bootstrap after native reservation attestation. Not a runtime port. */
final class AdmissionAnchorDDL
{
    public static function render(string $canonical,string $digest): string
    {
        $a=json_decode($canonical,true,32,JSON_THROW_ON_ERROR);
        $keys=['probe','order_id','line','contract_scope','account','environment','customer','reference','currency','amount_cents','due_date','payment_id'];
        $actual=is_array($a)?array_keys($a):[];sort($actual);sort($keys);
        if($actual!==$keys || !hash_equals(hash('sha256',$canonical),$digest)
          || !is_array($a['contract_scope']) || ($a['contract_scope']['id']??null)!==$a['order_id']
          || !is_string($a['contract_scope']['company']??null)
          || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$a['contract_scope']['company'])
          || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$a['order_id'])
          || $a['line']!=='setup-1' || $a['probe']!=='admit-v1:sourceEntryA'
          || $a['environment']!=='sandbox' || $a['currency']!=='BRL'
          || !preg_match('/\Alab-[a-zA-Z0-9-]{1,60}\z/D',$a['account'])
          || !preg_match('/\Acus_LAB[a-zA-Z0-9]{1,60}\z/D',$a['customer'])
          || !preg_match('/\Apay_LAB[a-zA-Z0-9]{1,60}\z/D',$a['payment_id'])
          || !preg_match('/\Aimagine:lab-op-v1:[a-f0-9]{64}\z/D',$a['reference'])
          || !is_int($a['amount_cents']) || $a['amount_cents']<=0
          || !is_string($a['due_date']) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$a['due_date'])
)
            throw new RuntimeException('ADMISSION_ANCHOR_REFUSED');
        // Hex literal contains no quote, escape or dollar-body delimiter. Its bytes are fixed
        // by bootstrap; decoding preserves the PostgreSQL-canonical source exactly.
        $literal="convert_from(decode('".bin2hex($canonical)."','hex'),'UTF8')::jsonb";
        $sql=file_get_contents(__DIR__.'/admission.sql.in');
        if(substr_count($sql,'__ANCHOR__')!==2 || substr_count($sql,'__DIGEST__')!==2)
            throw new RuntimeException('ADMISSION_TEMPLATE_REFUSED');
        return str_replace(['__ANCHOR__','__DIGEST__'],[$literal,"'".$digest."'"],$sql);
    }
}
