# OG image template

Renders `public/assets/og/og-default.png` (1200x630) for social previews.

## Regenerate

```sh
node og-template/render.mjs
```

The script spins up a tiny HTTP server, points Puppeteer at it, screenshots the page at 1200x630, and writes the PNG into `public/assets/og/og-default.png`. The PNG is committed so prod doesn't need to render it on each deploy.

## Files

- `og-default.html` — the design. Edit this when the branding changes.
- `render.mjs` — Puppeteer driver. Uses the system-installed `puppeteer` (do not add node_modules to this repo).

## Visual elements

- Dark `#0e0e0d` background with subtle radial vignette + dotted grid
- Triangle Immunity mark + Fraunces wordmark, top-left
- "Decentralized threat intel" pill, top-right
- Hero serif headline ("An attack on one is a vaccine for all")
- Lede explaining antibodies
- Bottom row: URL + chain note in mono
- **Gradient bottom line**: `#7ab87a → #a8cfa8 → #e8d4a0 → #dca0a0 → #c45a5a` (immune green → accent cream → threat red, mapping the verdict spectrum)
