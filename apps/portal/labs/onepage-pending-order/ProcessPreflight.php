<?php
declare(strict_types=1);
namespace App\Labs\OnepagePendingOrder;
use RuntimeException;
final class ProcessPreflight {
    public static function assertClean():void {
        if(PHP_SAPI!=='cli'||php_ini_loaded_file()!==false||php_ini_scanned_files()!==false
            ||ini_get('auto_prepend_file')!==''||ini_get('auto_append_file')!=='') throw new RuntimeException('CLEAN_PHP_REQUIRED');
        $allowed=['PATH','HOME','LC_ALL','LD_LIBRARY_PATH','PWD','SHLVL','_',
            'APP_ENV','APP_DEBUG','APP_KEY','ONEPAGE_BUYER_PG_LAB','ONEPAGE_PENDING_ORDER_LAB',
            'DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD',
            'SESSION_DRIVER','CACHE_STORE','LOG_CHANNEL'];
        foreach(array_keys(getenv()) as $name) {
            if(!in_array($name,$allowed,true)) throw new RuntimeException('AMBIENT_CONFIG_REFUSED');
        }
        if(getenv('APP_ENV')!==false && getenv('APP_ENV')!=='testing') throw new RuntimeException('TESTING_REQUIRED');
        $base=dirname(__DIR__,2);
        foreach([$base.'/.env',$base.'/.env.testing',$base.'/bootstrap/cache/config.php'] as $path)
            if(file_exists($path)||is_link($path))throw new RuntimeException('DOTENV_OR_CONFIG_CACHE_REFUSED');
        foreach(['pdo_pgsql','mbstring','dom','xml','tokenizer','ctype','fileinfo','sodium'] as $module)
            if(!extension_loaded($module))throw new RuntimeException('CLEAN_PHP_EXTENSION_REQUIRED');
    }
}
