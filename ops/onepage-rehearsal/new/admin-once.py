"""Explicitly approved local-admin one-shot only; never called by the assistant in preparation."""
import pathlib,subprocess,hashlib,json,os,sys,shutil
ROOT=pathlib.Path(__file__).resolve().parent
D=['docker','--host','unix:///var/run/docker.sock']
def call(args,**kw):return subprocess.run(D+args,check=True,text=True,capture_output=True,**kw)
def main():
    if len(sys.argv)!=2 or len(sys.argv[1])!=64:raise RuntimeError('APPROVED_MANIFEST_SHA_REQUIRED')
    raw=(ROOT/'MANIFEST.json').read_bytes();digest=hashlib.sha256(raw).hexdigest()
    if digest!=sys.argv[1]:raise RuntimeError('MANIFEST_REFUSED')
    m=json.loads(raw)
    for row in m['files']:
        rel=pathlib.PurePosixPath(row['path']);p=ROOT/rel
        if rel.is_absolute() or '..' in rel.parts or p.is_symlink() or ROOT not in p.resolve().parents or not p.is_file():raise RuntimeError('PATH_REFUSED')
        if p.stat().st_size!=row['bytes'] or hashlib.sha256(p.read_bytes()).hexdigest()!=row['sha256']:raise RuntimeError('BYTES_REFUSED')
    actual={str(p.relative_to(ROOT)) for p in (ROOT/'runtime').rglob('*') if p.is_file()}
    if actual!={r['path'] for r in m['files'] if r['path'].startswith('runtime/')}:raise RuntimeError('EXTRA_RUNTIME_FILE')
    name='onepage-rehearsal-'+digest[:16];tag=name+':local';out=ROOT/'evidence';out.mkdir(exist_ok=True)
    prior=out/'result.json'
    if prior.exists():
        r=json.loads(prior.read_text())
        if r.get('manifest')==digest and r.get('pass') and r.get('cleanup'):
            if call(['ps','-aq','--filter','name=^/'+name+'$']).stdout.strip() or call(['images','-q',tag]).stdout.strip():raise RuntimeError('UNEXPECTED_REMAINDER')
            print('ALREADY_COMPLETED_NO_REEXECUTION');return
        raise RuntimeError('PRIOR_ATTEMPT_REQUIRES_REVIEW')
    if call(['ps','-aq','--filter','name=^/'+name+'$']).stdout.strip() or call(['images','-q',tag]).stdout.strip():raise RuntimeError('RESOURCE_ALREADY_EXISTS')
    arch=call(['info','--format','{{.OSType}} {{.Architecture}}']).stdout.strip()
    if arch not in ['linux x86_64','linux amd64']:raise RuntimeError('DAEMON_PLATFORM_REFUSED')
    storage=call(['info','--format','{{.DockerRootDir}}']).stdout.strip()
    if not storage or shutil.disk_usage(storage).free<4*1024**3:raise RuntimeError('DOCKER_FREE_SPACE_BELOW_4G')
    result={'phase':'build','manifest':digest,'pass':False,'cleanup':False,'scope':'synthetic-disposable-no-asaas'}
    image=None
    try:
        env=dict(os.environ);env['DOCKER_BUILDKIT']='0'
        # Legacy builder enforces these resource flags; if unavailable, abort, do not fallback.
        r=call(['build','--force-rm','--rm','--memory','1073741824','--memory-swap','1073741824','--cpu-period','100000','--cpu-quota','100000','--label','imagine.rehearsal.bundle='+digest,'-t',tag,'.'],cwd=ROOT,env=env,timeout=900)
        (out/'build.log').write_text(r.stdout+r.stderr)
        result['phase']='image_receipt'
        meta=json.loads(call(['image','inspect',tag]).stdout)[0];image=meta['Id']
        if meta['Config'].get('Labels',{}).get('imagine.rehearsal.bundle')!=digest or meta['Size']>1536*1024**2:raise RuntimeError('IMAGE_RECEIPT_REFUSED')
        result['image_id']=image
        result['phase']='container_run'
        r=call(['run','--name',name,'--label','imagine.rehearsal.bundle='+digest,'--network','none','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges','--cpus','1','--memory','536870912','--memory-swap','536870912','--pids-limit','128','--shm-size','128m','--tmpfs','/tmp:rw,nosuid,nodev,size=256m,mode=1777','--tmpfs','/var/lib/postgresql:rw,nosuid,nodev,size=1m',image],timeout=240)
        (out/'functional.log').write_text(r.stdout+r.stderr)
        result['phase']='functional_receipt'
        status=json.loads(r.stdout)
        if status.get('exit')!=0 or status.get('stop')!=0 or not status.get('removed') or 'PASS ONE_SHOT_SAME_ORDER_OBSERVATION_AND_CONFIRMATION' not in status.get('stdout',''):raise RuntimeError('FUNCTIONAL_RECEIPT_REFUSED')
        result['pass']=True
    except Exception as e:
        result['error_type']=type(e).__name__
        if isinstance(e,subprocess.CalledProcessError):result['returncode']=e.returncode
        if isinstance(e,subprocess.CalledProcessError):(out/'failure.log').write_text((e.stdout or '')+(e.stderr or ''))
    finally:
        cleanup=True
        try:
            ids=call(['ps','-aq','--filter','name=^/'+name+'$']).stdout.strip()
            if ids:
                meta=json.loads(call(['inspect',ids]).stdout)[0]
                if meta['Config'].get('Labels',{}).get('imagine.rehearsal.bundle')!=digest:raise RuntimeError('CLEANUP_OWNERSHIP_REFUSED')
                call(['rm','-fv',ids])
            found=call(['images','-q',tag]).stdout.strip()
            if found:
                meta=json.loads(call(['image','inspect',tag]).stdout)[0]
                if meta['Config'].get('Labels',{}).get('imagine.rehearsal.bundle')!=digest:raise RuntimeError('IMAGE_OWNERSHIP_REFUSED')
                call(['image','rm',tag]) # no force/prune, never shared bases
            if call(['ps','-aq','--filter','name=^/'+name+'$']).stdout.strip() or call(['images','-q',tag]).stdout.strip():raise RuntimeError('CLEANUP_POSTCHECK_REFUSED')
        except Exception:cleanup=False
        result['cleanup']=cleanup;prior.write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2))
    if not result['pass'] or not result['cleanup']:raise SystemExit(1)
if __name__=='__main__':main()
