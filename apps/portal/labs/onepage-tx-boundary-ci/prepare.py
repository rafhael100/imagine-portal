"""Offline-only preparation; no namespace, DB, installation or network."""
import os,sys,json,subprocess,ast,importlib.util
from pathlib import Path
HERE=Path(__file__).resolve().parent
BASE=HERE.parent/'briefing-teardown-ci'
def need(v):
 if not v:raise ValueError('HTTP_PREPARE_REFUSED')
def command(args,cwd=None):
 p=subprocess.run(args,cwd=cwd,env={'PATH':'/usr/bin:/bin','LC_ALL':'C','PHP_INI_SCAN_DIR':''},capture_output=True,timeout=20)
 need(p.returncode==0);return p.stdout.decode().strip()
def main():
 repo=HERE.parents[3]
 spec=importlib.util.spec_from_file_location('event_preflight',HERE/'preflight.py');preflight=importlib.util.module_from_spec(spec);spec.loader.exec_module(preflight)
 preflight.check_checkout(repo)
 # Reuse only module list and read-only tooling function from the pinned #947 preparer.
 spec=importlib.util.spec_from_file_location('base_prepare',BASE/'prepare.py');base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
 php=os.environ['PHP_BIN'];ext=Path(command([php,'-r','echo ini_get("extension_dir");']))
 builtins=json.loads(command([php,'-n','-r','echo json_encode(get_loaded_extensions());']))
 lines=['display_errors=0','log_errors=0','memory_limit=256M','date.timezone=UTC','auto_prepend_file=','auto_append_file=']
 for name in base.MODULES:
  if name.lower() not in [n.lower() for n in builtins]:need((ext/(name+'.so')).is_file());lines+=['extension='+str(ext/(name+'.so'))]
 ini=Path(os.environ['BRIEFING_PHP_INI'])
 with ini.open('x') as f:f.write('\n'.join(lines)+'\n')
 os.chmod(ini,0o600)
 for folder in [HERE]:
  for path in folder.glob('*.php'):command([php,'-n','-l',str(path)])
  for path in folder.glob('*.py'):ast.parse(path.read_text())
 app=HERE.parents[1]
 command([php,'-c',str(ini),'-r',"require 'vendor/autoload.php'; require 'labs/onepage-tx-boundary-ci/offline.php';"],app)
 # Discovery only: no TestCase setup, migration, Kernel request or connection.
 out=command([php,'-c',str(ini),'-r',"require 'vendor/autoload.php'; exit((new PHPUnit\\TextUI\\Application())->run(['phpunit','--no-configuration','--list-tests','labs/onepage-tx-boundary-ci/NativeBriefingPgTest.php']));"],app)
 need('test_transaction_boundary_only' in out and 'test_private_durable_briefing_only' not in out)
 print('PASS TX_BOUNDARY_OFFLINE_PREPARED')
if __name__=='__main__':
 try:main()
 except BaseException:print('TX_BOUNDARY_PREPARE_REFUSED');sys.exit(1)
