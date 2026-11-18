"""No database or namespace. Refuse absent integral base before tooling preparation."""
import os,sys,ast,json,subprocess
from pathlib import Path
from preflight import check_checkout
HERE=Path(__file__).resolve().parent
from ini_policy import render
def run(args,cwd=None):
 p=subprocess.run(args,cwd=cwd,env={'PATH':'/usr/bin:/bin','LC_ALL':'C','PHP_INI_SCAN_DIR':''},capture_output=True,timeout=25)
 if p.returncode!=0 or p.stderr:raise ValueError('PREPARE_REFUSED')
 return p.stdout.decode()
def main():
 check_checkout();php=os.environ['PHP_BIN'];app=HERE.parents[1]
 ext=Path(run([php,'-r','echo ini_get("extension_dir");']).strip());builtins=json.loads(run([php,'-n','-r','echo json_encode(get_loaded_extensions());']))
 content=render(ext,builtins)
 ini=Path(os.environ['BRIEFING_PHP_INI'])
 with ini.open('x') as f:f.write(content)
 ini.chmod(0o600)
 run([php,'-c',str(ini),'-r','exit(extension_loaded("curl") && function_exists("curl_init") && !function_exists("curl_exec") && !function_exists("curl_multi_exec") ? 0 : 1);'])
 from preflight import FILES
 for name in FILES:
  p=HERE.parents[3]/name
  if p.suffix=='.php':run([php,'-n','-l',str(p)])
  if p.suffix=='.py':ast.parse(p.read_text())
 out=run([php,'-c',str(ini),'-r',"require 'vendor/autoload.php'; exit((new PHPUnit\\TextUI\\Application())->run(['phpunit','--no-configuration','--list-tests','labs/onepage-d0-reader-pg/ConfiguredReaderPgTest.php']));"],app)
 if 'test_configured_reader_pg_only' not in out:raise ValueError('PREPARE_REFUSED')
 result=run([php,'-c',str(ini),'labs/onepage-d0-configured-reader/offline.php',str(app/'app'),str(app/'app/Domain/SitesIntegration/AsaasReadOnlyPaymentClient.php')],app)
 if result!='PASS CONFIGURED_SANDBOX_READER 30 CANONICAL_CLIENT_WITH_CURL_INTERCEPTION_MODEL_STORE_NO_NETWORK_NO_SQL\n':raise ValueError('PREPARE_REFUSED')
 print('PASS D0_HTTP_OFFLINE_PREPARED')
if __name__=='__main__':
 try:main()
 except BaseException:print('D0_HTTP_PREPARE_REFUSED');sys.exit(1)
