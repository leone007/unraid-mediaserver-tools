#!/usr/bin/env python3
"""JELLY branding textures (patch 11) from the owner's images in forras/ -> files/media/jellynas/.

jelly_loading.jpg  1920x1080 loading background (from forras/jelly_betoltes.webp)
jelly_badge.png    160x160 round JELLY badge, real alpha: plain LANCZOS from the 512 source (shown at 80 px)
avatar_mask.png    156x156 rounded square (diffuse mask of the Jellyfin user picture, shown at 78 px)
avatar_frame.png   176x176 rounded-square neon frame, cyan -> pink, transparent inside (shown at 88 px: the line's
                   outer edge is 80 px = the search button's focus circle, AF2 circlebutton.png 160 px with an 80 px disc)
spinner.png        72x72 ring with a cyan arc (rotated by the skin, shown at 36 px)
Everything is 2x the skin size, pre-filtered here: Kodi draws GUI textures bilinear WITHOUT mipmaps, so a texture
much bigger than its drawn size aliases (4x looked jagged on the Fire TV Stick 4K Max, 2026-11-28). Never more than 2x.
Shapes are drawn at 4x the skin size and reduced with LANCZOS (anti-aliased, crisp).
Run: python3 kodi/skin.arctic.fuse.2.jellynas/gen_jelly_media.py
"""
import os
from PIL import Image, ImageDraw, ImageFilter, ImageChops

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, 'forras')
OUT = os.path.join(HERE, 'files', 'media', 'jellynas')
CYAN, PINK = (51, 214, 255), (255, 63, 191)

K = 2                                   # texture pixels per skin pixel (never more than 2: no mipmaps in Kodi)
SS = 2                                  # supersampling of the drawn shapes (K * SS = 4x the skin size)
F, PIC, RAD = 88 * K, 78 * K, 14 * K    # frame box 88 px (line outer edge 80), picture 78 px, corner radius 14 px (skin px)
BADGE = 80                               # badge 80 px = the search circle


def rounded(size, inset, radius, width=0):
    """A rounded square (filled, or a ring of the given width), drawn SS times larger and reduced: smooth edges."""
    s = size * SS
    m = Image.new('L', (s, s), 0)
    d = ImageDraw.Draw(m)
    box = (inset * SS, inset * SS, s - 1 - inset * SS, s - 1 - inset * SS)
    if width:
        d.rounded_rectangle(box, radius * SS, outline=255, width=width * SS)
    else:
        d.rounded_rectangle(box, radius * SS, fill=255)
    return m.resize((size, size), Image.LANCZOS)


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

    badge = Image.open(os.path.join(SRC, 'jelly_badge_512.png')).convert('RGBA').resize((BADGE * K, BADGE * K), Image.LANCZOS)
    # no sharpening: LANCZOS at 2x is sharp enough; an unsharp mask gave light halos around the letters (2.12.12.34)
    badge.save(os.path.join(OUT, 'jelly_badge.png'), optimize=True)

    m = rounded(PIC, 0, RAD)
    Image.merge('RGBA', (m, m, m, m)).save(os.path.join(OUT, 'avatar_mask.png'), optimize=True)

    inset = (F - PIC) // 2 - K            # the ring overlaps the picture edge by 1 skin px
    ring = rounded(F, inset, RAD + K, width=2 * K)          # 2 skin px neon line (1 px at 1080, 4 px in the texture)
    core = rounded(F, inset + K // 2, RAD + K, width=K)     # a brighter inner core of the line
    glow = ring.filter(ImageFilter.GaussianBlur(2.5 * K)).point(lambda v: min(255, int(v * 1.5)))
    alpha = ImageChops.lighter(ImageChops.lighter(ring, core), glow.point(lambda v: int(v * 0.5)))
    frame = gradient(F).convert('RGBA')
    frame.putalpha(alpha)
    frame.save(os.path.join(OUT, 'avatar_frame.png'), optimize=True)

    S = 36 * K * SS
    sp = Image.new('RGBA', (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(sp)
    w, b = 3 * K * SS, 2 * K * SS
    d.ellipse((b, b, S - 1 - b, S - 1 - b), outline=(255, 255, 255, 70), width=w)
    d.arc((b, b, S - 1 - b, S - 1 - b), -90, 30, fill=CYAN + (255,), width=w)
    sp = sp.resize((36 * K, 36 * K), Image.LANCZOS)
    sp.save(os.path.join(OUT, 'spinner.png'), optimize=True)
    for f in sorted(os.listdir(OUT)):
        print('OK   %s %d bytes' % (f, os.path.getsize(os.path.join(OUT, f))))


if __name__ == '__main__':
    main()
