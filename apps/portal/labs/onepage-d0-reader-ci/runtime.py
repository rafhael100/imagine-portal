"""One runner, prescribed bytes, refuse before provisioning unless independently reviewed."""
import hashlib,json,os,re,subprocess,sys
from pathlib import Path
HERE=Path(__file__).resolve().parent
BASE='e95baafac952e5fb8577bd4407de76263d11500e'
PG_RECEIPT_SHA='3c1b526a3b148249af0bfa13ee8610ca456ca33e3996ff810f901ac61dafe1ef'
TOOLS_ENV={'PATH':'/usr/bin:/bin','LC_ALL':'C','PHP_INI_SCAN_DIR':'','PHPRC':'/dev/null'}
def need(v):
    if not v: raise ValueError('D0_COMPOSED_RUNTIME_REFUSED')
def sha(raw):return hashlib.sha256(raw).hexdigest()
def pairs(p):
    d=dict(p);need(len(d)==len(p));return d
def read(p):return json.loads(p.read_bytes(),object_pairs_hook=pairs)
def fmt(v):return isinstance(v,str) and re.fullmatch('[a-f0-9]{64}',v) is not None and len(set(v))>4
def verify_file(p,h,n=None):
    need(p.is_file() and not p.is_symlink() and fmt(h))
    need(n is None or p.stat().st_size==n)
    need(sha(p.read_bytes())==h)
def specification(runtime,ini):
    need(set(runtime)=={'schema','php_version','php','composer','modules','ini_sha256','posix','liburing'})
    need(runtime['schema']=='imagine.noble-php-runtime.v1')
    need(re.fullmatch(r'8[.]4[.][0-9]+',runtime['php_version']))
    for k in ('php','composer'):
        p=runtime[k];need(set(p)=={'path','sha256'} and fmt(p['sha256']))
        need(re.fullmatch(r'/usr/(?:local/)?(?:bin|share)/[a-zA-Z0-9_./-]+',p['path']) and '..' not in Path(p['path']).parts)
    need(runtime['php']['path']!=runtime['composer']['path'])
    need(fmt(runtime['ini_sha256']) and sha(ini)==runtime['ini_sha256'])
    expected=(line.removeprefix('extension=') for line in ini.decode('ascii').splitlines() if line.startswith('extension='))
    modules=list(expected)
    need(len(modules)==len(set(modules)) and set(modules)==set(runtime['modules']))
    for p,h in runtime['modules'].items():
        need(re.fullmatch(r'/usr/(?:local/)?lib/php/[0-9]+/[a-z_]+[.]so',p) and fmt(h))
    from importlib.machinery import SourceFileLoader
    policy=HERE/'ini_policy.py'
    if not policy.exists():policy=HERE/'ini.policy.reference'
    loader=SourceFileLoader('noble_ini_policy',str(policy))
    import types
    module=types.ModuleType(loader.name);loader.exec_module(module)
    expected_head=[
        'display_errors=0','log_errors=0','memory_limit=256M','date.timezone=UTC','auto_prepend_file=','auto_append_file=',
        'allow_url_fopen=0','allow_url_include=0',
        'disable_functions=curl_exec,curl_multi_exec,curl_multi_socket_action,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,socket_create,socket_connect,socket_bind']
    dyn_names=[Path(p).stem for p in modules]
    need(len(dyn_names)==len(set(dyn_names)) and dyn_names==[n for n in module.MODULES if n in dyn_names])
    need(ini==(chr(10).join(expected_head+['extension='+p for p in modules])+chr(10)).encode())
    need(runtime['posix']=='builtin' or (runtime['posix'] in modules and Path(runtime['posix']).name=='posix.so'))
    u=runtime['liburing'];need(set(u)=={'filename','sha256','size','resolved','file_sha256','file_size'})
    need(re.fullmatch(r'pool/main/libu/liburing/liburing2_[a-zA-Z0-9.+~-]+_amd64[.]deb',u['filename']))
    need(re.fullmatch(r'usr/lib/x86_64-linux-gnu/liburing[.]so[.]2[.][0-9]+',u['resolved']))
    need(fmt(u['sha256']) and fmt(u['file_sha256']) and all(type(u[k]) is int and 0<u[k]<4194304 for k in ('size','file_size')))
    return runtime
def pg_expected(path):
    verify_file(path,PG_RECEIPT_SHA);r=read(path)
    need(r['portal_base']==BASE and r['version']=='18.7-1.pgdg24.04+2' and r['suite']=='noble-pgdg' and r['architecture']=='amd64')
    return r
