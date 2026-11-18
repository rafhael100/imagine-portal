"""Offline assembly/lint only. Emits no ambient environment or tool diagnostics."""
import os,sys,subprocess,json,ast,tempfile
from pathlib import Path
HERE=Path(__file__).resolve().parent
MODULES=['ctype','dom','fileinfo','iconv','mbstring','pdo','pdo_pgsql','phar','posix','sodium','tokenizer','xml','xmlwriter']
def run(args):
 r=subprocess.run(args,env={'PATH':'/usr/bin:/bin','LC_ALL':'C','PHP_INI_SCAN_DIR':''},capture_output=True,timeout=20)
 if r.returncode:raise ValueError('PREPARE_REFUSED')
 return r.stdout.decode().strip()
def main():
 php=os.environ['PHP_BIN']
 directory=Path(run([php,'-r','echo ini_get("extension_dir");']))
 builtins=json.loads(run([php,'-n','-r','echo json_encode(get_loaded_extensions());']))
 lines=['display_errors=0','log_errors=0','memory_limit=256M','date.timezone=UTC','auto_prepend_file=','auto_append_file=']
 for name in MODULES:
  if name.lower() in [n.lower() for n in builtins]:continue
  path=directory/(name+'.so')
  if not path.is_file():raise ValueError('MODULE_REFUSED')
  lines+=['extension='+str(path)]
 ini=Path(os.environ['BRIEFING_PHP_INI'])
 with ini.open('x') as f:f.write('\n'.join(lines)+'\n')
 os.chmod(ini,0o600)
 for f in HERE.glob('*.php'):run([php,'-n','-l',str(f)])
 for f in HERE.glob('*.py'):ast.parse(f.read_text())
 run([php,'-c',str(ini),str(HERE/'offline.php')])
 run([sys.executable,'-B',str(HERE/'checks.py')])
 # Read-only loading of the actual new test and vendor stack, no test method / app / PDO.
 code='require "vendor/autoload.php"; require '+json.dumps(str(HERE/'NativeBriefingPgTest.php'))+'; if (!class_exists("Tests\\\\Integration\\\\NativeBriefingPgTest")) exit(1);'
 run([php,'-c',str(ini),'-r',code])
 for mode,expected in [('direct','PASS MODEL_MANUAL_RUNBARE_TEARDOWN_REGISTRY_REFUSED'),('runner','PASS MODEL_RUNNER_TEARDOWN_COMPLETE'),('runner-fail','PASS MODEL_RUNNER_TEARDOWN_FAILURE_PROPAGATED')]:
  if run([php,'-c',str(ini),str(HERE/'LifecycleModelTest.php'),mode])!=expected:raise ValueError('LIFECYCLE_MODEL_REFUSED')
 print('PASS BRIEFING_OFFLINE_PREPARED')
if __name__=='__main__':
 try:main()
 except Exception:print('BRIEFING_PREPARE_REFUSED');sys.exit(1)
