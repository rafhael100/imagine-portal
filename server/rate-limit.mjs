// Atomic SQLite rate bucket accounting shared by authentication, downloads
// and admin BI. A SELECT followed by UPDATE can lose increments across Node
// processes, allowing concurrent workers to exceed an intended quota.
const UPSERT_BUCKET=`INSERT INTO rate_limits(key,count,reset_at) VALUES(?,1,?)
 ON CONFLICT(key) DO UPDATE SET
  count=CASE WHEN rate_limits.reset_at<=? THEN 1 ELSE rate_limits.count+1 END,
  reset_at=CASE WHEN rate_limits.reset_at<=? THEN excluded.reset_at ELSE rate_limits.reset_at END
 WHERE rate_limits.reset_at<=? OR rate_limits.count<?`;

export function consumeRateLimit(db,key,max,window,at=Date.now()){
 if(typeof key!=='string'||key.length===0||key.length>256||
    !Number.isSafeInteger(max)||max<1||!Number.isSafeInteger(window)||window<1||
    !Number.isSafeInteger(at)||at<0||!Number.isSafeInteger(at+window))
  throw new TypeError('Configuração de limite inválida.');
 // One SQL statement acquires the SQLite writer lock; other connections see
 // its committed count instead of racing against a stale read.
 const result=db.prepare(UPSERT_BUCKET).run(key,at+window,at,at,at,max);
 return result.changes===1;
}
