import http.server,json,threading,unittest,urllib.request,urllib.error
from asaas_sandbox_webhook import parse_event,InvalidEvent,Handler,PATH
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
 def send(self,event=EVENT,token=TOKEN,path=PATH):
  req=urllib.request.Request('http://127.0.0.1:'+str(self.server.server_port)+path,data=json.dumps(event).encode(),headers={'asaas-access-token':token,'Content-Type':'application/json'})
  try:
   with urllib.request.urlopen(req,timeout=2) as response:return response.status
  except urllib.error.HTTPError as e:return e.code
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
 def test_path_and_size(self):
  self.assertEqual(self.send(path='/other'),404);self.assertEqual(self.send(EVENT|{'padding':'x'*65536}),413)
if __name__=='__main__':unittest.main()
