#!/usr/bin/env python3
"""
GSS Clientele — client logo normalizer.

Takes the raw client logo files (any mix of PNG/JPG/WEBP, transparent or not,
wildly different crops and proportions) and produces one evenly-balanced,
transparent WEBP per logo, all on an identical 3:1 canvas.

What it does to each file
  1. removes a flat light background (flood-filled from the edges only, so
     white *inside* letterforms and counters survives)
  2. trims the artwork tight to its real bounding box
  3. scales it by equal-VISUAL-AREA rather than equal height, so a long thin
     wordmark and a chunky square mark end up looking the same size
  4. centres it on a fixed 660x220 transparent canvas

Because every output shares one canvas, the page needs no per-logo CSS at all:
each tile is the same box and the logos are already balanced inside it.

Re-run this any time new logos are added:
    python3 normalize_logos.py <input-dir> <output-dir>
"""

import os
import sys
import glob
import math
import json
from collections import deque

from PIL import Image

# ── output canvas ────────────────────────────────────────────────────────
CANVAS_W, CANVAS_H = 660, 220          # 3:1, @2x of the ~330x110 display box
SAFE_W = int(CANVAS_W * 0.94)          # never let art touch the edges
SAFE_H = int(CANVAS_H * 0.90)
TARGET_AREA = SAFE_W * SAFE_H * 0.58   # the "optical weight" every logo gets

# Pure equal-area (p = 0.5) makes long thin wordmarks run too wide, because a
# wordmark is mostly whitespace. Biasing p above 0.5 pulls wide logos in.
ASPECT_BIAS = 0.57

# Optical corrections a formula can't see: type weight, colour intensity and
# the padding a brand baked into its own plate. Eyeballed against the set.
MANUAL_SCALE = {
    'data-concepts':        0.84,   # very heavy bold face, reads oversized
    'cloudq':               0.95,   # high-chroma, advances
    'vkore-solutions':      0.95,
    'accumatch-consulting': 0.96,   # stacked lockup
    'skybeyo-technologies': 0.96,
    'stellar-gis':          0.96,
    'mphasis':              0.97,
    # plated lockups carry their own internal padding, so lift them slightly
    'concentrix-catalyst':  1.10,
    'insight-global':       1.10,
    'next-source-staffing': 1.10,
    'p3-uplift':            1.10,
    'paige-technologies':   1.10,
}

PLATE_RADIUS = 10                      # soften baked-on brand plates into chips

BG_TOLERANCE = 30                      # how close to the corner colour counts as background
DARK_PLATE_MAX_LUM = 0.42              # below this the background is a deliberate brand plate


def luminance(rgb):
    r, g, b = [c / 255 for c in rgb[:3]]
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def edge_background_colour(im):
    """Most common colour around the border of the image."""
    w, h = im.size
    px = im.load()
    counts = {}
    for x in range(w):
        for y in (0, h - 1):
            counts[px[x, y]] = counts.get(px[x, y], 0) + 1
    for y in range(h):
        for x in (0, w - 1):
            counts[px[x, y]] = counts.get(px[x, y], 0) + 1
    return max(counts.items(), key=lambda kv: kv[1])


def strip_background(im, bg):
    """
    Flood-fill the background away from every border pixel that matches `bg`.
    Only *connected* background is removed, so the white inside an 'o' stays.
    """
    w, h = im.size
    px = im.load()
    br, bgc, bb = bg[:3]

    def matches(p):
        if len(p) == 4 and p[3] < 12:
            return True
        return (abs(p[0] - br) <= BG_TOLERANCE
                and abs(p[1] - bgc) <= BG_TOLERANCE
                and abs(p[2] - bb) <= BG_TOLERANCE)

    seen = bytearray(w * h)
    q = deque()
    for x in range(w):
        for y in (0, h - 1):
            if not seen[y * w + x] and matches(px[x, y]):
                seen[y * w + x] = 1
                q.append((x, y))
    for y in range(h):
        for x in (0, w - 1):
            if not seen[y * w + x] and matches(px[x, y]):
                seen[y * w + x] = 1
                q.append((x, y))

    while q:
        x, y = q.popleft()
        px[x, y] = (px[x, y][0], px[x, y][1], px[x, y][2], 0)
        for dx, dy in ((1, 0), (-1, 0), (0, 1), (0, -1)):
            nx, ny = x + dx, y + dy
            if 0 <= nx < w and 0 <= ny < h and not seen[ny * w + nx]:
                if matches(px[nx, ny]):
                    seen[ny * w + nx] = 1
                    q.append((nx, ny))
    return im


def soften_edges(im):
    """One-pixel alpha feather so trimmed artwork doesn't look cut out."""
    from PIL import ImageFilter
    a = im.getchannel('A').filter(ImageFilter.GaussianBlur(0.4))
    im.putalpha(a)
    return im


def round_corners(im, radius):
    """Soften a baked-on brand plate so it reads as a deliberate chip."""
    from PIL import ImageDraw
    scale = 4
    mask = Image.new('L', (im.width * scale, im.height * scale), 0)
    ImageDraw.Draw(mask).rounded_rectangle(
        (0, 0, im.width * scale - 1, im.height * scale - 1),
        radius=radius * scale, fill=255)
    mask = mask.resize(im.size, Image.LANCZOS)
    out = im.copy()
    a = out.getchannel('A').point(lambda v: v)
    out.putalpha(Image.composite(a, Image.new('L', im.size, 0), mask))
    return out


