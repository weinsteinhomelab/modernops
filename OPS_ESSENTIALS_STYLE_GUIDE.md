# Ops Essentials — Design Guide

How Ops Essentials looks, reads and behaves. It covers the app our clients log into
(Ops Customers, Ops Quotes, Ops Inbox), the quotes and PDFs it produces, and how we
describe the products. The Modern Ops website follows `STYLE_GUIDE.md`.

The shared look lives in `hq/app/public/admin/_layout.php` (tokens, sidebar, components).
Page-specific layouts are in `index.php` (Dashboard), `customer.php` and `quote_edit.php`.
When in doubt, match those three screens.

---

## 1. What it should feel like

- A product a busy owner opens between jobs. Fast, calm, obvious.
- Every screen answers: what is this, what needs doing, where do I click.
- The Dashboard leads with **what needs you**, not with counts.
- Soft panels group things: white cards with a light border and a faint shadow on a
  warm grey page. One job per panel.

---

## 2. Brand

**Ops Essentials is the brand on screen.** The client's business shows only as the
workspace label at the top of the sidebar. The client's own colors and logo are used
on what *their* customers see: quotes, PDFs and emails (section 9).

**The mark: OpsE.** A rounded green tile (gradient `#2fb383` → `#0f6e52`, top-left to
bottom-right) with the letters **Ops** in black and **E** in white, Inter Tight 800.

| Where | Size |
|---|---|
| Sidebar, next to "Ops Essentials / by Modern Ops" | 30px tall |
| Login page, above "Ops Essentials" | 48px tall |
| Favicon | 32px square SVG (`brand_favicon()`) |

- Use `oe_mark()` in PHP; don't redraw it.
- Never recolor the letters, add effects, or put the mark on a busy background.
- Page titles read "<Page> · Ops Essentials".

---

## 3. Names

| Use | Don't use |
|---|---|
| Ops Essentials (all three together) | the suite, the platform, HQ suite |
| Ops Customers | CRM, customer tool |
| Ops Quotes | quoting tool, proposals tool |
| Ops Inbox | Email tab, email client |
| Catalog (inside Ops Quotes) | price list, product library |
| Dashboard | Home, Overview (the phone tab bar says "Home" for space) |

- Always "Ops" plus the word, capitalized, no hyphen: **Ops Quotes**.
- Sidebar group headings use the product names (OPS CUSTOMERS, OPS QUOTES, OPS INBOX);
  the links inside drop "Ops": Customers, Requests, Quotes, Catalog, Inbox.
- In code, use `product_name()` rather than typing the name.

---

## 4. Color

All colors are tokens on `:root` in `_layout.php`. Never type a hex value into a component
rule. Status colors are the same for every client so they always mean the same thing.

| Token | Light | Dark | Used for |
|---|---|---|---|
| `--accent` | `#0f6e52` | `#3cbf8f` | Primary buttons, links, active states, focus rings |
| `--tint` | `#e3f1ea` | `#173a2d` | Success / accepted backgrounds, flash ok |
| `--bg` | `#f3f4f1` | `#0e1311` | Page background |
| `--surface` | `#ffffff` | `#161d1a` | Panels |
| `--surface-2` | `#f8f9f6` | `#1b2420` | Table headers, hover rows, composers |
| `--text` / `--text-2` / `--muted` | `#16201b` / `#4d5a53` / `#6f7c76` | `#e8eeea` / `#b3c0b9` / `#8a9993` | Body / secondary / labels |
| `--border` / `--border-2` | `#e3e6e0` / `#d5dad3` | `#26312c` / `#33413a` | Panel edges / inputs and secondary buttons |
| `--side` | `#0f1a16` | `#0a0f0d` | Sidebar (always dark) |
| `--blue` + `-soft` | `#2459c9` / `#e6edfb` | | Sent, new, info |
| `--amber` + `-soft` | `#a86400` / `#fdf1dc` | | Needs attention, pending approval |
| `--red` + `-soft` | `#b42828` / `#fbe6e4` | | Declined, delete, errors |
| `--violet` + `-soft` | `#6a45c2` / `#efe9fb` | | Approvals, win rate |

Old names (`--topbar`, `--field`, `--field-border`, `--accent-text`) still exist so older page
styles keep working. Use the names above in new code.

---

