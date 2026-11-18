<?php
declare(strict_types=1);
namespace App\Labs\OnepageBuyer;

use RuntimeException;

/** Historical disposable wrappers consume the canonical product SQL; this class only renders text. */
final class CoreSchema
{
    public static function read(string $path): string
    {
        $wrapper=file_get_contents($path);
        if (!is_string($wrapper)) throw new RuntimeException('ORDER_SCHEMA_REQUIRED');
        if (!preg_match('/^-- @portal-native-core (BUYER|ORDERS|READ)$/m',$wrapper,$match)) return $wrapper;
        $core=file_get_contents(__DIR__.'/../../database/security/portal_onepage_orders.sql');
        $start='-- ONEPAGE_STAGE_'.$match[1].'_BEGIN';
        $end='-- ONEPAGE_STAGE_'.$match[1].'_END';
        if (!is_string($core)||substr_count($core,$start)!==1||substr_count($core,$end)!==1) {
            throw new RuntimeException('CANONICAL_ORDER_STAGE_REQUIRED');
        }
        $stage=explode($end,explode($start,$core,2)[1],2)[0];
        if ($match[1]==='ORDERS') {
            // Explicit fixture-only publication source. The wrapper's unchanged DB/process
            // guards still precede this SQL. Product readers cannot select this namespace.
            $a='-- ONEPAGE_PUBLICATION_SOURCE_BEGIN';$b='-- ONEPAGE_PUBLICATION_SOURCE_END';
            if (substr_count($stage,$a)!==1||substr_count($stage,$b)!==1) throw new RuntimeException('TERMS_SOURCE_BOUNDARY_REQUIRED');
            $replacement=<<<'SQL'
CREATE FUNCTION portal_onepage.terms_usable(t portal_onepage.order_terms) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
 SELECT t.publication_ref IS NULL AND t.contract_ref LIKE 'lab-%'
 AND t.terms->>'scope'='synthetic_only';
$$;
SQL;
            $stage=explode($a,$stage,2)[0].$replacement.explode($b,$stage,2)[1];
        }
        $stage=str_replace([
            "'company_reference',actor.organization_reference,'publication_ref',t.publication_ref,\n ",
            "'company_reference',actor.organization_reference,'publication_ref',saved.publication_ref,\n ",
        ],'',$stage);
        $stage=str_replace(['portal_onepage_owner','portal_onepage_runtime','portal_onepage_publisher','portal_onepage.',
            'SCHEMA portal_onepage;','SCHEMA portal_onepage FROM','SCHEMA portal_onepage TO','ONEPAGE_ORDER_AND_TERMS_IMMUTABLE'],
            ['onepage_buyer_owner','onepage_buyer_runtime','onepage_buyer_publisher','onepage_buyer_lab.',
            'SCHEMA onepage_buyer_lab;','SCHEMA onepage_buyer_lab FROM','SCHEMA onepage_buyer_lab TO','LAB_ORDER_AND_TERMS_IMMUTABLE'],$stage);
        return str_replace($match[0],$stage,$wrapper);
    }
}
