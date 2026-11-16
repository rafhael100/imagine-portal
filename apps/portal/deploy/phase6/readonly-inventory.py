#!/usr/bin/env python3
"""Read-only host inventory. Never grants GO or reserves a port; no env/inspect secrets."""
import datetime,hashlib,ipaddress,json,os,pathlib,subprocess,sys

def run(args):
 r=subprocess.run(args,capture_output=True,text=True,timeout=15,check=True)
 return r.stdout

def inspect(port=18092,subnet='10.87.6.0/28'):
 proposal=ipaddress.ip_network(subnet,strict=True)
 if not proposal.is_private or proposal.version!=4:raise ValueError('Private candidate network required')
 stacks=json.loads(run(['docker','compose','ls','--format','json']))
 containers=[json.loads(x) for x in run(['docker','ps','-a','--format','{{json .}}']).splitlines() if x]
 netids=run(['docker','network','ls','-q']).splitlines()
 networks=[]
 for net in netids:
  # Only IPAM config/name/internal, never arbitrary container labels/env.
  meta=json.loads(run(['docker','network','inspect','--format','{{json .IPAM.Config}}',net])) or []
  name=run(['docker','network','inspect','--format','{{.Name}}',net]).strip()
  internal=run(['docker','network','inspect','--format','{{.Internal}}',net]).strip()=='true'
  networks.append({'name':name,'internal':internal,'subnets':[i['Subnet'] for i in meta if i.get('Subnet')]})
 routes=json.loads(run(['ip','-j','route']))
 existing=[ipaddress.ip_network(s,strict=False) for n in networks for s in n['subnets']]
 existing += [ipaddress.ip_network(r['dst'],strict=False) for r in routes if r.get('dst') not in (None,'default')]
 conflicts=[str(x) for x in existing if x.version==4 and proposal.overlaps(x)]
 listeners=run(['ss','-H','-ltn']).splitlines()
 occupied=any(x.split()[3].rsplit(':',1)[-1]==str(port) for x in listeners if len(x.split())>3)
 mem={x.split(':')[0]:int(x.split()[1])*1024 for x in pathlib.Path('/proc/meminfo').read_text().splitlines() if x.startswith(('MemTotal:','MemAvailable:'))}
 disk=os.statvfs('/srv');host=hashlib.sha256(pathlib.Path('/etc/machine-id').read_bytes()).hexdigest()[:16]
 return {'schema':'performance.phase6.host-readonly.v1','at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'host_fingerprint':host,
  'cpu_count':os.cpu_count(),'memory_bytes':mem,'srv_available_bytes':disk.f_bavail*disk.f_frsize,
  'compose_projects':[{'name':s['Name'],'status':s.get('Status')} for s in stacks],
  'containers':[{'name':c['Names'],'image':c['Image'],'status':c['Status'],'ports':c.get('Ports','')} for c in containers],
  'networks':networks,'named_volumes':run(['docker','volume','ls','-q']).splitlines(),
  'proposal':{'project':'imagine-performance-p6-staging','port':port,'port_observed_unused':not occupied,'subnet':subnet,'subnet_conflicts':conflicts,'reservation_made':False,'project_name_conflict':any(s['Name']=='imagine-performance-p6-staging' for s in stacks)},
  'installation_authorized':False,'status':'NO_GO_PENDING_REVIEW_AND_APPROVAL'}
if __name__=='__main__':
 try: print(json.dumps(inspect(),sort_keys=True))
 except Exception: print(json.dumps({'status':'blocked','error':'readonly_inventory_unavailable','installation_authorized':False}));sys.exit(2)
