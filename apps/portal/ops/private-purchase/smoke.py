"""Private VPS synthetic smoke; never reads Basic Auth or identity DB credentials."""
import secrets
import getpass
import sys
import urllib.request
import urllib.error
import urllib.parse
from http.cookies import SimpleCookie
from html.parser import HTMLParser

BASE='http://127.0.0.1:18083'
PREFIX='/_rehearsal/purchase'
PREVIEW='/_preview/portal'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None
class Fields(HTMLParser):
    def __init__(self):
        super().__init__(); self.data={}
    def handle_starttag(self,tag,attrs):
        values=dict(attrs)
        if tag=='input' and 'name' in values:
            self.data[values['name']]=values.get('value','')
cookies={}
client=urllib.request.build_opener(NoRedirect())
def request(path,data=None):
    assert (path.startswith(PREFIX) or path in (PREVIEW, PREVIEW+'/login')) and not path.startswith('//')
    body=None if data is None else urllib.parse.urlencode(data).encode()
    headers={}
    # The app pins HTTPS host behind the gateway; this explicit loopback probe
    # transports its own synthetic cookie only to the fixed local endpoint.
    if cookies: headers['Cookie']='; '.join(k+'='+v for k,v in cookies.items())
    req=urllib.request.Request(BASE+path,data=body,headers=headers)
    try: response=client.open(req,timeout=10)
    except urllib.error.HTTPError as error: response=error
    for value in response.headers.get_all('Set-Cookie',[]):
        parsed=SimpleCookie(); parsed.load(value)
        cookies.update({k:v.value for k,v in parsed.items()})
    content=response.read().decode()
    fields=Fields(); fields.feed(content)
    return response.code,response.headers,content,fields.data

def redirect(headers):
    target=urllib.parse.urlsplit(headers['Location'])
    assert target.scheme=='https' and target.netloc=='portal-dev.imaginecomunica.com.br'
    assert target.path.startswith(PREFIX+'/orders/TEST-') and not target.query
    return target.path

def client_login(password):
    status,_,_,form=request(PREVIEW)
    assert status==200 and '_token' in form
    status,headers,_,_=request(PREVIEW+'/login',{
        '_token':form['_token'],
        'username':'cliente.teste',
        'password':password,
    })
    assert status==302
    dest=urllib.parse.urlsplit(headers['Location'])
    assert (dest.scheme=='https' and dest.netloc=='portal-dev.imaginecomunica.com.br'
            and dest.path==PREVIEW+'/client/overview'), 'Synthetic client login failed'

# Default smoke can safely verify denial without knowing an operator's password.
# Explicit --interactive-client reads a disposable synthetic password from TTY;
# it never uses CLI arguments, environment variables, logs or real identities.
assert sys.argv[1:] in ([], ['--interactive-client'])
status,_,_,_=request(PREFIX)
assert status==403, 'Anonymous operator must not access purchase data'
if not sys.argv[1:]:
    print('PASS private preview denies guest orders; run --interactive-client for full synthetic purchase smoke')
    sys.exit(0)
password=getpass.getpass('Senha do cliente.teste (somente perfil fictício): ')
assert password, 'Synthetic test profile password is required'
client_login(password)

for product in ['onepage','landing-pages','performance']:
    code,headers,body,fields=request(PREFIX)
    assert code==200 and '_token' in fields
    assert 'secure' in '\n'.join(headers.get_all('Set-Cookie',[])).lower()
    code,headers,_,_=request(PREFIX+'/orders',{'_token':fields['_token'],'product':product,'request_key':'TEST-vps-'+secrets.token_hex(16)})
    assert code==302
    order=redirect(headers)
    code,_,_,fields=request(order); assert code==200
    code,headers,_,_=request(order+'/payment',{'_token':fields['_token'],'revision':fields['revision'],'outcome':'approved','sample_ack':'1'})
    assert code==302 and redirect(headers)==order
    code,_,_,fields=request(order); assert code==200
    code,headers,_,_=request(order+'/briefing',{'_token':fields['_token'],'revision':fields['revision'],'business':'Synthetic VPS test','goal':'Validate persistent sample','audience':'Fictional audience','materials':'Sample materials'})
    assert code==302 and redirect(headers)==order
    cookies.clear()
    code,_,_,_=request(order)
    assert code==403, 'Fresh session cannot see even synthetic order before login'
    client_login(password)
    code,_,body,_=request(order)
    assert code==200 and 'Briefing salvo' in body and 'Synthetic VPS test' in body
    print('PASS VPS persisted synthetic purchase: '+product)
print('PASS client-only synthetic access, independent Secure cookie, CSRF forms and authenticated re-login; no real identity or billing')
