"""No database or namespace. Refuse absent integral base before tooling preparation."""
import os,sys,ast,json,subprocess
from pathlib import Path
from preflight import check_checkout
HERE=Path(__file__).resolve().parent
MODULES=['ctype','dom','fileinfo','iconv','mbstring','pdo','pdo_pgsql','phar','posix','sodium','tokenizer','xml','xmlwriter']
def run(args,cwd=None):
 p=subprocess.run(args,cwd=cwd,env={'PATH':'/usr/bin:/bin','LC_ALL':'C','PHP_INI_SCAN_DIR':''},capture_output=True,timeout=25)
 if p.returncode!=0 or p.stderr:raise ValueError('PREPARE_REFUSED')
 return p.stdout.decode()
def main():
 check_checkout();php=os.environ['PHP_BIN'];app=HERE.parents[1]
 ext=Path(run([php,'-r','echo ini_get("extension_dir");']).strip());builtins=json.loads(run([php,'-n','-r','echo json_encode(get_loaded_extensions());']))
 lines=['display_errors=0','log_errors=0','memory_limit=256M','date.timezone=UTC','auto_prepend_file=','auto_append_file=',
 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,socket_create,socket_connect,socket_bind']
 for name in MODULES:
  if name.lower() not in [n.lower() for n in builtins]:
   if not (ext/(name+'.so')).is_file():raise ValueError('PREPARE_REFUSED')
   lines.append('extension='+str(ext/(name+'.so')))
 ini=Path(os.environ['BRIEFING_PHP_INI'])
 with ini.open('x') as f:f.write('\n'.join(lines)+'\n')
 ini.chmod(0o600)
 from preflight import FILES
 for name in FILES:
  p=HERE.parents[3]/name
  if p.suffix=='.php':run([php,'-n','-l',str(p)])
  if p.suffix=='.py':ast.parse(p.read_text())
 out=run([php,'-c',str(ini),'-r',"require 'vendor/autoload.php'; exit((new PHPUnit\\TextUI\\Application())->run(['phpunit','--no-configuration','--list-tests','labs/onepage-d0-account-epoch/AccountEpochPgTest.php']));"],app)
 if 'test_account_epoch_only' not in out:raise ValueError('PREPARE_REFUSED')
 result=run([php,'-c',str(ini),'labs/onepage-d0-account-epoch/offline.php',str(app/'app')],app)
 if result!='PASS EPOCH_SERVICE_OFFLINE 25 FAKE_PSP_MODEL_STORE_NO_SQL\n':raise ValueError('PREPARE_REFUSED')
 print('PASS D0_HTTP_OFFLINE_PREPARED')
if __name__=='__main__':
 try:main()
 except BaseException:print('D0_HTTP_PREPARE_REFUSED');sys.exit(1)
