/* Orientação de leitura: não cria, reativa ou altera cobranças. */
export function subscriptionGuidance(order,maintenance,payments,{billingReady=false,now=new Date(),checkoutNow=Date.now(),delayDays=60}={}){
 if(order.product_id!=='site')return null;
 const m=maintenance,late=payments.filter(p=>p.kind==='maintenance'&&['PENDING','OVERDUE'].includes(p.status)&&(p.status==='OVERDUE'||p.due_date&&p.due_date<day(now))).sort((a,b)=>(a.due_date||'').localeCompare(b.due_date||''))[0];
 const result=(mode,label,message,attention=true,extra={})=>({mode,label,message,attention,...extra});
 if(m?.cancel_requested_at&&!m?.canceled_at)return result('help','Acompanhar cancelamento','Há um cancelamento em andamento. Fale com a Imagine antes de autorizar uma nova assinatura.');
 if(['creating','uncertain','cancel_requested'].includes(m?.status))return result('help','Conferir minha assinatura','A assinatura precisa de conferência pela Imagine. Não crie uma segunda autorização.');
 if(late)return result('payment','Regularizar mensalidade','Há uma mensalidade em atraso. Confira a cobrança; o pagamento não garante a reativação imediata do plano.',true,{payment_id:late.id});
 if(m?.status==='active')return result('active','Ver minha assinatura','Sua assinatura está ativa no cadastro. Consulte vencimentos e cobranças em Pagamentos.',false);
 if(['inactive','canceled','expired'].includes(m?.status)||m?.canceled_at)return result('help',m?.status==='expired'?'Renovar autorização':'Solicitar reativação','A Imagine precisa conferir o cadastro anterior e as condições antes de liberar uma nova autorização.');
 if(!['CONFIRMED','RECEIVED','RECEIVED_IN_CASH'].includes(order.payment_status))return result('wait_payment','Ver contratação','A configuração da assinatura fica disponível após a confirmação do pagamento do projeto.',false);
 if(!billingReady)return result('unavailable','Configurar assinatura mensal','A ativação online ainda não foi liberada pela Imagine. Consulte as condições ou registre seu interesse no projeto.');
 if(m?.status==='checkout')return m.checkout_expires_at>checkoutNow?result('continue','Continuar autorização','Você já iniciou a configuração. Continue no Asaas, sem gerar outra assinatura.'):result('help','Renovar autorização','O prazo desta autorização terminou. Peça à Imagine a conferência antes de uma nova tentativa.');
 if(m)return result('help','Conferir minha assinatura','Confira com a Imagine a situação atual do plano.');
 if(!order.accepted_at||!Number.isFinite(new Date(order.accepted_at).getTime()))return result('help','Configurar assinatura mensal','A data da contratação precisa ser conferida pela Imagine.');
 const due=new Date(order.accepted_at);due.setUTCDate(due.getUTCDate()+delayDays);const first_due=day(due);
 if(first_due<=day(now))return result('help','Configurar assinatura mensal','A data inicial de cobrança precisa ser atualizada pela Imagine antes da autorização.');
 return result('start','Configurar assinatura mensal','Revise as condições e autorize seu cartão na página segura do Asaas.',true,{first_due});
}
function day(value){return new Intl.DateTimeFormat('en-CA',{timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).format(value);}
