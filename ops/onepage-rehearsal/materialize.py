"""Public pinned-source materialization only. No build, SQL or deployment."""
import hashlib,io,json,pathlib,re,shutil,sys,tarfile,urllib.request
HERE=pathlib.Path(__file__).resolve().parent
PINS={'5b4fe89fdda1b9009dc5079143ca832e85a4ee9f','4dc198d2109fe1c7590b5f012e77757ed593e1d5','d1aaab523848baf80325a023f3ebbf4998a3607f'}
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('REDIRECT_REFUSED')
OPENER=urllib.request.build_opener(NoRedirect())
def digest(b):return hashlib.sha256(b).hexdigest()
def relative(s):
 p=pathlib.PurePosixPath(s)
 if p.is_absolute() or '..' in p.parts or str(p)!=s:raise ValueError('PATH_REFUSED')
 return p
def transform(b,kind):
 if kind is None:return b
 if kind!='service_port':raise ValueError('TRANSFORM_REFUSED')
 s=b.decode();old=['namespace PrivateServiceReceipt;','private ServiceGate $gate',"!$this->gate->check($this->db,'onepage_d0_service_b','portal_native_orders_ci')"]
 new=['namespace RebuiltOnepageWorker;','private \\Closure $gate',"($this->gate)($this->db)!==true"]
 for a,z in zip(old,new):
  if s.count(a)!=1:raise ValueError('TRANSFORM_COUNT_REFUSED')
  s=s.replace(a,z)
 return s.encode()
def public_bytes(rows):
 result={}
 for commit in sorted({r['commit'] for r in rows}):
  if commit not in PINS:raise ValueError('PIN_REFUSED')
  url='https://codeload.github.com/rafhael100/imagine-portal/tar.gz/'+commit
  with OPENER.open(url,timeout=60) as response:
   if response.url!=url:raise ValueError('REDIRECT_REFUSED')
   archive=response.read(64*1024*1024+1)
  if len(archive)>64*1024*1024:raise ValueError('ARCHIVE_SIZE_REFUSED')
  selected={r['path']:r for r in rows if r['commit']==commit}
  prefix='imagine-portal-'+commit+'/'
  with tarfile.open(fileobj=io.BytesIO(archive),mode='r:gz') as tar:
   for member in tar:
    if not member.name.startswith(prefix):continue
    path=member.name[len(prefix):]
    if path not in selected:continue
    if path in result.get(commit,{}) or not member.isfile() or member.size>4*1024*1024:raise ValueError('MEMBER_REFUSED')
    b=tar.extractfile(member).read();r=selected[path]
    blob=hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
    if blob!=r['blob']:raise ValueError('BLOB_REFUSED')
    result.setdefault(commit,{})[path]=b
  if set(result.get(commit,{}))!=set(selected):raise ValueError('SOURCE_MISSING')
 return result
def assemble(out,plan,fetch):
 if out.exists() or out.is_symlink():raise ValueError('DESTINATION_EXISTS')
 values={}
 for row in plan['new']:
  p=HERE/relative(row['source']);b=p.read_bytes()
  if p.is_symlink() or digest(b)!=row['sha256']:raise ValueError('NEW_BYTES_REFUSED')
  values[row['destination']]=b
 sources=fetch(plan['public'])
 for r in plan['public']:
  b=transform(sources[r['commit']][r['path']],r.get('transform'))
  if digest(b)!=r['sha256']:raise ValueError('OUTPUT_REFUSED')
  if r['destination'] in values:raise ValueError('DUPLICATE_DESTINATION')
  values[r['destination']]=b
 inputs={'/opt/onepage/'+p:digest(b) for p,b in sorted(values.items()) if p.startswith('runtime/') and not p.startswith('runtime/local-harness/')}
 values['runtime/local-harness/inputs.json']=(json.dumps(inputs,indent=2)+'\n').encode()
 # Every output, including metadata, is predetermined by the approved manifest.
 manifest=(HERE/'bundle-manifest.json').read_bytes();m=json.loads(manifest)
 if set(values)!={r['path'] for r in m['files']}:raise ValueError('INVENTORY_REFUSED')
 for r in m['files']:
  b=values[r['path']]
  if len(b)!=r['bytes'] or digest(b)!=r['sha256']:raise ValueError('FINAL_BYTES_REFUSED')
  relative(r['path'])
 out.mkdir(mode=0o700)
 try:
  for path,b in values.items():
   p=out/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b);p.chmod(0o644)
  (out/'MANIFEST.json').write_bytes(manifest)
 except BaseException:
  shutil.rmtree(out);raise
 print('MATERIALIZED_MANIFEST_SHA256='+digest(manifest))
if __name__=='__main__':
 if len(sys.argv)!=3:raise SystemExit('usage: materialize.py APPROVED_TRANSFER_MANIFEST_SHA NEW_DIRECTORY')
 raw=(HERE/'TRANSFER-MANIFEST.json').read_bytes()
 if digest(raw)!=sys.argv[1]:raise SystemExit('TRANSFER_MANIFEST_REFUSED')
 for r in json.loads(raw)['files']:
  p=HERE/relative(r['path'])
  if p.is_symlink() or not p.is_file() or p.stat().st_size!=r['bytes'] or digest(p.read_bytes())!=r['sha256']:raise SystemExit('TRANSFER_BYTES_REFUSED')
 assemble(pathlib.Path(sys.argv[2]),json.loads((HERE/'plan.json').read_text()),public_bytes)
