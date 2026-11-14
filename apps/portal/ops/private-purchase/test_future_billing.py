import copy,unittest
from asaas_sandbox_worker import FutureWorker,Unconfirmed
OID='TEST-'+'a'*32
class DB:
 def __init__(self):self.row={'order_id':OID,'charge_key':'setup-2','kind':'setup','amount_cents':33000,'due_date':'2027-01-14','state':'queued','customer_ref':'cus_fixture','provider_ref':None}
 def patch_future(self,oid,key,changes):assert oid==OID and key==self.row['charge_key'];self.row.update(changes)
class API:
 def __init__(self):self.obj=None;self.posts=[];self.uncertain=False
 def find(self,kind,ref):return copy.deepcopy(self.obj)
 def call(self,method,path,body=None):
  if method=='GET':return copy.deepcopy(self.obj)
  self.posts.append(path);monthly=path=='/subscriptions'
  self.obj=body|{'id':'sub_0_fixture' if monthly else 'pay_0_fixture','status':'ACTIVE' if monthly else 'PENDING','invoiceUrl':'https://sandbox.asaas.com/i/fixture'}
  if self.uncertain:raise Unconfirmed('Timeout')
  return copy.deepcopy(self.obj)
class Tests(unittest.TestCase):
 def setUp(self):self.db=DB();self.api=API();self.w=FutureWorker(self.db,self.api)
 def test_setup_no_duplicate(self):
  self.w.process(self.db.row);self.w.process(self.db.row)
  self.assertEqual(self.api.posts,['/payments']);self.assertEqual(self.db.row['state'],'pending')
  self.assertEqual(self.api.obj['externalReference'],'imagine:'+OID+':setup-2')
 def test_monthly_native_subscription(self):
  self.db.row.update(charge_key='monthly',kind='monthly',amount_cents=7990,due_date='2027-02-12')
  self.w.process(self.db.row);self.w.process(self.db.row)
  self.assertEqual(self.api.posts,['/subscriptions']);self.assertEqual(self.db.row['state'],'active')
  self.assertEqual(self.api.obj['cycle'],'MONTHLY');self.assertEqual(self.api.obj['nextDueDate'],'2027-02-12')
  self.assertIsNone(self.db.row['invoice_url'])
 def test_uncertain_post_recovered(self):
  self.api.uncertain=True
  with self.assertRaises(Unconfirmed):self.w.process(self.db.row)
  self.w.process(self.db.row);self.assertEqual(len(self.api.posts),1)
 def test_uncertain_missing_never_repeated(self):
  self.db.row['state']='payment_creating';self.w.process(self.db.row);self.assertEqual(self.api.posts,[])
 def test_terms_mismatch_rejected(self):
  self.w.process(self.db.row)
  for changes in [{'value':1},{'dueDate':'2027-01-15'},{'customer':'cus_wrong'},{'externalReference':'forged'},{'id':'pay_other'}]:
   original=self.api.obj.copy();self.api.obj.update(changes)
   with self.subTest(changes=changes),self.assertRaises(Unconfirmed):self.w.process(self.db.row)
   self.api.obj=original
 def test_monthly_amount_anchor_cycle(self):
  self.db.row.update(charge_key='monthly',kind='monthly',amount_cents=7990,due_date='2099-02-12');self.w.process(self.db.row)
  for changes in [{'value':'NaN'},{'cycle':'YEARLY'},{'nextDueDate':'2099-02-13'},{'status':'FORGED'}]:
   original=self.api.obj.copy();self.api.obj.update(changes)
   with self.subTest(changes=changes),self.assertRaises(Unconfirmed):self.w.process(self.db.row)
   self.api.obj=original
if __name__=='__main__':unittest.main()
