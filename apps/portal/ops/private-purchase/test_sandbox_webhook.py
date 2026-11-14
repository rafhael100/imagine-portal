import http.server,json,threading,unittest,urllib.request,urllib.error
from types import SimpleNamespace
from unittest.mock import patch
from asaas_sandbox_webhook import parse_event,InvalidEvent,Handler,PATH,Inbox as SqlInbox
TOKEN='sandbox-test-fixture-token-not-a-live-credential-0001'
EVENT={'id':'evt_05b708f961d739ea7eba7e4db318f621&368604920','event':'PAYMENT_RECEIVED','payment':{'id':'pay_0_fixture','customer':'private-not-retained','value':330,'externalReference':'imagine:TEST-'+'a'*32+':initial'}}
class Inbox:
 def __init__(self):self.events={};self.fail=False
 def save(self,event):
  if self.fail:raise RuntimeError()
  self.events[event['event_hash']]=event
class Tests(unittest.TestCase):
 def setUp(self):
  self.server=http.server.HTTPServer(('127.0.0.1',0),Handler);self.server.token=TOKEN;self.server.inbox=Inbox()
  self.thread=threading.Thread(target=self.server.serve_forever,daemon=True);self.thread.start()
 def tearDown(self):self.server.shutdown();self.thread.join();self.server.server_close()
 def send(self,event=EVENT,token=TOKEN,path=PATH,host=None):
  req=urllib.request.Request('http://127.0.0.1:'+str(self.server.server_port)+path,data=json.dumps(event).encode(),headers={'asaas-access-token':token,'Content-Type':'application/json',**({'Host':host} if host else {})})
  try:
   with urllib.request.urlopen(req,timeout=2) as response:return response.status
  except urllib.error.HTTPError as e:return e.code
 def test_sql_broker_never_uses_postgres_or_set_role(self):
  event=parse_event(json.dumps(EVENT).encode())
  with patch('asaas_sandbox_webhook.subprocess.run',
             return_value=SimpleNamespace(returncode=0,stdout='t\\n',stderr='')) as call:
   SqlInbox().save(event)
  args,opts=call.call_args
  argv=args[0]
  self.assertEqual(argv[:7],['docker','compose','run','--rm','-T','--no-deps','webhook-broker'])
  self.assertIn('purchase_rehearsal_hook',argv)
  self.assertNotIn('postgres',argv)
  self.assertNotIn('SET ROLE',opts['input'])
  self.assertNotIn('SET LOCAL ROLE',opts['input'])
  self.assertIn('ON CONFLICT DO NOTHING',opts['input'])
  self.assertIn('pg_advisory_xact_lock(724116)',opts['input'])
  self.assertTrue(opts['capture_output'])
  self.assertFalse(opts['check'])
  self.assertNotIn('asaas-access-token',opts['input'])
  with patch('asaas_sandbox_webhook.subprocess.run',
             return_value=SimpleNamespace(returncode=1,stdout='',stderr='SECRET-NEVER-LOG')) as failed:
   with self.assertRaises(RuntimeError) as denied: SqlInbox().save(event)
  self.assertNotIn('SECRET-NEVER-LOG',str(denied.exception))
  self.assertTrue(failed.called)

 def test_authentication_and_private_payload(self):
  self.assertEqual(self.send(token='wrong'),401);self.assertEqual(self.server.inbox.events,{})
  self.assertEqual(self.send(),200);saved=next(iter(self.server.inbox.events.values()))
  self.assertNotIn('customer',saved);self.assertNotIn('value',saved);self.assertEqual(len(saved['event_hash']),64)
 def test_duplicate_delivery_and_new_fields(self):
  self.assertEqual(self.send(),200);self.assertEqual(self.send(EVENT|{'newProviderField':True}),200);self.assertEqual(len(self.server.inbox.events),1)
 def test_unknown_event_is_ignored_without_breaking_delivery(self):
  self.assertEqual(self.send({'id':'evt_unknown','event':'SOME_FUTURE_EVENT'}),200);self.assertEqual(self.server.inbox.events,{})
 def test_malformed_events(self):
  for event in [{},EVENT|{'id':'bad\nID'},EVENT|{'payment':{'id':'pay_unsafe/../'}},EVENT|{'payment':{'id':'pay_ok','subscription':'sub_bad/'}},EVENT|{'id':'x'*201}]:
   with self.subTest(event=event):self.assertEqual(self.send(event),400)
 def test_persistence_failure_never_acknowledged(self):
  self.server.inbox.fail=True;self.assertEqual(self.send(),503)
 def test_public_host_is_pinned(self):
  self.server.expected_host='portal-dev.imaginecomunica.com.br'
  self.assertEqual(self.send(),404);self.assertEqual(self.send(host=self.server.expected_host),200)
 def test_path_and_size(self):
  self.assertEqual(self.send(path='/other'),404);self.assertEqual(self.send(EVENT|{'padding':'x'*65536}),413)
if __name__=='__main__':unittest.main()
