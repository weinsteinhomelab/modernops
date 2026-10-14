# Modern Ops — Style Guide

How Modern Ops things should be built. Applies to the public website, the HQ
dashboard, and generated documents (quotes/PDFs). When in doubt, match what's
already here.

## Brand & voice
- **Who:** Modern Ops — web & business operations for small businesses anywhere in the US (based in PA).
- **Voice:** plain, confident, friendly. No jargon. Short sentences. Reassure
  non-technical owners ("we handle the tech so you can run your business").
- **Never:** hype, buzzwords, pushy sales language.

## Colors
Brand green is the anchor. Define colors as CSS custom properties; never hardcode
hex in component rules.

| Token | Light | Dark | Use |
|---|---|---|---|
| accent | `#1a6b4a` | `#35b082` | buttons, links, highlights |
| accent (deep) | `#0F3D2A` | — | quote/PDF headers & dark total band |
| accent-light | `#e8f4ee` / `#E6F4EC` | — | tags, quote section bands |
| bg | `#f4f3ef` | `#11161a` | page background |
| surface | `#ffffff` | `#1b2328` | cards |
| text | `#1a1a1a` | `#eef1ee` | body text |
| muted | `#555` | `#9fb0aa` | secondary text |
| border | `#e2e2dd` | `#2a353b` | hairlines, card edges |

## Typography
- **Website headings:** `Playfair Display` (700/800), italic emphasis in accent green.
- **Website body:** `Lato`.
- **HQ dashboard:** system UI stack for body; `Georgia` serif for headings/brand.
- **Quotes / PDF:** `Arial`/Helvetica, dark green `#0F3D2A` headers.
- Favicon/logo monogram: rounded green square, serif "MO" (M black, O white).

## Dark / light theming
- Every HQ/admin page supports both, with a toggle (◐) and `localStorage` key `mohq-theme`.
- Default follows `prefers-color-scheme`.
- Tokens: light defaults on `:root`; dark overrides under both
  `:root[data-theme="dark"]` and `@media (prefers-color-scheme: dark)` guarded by
  `:root:not([data-theme="light"])`.
- Documents meant to represent paper (quote view/PDF) stay on white regardless of theme.

## Components
- **Buttons:** solid accent (primary), bordered surface (`.secondary`), `.small`,
  and a red-outline `.danger` for destructive actions. Radius ~7px, weight 600–700.
- **Cards:** surface bg, 1px border, 10–16px radius, ~1.25rem padding.
- **Tables:** uppercase muted headers, hairline row borders, no zebra.
- **Badges:** pill, uppercase, status-colored (new=green-tint, won/accepted=solid green, etc.).
- **Forms:** labels are small/semibold/muted above inputs; inputs full-width, 7px radius.

## Layout & motion
- Content max-width ~1000–1100px, centered, generous whitespace.
- Mobile-first: everything must work at phone width; grids collapse to one column.
- **Animations:** subtle and fast (200–400ms), fade + slight slide. Use
  `IntersectionObserver` (no libraries), stagger lists, and **always** respect
  `prefers-reduced-motion` and degrade gracefully without JS.

## Quote / PDF document
- Header "MODERN OPS" + tagline with green underline; `QUOTE` heading.
- Fields: Prepared for / Business / Quote date / Valid until / Quote #.
- Two groups: **ONE-TIME SERVICES** and **MONTHLY RECURRING (OPTIONAL)**, each a
  light-green band + line items (bold title, grey description, right-aligned price).
- Totals: light-green **ONE-TIME TOTAL** box + dark-green **MONTHLY TOTAL** box;
  optional tax line above (rate + label, set per quote).
- Notes, then (optional) Terms & Conditions + signature page, then the italic
  grey thank-you footer.

## Code & security conventions
- PHP: PDO with **prepared statements** only; escape all output (`e()`),
  CSRF tokens on every form, bcrypt password hashing.
- Config via **environment variables**; secrets live in `.env`/server only,
  never in git. Security headers + login throttling on authenticated apps.
- Keep changes minimal and match surrounding style.

## Delivery conventions
- **Preview before push:** show a `preview.html` (or rendered sample) for any new
  page/document before it goes live.
- Public website deploys from `main` → SiteGround (auto). HQ (home-server) code
  lives under `hq/` and is excluded from the website deploy; the Zima box pulls
  the working branch and rebuilds.
- Confirm outward-facing or hard-to-reverse actions before doing them.
