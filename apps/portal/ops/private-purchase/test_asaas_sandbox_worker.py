import copy,decimal,unittest
from asaas_sandbox_worker import Worker,Unconfirmed,FIXTURE_NAME,verify_payment,invoice_url
OID='TEST-'+'a'*32
class DB:
    def __init__(self):self.row={'order_id':OID,'state':'queued','amount_cents':33000,'due_date':'2026-11-14','customer_ref':None,'payment_ref':None,'sync_requested':True};self.approved=False;self.hold=False
    def patch(self,oid,changes):assert oid==OID;self.row.update(changes)
    def settle(self,oid,state):
        if state=='reversed':self.hold=True
        if state=='approved' and not self.hold:self.approved=True
class API:
    def __init__(self):self.customer=None;self.payment=None;self.posts=[];self.uncertain=None
    def find(self,kind,ref):
        obj=self.customer if kind=='customers' else self.payment
        if obj is not None and obj['externalReference']!=ref:raise Unconfirmed('Reference conflict')
        return copy.deepcopy(obj)
    def call(self,method,path,payload=None):
        if method=='GET':return copy.deepcopy(self.payment)
        self.posts.append((path,payload))
        if path=='/customers':
            self.customer=dict(payload,id='cus_fixture')
            result=self.customer
        else:
            self.payment=dict(payload,id='pay_fixture',status='PENDING',invoiceUrl='https://sandbox.asaas.com/i/fixture')
            result=self.payment
        if self.uncertain==path:raise Unconfirmed('Write outcome uncertain')
        return copy.deepcopy(result)
class Tests(unittest.TestCase):
    def setUp(self):self.db=DB();self.api=API();self.w=Worker(self.db,self.api)
    def test_create_reconcile_no_duplicate(self):
        self.w.process(self.db.row);self.assertEqual(self.db.row['state'],'pending');self.assertFalse(self.db.approved)
        self.assertEqual(len(self.api.posts),2);self.assertEqual(self.api.posts[0][1]['notificationDisabled'],True)
        self.assertFalse({'email','mobilePhone','phone'}&set(self.api.posts[0][1]))
        self.api.payment['status']='RECEIVED';self.w.process(self.db.row)
        self.assertTrue(self.db.approved);self.assertEqual(len(self.api.posts),2)
        self.w.process(self.db.row);self.assertEqual(len(self.api.posts),2)
    def test_uncertain_customer_reconciles_created_result(self):
        self.api.uncertain='/customers'
        with self.assertRaises(Unconfirmed):self.w.process(self.db.row)
        self.assertEqual(self.db.row['state'],'customer_creating')
        self.api.uncertain=None;self.w.process(self.db.row)
        self.assertEqual(len([p for p in self.api.posts if p[0]=='/customers']),1)
    def test_uncertain_customer_not_repeated_when_not_found(self):
        self.db.row['state']='customer_creating';self.w.process(self.db.row);self.assertEqual(self.api.posts,[])
    def test_uncertain_payment_not_repeated(self):
        self.api.uncertain='/payments'
        with self.assertRaises(Unconfirmed):self.w.process(self.db.row)
        self.assertEqual(self.db.row['state'],'payment_creating')
        self.api.payment=None;self.api.uncertain=None;self.w.process(self.db.row)
        self.assertEqual(len([p for p in self.api.posts if p[0]=='/payments']),1)
    def test_bad_financial_fields(self):
        self.w.process(self.db.row)
        for change in [{'customer':'cus_other'},{'value':1},{'value':'NaN'},{'value':'Infinity'},{'externalReference':'forged'},{'id':'pay_other'}]:
            with self.subTest(change=change),self.assertRaises(Unconfirmed):verify_payment(self.api.payment|change,self.db.row)
    def test_settlement_and_reversal(self):
        self.w.process(self.db.row)
        for status,billing,result in [('PENDING','PIX','pending'),('CONFIRMED','PIX','pending'),('CONFIRMED','CREDIT_CARD','pending'),('RECEIVED','PIX','approved'),('REFUNDED','PIX','reversed'),('CHARGEBACK_REQUESTED','CREDIT_CARD','reversed')]:
            with self.subTest(status=status,billing=billing):self.assertEqual(verify_payment(self.api.payment|{'status':status,'billingType':billing},self.db.row),result)
    def test_refund_hold_is_sticky_even_if_psp_returns_received_again(self):
        self.w.process(self.db.row)
        self.api.payment['status']='RECEIVED'
        self.w.process(self.db.row)
        self.assertTrue(self.db.approved)
        self.api.payment['status']='REFUNDED'
        self.w.process(self.db.row)
        self.assertTrue(self.db.hold)
        self.api.payment['status']='RECEIVED'
        self.w.process(self.db.row)
        self.assertTrue(self.db.hold)
        for change in [
            {'status':'RECEIVED','refundedDate':'2026-11-14'},
            {'status':'RECEIVED','refunds':[{'id':'refund_fake'}]},
            {'status':'RECEIVED','chargeback':{'status':'OPEN'}},
            {'status':'RECEIVED','deleted':True},
        ]:
            with self.subTest(change=change):
                self.assertEqual(verify_payment(self.api.payment|change,self.db.row),'reversed')
        for change in [
            {'status':'RECEIVED','subscription':'sub_test'},
            {'status':'RECEIVED','currency':'USD'},
        ]:
            with self.subTest(change=change),self.assertRaises(Unconfirmed):
                verify_payment(self.api.payment|change,self.db.row)

    def test_urls(self):
        self.assertEqual(invoice_url('https://sandbox.asaas.com/i/fixture'),'https://sandbox.asaas.com/i/fixture')
        for url in ['http://sandbox.asaas.com/i/x','https://asaas.com/i/x','https://sandbox.asaas.com.evil/i/x','https://user:pass@sandbox.asaas.com/i/x','https://sandbox.asaas.com:443/i/x','https://sandbox.asaas.com/i/x?token=bad','javascript:alert(1)','https://sandbox.asaas.com/other']:
            with self.subTest(url=url):self.assertIsNone(invoice_url(url))
    def test_invalid_intent_never_calls_provider(self):
        for change in [{'order_id':'forged'},{'amount_cents':True},{'amount_cents':-1},{'amount_cents':100000001}]:
            with self.subTest(change=change),self.assertRaises(Unconfirmed):self.w.process(self.db.row|change)
        self.assertEqual(self.api.posts,[])
if __name__=='__main__':unittest.main()
