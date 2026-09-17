# Unused files and deployment audit

This audit checks all 12 website pages, the root entry and every stylesheet, including responsive image sources and social preview images. JavaScript builds the client rows from those HTML images. No files were deleted.

## Images not referenced by the website

20 images (32.39 MiB) are not referenced by the current site. They can be left out of deployment. Several are original source images or alternative exports, so retain them if useful for later editing.

| File | Likely purpose |
| --- | --- |
| `images/backgrounds/solutions-bg-dark-mobile.webp` | Unused background variant |
| `images/backgrounds/solutions-bg-dark.webp` | Unused background variant |
| `images/backgrounds/solutions-bg-light-mobile.webp` | Unused background variant |
| `images/backgrounds/solutions-bg-light.webp` | Unused background variant |
| `images/brand/gss_logo.png` | Original light logo; the site now uses the web-sized `gss-logo-light.webp` and `favicon-48.png` |
| `images/brand/gss_logo_dark.png` | Original dark logo; the site now uses the web-sized `gss-logo-dark.webp` |
| `images/certifications/inc-5000-logo.jpg` | Alternative export or image size |
| `images/certifications/uspaacc-logo.jpg` | Alternative export or image size |
| `images/home/hero-datahall-light.webp` | Alternative export or image size |
| `images/home/hero-skyline-day.webp` | Alternative export or image size |
| `images/pages/our-focus-area.png` | Original Our Focus Area hero; the page now uses `fa-hero-560/900/1300.jpg` |
| `images/pages/solutions-hero.png` | Original Technology & Delivery hero; the page now uses `td-hero-560/900/1300.jpg` |
| `images/source/1f7e1ba3-c529-4a97-8b1f-899c4c4e63ed.png` | Original/reference artwork |
| `images/source/698b36cb-d60c-4e13-bb54-4e79e2a27972.png` | Original/reference artwork |
| `images/source/about-team-src.png` | Original/reference artwork |
| `images/source/about-work-src.png` | Original/reference artwork |
| `images/source/business-consulting.png` | Original/reference artwork |
| `images/source/data-business-insights.png` | Original/reference artwork |
| `images/source/data-hall-src.png` | Original/reference artwork |
| `images/source/hero-skyline-night-src.png` | Original/reference artwork |

## Development files that the live site does not need

- `docs/previews/`: 36 historical screenshots (15.76 MiB). Optional visual references.
- `tests/fixtures/`: 2 saved third-party diagnostic responses. The PHP regression test does not load these.
- `tools/diagnostics/ceipal.php`: standalone diagnostic; the website uses `server/careers-jobs.php`.
- `.local-tools/clientele-review/` and `.local-tools/organization-review/`: temporary preparation and verification files.
- `.local-tools/php.zip`: downloaded PHP archive; the installed runtime lives in `.local-tools/php/`.
- Preview server log files in `.local-tools/`: development output.

## Keep these

- All referenced images, all page files, the CSS modules and JavaScript files.
- `images/pages/clx-hero-1300.jpg` and `images/pages/gt-hero-1300.jpg`: used only for link previews (Clientele and Global Talent), because some social sites do not show WebP preview images.
- `images/clients/_manifest.json` and `tools/normalize-client-logos.py` for maintaining the client logos.
- `server/careers-jobs.php` and the private server configuration for the live careers feed.
- `.local-tools/php/`: the current VS Code preview task uses this installed runtime.
- `tests/careers-jobs-test.php` and `tools/check-site.py`: useful regression and reference checks.

Link-preview images use full addresses (`https://globalsoftsystems.com/images/...`). `tools/check-site.py` skips full addresses, so it does not check those files; keep every image named in a page's `og:image` tag.

Re-run `python tools/check-site.py` after any cleanup. Unreferenced means unused by the current website, not necessarily worthless as an editing source.
