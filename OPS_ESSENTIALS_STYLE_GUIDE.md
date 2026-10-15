# Ops Essentials — Style Guide

How Ops Essentials screens, documents and copy should look and read. It covers
the app our clients log into (Ops Customers, Ops Quotes, Ops Inbox), the quotes
and PDFs it produces, and how we describe the products. The Modern Ops website
and brand follow `STYLE_GUIDE.md`. When in doubt, match what's already in the app.

Values here come from `hq/app/public/admin/_layout.php` and `_quote_pdf.php`.

---

## 1. What it should feel like

- A tool a busy owner opens between jobs. Fast, quiet, obvious.
- Their business is the brand on screen, not ours. Their name and colors sit in
  the top bar; Ops Essentials stays in the background.
- Every screen answers: what is this, what needs doing, where do I click.

---

## 2. Names

| Use | Don't use |
|---|---|
| Ops Essentials (all three together) | the suite, the platform, HQ suite |
| Ops Customers | CRM, customer tool |
| Ops Quotes | quoting tool, proposals tool |
| Ops Inbox | Email tab, email client |
| Catalog (inside Ops Quotes) | price list, product library |
| Dashboard | Home, Overview |

- Always "Ops" plus the word, capitalized, no hyphen: **Ops Quotes**, not ops-quotes or OpsQuotes.
- In the app, nav labels drop "Ops": Customers, Requests, Quotes, Catalog, Inbox.
  The product name goes in the nav tooltip, the dashboard group labels and
  "not part of this account" messages.
- In the code, use `product_name()` rather than typing the name.
- The portal is named after the client: "Sunny Side Bakery HQ".

---

## 3. Color

Each client brings four colors (set in their config file, see BUILD_GUIDE.md §6).
Everything else is fixed so every client's app stays readable.

**Client colors**

| Token | Modern Ops value | Used for |
|---|---|---|
| `accent` | `#1a6b4a` | Buttons, links, active nav, stat numbers, focus rings |
| `accent_dark` | `#35b082` | Accent in dark mode (lighter so it reads on dark) |
| `deep` | `#0F3D2A` | Quote/PDF headings, dark total boxes |
| `tint` | `#E6F4EC` | Quote section bands, light total box, "new" badge |
| `highlight` | `#5fcfa0` | The portal word ("HQ") in the dark top bar |

**Fixed colors**

| Token | Light | Dark | Used for |
|---|---|---|---|
| `bg` | `#f4f3ef` | `#11161a` | Page background |
| `surface` | `#ffffff` | `#1b2328` | Cards |
| `text` | `#1a1a1a` | `#eef1ee` | Body text |
| `muted` | `#5a5a5a` | `#9fb0aa` | Labels, secondary text |
| `border` | `#e2e2dd` | `#2a353b` | Card edges, table lines |
| `topbar` | `#1a1a1a` | `#0c1114` | Top bar (always dark) |
| danger | `#b02424` | | Delete buttons, decline badge, errors |

**Rules**
- Define colors as CSS custom properties on `:root`; never type a hex value into a
  component rule. Client colors come from PHP (`brand_color()`), never hard-coded.
- Picking client colors: `accent` must pass 4.5:1 contrast with white text;
  `deep` must be dark (white text sits on it); `tint` must be very light
  (dark text sits on it). Check a real PDF before handing over.
- Status colors stay the same for every client so they always mean the same thing.

---

## 4. Type

| Where | Font | Size / weight |
|---|---|---|
| App body | System UI stack: `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif` | 15px, line-height 1.55 |
| Page title (`h1.page`) | Georgia, serif | 1.5rem, 700 |
| Top bar name | Georgia, serif | 1.05rem, 700 |
| Stat numbers | Georgia, serif | 1.8rem, 700, accent color |
| Labels, table headers, stat labels | System UI | 0.72–0.82rem, uppercase for headers, letter-spacing 0.06em |
| Buttons | System UI | 0.88rem, 600 (`.small`: 0.8rem) |
| Quote paper + PDF | Arial / Helvetica | Headings bold in `deep`; body 9–10pt |

- No web fonts in the app. It loads fast and looks native on every device.
- Sentence case for buttons, titles and labels ("New quote", "Add as customer").
  Uppercase only for small table headers, stat labels and PDF section bands.

---

## 5. Page layout

Every page is built from `layout_header()` and `layout_footer()`.

- **Top bar:** dark, sticky, 56px tall. Client name + portal word on the left,
  then the nav. Theme toggle (◐), username and Log out on the right.
- **Content:** uses the full width of the screen, 2.5rem side padding (1rem on phones), 1.75rem top.
  Edit forms lay their fields out across the page with `.grid-auto` (as many 220px+
  columns as fit; one column on a phone). Only the quote preview stays page-sized,
  because it mirrors the printed PDF.
- **Page head:** `h1.page`, then one line of `.sub` text saying what the page is
  for. The main action (`.btn`, e.g. "+ New quote") sits top right.
- **Body:** full-width sections (`.card`) separated by hairlines. No boxes, rounded panels or shadows; the only shaded areas are notices (`.notice`) and the quote preview (`.paper`). One job per section.
- **Sections** always follow list → detail → create/edit (BUILD_GUIDE.md §4).

**Mobile (640px and below)**
- Two-column grids become one column; nav links shrink; the username hides.
- Every page must work at 375px wide with no sideways scrolling.
- Wide tables either fit or hide less important columns. Don't make the page scroll sideways.

