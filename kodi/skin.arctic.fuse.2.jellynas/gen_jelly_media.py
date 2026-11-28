#!/usr/bin/env python3
"""JELLY branding textures (patch 11) from the owner's images in forras/ -> files/media/jellynas/.

jelly_loading.jpg  1920x1080 loading background (from forras/jelly_betoltes.webp)
jelly_badge.png    256x256 round JELLY badge, real alpha (from forras/jelly_badge_512.png)
avatar_mask.png    rounded square (diffuse mask of the Jellyfin user picture)
avatar_frame.png   rounded-square neon frame, cyan -> pink, transparent inside
spinner.png        ring with a cyan arc (rotated by the skin)
Run: python3 kodi/skin.arctic.fuse.2.jellynas/gen_jelly_media.py
"""
import os
from PIL import Image, ImageDraw, ImageFilter, ImageChops

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, 'forras')
OUT = os.path.join(HERE, 'files', 'media', 'jellynas')
CYAN, PINK = (51, 214, 255), (255, 63, 191)

# Frame geometry (2x of the skin size: frame 76 px, picture 66 px inside it)
F, PIC, RAD = 152, 132, 24


def rounded(size, inset, radius, width=0):
    m = Image.new('L', (size, size), 0)
    d = ImageDraw.Draw(m)
    box = (inset, inset, size - 1 - inset, size - 1 - inset)
    if width:
        d.rounded_rectangle(box, radius, outline=255, width=width)
    else:
        d.rounded_rectangle(box, radius, fill=255)
    return m


def gradient(size):
    g = Image.new('RGB', (size, size))
    px = g.load()
    for y in range(size):
        for x in range(size):
            t = (x + y) / (2.0 * (size - 1))
            px[x, y] = tuple(int(CYAN[i] + (PINK[i] - CYAN[i]) * t) for i in range(3))
    return g


def main():
    os.makedirs(OUT, exist_ok=True)
    bg = Image.open(os.path.join(SRC, 'jelly_betoltes.webp')).convert('RGB').resize((1920, 1080), Image.LANCZOS)
    bg.save(os.path.join(OUT, 'jelly_loading.jpg'), quality=86, optimize=True, progressive=False)

    badge = Image.open(os.path.join(SRC, 'jelly_badge_512.png')).convert('RGBA').resize((256, 256), Image.LANCZOS)
    badge.save(os.path.join(OUT, 'jelly_badge.png'), optimize=True)

    m = rounded(128, 0, 24)
    Image.merge('RGBA', (m, m, m, m)).save(os.path.join(OUT, 'avatar_mask.png'), optimize=True)

    inset = (F - PIC) // 2 - 3            # the ring overlaps the picture edge by about 3 px (2x)
    ring = rounded(F, inset, RAD + 3, width=6)
    glow = ring.filter(ImageFilter.GaussianBlur(5)).point(lambda v: min(255, int(v * 1.6)))
    alpha = ImageChops.lighter(ring, glow.point(lambda v: int(v * 0.55)))
    frame = gradient(F).convert('RGBA')
    frame.putalpha(alpha)
    frame.save(os.path.join(OUT, 'avatar_frame.png'), optimize=True)

    S = 96
    sp = Image.new('RGBA', (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(sp)
    d.ellipse((6, 6, S - 7, S - 7), outline=(255, 255, 255, 70), width=7)
    d.arc((6, 6, S - 7, S - 7), -90, 30, fill=CYAN + (255,), width=7)
    sp.save(os.path.join(OUT, 'spinner.png'), optimize=True)
    for f in sorted(os.listdir(OUT)):
        print('OK   %s %d bytes' % (f, os.path.getsize(os.path.join(OUT, f))))


if __name__ == '__main__':
    main()
