# Editing the client logos on the Clientele page

Everything to do with the logo wall is in **one place**: the block near the
bottom of `clientele.html` marked

```html
<div class="clientele-logo-list" id="clienteleLogos">
```

Each logo is a single line. Nothing else on the page needs touching — the three
scrolling rows are built from this list automatically, and they re-balance
themselves whenever you add or remove a line.

---

## Add a logo

1. Put the image file in `images/clients/`.
2. Copy any existing line in the list and change the file name and the company
   name:

```html
<li><img src="images/clients/acme-corp.webp" alt="Acme Corp"
         width="660" height="220" loading="lazy" decoding="async"></li>
```

Leave `width="660" height="220"` exactly as it is. Every prepared logo sits on
the same 660×220 canvas, and those numbers are what stop the row from jumping
around while images load.

## Remove a logo

Delete its whole `<li>` line.

## Replace a logo

Drop the new file into `images/clients/` under the **same file name**. Nothing
in the HTML changes.

## Reorder

Move the `<li>` lines up or down. They are dealt across the three rows in the
order they appear — line 1 → row 1, line 2 → row 2, line 3 → row 3, line 4 →
row 1, and so on.

---

## Preparing a new logo file (important)

The logos currently on the page have all been put through the same preparation,
which is why they look evenly sized rather than some huge and some tiny. A raw
logo dropped straight in will look wrong next to them.

Run the prepare script on the new file:

```
python3 images/clients/_normalize-logos.py  <folder-of-new-logos>  images/clients
```

It does four things to each file:

- removes a flat white background (only from the outside edges, so white
  *inside* letters survives)
- trims the artwork tight to its real edges
- scales it by **equal visual area**, so a long thin wordmark and a chunky
  square mark end up looking the same size
- centres it on the standard 660×220 transparent canvas

If one logo still looks a touch big or small next to its neighbours, add it to
the `MANUAL_SCALE` table near the top of that script (`0.9` = a little smaller,
`1.1` = a little larger) and run it again.

Logos that arrive printed on their own dark plate — Concentrix, Insight Global,
nextSource, p3+uplift and Paige are the current examples — keep the plate,
because their artwork is white and would vanish without it. The script detects
these and rounds the plate corners so it reads as a deliberate brand chip.

### Source file quality

Give the script the largest version of a logo you have. Four of the current
logos came from sources too small to be sharp and were upscaled:

| Logo | Source artwork | Upscaled by |
|---|---|---|
| Data Concepts | 154 × 24 px | 2.7× |
| Apex 2000 Inc. | 266 × 36 px | 2.0× |
| vKore Solutions | 147 × 85 px | 1.8× |
| VendorPass | 276 × 55 px | 1.6× |

They are acceptable at the size they display, but a better original (ideally an
SVG, or a PNG around 800 px wide) would sharpen them noticeably.

---

## Things worth knowing

**Tile surface.** The logo tiles are light in *both* light and dark mode. This
is deliberate. Most of these logos are dark monochrome wordmarks — on a dark
tile more than half of them disappear, and inverting them would misrepresent
the brands. A pale plate on the navy keeps every logo at full brand fidelity
with no per-logo filters.

**Speed.** One shared setting, now in `style.css` next to the rest of the
design tokens:

```css
--clx-speed: 32;   /* pixels per second */
```

Lower is slower. All three rows always move at the same speed regardless of how
many logos each holds. The rows never pause — not on hover, and the cards are
not clickable.

**Card shape.** The card geometry follows the Cloudinary logo marquee: 260x80
with a 24px gap, 8px radius, flat fill, no border and no shadow. The edge fade
runs a full 30% in from each side.

**Row count.** Also in `clientele.js`, `var ROWS = 3`. If you drop below about
12 logos, consider 2 rows so each one stays full.

**Accessibility.** With "reduce motion" turned on in the operating system, the
rows stop and the logos are laid out as a plain static grid instead — each one
shown once. The same grid is what appears if JavaScript is unavailable.
