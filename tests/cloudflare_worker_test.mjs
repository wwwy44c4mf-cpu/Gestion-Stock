import test from 'node:test';
import assert from 'node:assert/strict';
import worker from '../docker/cloudflare/worker.mjs';
const PUBLIC='https://gestion-stock.materiel-reseau.workers.dev', UPSTREAM='https://gstockbl.anthony-laporte.ovh';
function mock(t, fn) { const previous=globalThis.fetch; globalThis.fetch=fn; t.after(()=>{globalThis.fetch=previous;}); }

test('only the exact HTTPS alias is accepted; no query or path can select a proxy target', async t=>{
 let calls=0;mock(t,async r=>{calls++;assert.equal(new URL(r.url).origin,UPSTREAM);return new Response('ok');});
 for(const url of ['https://other.workers.dev/a','http://gestion-stock.materiel-reseau.workers.dev/a'])assert.equal((await worker.fetch(new Request(url))).status,404);
 const response=await worker.fetch(new Request(PUBLIC+'//example.invalid/path?url=https://evil.invalid'));
 assert.equal(response.status,200);assert.equal(calls,1);
});
test('POST preserves its body, session and CSRF token, strips spoofed proxy headers and does not follow redirects',async t=>{
 mock(t,async (r,options)=>{assert.equal(r.method,'POST');assert.equal(r.redirect,'manual');assert.equal(r.cache,'no-store');assert.equal(await r.text(),'quantity=1');assert.equal(r.headers.get('Cookie'),'GESTION_STOCK_SESSION=fixture');assert.equal(r.headers.get('X-CSRF-Token'),'fixture-token');assert.equal(r.headers.get('Origin'),UPSTREAM);assert.equal(r.headers.get('X-Forwarded-For'),null);assert.equal(r.headers.get('X-Forwarded-Host'),null);assert.equal(options.cf.cacheTtlByStatus['100-599'],-1);return new Response(null,{status:303,headers:{Location:UPSTREAM+'/consommations?ok=1'}});});
 const response=await worker.fetch(new Request(PUBLIC+'/sortie',{method:'POST',headers:{Origin:PUBLIC,Cookie:'GESTION_STOCK_SESSION=fixture','X-CSRF-Token':'fixture-token','X-Forwarded-For':'forged','X-Forwarded-Host':'evil.invalid'},body:'quantity=1'}));
 assert.equal(response.status,303);assert.equal(response.headers.get('Location'),PUBLIC+'/consommations?ok=1');assert.equal(response.headers.get('Cache-Control'),'no-store, private');
});
test('cross-site writes are refused before calling the origin',async t=>{mock(t,()=>{throw Error('must not fetch');});assert.equal((await worker.fetch(new Request(PUBLIC+'/',{method:'POST',headers:{Origin:'https://evil.invalid'},body:'password=fixture'}))).status,403);});
test('external redirects are refused without forwarding cookies to the external site',async t=>{let calls=0;mock(t,async()=>{calls++;return new Response(null,{status:302,headers:{Location:'https://evil.invalid/collect'}});});const r=await worker.fetch(new Request(PUBLIC+'/'));assert.equal(r.status,502);assert.equal(calls,1);assert.equal(r.headers.get('Location'),null);});
test('relative redirects stay on the alias and multiple cookies preserve security attributes',async t=>{
 const h=new Headers({'Location':'/profil','Content-Security-Policy':"default-src 'self'",'Permissions-Policy':'camera=(self), microphone=()'});h.append('Set-Cookie','GESTION_STOCK_SESSION=fixture; Domain=gstockbl.anthony-laporte.ovh; Path=/; HttpOnly; SameSite=Lax');h.append('Set-Cookie','second=fixture; Expires=Wed, 21 Oct 2037 07:28:00 GMT; Secure; HttpOnly');
 mock(t,async()=>new Response(null,{status:303,headers:h}));const r=await worker.fetch(new Request(PUBLIC+'/'));assert.equal(r.headers.get('Location'),PUBLIC+'/profil');const cookies=r.headers.getSetCookie();assert.equal(cookies.length,2);assert.ok(cookies.every(c=>c.includes('Secure')&&c.includes('HttpOnly')&&!/Domain=/i.test(c)));assert.ok(cookies[1].includes('Wed, 21 Oct'));assert.equal(r.headers.get('Permissions-Policy'),'camera=(self), microphone=()');assert.equal(r.headers.get('Content-Security-Policy'),"default-src 'self'");
});
test('upstream errors are neutral and contain no origin or credentials',async t=>{mock(t,async()=>{throw Error(UPSTREAM+' secret');});const r=await worker.fetch(new Request(PUBLIC+'/'));assert.equal(r.status,502);assert.ok(!(await r.text()).includes(UPSTREAM));});
