import {randomBytes,createHash,scrypt,timingSafeEqual} from 'node:crypto';
import {promisify} from 'node:util';
const derive=promisify(scrypt);
export const token=()=>randomBytes(32).toString('base64url');
export const digest=value=>createHash('sha256').update(value).digest('hex');
export function equal(a,b){const x=Buffer.from(String(a||'')),y=Buffer.from(String(b||''));return x.length===y.length&&timingSafeEqual(x,y)}
export function passwordValid(value){return typeof value==='string'&&value.length>=12&&value.length<=128}
export async function hashPassword(value){if(!passwordValid(value))throw Object.assign(new Error('Use uma senha de 12 a 128 caracteres.'),{status:400});const salt=randomBytes(16).toString('hex');const hash=await derive(value,salt,64);return `${salt}:${hash.toString('hex')}`}
export async function verifyPassword(value,stored){if(typeof value!=='string'||value.length>128)return false;const [salt,hex]=stored.split(':');const hash=await derive(value,salt,64);return equal(hash.toString('hex'),hex)}
export function validDocument(value){const doc=String(value||'').replace(/\D/g,'');if(![11,14].includes(doc.length)||/^(\d)\1+$/.test(doc))return false;const calc=(base,weights)=>{const sum=[...base].reduce((n,d,i)=>n+Number(d)*weights[i],0),r=sum%11;return r<2?0:11-r};if(doc.length===11){const a=calc(doc.slice(0,9),[10,9,8,7,6,5,4,3,2]);return a===Number(doc[9])&&calc(doc.slice(0,10),[11,10,9,8,7,6,5,4,3,2])===Number(doc[10])}return calc(doc.slice(0,12),[5,4,3,2,9,8,7,6,5,4,3,2])===Number(doc[12])&&calc(doc.slice(0,13),[6,5,4,3,2,9,8,7,6,5,4,3,2])===Number(doc[13])}
export const cleanText=(value,max=2000)=>typeof value==='string'?value.trim().slice(0,max):'';
export const validEmail=value=>typeof value==='string'&&value.length<=254&&/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
export function safeAsaasUrl(value){try{const u=new URL(value);return u.protocol==='https:'&&(u.hostname==='asaas.com'||u.hostname.endsWith('.asaas.com'))?u.href:null}catch{return null}}
export const publicUser=u=>({id:u.id,name:u.name,email:u.email,role:u.role,...(u.staff_profile?{staff_profile:u.staff_profile,permissions:u.permissions}:{})});
