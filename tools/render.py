import asyncio,sys
from playwright.async_api import async_playwright
async def main():
    async with async_playwright() as p:
        b=await p.chromium.launch(executable_path='/opt/pw-browsers/chromium' if False else None)
        for n,sz in [('streambox_mono',128),('seedbox_mono',128),('streambox_discord',512),('seedbox_discord',512)]:
            pg=await b.new_page(viewport={'width':sz,'height':sz})
            svg=open(n+'.svg').read()
            await pg.set_content('<html><body style="margin:0;background:transparent">'+svg+'</body></html>')
            await pg.screenshot(path=n+'.png',omit_background=True,clip={'x':0,'y':0,'width':sz,'height':sz})
        await b.close()
asyncio.run(main())