---

## 6. Components

| Component | Look | Use |
|---|---|---|
| Section (`.card`) | No box: full width, a hairline rule above, 1.4rem top/bottom padding, Georgia heading from its first `<strong>` | Groups one job |
| Stat (`.stat`) | No box: 3px accent bar on the left, big Georgia number + small uppercase label | Dashboard counts; links to its list |
| Primary button (`.btn`) | Accent fill, white text, 7px radius | The one main action on a screen |
| Secondary (`.btn.secondary`) | Surface fill, border | Everything else |
| Small (`.btn.small`) | Tighter padding | Row actions in tables |
| Danger (`.btn.danger`) | Surface fill, red text + light red border | Delete / remove; always asks first |
| Table | Uppercase muted headers, hairline rows, no stripes | Lists |
| Badge (`.badge`) | Pill, uppercase, bold 0.72rem | Status only |
| Form fields | Full width, 7px radius, label above in small muted semibold | All inputs |
| Flash (`flash()`) | Tinted bar at the top: green ok, red err | Result of the last action |
| Empty (`.empty`) | Centered muted sentence | Nothing to show yet |

**Status badges** (same for every client)

| Status | Style |
|---|---|
| new | `tint` background, accent text |
| contacted | `#fff3d6` / `#8a6d1a` |
| quoted, sent | `#e0ecff` / `#2451b0` |
| won, accepted | Accent fill, white text |
| closed, draft | `#e6e6e6` / `#777` |
| declined | `#fde2e2` / `#b02424` |

- One primary button per screen. If two buttons look equally important, one is wrong.
- Icons: none in the app, apart from ◐ (theme), ↗ (opens in a new tab), × (remove)
  and ▲ ▼ ⠿ (reorder).

---

## 7. Light and dark

- Every page supports both. Default follows the device; the ◐ button overrides it
  and the choice is saved in `localStorage` (`mohq-theme`).
- Light values live on `:root`; dark values sit under `:root[data-theme="dark"]` and
  under `@media (prefers-color-scheme: dark)` guarded by `:root:not([data-theme="light"])`.
- Anything that represents paper (the quote preview, an HTML email) stays white in
  both modes.

---

## 8. Quotes and PDFs

The quote preview on screen and the PDF must match line for line.

1. Logo (or the client name) left, **QUOTE** and the number right, with a `deep` rule under it.
   Then three columns: Prepared for, From, Quote details.
2. Every PDF page has a grey footer: client name, domain, email, and "Page X of Y".
3. **SCOPE OF WORK** band (`tint` background, `deep` text), if filled in.
4. **ONE-TIME SERVICES** band and lines. Section headings the user added show as
   bold `deep` text with a rule under them.
5. Recurring band below it, always below one-time: "MONTHLY RECURRING" if every
   recurring line is monthly, "RECURRING SERVICES" if there's a mix. Each line shows
   its period: /mo, /qtr, /6 mo, /yr.
6. Totals panel on the right (`tint`): Subtotal, tax (only if above 0), a dark (`deep`)
   **Due on acceptance** bar, then **THEN** with one line per recurring period ("Every quarter  $100.00/qtr").
7. Lines with a quantity above 1 show "2 x $52.00" under the description.
8. **OPTIONAL ADD-ONS** band (if any): a grey italic line saying they are not in the
   totals, then the lines (with their own section headings and periods).
9. **NEXT STEPS** (from the client file) and the payment line, then a boxed "Ready to go ahead?"
   link to accept online, or the "Accepted online by …" record once accepted.
10. Notes, then the thank-you line and contact email in grey italics.
11. If included: Terms & Conditions page, then signature lines (client name + date, client signature
   + date, printed name, title). Once accepted online, the signature section shows the acceptance record instead.

- Cost, margin and profit are **internal**. They never appear on the preview, the
  PDF, the email or anything the customer sees.
- A line with a price of $0 still shows $0.00 today.
- File name: `<file_prefix>-<quote number>.pdf`.

---

## 9. Writing

Plain, direct and short. Write like a person at the counter, not a brochure.

**Do**
- Say what happens: "Saved.", "Quote emailed to the customer.", "Message deleted."
- Tell people what to do next: "No quotes yet. Click **New quote** to build your first."
- Name the problem and the fix: "Please choose a business."
- Use the customer's words: customer, quote, price, email. Not lead pipeline,
  proposal workflow or CRM.
- Numbers as numerals, money with two decimals: $45.00, 30%.

**Don't**
- No filler openers or closers ("Great news!", "Seamlessly", "Unlock", "Elevate",
  "Empower", "Effortless", "Streamline your workflow").
- No exclamation marks in the app.
- No em dashes in client-facing copy (quotes, emails, the website). Use a period
  or a comma.
- No jargon: no "sync", "instance", "module", "leverage", "solution".
- Don't blame the user ("You entered an invalid…"). Say what's needed instead.

**Confirm before destroying:** "Delete this quote permanently?", "Remove this contact?"
One short question, no extra warning text.

---

## 10. Before shipping a screen

- [ ] Uses the client's colors through tokens; no client value typed in.
- [ ] One primary button.
- [ ] Empty, loading and error states written (BUILD_GUIDE.md §7).
- [ ] Works at 375px and in dark mode.
- [ ] Product names spelled exactly; copy passes section 9.
