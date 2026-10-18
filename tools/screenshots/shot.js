// node shot.js <frag.html> <out.png> <width> <map json: {"api_fetch.php":"x.json", ...}>
const {chromium}=require('playwright'); const fs=require('fs'); const path=require('path');
const [frag,out,width,mapj]=process.argv.slice(2); const map=JSON.parse(mapj); const M=__dirname+'/mock/';
const css=`
:root{color-scheme:dark}
body{margin:0;padding:16px;background:#121212;color:#f2f2f2;font-family:"clear-sans","Helvetica Neue",Arial,sans-serif;font-size:13px}
a{color:#e68a00}
table.dash_tile{width:${width}px;border-collapse:collapse;background:#1c1b1b;border:1px solid #2b2b2b;border-radius:6px;overflow:hidden}
table.dash_tile td{padding:0 14px}
table.dash_tile tbody tr:first-child td{padding:14px 14px 8px}
.tile-header{display:flex;justify-content:space-between;align-items:center;width:100%}
.tile-header-left{display:flex;align-items:center;gap:12px}
.tile-header-main{margin:0;font-size:17px;font-weight:bold;color:#f2f2f2}
.tile-header .section{display:flex;flex-direction:column;gap:3px}
.tile-header-right-controls{display:flex;gap:8px}.tile-header-right-controls a{color:#8a8a8a}
i.f32{display:inline-block;width:32px;height:32px}
.control{font-size:16px}
`;
(async()=>{
 const b=await chromium.launch(); const pg=await b.newPage({viewport:{width:+width+32,height:900},deviceScaleFactor:2});
 await pg.route('**/*',async r=>{const u=new URL(r.request().url());
   if(u.hostname==='nas.local'){
     if(u.pathname==='/Dashboard'){return r.fulfill({contentType:'text/html',body:`<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css"><style>${css}</style><script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script></head><body>${fs.readFileSync(frag,'utf8')}</body></html>`});}
     const f=path.basename(u.pathname); const pop=new URLSearchParams(r.request().postData()||'').get('op'); if(pop && f!=='tools.php' && fs.existsSync(M+f.replace('.php','')+'_'+pop+'.json')){return r.fulfill({contentType:'application/json',body:fs.readFileSync(M+f.replace('.php','')+'_'+pop+'.json','utf8')});}
     if(f==='tools.php'){const op=new URLSearchParams(r.request().postData()||'').get('op')||'state';const fn='tools_'+op+'.json';return r.fulfill({contentType:'application/json',body:fs.existsSync(M+fn)?fs.readFileSync(M+fn,'utf8'):'{"ok":true}'});}
     let key=f+(u.searchParams.get('only')?'?'+u.searchParams.get('only'):'');
     if(map[key]){return r.fulfill({contentType:'application/json',body:fs.readFileSync(M+map[key],'utf8')});}
     if(/icon\.png$/.test(f)){const id=u.pathname.split('/')[2];const n={sysbox:'sysbox',diskbox:'diskbox',streambox:'streambox',qbittorrent_widget:'seedbox'}[id];return r.fulfill({contentType:'image/png',body:fs.readFileSync(path.join(__dirname,'../../icons/',n+'_mono.png'))});}
     if(/\.png$/.test(f)){return r.fulfill({status:404,body:''});}
     return r.fulfill({contentType:'application/json',body:'{"ok":false}'});
   } return r.continue();});
 pg.on('pageerror',e=>console.log('PAGEERR',e.message));
 await pg.goto('http://nas.local/Dashboard'); await pg.waitForTimeout(4000);
 for(const c of (process.env.CLICK||'').split('|').filter(Boolean)){const e=await pg.$(c); if(e){await e.click(); await pg.waitForTimeout(400);} else console.log('noclick',c);}
 if(process.env.HIDE){await pg.addStyleTag({content:process.env.HIDE+'{display:none!important}'});}
 await pg.waitForTimeout(500);
 const el=await pg.$('table.dash_tile')||await pg.$(process.env.SEL||'body'); await el.screenshot({path:out});
 await b.close(); console.log('saved',out);
})();
