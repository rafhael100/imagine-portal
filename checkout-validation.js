/* Validação de formato e dígitos verificadores; não consulta titularidade cadastral. */
(function(root){
 const digits=v=>String(v||'').replace(/\D/g,'');
 const cleanDocument=v=>String(v||'').toUpperCase().replace(/[.\s/\-]/g,'');
 function validDocument(value){const d=cleanDocument(value);if(/^(.)\1+$/.test(d))return false;const dv=(base,weights)=>{const r=[...base].reduce((n,c,i)=>n+(c.charCodeAt(0)-48)*weights[i],0)%11;return r<2?0:11-r};if(/^\d{11}$/.test(d))return dv(d.slice(0,9),[10,9,8,7,6,5,4,3,2])===+d[9]&&dv(d.slice(0,10),[11,10,9,8,7,6,5,4,3,2])===+d[10];return /^[A-Z\d]{12}\d{2}$/.test(d)&&dv(d.slice(0,12),[5,4,3,2,9,8,7,6,5,4,3,2])===+d[12]&&dv(d.slice(0,13),[6,5,4,3,2,9,8,7,6,5,4,3,2])===+d[13]}
 function documentMask(value){const d=String(value).toUpperCase().replace(/[^A-Z\d]/g,'').slice(0,14);return d.length<=11&&!/[A-Z]/.test(d)?d.replace(/^(\d{3})(\d)/,'$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/,'$1.$2.$3').replace(/(\d{3})\.(\d{3})\.(\d{3})(\d)/,'$1.$2.$3-$4'):d.replace(/^(.{2})(.)/,'$1.$2').replace(/^(.{2})\.(.{3})(.)/,'$1.$2.$3').replace(/^(.{2})\.(.{3})\.(.{3})(.)/,'$1.$2.$3/$4').replace(/\/(.{4})(.)/,'/$1-$2')}
 const ddds=new Set('11 12 13 14 15 16 17 18 19 21 22 24 27 28 31 32 33 34 35 37 38 41 42 43 44 45 46 47 48 49 51 53 54 55 61 62 63 64 65 66 67 68 69 71 73 74 75 77 79 81 82 83 84 85 86 87 88 89 91 92 93 94 95 96 97 98 99'.split(' '));
 function phoneDigits(v){let d=digits(v);if((d.length===12||d.length===13)&&d.startsWith('55'))d=d.slice(2);return d}
 function validPhone(v){const d=phoneDigits(v);return ddds.has(d.slice(0,2))&&(/^[1-9]\d9\d{8}$/.test(d)||/^[1-9]\d[2-5]\d{7}$/.test(d))&&!/^(\d)\1+$/.test(d.slice(2))}
 function phoneMask(v){const d=phoneDigits(v).slice(0,11);if(d.length<3)return d?'('+d:d;const n=d.slice(2),split=n.length>8?5:4;return '('+d.slice(0,2)+') '+n.slice(0,split)+(n.length>split?'-'+n.slice(split):'')}
 const api={validDocument,documentMask,validPhone,phoneMask,cleanDocument,phoneDigits};if(typeof module!=='undefined'&&module.exports)module.exports=api;else root.CheckoutValidation=api;
})(typeof window!=='undefined'?window:{});