## 5. Type

| Where | Font | Size / weight |
|---|---|---|
| App body | Inter (self-hosted, `admin/fonts/inter.woff2`) | 14.5px, line-height 1.55 |
| Page title (`h1.page`) | Inter Tight | 26px (23px on phones), 700, letter-spacing −0.02em |
| Big numbers (stats, totals) | Inter Tight | 28px / 22px, 700, tabular figures |
| Panel headings | Inter | 15px, 650 |
| Labels | Inter | 12.5px, 600, `--text-2` |
| Sidebar group headings | Inter | 10.5px, 700, uppercase, letter-spacing 0.09em |
| Quote paper + PDF | Arial / Helvetica | Headings bold in the client's `deep`; body 9–10pt |

- Fonts are served from our own server (the app's security policy blocks outside fonts).
  Inter is under the SIL Open Font License (`fonts/OFL.txt`).
- Sentence case for buttons, titles and labels ("New quote", "Log it").
  Uppercase only for small sidebar group headings and PDF section bands.
- Money uses tabular figures so columns line up.

---

## 6. Layout

Every page is built from `layout_header()` and `layout_footer()`.

**Sidebar (no top bar).** Everything lives in the dark sidebar on the left, 244px wide and
sticky:
1. The OpsE mark + "Ops Essentials / by Modern Ops".
2. The workspace (client) label with its monogram.
3. Dashboard, then one group per product the client has, then Admin (admins only).
   Counts sit on the right of a link: green for things to act on (new requests, approvals).
4. At the bottom: Dark/Light mode, Your account, then the person (initials, name, role)
   with the log-out icon.

**Content.** 28px top / 32px side padding, up to 1500px wide. Each page starts with a back
link where it has a parent ("← All customers"), then the page head: `h1.page`, a one-line
`.sub`, and the main actions on the right (primary last).

**Panels.** `.card` (or `.panel` on the three redesigned screens) = white, 1px border,
12px radius, faint shadow. A panel's heading row (`.ph`) has the title on the left and a
small text link on the right ("Edit", "+ Add", "See all").

**Two-column screens.** Detail screens put the main work on the left and context on the
right (Dashboard: needs-you + quotes | inbox + activity; Quote: editor | sticky Summary).
The customer page is the reverse (details + people + notes | activity tabs). Below 1100px
they become one column.

**Phones (860px and below).**
- The sidebar becomes a drawer; a bottom tab bar shows Home, Customers, Quotes, Inbox and
  **More** (opens the full sidebar). Tabs only show for products the client has.
- Content padding 16px; leave room at the bottom for the tab bar.
- List tables turn into compact rows (first cell on top, the rest flowing after it, the
  button on the right). No page may scroll sideways at 375px.
- Quote line items stack: title, then qty / cost / margin, then price / billing / total,
  then the description.

---

## 7. Components

| Component | Look | Use |
|---|---|---|
| Panel (`.card`, `.panel`) | White card, border, 12px radius, faint shadow | Groups one job |
| Stat (`.stat`) | Panel with a small colored square + label, big Inter Tight number, one muted line under it | Dashboard numbers; links to their list |
| Needs-you row (`.todo`) | Tinted icon tile, bold line, muted detail, secondary small button on the right | Things to act on today |
| Timeline (`.feed .ev`) | Round icon on a thin vertical line, text, date on the right; notes in a soft box | Activity, customer history |
| Tabs (`.tab`) | Text tabs with a 2px accent underline when on, count pill after the label | Switching views inside a panel |
| Primary button (`.btn`) | Accent fill, 38px tall, 9px radius | The one main action on a screen |
| Secondary (`.btn.secondary`) | White with a border | Everything else |
| Small (`.btn.small`) | 32px tall | Row actions |
| Danger (`.btn.danger`) | White, red text and light red border | Delete / remove; always asks first |
| Badge (`.badge`) | Pill with a colored dot, sentence case | Status only |
| Form fields | 38px tall, 8px radius, label above; focus = accent border + soft ring | All inputs |
| Flash (`flash()`) | Rounded tinted bar: green ok, red err | Result of the last action |
| Notice (`.notice`) | Amber (or red `.err`) rounded box | Approval and warning callouts |
| Empty (`.empty`) | Centered muted sentence with the next step | Nothing to show yet |

**Status badges**

| Status | Style |
|---|---|
| new, sent, quoted, Demo | blue on `--blue-soft` |
| contacted, Needs approval | amber on `--amber-soft` |
| won, accepted, approved, Live, Customer | accent on `--tint` |
| closed, draft | `--text-2` on grey |
| declined, rejected | red on `--red-soft` |
| Prospect | blue |

**Icons.** Outline icons from `oe_icon()` (24px grid, 1.8 stroke, `currentColor`), 18px in
the sidebar and buttons, 14–16px inside timelines. Add new ones to `oe_icon()`; don't
paste SVG into pages. Keep × for remove, ↗ for "opens in a new tab", ▲ ▼ ⠿ for reorder.

- One primary button per screen.

---

## 8. Light and dark

- Every page supports both. The default follows the device; "Dark mode / Light mode" in the
  sidebar overrides it and is saved in `localStorage` (`mohq-theme`).
- Light values live on `:root`; dark values under `:root[data-theme="dark"]` and under
  `@media (prefers-color-scheme: dark)` guarded by `:root:not([data-theme="light"])`.
- The sidebar is dark in both. Anything that represents paper (the quote preview, an HTML
  email) stays white in both.

---

## 9. Quotes and PDFs

Customer-facing documents carry **the client's** brand (colors from their config:
`accent`, `deep`, `tint`), not Ops Essentials. The on-screen preview and the PDF match
line for line.

