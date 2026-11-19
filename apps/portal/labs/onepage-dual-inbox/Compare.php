<?php
declare(strict_types=1);
namespace PrivateInboxSemantic;

use RuntimeException;

final class Compare
{
    private const PINS=[
        'F391'=>'3efabeccc3df221dd88af27661216a51860d2d810464f66737d6a0bc604a9efa',
        'F392'=>'d0dceed8d6be174283a80edfee2d7a41b7b85da7fcca5f32922c10f0468028c9',
    ];
    private const KEYS=['acl','columns','constraints','databases','extensions','forbidden','indexes',
        'memberships','policies','relations','roles','rows','schemas'];
    private static function require(bool $ok): void
    {
        if (!$ok) throw new RuntimeException('INBOX_SEMANTIC_REFUSED');
    }

    private static function keys(array $value,array $expected): void
    {
        $keys=array_keys($value);sort($keys);
        self::require($keys===$expected);
    }

    private static function bag(array $items): array
    {
        self::require(array_is_list($items));
        foreach ($items as $row) self::require(is_array($row)&&array_is_list($row));
        usort($items,static fn($a,$b)=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));
        return $items;
    }

    /** Closed SQL tokenization; quotes/literal values are not stripped. */
    private static function tokens(string $input): array
    {
        self::require(strlen($input)<2048);
        $out=[];
        while ($input!=='') {
            $input=ltrim($input," \t\r\n");
            if ($input==='') break;
            self::require(preg_match("~\\A(?:'(?:[^']|'')*'|::|<>|>=|<=|[A-Za-z_][A-Za-z0-9_]*|[0-9]+|[()\[\]=\~,])~",$input,$m)===1);
            $token=$m[0];$out[]=str_starts_with($token,"'")?$token:strtolower($token);
            $input=substr($input,strlen($token));
        }
        return self::removeOuter($out);
    }

    private static function removeOuter(array $tokens): array
    {
        while (($tokens[0]??null)==='(' && end($tokens)===')') {
            $depth=0;
            foreach ($tokens as $i=>$token) {
                if ($token==='(') ++$depth;
                if ($token===')') --$depth;
                if ($depth===0) break;
            }
            if ($i!==count($tokens)-1) break;
            $tokens=array_slice($tokens,1,-1);
        }
        return $tokens;
    }

    private static function literal(string $value): string
    {
        return "'".str_replace("'","''",$value)."'";
    }

    /** Only known PG18 pretty deparse or equivalent listed spelling; no arbitrary normalization. */
    private static function expressions(array $tree): array
    {
        $op=array_shift($tree);$args=$tree;
        return match($op) {
            'int' => [(string)$args[0]],
            'text','name','uuid' => [self::literal($args[0]).'::'.$op],
            'column' => [$args[0]],
            'current_user','current_timestamp' => [$op],
            'is_null','is_not_null' => [$args[0].($op==='is_null'?' IS NULL':' IS NOT NULL')],
            'eq','ne','regex' => array_map(static fn($l,$r)=>$l.' '.['eq'=>'=','ne'=>'<>','regex'=>'~'][$op].' '.$r,
                self::expressions($args[0]),self::expressions($args[1])),
            'and','or' => self::booleanForms($op,$args),
            'ordered_text_in' => [$args[0].' = ANY (ARRAY['.implode(', ',array_map(static fn($v)=>self::literal($v).'::text',$args[1])).'])',
                $args[0].' IN ('.implode(', ',array_map(self::literal(...),$args[1])).')'],
            'closed_int_range' => [$args[0].' >= '.$args[1].' AND '.$args[0].' <= '.$args[2],
                 '('.$args[0].' >= '.$args[1].') AND ('.$args[0].' <= '.$args[2].')',
                 $args[0].' BETWEEN '.$args[1].' AND '.$args[2]],
            default => throw new RuntimeException('INBOX_SEMANTIC_REFUSED'),
        };
    }

    private static function booleanForms(string $op,array $children): array
    {
        $variants=[''];
        foreach ($children as $child) {
            $expanded=[];
            foreach ($variants as $prior) foreach(self::expressions($child) as $text) {
                foreach([$text,'('.$text.')'] as $variant) {
                    // Non-parenthesized AND within OR has the expected SQL precedence.
                    // OR within AND may not lose its grouping.
                    if ($child[0]==='or'&&$op==='and'&&$variant===$text) continue;
                    $expanded[]=$prior.($prior===''?'':' '.strtoupper($op).' ').$variant;
                }
            }
            $variants=$expanded;
        }
        return array_values(array_unique($variants));
    }

    private static function expr(mixed $raw, ?array $tree): void
    {
        if ($tree===null) {self::require($raw===null);return;}
        self::require(is_string($raw));
        $tokens=self::tokens($raw);
        foreach(self::expressions($tree) as $form)
            if ($tokens===self::tokens($form)) return;
        throw new RuntimeException('INBOX_SEMANTIC_REFUSED');
    }

    private static function row(array $row,int $count): void
    {
        self::require(array_is_list($row)&&count($row)===$count);
    }

    private static function manifest(string $phase, string $bytes): array
    {
        self::require(isset(self::PINS[$phase])&&hash_equals(self::PINS[$phase],hash('sha256',$bytes)));
        $m=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
        self::require(is_array($m)&&$m['phase']===$phase);
        return $m;
    }

    /**
     * $baseline MUST be independently qualified and frozen before F391.
     * Project only baseline schema public/database ACL, roles/memberships,
     * DB metadata, and built-in extensions; do not learn expected state here.
     */
    public static function matches(string $phase,string $manifestBytes,array $baseline,array $observed): void
    {
        $m=self::manifest($phase,$manifestBytes);$t=$m['table'];
        self::keys($baseline,['acl','databases','extensions','memberships','roles']);
        $baseDbNames=array_column($baseline['databases'],0);sort($baseDbNames);
        self::require($baseDbNames===['portal_asaas_ci','portal_native_orders_ci','postgres','template0','template1']);
        self::require($baseline['extensions']===[['plpgsql','1.0',$t['owner'],'pg_catalog',false]]);
        self::keys($observed,self::KEYS);
        foreach ($observed as $key=>$val) if($key!=='rows') self::require(is_array($val)&&array_is_list($val));
        self::require($observed['rows']===$t['rows_before_fixtures']
            && $observed['forbidden']===[0,0,0,0,0,0,0]);
        $owner=$t['owner'];$object=$t['schema'].'.'.$t['name'];
        $expectedRelations=[[$t['schema'],$t['name'],'r','p',$owner,true,true,false,null,false]];
        foreach($t['indexes'] as $name=>$_) $expectedRelations[]=[$t['schema'],$name,'i','p',$owner,false,false,false,null,true];
        $expectedSchemas=[];
        foreach($m['schema_owners'] as $n=>$o) $expectedSchemas[]=[$n,$o];
        self::require(self::bag($observed['relations'])===self::bag($expectedRelations)
            && self::bag($observed['schemas'])===self::bag($expectedSchemas));
        self::require(count($observed['columns'])===count($t['columns']));
        foreach($t['columns'] as $i=>$c) {
            $row=$observed['columns'][$i];self::row($row,8);
            self::require(array_slice($row,0,7)===[$i+1,$c['name'],$c['type'],$c['not_null'],'','',true]);
            self::expr($row[7],$c['default']);
        }

        $checks=$t['checks'];$notNull=$t['not_null_constraints'];$pk=$t['primary_key'];
        $unique=[];
        foreach($observed['constraints'] as $row) {
            self::row($row,10);[$name,$kind]=$row;
            self::require(is_string($name)&&!isset($unique[$name])&&array_slice($row,2,6)===[true,true,false,false,true,0]);
            $unique[$name]=true;
            if ($kind==='n') {
                self::require(is_array($row[8])&&count($row[8])===1&&$row[9]===null
                    && in_array($row[8][0],$notNull,true));
                $notNull=array_values(array_diff($notNull,$row[8]));
            } elseif ($kind==='p') {
                self::require($pk!==null&&$name===$pk['name']&&$row[8]===$pk['columns']&&$row[9]===null);
                $pk=null;
            } else {
                self::require($kind==='c'&&isset($checks[$name]));
                $columns = match($checks[$name][0]) {
                    'regex'=>[$checks[$name][1][1]],
                    'ordered_text_in','closed_int_range'=>[$checks[$name][1]],
                    'or'=>['lease_expires_at','lease_token','state'],
                    default=>throw new RuntimeException('INBOX_SEMANTIC_REFUSED'),
                };
                self::require(is_array($row[8])); sort($row[8]);sort($columns);
                self::require($row[8]===$columns);
                self::expr($row[9],$checks[$name]);unset($checks[$name]);
            }
        }
        self::require($pk===null&&$notNull===[]&&$checks===[]);
        $indexes=$t['indexes'];
        foreach($observed['indexes'] as $row) {
            self::row($row,15);$n=$row[0];self::require(is_string($n)&&isset($indexes[$n]));$x=$indexes[$n];
            self::require(array_slice($row,1,12)===[$x['unique'],$x['primary'],true,true,true,true,false,$x['am'],
                count($x['keys']),count($x['keys']),true,$x['keys']]&&$row[14]===true);
            self::expr($row[13],$x['predicate']);unset($indexes[$n]);
        }
        self::require($indexes===[]);
        $policies=$t['policies'];
        foreach($observed['policies'] as $row) {
            self::row($row,6);$n=$row[0];self::require(is_string($n)&&isset($policies[$n]));$p=$policies[$n];
            self::require(array_slice($row,1,3)===[['ALL'=>'*','UPDATE'=>'w','SELECT'=>'r'][$p['command']],true,[$p['role']]]);
            self::expr($row[4],$p['using']);self::expr($row[5],$p['check']);unset($policies[$n]);
        }
        self::require($policies===[]);

        $roles=$baseline['roles'];
        $dbs=$baseline['databases'];$dbOwner=null;
        foreach($dbs as &$db) if ($db[0]==='portal_asaas_ci') {$dbOwner=$db[1];$db[5]=false;}
        unset($db);
        self::require($dbOwner===$owner);
        foreach($m['phase_roles'] as $name=>$f) {
            $row=[$name,$f['login'],$f['superuser'],$f['inherit'],$f['createdb'],$f['createrole'],$f['replication'],$f['bypassrls']];
            if ($name==='postgres') self::require(in_array($row,$roles,true));
            else {
                foreach($roles as $baseRole) self::require($baseRole[0]!==$name);
                $roles[]=$row;
            }
        }
        self::require(self::bag($observed['databases'])===self::bag($dbs)
            && self::bag($observed['roles'])===self::bag($roles)
            && self::bag($observed['memberships'])===self::bag($baseline['memberships'])
            && self::bag($observed['extensions'])===self::bag($baseline['extensions']));

        $acl=$baseline['acl'];
        foreach($acl as $row) {
            self::row($row,7);
            self::require(($row[0]==='database'||($row[0]==='schema'&&$row[1]==='public'))&&$row[2]===null);
        }
        $append=static function(string $kind,string $name,?string $col,string $grantor,array $grants) use (&$acl): void {
            foreach($grants as $who=>$privileges)foreach($privileges as $privilege)
                $acl[]=[$kind,$name,$col,$who,$grantor,$privilege,false];
        };
        foreach($m['schema_acl'] as $schema=>$grants) $append('schema',$schema,null,$m['schema_owners'][$schema],$grants);
        $append('relation',$object,null,$owner,$t['table_acl']);
        foreach($t['explicit_column_acl'] as $col=>$grants) $append('column',$object,$col,$owner,$grants);
        foreach($m['added_database_acl'] as $db=>$grants) $append('database',$db,null,$dbOwner,$grants);
        self::require(self::bag($observed['acl'])===self::bag($acl));
    }
}
