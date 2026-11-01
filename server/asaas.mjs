export class AsaasError extends Error{constructor(message,{uncertain=false,status=502}={}){super(message);this.uncertain=uncertain;this.status=status}}
export function createAsaas({environment='sandbox',apiKey='',fetchImpl=fetch}){
 if(!['sandbox','production'].includes(environment))throw Error('ASAAS_ENV inválido');
 const base=environment==='production'?'https://api.asaas.com/v3':'https://api-sandbox.asaas.com/v3';
 async function request(method,path,data){if(!apiKey)throw new AsaasError('A integração Asaas ainda não foi configurada no servidor.',{status:503});let response;try{response=await fetchImpl(base+path,{method,headers:{'Content-Type':'application/json','access_token':apiKey,'User-Agent':'ImaginePortal/1.0'},body:data===undefined?undefined:JSON.stringify(data),redirect:'error',signal:AbortSignal.timeout(method!=='GET'&&(/subscriptions|creditCard|payWithCreditCard/.test(path)||data?.creditCardToken)?65000:20000)})}catch{throw new AsaasError('Não foi possível confirmar a operação no Asaas. Aguarde a conciliação antes de tentar novamente.',{uncertain:method!=='GET'})}if(!response.ok){const uncertain=method!=='GET'&&(response.status>=500||response.status===408);throw new AsaasError(response.status===400?'O Asaas recusou os dados da operação. Confira o cadastro e as condições.':'O Asaas não concluiu a operação. Consulte a configuração ou tente sincronizar mais tarde.',{uncertain,status:response.status===400?400:502})}try{return await response.json()}catch{throw new AsaasError('Resposta inconclusiva do Asaas.',{uncertain:method!=='GET'})}}
 async function all(path){const items=[];let offset=0;for(let page=0;page<100;page++){const response=await request('GET',path+(path.includes('?')?'&':'?')+'limit=100&offset='+offset);items.push(...(response.data||[]));if(!response.hasMore)return items;offset+=100}throw new AsaasError('Conciliação excedeu o limite de páginas. Requer revisão.');}
 return {configured:!!apiKey,environment,request,all,
  findCustomer:ref=>all('/customers?externalReference='+encodeURIComponent(ref)),
  createCustomer:data=>request('POST','/customers',data),
  findPayments:ref=>all('/payments?externalReference='+encodeURIComponent(ref)),
  createPayment:data=>request('POST','/payments',data),
  tokenizeCard:data=>request('POST','/creditCard/tokenizeCreditCard',data),
  createSubscription:data=>request('POST','/subscriptions',data),
  updateSubscription:(id,data)=>request('PUT','/subscriptions/'+encodeURIComponent(id),data),
  updateSubscriptionCard:(id,data)=>request('PUT','/subscriptions/'+encodeURIComponent(id)+'/creditCard',data),
  payWithCreditCard:(id,data)=>request('POST','/payments/'+encodeURIComponent(id)+'/payWithCreditCard',data),
  getPayment:id=>request('GET','/payments/'+encodeURIComponent(id)),
  createCheckout:data=>request('POST','/checkouts',data),
  cancelCheckout:id=>request('POST','/checkouts/'+encodeURIComponent(id)+'/cancel',{}),
  listSubscriptions:customer=>all('/subscriptions?customer='+encodeURIComponent(customer)+'&includeDeleted=true'),
  getSubscription:id=>request('GET','/subscriptions/'+encodeURIComponent(id)),
  subscriptionPayments:id=>all('/subscriptions/'+encodeURIComponent(id)+'/payments'),
  cancelSubscription:id=>request('DELETE','/subscriptions/'+encodeURIComponent(id)),
  checkoutUrl:id=>`https://${environment==='sandbox'?'sandbox.':''}asaas.com/checkoutSession/show?id=${encodeURIComponent(id)}`
 };
}