def art_stats(im):
    """Mean luminance and saturation of the visible pixels — used for reporting."""
    small = im.resize((min(im.width, 160), min(im.height, 160)))
    px = small.load()
    lum, sat, n = 0.0, 0.0, 0
    for y in range(small.height):
        for x in range(small.width):
            r, g, b, a = px[x, y]
            if a < 40:
                continue
            mx, mn = max(r, g, b), min(r, g, b)
            sat += 0 if mx == 0 else (mx - mn) / mx
            lum += luminance((r, g, b))
            n += 1
    if not n:
        return 0.5, 0.0
    return lum / n, sat / n


def slugify(name):
    out = []
    for ch in name.lower():
        if ch.isalnum():
            out.append(ch)
        elif ch in ' -_&+':
            out.append('-')
    slug = ''.join(out)
    while '--' in slug:
        slug = slug.replace('--', '-')
    return slug.strip('-')


# Display names: filenames are inconsistent / contain typos, so the alt text
# and the marquee label come from this table rather than from the file name.
DISPLAY_NAMES = {
    'accumatch-consulting':    'Accumatch Consulting',
    'apex-systems':            'Apex Systems',
    'apex-2000-inc':           'Apex 2000 Inc.',
    'cloudq':                  'CloudQ',
    'concentrix-catalyst':     'Concentrix Catalyst',
    'data-concepts':           'Data Concepts',
    'ecloud-labs':             'eCloud Labs',
    'gac-solutions':           'GAC Solutions',
    'hcl-global':              'HCL Global Systems',
    'hcl-vendor-pass':         'VendorPass, an ICON Consultants company',
    'insight-global':          'Insight Global',
    'medsure-systems':         'MedSure Systems',
    'mphasis':                 'Mphasis',
    'next-source-staffing':    'nextSource',
    'niche-software':          'Niche Software Solutions',
    'p3-uplift':               'P3 + Uplift',
    'paige-technologies':      'Paige Technologies',
    'paragon-it-proffesional': 'Paragon IT Professionals',
    'populus-group':           'Populus Group',
    'sidmans':                 'Sidmans',
    'skybeyo-technologies':    'Skybeyo Technologies',
    'smart-it-frame':          'Smart IT Frame',
    'snap-it-solutions':       'Snap IT Solutions',
    'sogeti':                  'Sogeti, part of Capgemini',
    'stellar-gis':             'Stellar GIS Corp',
    'synapse-tech-services':   'Synapse Tech Services',
    'vkore-solutions':         'vKore Solutions',
}


def main(src_dir, out_dir):
    os.makedirs(out_dir, exist_ok=True)
    files = sorted(
        f for f in glob.glob(os.path.join(src_dir, '*'))
        if os.path.splitext(f)[1].lower() in ('.png', '.jpg', '.jpeg', '.webp', '.gif')
    )

    manifest = []
    for path in files:
        base = os.path.splitext(os.path.basename(path))[0]
        slug = slugify(base)
        im = Image.open(path).convert('RGBA')

        bg, _ = edge_background_colour(im)
        bg_lum = luminance(bg)
        bg_is_transparent = len(bg) == 4 and bg[3] < 12
        plate = (not bg_is_transparent) and bg_lum < DARK_PLATE_MAX_LUM

        if bg_is_transparent:
            treatment = 'transparent'
        elif plate:
            # A deliberate dark brand plate (white artwork printed on navy).
            # Keep the plate — the artwork cannot survive without it — and just
            # trim the excess so the chip sits tight.
            treatment = 'plate'
        else:
            im = strip_background(im, bg)
            im = soften_edges(im)
            treatment = 'stripped'

        bbox = im.getbbox() if treatment != 'plate' else None
        if bbox:
            im = im.crop(bbox)
        elif treatment == 'plate':
            # trim uniform plate margin: crop to where the plate colour ends
            im = im.crop(im.convert('RGB').getbbox() or (0, 0, im.width, im.height))

        if treatment == 'plate' and PLATE_RADIUS:
            im = round_corners(im, PLATE_RADIUS)

        w, h = im.size
        ratio = w / h

        # aspect-biased equal area, then clamped to the safe box
        k = math.sqrt(TARGET_AREA) * MANUAL_SCALE.get(slug, 1.0)
        th = k / (ratio ** ASPECT_BIAS)
        tw = k * (ratio ** (1 - ASPECT_BIAS))
        if tw > SAFE_W:
            tw, th = SAFE_W, SAFE_W / ratio
        if th > SAFE_H:
            th, tw = SAFE_H, SAFE_H * ratio
        tw, th = max(1, int(round(tw))), max(1, int(round(th)))

        art = im.resize((tw, th), Image.LANCZOS)
        canvas = Image.new('RGBA', (CANVAS_W, CANVAS_H), (0, 0, 0, 0))
        canvas.alpha_composite(art, ((CANVAS_W - tw) // 2, (CANVAS_H - th) // 2))

        lum, sat = art_stats(art)
        out_name = f'{slug}.webp'
        canvas.save(os.path.join(out_dir, out_name), 'WEBP', quality=94, method=6)

        manifest.append({
            'slug': slug,
            'file': out_name,
            'name': DISPLAY_NAMES.get(slug, base),
            'source': os.path.basename(path),
            'src_size': [w, h],
            'ratio': round(ratio, 2),
            'placed': [tw, th],
            'treatment': treatment,
            'lum': round(lum, 3),
            'sat': round(sat, 3),
            'upscale': round(tw / w, 2),
        })
        flag = '  << LOW-RES SOURCE' if tw / w > 1.6 else ''
        print(f'{slug:26} {treatment:12} {w}x{h} r={ratio:5.2f} -> {tw}x{th}'
              f'  x{tw / w:.2f}{flag}')

    with open(os.path.join(out_dir, '_manifest.json'), 'w') as fh:
        json.dump(manifest, fh, indent=2)
    print(f'\n{len(manifest)} logos written to {out_dir}')


if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else 'logos_raw',
         sys.argv[2] if len(sys.argv) > 2 else 'gss/images/clients')
