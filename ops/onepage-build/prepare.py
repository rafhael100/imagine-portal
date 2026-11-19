import pathlib,json,subprocess,hashlib,shutil,re,os
HERE=pathlib.Path(__file__).resolve().parent
# Only the initial draft-open event, same repo, exact parent and seven-file delta.
e=json.loads(pathlib.Path(os.environ['GITHUB_EVENT_PATH']).read_text());pr=e['pull_request']
assert os.environ['GITHUB_EVENT_NAME']=='pull_request' and e['action']=='opened' and os.environ['GITHUB_RUN_ATTEMPT']=='1'
assert pr['draft'] is True and pr['state']=='open'
assert pr['head']['repo']['full_name']==pr['base']['repo']['full_name']=='rafhael100/imagine-portal'
assert pr['head']['ref']=='test/onepage-worker-build-only-v1'
assert pr['base']['sha']=='4dc198d2109fe1c7590b5f012e77757ed593e1d5'
repo=HERE.parents[1]
assert subprocess.check_output(['git','-C',str(repo),'rev-parse','HEAD'],text=True).strip()==pr['head']['sha']
assert subprocess.check_output(['git','-C',str(repo),'rev-parse','HEAD^'],text=True).strip()==pr['base']['sha']
expected={'.github/workflows/onepage-worker-build-only.yml'}|{'ops/onepage-build/'+n for n in ['Worker.php','entry.php','load.php','Dockerfile','prepare.py','sources.json']}
assert set(subprocess.check_output(['git','-C',str(repo),'diff','--name-only','HEAD^','HEAD'],text=True).splitlines())==expected

m=json.loads((HERE/'sources.json').read_text());src=pathlib.Path('upstream');out=pathlib.Path('build-context')
assert subprocess.check_output(['git','-C',str(src),'rev-parse','HEAD'],text=True).strip()==m['source_commit']
overlay=pathlib.Path('overlay954')
assert subprocess.check_output(['git','-C',str(overlay),'rev-parse','HEAD'],text=True).strip()==m['overlay_commit']
def blob(path,expected):
 origin=overlay if path in m['overlay_paths'] else src
 b=(origin/path).read_bytes();assert hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()==expected,'SOURCE_BLOB_REFUSED: '+path
 return b
# Verify explicit public overlay and all base bytes before copying/build.
verified={r['path']:blob(r['path'],r['blob']) for r in m['runtime']}
reuse={n:blob(p,h) for n,(p,h) in m['reuse'].items()}
assert m['source_equivalence_approved'] is True,'SOURCE_EQUIVALENCE_UNAPPROVED'
for k in ['php_base','composer_base']:assert isinstance(m[k],str) and re.fullmatch(r'[a-z0-9./_-]+@sha256:[0-9a-f]{64}',m[k]),'BASE_DIGEST_MISSING'
assert not out.exists();out.mkdir()
for path,b in verified.items():
 p=out/'application'/path.removeprefix('apps/portal/');p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
(out/'worker').mkdir()
for n,b in reuse.items():
 if n=='ServiceAuthorityPort.php':
  s=b.decode();assert s.count('private ServiceGate $gate')==1
  s=s.replace('namespace PrivateServiceReceipt;','namespace RebuiltOnepageWorker;').replace('private ServiceGate $gate','private \\Closure $gate').replace("!$this->gate->check($this->db,'onepage_d0_service_b','portal_native_orders_ci')","($this->gate)($this->db)!==true")
  b=s.encode()
 (out/'worker'/n).write_bytes(b)
for n in ['Worker.php','entry.php','load.php']:shutil.copy2(HERE/n,out/'worker'/n)
shutil.copy2(HERE/'Dockerfile',out/'Dockerfile')
