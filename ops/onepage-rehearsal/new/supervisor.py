"""New exclusive local PG18 only. Never discovers/connects to another cluster."""
import os,pathlib,tempfile,subprocess,json,shutil,hashlib,time,secrets
ROOT=pathlib.Path('/opt/onepage/runtime')
HERE=pathlib.Path(__file__).resolve().parent
BIN=pathlib.Path('/usr/lib/postgresql/18/bin')

PHP=pathlib.Path('/usr/bin/php8.4')
HARNESS=ROOT/'local-harness/run.php'

def main():
    assert HARNESS.is_file(),'HARNESS_NOT_READY'
    w=pathlib.Path(tempfile.mkdtemp(prefix='onepage-positive-local-'));data=w/'data';port=55484
    env={k:v for k,v in os.environ.items() if not k.startswith(('PG','GITHUB_','PORTAL_TEST_'))}
    env.pop('LD_LIBRARY_PATH',None) # Debian packaged PHP loads its normal verified INI modules
    receipt={'mode':'local-new-disposable','directory':str(data),'port':port,'host':'127.0.0.1','bootstrap':'onepage_bootstrap_synthetic'}
    result={'application_sql_status':'await_harness_evidence','historical_access':False}
    def run(name,args):return subprocess.run([str(BIN/name)]+args,env=env,capture_output=True,text=True,timeout=40)
    try:
        r=run('initdb',['-D',str(data),'-L','/usr/share/postgresql/18','--auth-local=trust','--auth-host=trust','--no-locale','--encoding=UTF8','--username=onepage_bootstrap_synthetic'])
        # No runtime aliases inherited; bootstrap role above is literal synthetic.
        # initdb username must match receipt before server can be started.
        if r.returncode:raise RuntimeError('INITDB_FAILED: '+r.stderr)
        assert receipt['bootstrap']=='onepage_bootstrap_synthetic'
        r=run('pg_ctl',['-D',str(data),'-l',str(w/'server.log'),'-o',f"-h 127.0.0.1 -p {port} -c unix_socket_directories=''",'-w','-t','10','start'])
        if r.returncode:raise RuntimeError('START_FAILED: '+(w/'server.log').read_text())
        pid=(data/'postmaster.pid').read_text().splitlines()
        assert pid[1]==str(data) and pid[3]==str(port) and pid[5]=='127.0.0.1','LOCAL_PIDFILE_REFUSED'
        sql="SELECT json_build_object('db',current_database(),'login',session_user,'directory',current_setting('data_directory'),'host',host(inet_server_addr()),'port',inet_server_port(),'version',current_setting('server_version_num'),'system_identifier',system_identifier::text,'started',extract(epoch FROM pg_postmaster_start_time())::text) FROM pg_control_system()"
        r=run('psql',['-X','-w','-h','127.0.0.1','-p',str(port),'-U',receipt['bootstrap'],'-d','postgres','-At','-c',sql]);assert r.returncode==0,'IDENTITY_QUERY_FAILED'
        row=json.loads(r.stdout);assert row['directory']==str(data) and row['port']==port and row['host']=='127.0.0.1' and row['login']==receipt['bootstrap'] and row['db']=='postgres' and int(row['version'])//10000==18
        receipt={'mode':'dedicated-disposable-synthetic','systemIdentifier':row['system_identifier'],'dataDirectory':str(data),'port':port,'postmasterPid':pid[0],'started':row['started'],'expiresAt':int(time.time())+240,'nativeDatabase':'onepage_isolated_native_'+secrets.token_hex(6),'inboxDatabase':'portal_asaas_ci'}
        path=w/'receipt.json';path.write_text(json.dumps(receipt));path.chmod(0o600)
        # PHP harness independently validates effective Laravel config and same SQL identity before DDL.
        result['harness_invoked']=True
        r=subprocess.run([str(PHP),str(HARNESS),str(path)],env=env,capture_output=True,text=True,timeout=180)
        result.update(exit=r.returncode,stdout=r.stdout,stderr=r.stderr)
    except Exception as e:result['error']=str(e)
    finally:
        if (data/'postmaster.pid').exists():
            r=run('pg_ctl',['-D',str(data),'-m','immediate','-w','-t','10','stop']);result['stop']=r.returncode
            if r.returncode!=0:raise RuntimeError('TEARDOWN_FAILED_PRESERVE_DIRECTORY')
        shutil.rmtree(w);result['removed']=not w.exists();# Result is emitted to captured container stdout; immutable image receives no write
        print(json.dumps(result,indent=2));
        if result.get('exit')!=0 or result.get('stop')!=0 or not result.get('removed'):raise SystemExit(1)
if __name__=='__main__':main()