def call(*args,env=TOOLS_ENV,timeout=30):
    return subprocess.run(args,env=env,check=True,timeout=timeout,stdin=subprocess.DEVNULL,
                          stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout
def fetch(root,entry,origin):
    dest=root/'debs'/Path(entry['filename']).name
    call('/usr/bin/curl','--max-time','45','--max-filesize',str(entry['size']),'--proto','=https','--tlsv1.2','--fail','--silent','--show-error',origin+entry['filename'],'-o',str(dest),timeout=50)
    verify_file(dest,entry['sha256'],entry['size'])
    need(call('/usr/bin/dpkg-deb','-f',str(dest),'Architecture').strip()==b'amd64')
    if 'version' in entry: need(call('/usr/bin/dpkg-deb','-f',str(dest),'Version').decode().strip()==entry['version'])
    call('/usr/bin/dpkg-deb','-x',str(dest),str(root/'root'))
def check_extracted(root,record):
    dest=root/'root';files={}
    for key,entry in record.items():
        p=dest/key
        need(p.resolve()==dest/entry['resolved'])
        verify_file(p.resolve(),entry['sha256'],entry['size'])
        files[key]=p
    return files
def provision(root,receipt,runtime):
    need(not root.is_symlink() and root.resolve()==root and not any(root.iterdir()))
    need('VERSION_CODENAME=noble' in Path('/etc/os-release').read_text().splitlines())
    need(call('/usr/bin/dpkg','--print-architecture').strip()==b'amd64')
    (root/'debs').mkdir();(root/'root').mkdir()
    for package in receipt['packages'].values(): fetch(root,package,'https://apt.postgresql.org/pub/repos/apt/')
    u=runtime['liburing'];fetch(root,u,'https://archive.ubuntu.com/ubuntu/')
    for package in receipt['packages'].values(): check_extracted(root,package['files'])
    library='usr/lib/x86_64-linux-gnu/'
    check_extracted(root,{library+'liburing.so.2':{'resolved':u['resolved'],'sha256':u['file_sha256'],'size':u['file_size']}})
    # pg_isready comes from the whole SHA-pinned client .deb; no independent extracted-file pin in #963.
    pg=root/'root/usr/lib/postgresql/18/bin'; lib=root/'root'/library
    env=TOOLS_ENV|{'LD_LIBRARY_PATH':str(lib)}
    for name in ('postgres','initdb','pg_ctl','pg_controldata','psql','pg_isready'):
        p=pg/name;need(p.is_file() and p.resolve()==p)
        linked=call('/usr/bin/ldd',str(p),env=env);need(b'not found' not in linked)
        if name in ('psql','pg_isready'):
            need(('libpq.so.5 => '+str(lib/'libpq.so.5')+' ').encode() in linked)
    driver=next((Path(p) for p in runtime['modules'] if Path(p).stem=='pdo_pgsql'),None)
    if driver is not None:
        verify_file(driver,runtime['modules'][str(driver)])
        need(('libpq.so.5 => '+str(lib/'libpq.so.5')+' ').encode() in call('/usr/bin/ldd',str(driver),env=env))
    else:
        verify_file(Path(runtime['php']['path']),runtime['php']['sha256'])
        linked=call('/usr/bin/ldd',runtime['php']['path'],env=env)
        need(('libpq.so.5 => '+str(lib/'libpq.so.5')+' ').encode() in linked)
    for name in ('postgres','initdb','psql','pg_controldata'):
        need(b' (PostgreSQL) 18.7' in call(str(pg/name),'--version',env=env))
    return pg,lib
def validate_runtime(r,ini,lib):
    for k in ('php','composer'):verify_file(Path(r[k]['path']),r[k]['sha256'])
    for p,h in r['modules'].items():verify_file(Path(p),h)
    php=r['php']['path'];env=TOOLS_ENV|{'LD_LIBRARY_PATH':str(lib)}
    code=('if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4 || PHP_SAPI !== "cli" || php_ini_scanned_files() !== false'
          ' || !class_exists("PDO") || PDO::getAvailableDrivers() !== ["pgsql"]'
          ' || !extension_loaded("posix") || !function_exists("curl_init") || function_exists("curl_exec")'
          ' || function_exists("curl_multi_exec")) exit(1); echo PHP_VERSION;')
    need(call(php,'-c',str(ini),'-r',code,env=env).decode()==r['php_version'])
    need((b'posix' in call(php,'-n','-m',env=env).splitlines())==(r['posix']=='builtin'))
def main():
    need(len(sys.argv)==2);root=Path(sys.argv[1]);files=HERE.parent/'onepage-dual-inbox'
    r=specification(read(HERE/'reviewed-php-runtime.json'),(HERE/'reviewed-php.ini').read_bytes())
    receipt=pg_expected(files/'verified-noble-18.7-receipt.json')
    pg,lib=provision(root,receipt,r)
    validate_runtime(r,HERE/'reviewed-php.ini',lib)
    with (root/'runtime.env').open('x') as f:
        for k,v in {'PG_BIN':pg,'ONEPAGE_PG_RUNTIME_LIB':lib,'POSIX_EXTENSION':r['posix'],
                    'PHP_BIN':r['php']['path'],'REVIEWED_COMPOSER':r['composer']['path']}.items():
            need(re.fullmatch(r'[a-zA-Z0-9_./+-]+',str(v)));f.write(f'{k}={v}\n')
if __name__=='__main__':
    try:main();print('PASS D0_COMPOSED_RUNTIME')
    except BaseException:print('D0_COMPOSED_RUNTIME_REFUSED');sys.exit(1)
