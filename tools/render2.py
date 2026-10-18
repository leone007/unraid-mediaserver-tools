import asyncio
from playwright.async_api import async_playwright
async def main():
    async with async_playwright() as p:
        b=await p.chromium.launch()
        for n,sz in [('sysbox_mono',128)]:
            pg=await b.new_page(viewport={'width':sz,'height':sz})
            await pg.set_content('<html><body style="margin:0;background:transparent">'+open(n+'.svg').read()+'</body></html>')
            await pg.screenshot(path=n+'.png',omit_background=True,clip={'x':0,'y':0,'width':sz,'height':sz})
        await b.close()
asyncio.run(main())
