"""
Prohlížečový test service workeru proti ukázkové aplikaci (workbench).

    vendor/bin/testbench serve --port=8123 &
    pip install playwright && playwright install chromium
    CHROME=/cesta/k/chrome python3 tests/Browser/e2e.py

Ověřuje: registraci SW, precache, cache jen povolených stránek, offline
obrazovku, frontu offline akcí, údržbu (503), pwa:disable a pwa:enable.
"""
import asyncio, subprocess, json, os
from playwright.async_api import async_playwright
BASE='http://127.0.0.1:8123'
ROOT=os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
TB=[os.environ.get('PHP','php'),'vendor/bin/testbench']
def artisan(*a):
    r=subprocess.run(TB+list(a),cwd=ROOT,capture_output=True,text=True)
    return (r.returncode, (r.stdout+r.stderr).strip().splitlines()[-1:] )
results=[]
def check(name, ok, info=''):
    results.append((('PASS' if ok else 'FAIL'), name, info)); print(('PASS' if ok else 'FAIL'), name, info)
async def main():
    async with async_playwright() as p:
        b=await p.chromium.launch(executable_path=os.environ.get('CHROME') or None)
        ctx=await b.new_context(viewport={'width':1280,'height':800})
        pg=await ctx.new_page()
        logs=[]; pg.on('console', lambda m: logs.append(m.text)); pg.on('pageerror', lambda e: logs.append('ERR '+str(e)))
        await pg.goto(BASE+'/')
        await pg.evaluate("navigator.serviceWorker.ready")
        await pg.reload(); await pg.wait_for_timeout(800)
        ctrl=await pg.evaluate("!!navigator.serviceWorker.controller")
        check('SW ovládá stránku', ctrl)
        keys=await pg.evaluate("caches.keys()")
        check('precache vytvořen', any(k.startswith('pwa-static-') for k in keys), keys)
        off=await pg.evaluate("caches.match('/offline').then(r=>!!r)")
        check('offline stránka v precache', off)
        check('window.Pwa API', await pg.evaluate("typeof window.Pwa.install === 'function' && window.Pwa.displayMode() === 'browser'"))
        await pg.goto(BASE+'/orders'); await pg.goto(BASE+'/private'); await pg.wait_for_timeout(500)
        keys=await pg.evaluate("caches.keys()")
        pages=[k for k in keys if k.startswith('pwa-pages-')]
        cached=await pg.evaluate("(async()=>{const ks=(await caches.keys()).filter(k=>k.startsWith('pwa-pages-'));let out=[];for(const k of ks){const c=await caches.open(k);out=out.concat((await c.keys()).map(r=>new URL(r.url).pathname))}return out})()")
        check('cache jen povolených stránek (pwa.cache)', '/orders' in cached and '/private' not in cached, cached)
        # offline
        await ctx.set_offline(True)
        await pg.goto(BASE+'/orders'); t=await pg.text_content('#title')
        check('offline: povolená stránka z cache', t=='Objednávky', t)
        await pg.goto(BASE+'/private'); h=await pg.text_content('h1')
        check('offline: ostatní stránky → offline obrazovka', h in ('You are offline','Jste offline'), h)
        await pg.goto(BASE+'/orders')
        r=await pg.evaluate("""fetch('/items',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:'name=offline-item&_token='+document.querySelector('meta[name=csrf-token]').content}).then(r=>({s:r.status,q:r.headers.get('X-PWA-Queued')}))""")
        check('offline POST se uloží do fronty (202)', r['s']==202 and r['q']=='1', r)
        cnt=await pg.evaluate("new Promise(res=>{const q=indexedDB.open('pwa');q.onsuccess=()=>{const c=q.result.transaction('queue').objectStore('queue').count();c.onsuccess=()=>res(c.result)}})")
        check('fronta v IndexedDB', cnt==1, cnt)
        await ctx.set_offline(False)
        await pg.evaluate("navigator.serviceWorker.controller.postMessage({type:'REPLAY_QUEUE'})"); await pg.wait_for_timeout(1500)
        cnt=await pg.evaluate("new Promise(res=>{const q=indexedDB.open('pwa');q.onsuccess=()=>{const c=q.result.transaction('queue').objectStore('queue').count();c.onsuccess=()=>res(c.result)}})")
        check('po připojení se fronta odeslala', cnt==0, cnt)
        # session keepalive
        s=await pg.evaluate("fetch('/pwa/session',{headers:{Accept:'application/json'}}).then(r=>r.json())")
        check('keep-alive vrací CSRF', bool(s.get('csrf')), s.get('user'))
        # maintenance
        rc=artisan('pwa:down','--message=Nasazujeme novou verzi','--retry=60')
        resp=await pg.goto(BASE+'/orders'); h=await pg.text_content('h1')
        check('údržba: 503 s obrazovkou údržby (ne offline)', resp.status==503 and 'Nasazujeme' in (await pg.content()), (resp.status,h))
        cached_ok=await pg.evaluate("(async()=>{const ks=(await caches.keys()).filter(k=>k.startsWith('pwa-pages-'));for(const k of ks){const r=await (await caches.open(k)).match('/orders');if(r) return r.status}return null})()")
        check('údržba nepřepsala cache stránek', cached_ok==200, cached_ok)
        sw=await pg.evaluate("fetch('/sw.js',{cache:'no-store'}).then(r=>r.status)")
        check('sw.js během údržby (bez výjimek 503 → SW zůstává)', sw in (200,503), sw)
        artisan('up')
        await pg.goto(BASE+'/orders'); check('po údržbě normálně', await pg.text_content('#title')=='Objednávky')
        # kill switch
        artisan('pwa:disable','--force')
        await pg.goto(BASE+'/'); await pg.wait_for_timeout(2500)
        regs=await pg.evaluate("navigator.serviceWorker.getRegistrations().then(r=>r.length)")
        keys=await pg.evaluate("caches.keys()")
        check('pwa:disable odregistruje SW a smaže cache', regs==0 and not any(k.startswith('pwa-') for k in keys), (regs,keys))
        artisan('pwa:enable')
        await pg.goto(BASE+'/'); await pg.evaluate("navigator.serviceWorker.ready")
        check('pwa:enable znovu registruje', await pg.evaluate("navigator.serviceWorker.getRegistrations().then(r=>r.length)")==1)
        errs=[l for l in logs if l.startswith('ERR')]
        check('bez JS chyb na stránce', not errs, errs[:3])
        await b.close()
asyncio.run(main())
passed=sum(1 for r in results if r[0]=='PASS')
print(passed,'/',len(results))
raise SystemExit(0 if passed==len(results) else 1)