1. Logo (or the client name) left, **QUOTE** and the number right, with a `deep` rule under it.
   Then three columns: Prepared for, From, Quote details.
2. Every PDF page has a grey footer: client name, domain, email, and "Page X of Y".
3. **SCOPE OF WORK** band (`tint` background, `deep` text), if filled in.
4. **ONE-TIME SERVICES** band and lines. Section headings show as bold `deep` text with a rule.
5. Recurring band below it: "MONTHLY RECURRING" if every recurring line is monthly,
   "RECURRING SERVICES" if mixed. Each line shows its period: /mo, /qtr, /6 mo, /yr.
6. Totals panel on the right (`tint`): Subtotal, tax (only if above 0), a dark (`deep`)
   **Due on acceptance** bar, then **THEN** with one line per recurring period.
7. Lines with a quantity above 1 show "2 x $52.00" under the description.
8. **OPTIONAL ADD-ONS** band (if any), with a grey italic line saying they're not in the totals.
9. **NEXT STEPS** and the payment line, then the "Ready to go ahead?" accept-online link, or the
   "Accepted online by …" record once accepted.
10. Notes, then the thank-you line and contact email in grey italics.
11. If included: Terms & Conditions page, then signature lines (or the acceptance record).

- Cost, margin and profit are **internal**. In the app they sit in shaded "internal" rows of the
  quote Summary; they never appear on the preview, PDF, email or anything the customer sees.
- File name: `<file_prefix>-<quote number>.pdf`.

---

## 10. Writing

Plain, direct and short. Write like a person at the counter, not a brochure.

**Do**
- Say what happens: "Saved.", "Quote emailed to the customer.", "Touch point logged."
- Tell people what to do next: "No quotes yet. Make your first one."
- Name the problem and the fix: "Please choose a business."
- Use the customer's words: customer, quote, price, email.
- Numbers as numerals, money with two decimals in lists ($45.00); big dashboard numbers
  may round ($18,420).

**Don't**
- No filler ("Great news!", "Seamlessly", "Unlock", "Elevate", "Streamline your workflow").
- No exclamation marks in the app.
- No em dashes in client-facing copy (quotes, emails, the website).
- No jargon: "sync", "instance", "module", "leverage", "solution".
- Don't blame the user. Say what's needed instead.

**Confirm before destroying:** "Remove this contact?" One short question.

---

## 11. Before shipping a screen

- [ ] Built from `layout_header()` / `layout_footer()`; uses tokens, no hex in component rules.
- [ ] Content is in panels; one primary button.
- [ ] Back link where the page has a parent.
- [ ] Empty, loading and error states written (BUILD_GUIDE.md §7).
- [ ] Works at 375px (no sideways scroll, room for the tab bar) and in dark mode.
- [ ] Product names spelled exactly; copy passes section 10.
- [ ] Checked on a throwaway copy before going live.
