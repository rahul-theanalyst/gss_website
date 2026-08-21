Image naming convention for GSS site backgrounds
==================================================

Each section uses a light/dark pair with matching filenames:

  <section>-light.webp   <section>-dark.webp
  <section>-light-mobile.webp   <section>-dark-mobile.webp   (960px wide, for small screens)

Currently in place (generated + compressed from your ChatGPT exports):
  hero-light / hero-dark              -> index.html hero
  tech-light / tech-dark              -> solutions.html, section 1 ("Technology Should Move Your Business Forward")
  datacenter-light                    -> solutions.html, section 2 ("From Business Challenge to Technology Outcome")
                                          NOTE: no dark counterpart yet. solutions.html applies the
                                          `theme-bg--simulated-dark` CSS class as a temporary stand-in
                                          (darkens + tints the light photo via CSS filter). This is a
                                          placeholder, not the recommended long-term look — replace it:
                                            1. Generate datacenter-dark.webp from the "data center corridor,
                                               dark version" prompt.
                                            2. Add a second <img class="theme-bg__img theme-bg__img--dark">
                                               tag pointing at it (copy the pattern used in section 1).
                                            3. Remove the `theme-bg--simulated-dark` class from that section.

Still needed (solutions.html section 3 currently shows a plain gradient
placeholder with "Image pending" text so it's obvious in the browser):
  talent-light.webp / talent-dark.webp   -> "Talent at Scale. On Your Terms."

Also referenced by the original prompt set but not yet built into a page:
  about-light.webp / about-dark.webp     -> for a future about.html hero

How to add a new pair once generated:
  1. Drop the two raw PNG/JPG exports into this folder (or the R:\Global_Systems\bg_images
     folder you already have connected — ask Claude to compress + place them).
  2. Compress to WebP (~quality 78-82) and export a 960px-wide "-mobile" variant of each,
     matching the naming pattern above. Claude can do this with Pillow/cwebp on request.
  3. In the HTML, duplicate the <div class="theme-bg">...</div> block from an existing
     working section (e.g. the "tech" section in solutions.html) and swap the filenames.
