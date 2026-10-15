# Ops Essentials — Build Guide

How the Ops Essentials code is put together, how to add to it, and how to set
it up for a new client. Written from the code in `hq/app/` as of schema
version 6. If the code and this guide disagree, the code wins; fix the guide.

---

## 1. What Ops Essentials is

One PHP + MySQL app (the code under `hq/app/`) sold as three products:

| Product | What the client gets | Pages |
|---|---|---|
| **Ops Customers** | Customer list, website requests, contacts, contact history | Customers, Requests |
| **Ops Quotes** | Quote builder, branded PDF, emailing quotes, saved items | Quotes, Catalog (+ Customers, see below) |
| **Ops Inbox** | Read and send email from their business mailboxes | Inbox |

- **Dashboard** is shared. It shows one row of numbers per product the client has.
- **Catalog** is part of Ops Quotes and is never sold on its own.
- **Ops Inbox** is not Business Email Setup. Business Email Setup creates the
  address at the client's domain; Ops Inbox shows that mailbox inside HQ.
- A client can buy any one product, any two, or all three (the **Ops Essentials** bundle).
  The products are switched on and off in the client's config file (section 6).
- If a client has Ops Quotes but not Ops Customers, the Customers page stays
  available because every quote needs a customer. Requests stay hidden.

Modern Ops runs its own copy (`clients/modernops.php`). Our copy also has a
Previews link that clients never get.

---

## 2. File structure

```
hq/
  app/
    clients/              one config file per client (no secrets)
      _template.php       copy this for a new client
      modernops.php       our own copy
    lib/                  FPDF + fonts (quote PDFs)
    public/               web root (public_html on SiteGround)
      index.php           sends visitors to admin/
      api/submit.php      website contact form posts here (Ops Customers)
      api/quote.php       public quote data + online acceptance (Ops Quotes)
      brand/<slug>.png    client logo used on quotes and PDFs
      admin/              every page of the app
        _*.php            shared code, never served (blocked by .htaccess)
        config.php        reads the client file + .env (never served)
        schema.sql        full schema for a fresh install (never served)
        <page>.php        one file per screen
    Dockerfile            our home-server build
    composer.json/.lock   webklex/php-imap (Ops Inbox)
  docker-compose.yml      app + MariaDB + nightly backup (our box)
  sql/                    one-off data scripts, run by hand
  .env.sample             secrets template
```

### Shared files in `admin/`

| File | Job |
|---|---|
| `config.php` | `client()`, `product_on()`, `envv()`, and the constants the rest of the code uses |
| `_db.php` | PDO connection, `db()`, `e()` (escape), `money()`, `setting()` / `set_setting()` |
| `_auth.php` | Session, login, CSRF (`csrf_field()`, `check_csrf()`), login throttling, security headers, `require_login()` (also runs migrations) |
| `_layout.php` | Page chrome: `layout_header($title, $activeNavFile)`, `layout_footer()`, `flash($type, $msg)` |
| `_brand.php` | `brand_title()`, `brand_color()`, `hex_rgb()`, favicon, `product_name()`, `require_product()` |
| `_migrate.php` | `SCHEMA_VERSION` + one `run_phaseN_migration()` per upgrade |
| `_billing.php` | Billing periods (one-time, monthly, quarterly, semi-annual, annual) and their totals |
| `_quote_render.php` | Quote data (`quote_load()`, `quote_numbers()`), the customer link (`quote_accept_url()`), and the quote HTML shown in HQ and on the accept page (`quote_paper_html()`) |
| `_quote_pdf.php` | Builds the quote PDF; `quote_terms()`, `quote_file_name()` |
| `_mail.php` | SMTP sending (plain + PDF attachment) |
| `_imap.php` | Reading mailboxes for Ops Inbox; HTML email cleaning |
| `_sites.php` | Demo/live websites linked to a customer |
| `_verticals.php` | Industry list for the customer form |

---

## 3. Naming

- **Product names, exactly:** Ops Essentials, Ops Customers, Ops Quotes, Ops Inbox.
  Get them from `product_name('customers' | 'quotes' | 'inbox')`; don't retype them.
- **Nav and page labels:** Dashboard, Requests, Customers, Quotes, Catalog, Inbox.
  The nav link's tooltip names the product.
- **Pricebook items:** `<Product> Setup` and `<Product> Monthly`
  (e.g. "Ops Quotes Setup", "Ops Quotes Monthly"). Ops Customers monthly is
  per seat: "Ops Customers Monthly (per seat)".
- **Page files:** lowercase, no prefix for screens (`quotes.php`); a leading
  underscore for shared code (`_billing.php`), which also keeps it from being served.
- **Section file names:** `<things>.php` list, `<thing>.php` or `<thing>_view.php` detail,
  `<thing>_edit.php` create + edit (section 4).
- **Database:** plural snake_case tables (`quote_items`), singular `<thing>_id`
  foreign keys, `created_at` / `updated_at` timestamps, ENUMs for fixed lists.
- **Client slug:** lowercase letters, numbers, `-` or `_` (`sunnyside`). Used for
  the config file name and `CLIENT=` in `.env`.

---

## 4. Section layout: list, detail, create/edit

Every section uses the same three screens. Copy an existing section when you add one.

| Section | List | Detail | Create / edit |
|---|---|---|---|
| Customers | `customers.php` | `customer.php?id=` | `customer_edit.php` (new) / `?id=` (edit); contacts: `contact_edit.php` |
| Requests | `requests.php` | `request.php?id=` | none (requests come from the website form) |
| Quotes | `quotes.php` | `quote_view.php?id=` | `quote_edit.php` / `?id=` |
| Catalog | `catalog.php` (add form on the list) | none | `catalog_edit.php?id=` |
| Inbox | `email.php` | `email.php?uid=` | `email.php?compose=1` / `?reply=` |

**List**
- `layout_header('Quotes', 'quotes.php')`, an `h1.page`, a one-line `.sub`, the
  main button top right ("New quote").
- One `.card` with a `<table>`. Each row links to its detail page.
- Filters and search are GET parameters so the URL can be shared
  (`customers.php?q=&vertical=`, `requests.php?status=`).

**Detail**
- Back link at the top (`&larr; Quotes`), title, status badge.
- Actions in a `.row-actions` group. Destructive actions are separate
  `.btn.danger` forms with a `confirm()`.
- Small edits happen on the detail page as POST forms with an `action` field
  (`customer.php`: `add_tp`, `add_site`, `site_kind`, `del_site`, `del_contact`).
  After a POST, redirect (`Location: ...&saved=1`) so a refresh doesn't re-submit.

**Create / edit**
- One file does both. No `id` means create; `?id=` loads the row and means edit.
- POST to itself, validate, set `$err` and re-show the form on failure, or
  save and redirect to the detail page with `&saved=1`.
- Every form has `csrf_field()` and the handler calls `check_csrf()` first.

**Every page starts the same way:**
```php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();
require_product('quotes');   // or 'customers', 'inbox'; Customers pages pass ('customers', 'quotes')
```

---

## 5. Data model

```
businesses ─┬─< contacts
 (customers)├─< touchpoints        (contact history)
            ├─< sites              (demo / live websites)
            ├─< requests           (website form; business_id set when converted)
            └─< quotes ──< quote_items
                  │
                  └── contact_id ─> contacts (who the quote is for)

catalog_items   (saved items; copied into quote_items, not linked)
settings        (key/value: next_quote_number, schema_version, ...)
admin_users, login_attempts   (login)
```

**How the sections connect**
- **Request → customer.** `request.php` "Add as customer" creates a `businesses`
  row and a `contacts` row from the request, then sets `requests.business_id`.
- **Quote → customer.** Every quote needs `business_id`; `contact_id` is optional.
  The customer page lists that customer's quotes and requests.
- **Quote → Catalog.** Adding a saved item **copies** its title, description,
  price and billing into the quote line. There is no link back, so changing a
  Catalog price never changes a quote that already exists. This is on purpose.
- **Quote lines.** `quote_items` holds both items and section headings
  (`is_section = 1`, title only). `sort_order` is the order on the quote.
  `billing` puts a line in one-time or recurring; headings use `one_time` or
  `monthly` only to mark which area they sit in.
  `unit_cost` and `margin` are internal and never printed for the customer.
  `is_optional = 1` marks an optional add-on: listed after the totals on the quote and
  PDF, never counted in totals, the quote list or profit.
- **Online acceptance.** `quotes.public_token` is the customer link key (random, 32 hex). `api/quote.php`
  returns the quote HTML for that token and records acceptance: `accepted_at`, `accepted_name`,
  `accepted_ip`, `accepted_ua`, and the optional add-ons they ticked (`accepted_optional`, JSON ids).
  It never returns cost, margin or profit. Status becomes `accepted` and the business is emailed.
- **Inbox** has no tables. Mail stays on the mail server and is read over IMAP.

**Changing the schema**
1. Add the change to `schema.sql` (fresh installs).
2. Add `run_phaseN_migration()` in `_migrate.php` with idempotent SQL
   (`ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`), bump
   `SCHEMA_VERSION`, and call it from `maybe_migrate()`.
3. Existing databases upgrade themselves the next time someone logs in.
4. Back up the database before deploying a version that migrates.

---

## 5a. People, permissions and approvals

Code: `_users.php`. Pages: People (`users.php`, `user_edit.php`), Approvals (`approvals.php`),
Activity (`activity.php`), Your account (`account.php`). The admin pages are admin-only.

**Roles**
- **Admin:** sees and does everything, manages people, approves requests. There must
  always be at least one active admin; you can't turn off or demote yourself.
- **User:** sees only the customers assigned to them (`businesses.assigned_to`) and the
  quotes they made (`quotes.created_by`), plus whatever permissions an admin ticks.

**Permissions** (`user_permissions`, keys from `perm_list()`):

| Key | What it allows | New users |
|---|---|---|
| `customers.edit` | Add customers (assigned to themselves), edit their customers, contacts, history | on |
| `requests.view` | See website requests; converting one assigns the customer to them | off |
| `quotes.create` | Build and edit their quotes | on |
| `quotes.send` | Email quotes, download PDFs, share the customer link | on |
| `quotes.costs` | See cost, margin and profit (otherwise never sent to their browser) | off |
| `pricing.free` | Skip the approval queue for pricebook and pricing changes | off |

**Inbox:** `user_mailboxes` lists which mailboxes each user may open; admins see all.
Quotes always go out from `email.quote_from`, whoever sends them.

**Seats:** `seats` in the client file caps how many people can be active (0 = no limit).

**Approvals** (`approvals` table, `quote_pricing_changes()` / `quote_after_save()`):
- A user adding or changing a pricebook item creates a `catalog_add` / `catalog_edit`
  request instead of changing the Catalog. Approving applies it.
- When a user saves a quote, every line is compared with the pricebook (same title,
  same price, same billing period). Any difference, or a line not in the pricebook,
  sets `quotes.approval_status = 'pending'` with a hash of those lines and opens a
  `quote_pricing` request. Until it's approved the quote can't be emailed, downloaded
  or accepted online. Saving again with the same lines keeps the decision; changing
  them asks again. Admins and `pricing.free` users skip all of this.
- Admins with an email get notified of new requests; the requester is emailed the decision.

**Checking access in a page:** `require_admin()`, `require_perm('quotes.send')`,
`can_see_business($row)`, `can_see_quote($row)`, and `biz_scope()` / `quote_scope()`
for list queries. `log_activity()` records anything worth seeing later.

## 6. Client config: one file per client

The code is identical for every client. Two things change:

**`hq/app/clients/<slug>.php`** (in git, no secrets). Everything about the client:

| Key | What it controls |
|---|---|
| `name`, `portal` | Top bar, page titles, login ("Sunny Side Bakery HQ"); quote header; signature line |
| `monogram`, `logo` | Favicon + login tile; optional logo image in the top bar and login |
| `tagline`, `location`, `domain` | Quote header line; email `EHLO` |
| `site_origin` | The website allowed to post leads to `api/submit.php` |
| `colors.accent` / `accent_dark` | Buttons, links, active nav (light / dark mode) |
| `colors.deep` / `tint` | Quote and PDF headings, total boxes, section bands |
| `colors.highlight` | The portal word in the dark top bar |
| `products.customers` / `quotes` / `inbox` | Which products are on |
| `email.*` | Contact address printed on quotes, lead notifications, sending + quote mailbox, SMTP/IMAP hosts, Inbox mailboxes |
| `quotes.tax_rate`, `tax_label` | Default tax on a new quote (each quote can change it) |
| `quotes.first_number`, `valid_days`, `margin`, `notes` | New-quote defaults |
| `quotes.thank_you`, `file_prefix`, `email_subject`, `email_body` | Quote wording; `{name}` and `{number}` are filled in |
| `quotes.terms` | Terms & Conditions page, as `[heading, text]` pairs |
| `quotes.accept_page` | The page on the client's website where customers view and accept quotes (`?t=` is added). Blank turns online acceptance off |
| `quotes.next_steps`, `quotes.payment` | The NEXT STEPS section on every quote |
| `logo_file` | PNG/JPG (inside `hq/app`) printed on quotes and PDFs |
| `timezone` | Time zone for every date HQ shows or saves |
| `seats` | Most people who can be active at once (Ops Customers is per seat); 0 = no limit |
| `catalog` | Starting pricebook, used once to fill an empty Catalog |

Read values with `client('quotes.tax_rate')`. Never hard-code a client's name,
domain, color or email anywhere else.

**`.env`** (never in git). Secrets and infrastructure only: `CLIENT`, `DB_*`,
`SMTP_PASS`, `APP_SECRET`. In Docker these come from `docker-compose.yml`; on
SiteGround they're read from `~/www/<site>/.env`, one folder above the web root.

**Rules**
- **Logo:** HQ's security policy only allows images from HQ itself. Put the logo
  in `public/brand/<slug>.png` and set `'logo' => 'brand/<slug>.png'`
  (or use a `data:` URI). A link to another site will not load.
- **Colors:** `deep` must be dark enough for white text; `tint` must be light
  enough for dark text. Check both on a real PDF.
- **Terms:** use the client's own terms. Don't copy Modern Ops' terms into a
  client file.
- **Catalog:** changes after first run happen on the Catalog page, not in the file.

---

## 7. Empty, loading and error states

Every list and every card that can be empty needs all three. This is what the
code does today; new sections should match.

**Empty**
- `<div class="empty">` with one plain sentence that says what will appear and,
  if there's an action, how to start. Examples in the code:
  - "No quotes yet. Click **New quote** to build your first." (`quotes.php`)
  - "No requests yet. When someone submits your website form, it shows up here." (`index.php`)
  - Filtered lists say what was filtered: "No businesses match "smith"." (`customers.php`)
- Inside a card section use the smaller form: `class="empty" style="padding:1rem"`
  ("No contacts yet.", "No contact history yet.").

**Loading**
- Pages are built on the server, so there are no spinners. A page is either
  ready or still loading in the browser.
- The slow parts: Inbox (talks to the mail server) and sending a quote. These
  pages just take a moment. The Inbox empty state covers both cases: "Inbox is
  empty, or still loading."
- Anything that calls another site must time out (demo list: 3 seconds,
  cached 10 minutes in `_sites.php`) and the page must still work without it.
- In the quote editor, totals update as you type; there is nothing to wait for.

**Errors**
- **Form problems:** set `$err`, re-show the form with what they typed, and show
  `flash('err', $err)` at the top. Say what to fix ("Please choose a business.").
- **Success:** redirect with `&saved=1` (or `&sent=1`, `&deleted=1`) and show
  `flash('ok', ...)` on the next page.
- **Not found:** `http_response_code(404)`, normal page chrome, one card
  ("Quote not found."), and a way back.
- **Not part of the plan:** `require_product()` shows "Ops Quotes isn't part of
  this account." with a link to the dashboard.
- **Outside services:** catch the exception and tell the user what probably
  happened. Inbox: "Could not connect to info@... Every mailbox here has to use the
  same password (SMTP_PASS)." Quote email: "Could not send the email. Check SMTP
  settings, or download the PDF and send it manually."
- **API (`api/submit.php`):** JSON `{"ok":false,"error":"..."}` with a real status
  code (422 bad input, 404 product off, 500 save failed).
- Never show a PHP error or a stack trace to a user.

---

## 8. New-client setup (SiteGround)

Each client gets their own HQ at a subdomain (e.g. `hq.clientdomain.com`) on our
SiteGround account, with its own database. Code comes from this repo through the
**Deploy Ops Essentials** GitHub Action.

**Before you start, have:** the client's name, logo file, two brand colors,
domain, tax rate, their quote terms, which products they bought, their mailbox
addresses and the mailbox password.

1. **Config file.** Copy `hq/app/clients/_template.php` to
   `hq/app/clients/<slug>.php` and fill in every value. Set `products` to what
   they bought. Add the logo as `hq/app/public/brand/<slug>.png` if they have one.
2. **Commit** both files on a branch, review, merge to `main`.
3. **SiteGround site.** In Site Tools, add the subdomain `hq.clientdomain.com`
   (or a new site), set PHP to 8.2, and turn on the free SSL certificate.
   The folder will be `~/www/hq.clientdomain.com/`.
4. **Database.** Site Tools → MySQL: create a database and a user with all
   privileges on it. Save the names and password in the client's password entry.
5. **`.env` on the server.** Over SSH, create `~/www/hq.clientdomain.com/.env`
   (outside `public_html`), permissions `600`:
   ```
   CLIENT=<slug>
   DB_HOST=localhost
   DB_NAME=<database>
   DB_USER=<db user>
   DB_PASS=<db password>
   SMTP_PASS=<mailbox password>
   APP_SECRET=<long random string>
   ```
6. **Deploy.** GitHub → Actions → **Deploy Ops Essentials (client HQ) to
   SiteGround** → Run workflow on `main`, client = `<slug>`, site =
   `hq.clientdomain.com`. It installs `vendor/`, uploads `public/` to
   `public_html/`, uploads `lib/`, `vendor/` and only this client's config file
   next to it, and warns if `.env` is missing. (A manual workflow only shows in
   the Actions list once the file is on `main`.)
7. **First login.** Open `https://hq.clientdomain.com/`. The setup page creates
   the tables and the client's login. Have the client choose the password, or
   send it through a password manager, never by email.
8. **Website form** (Ops Customers only). Point the client's contact form at
   `https://hq.clientdomain.com/api/submit.php`, from the same address as
   `site_origin`. Send a test lead and check it shows under Requests and the
   notification arrives.
9. **Inbox** (Ops Inbox only). Open Inbox and check every mailbox in
   `email.accounts` loads.
10. **Quotes** (Ops Quotes only). Build a test quote, download the PDF, email it to
    yourself, then delete it.
    If they want online acceptance, copy `quote/index.html` from this repo to their website,
    change `API` and `CONTACT` at the top of its script, and set `quotes.accept_page` to its address.
    Accept a test quote end to end.
11. **Backups.** Confirm SiteGround daily backups are on for the site.
12. **Hand over:** login address, username, how to reset the password through us.

To update a client later: merge to `main`, then run the same workflow again.
Migrations run on their next login.

---

## 9. Pre-ship checklist

Run through this before any change goes to our copy or a client's.

**Test first**
- [ ] Built and tried on a throwaway copy with a copy of the real database,
      never on the live one.
- [ ] Database backed up before deploying anything that migrates.
- [ ] Logged in and opened every page in the nav: no errors, no PHP warnings.
- [ ] Tried it with the products switched off: pages for those products
      show "isn't part of this account", and they're gone from the nav and dashboard.

**People**
- [ ] Tried it as a user without each permission: pages refuse, lists only show their own records.
- [ ] Anything that changes prices or the pricebook still goes through approvals for users.

**Client values**
- [ ] No client name, domain, color, email or price typed into code. Search the diff
      for `modern-ops`, `Modern Ops` and `#` colors outside `clients/`.
- [ ] New settings that differ per client are in `_template.php` with a comment.

**Pages**
- [ ] Empty, loading and error states from section 7 are all there.
- [ ] Works at phone width (375px) and in dark mode.
- [ ] Every form has `csrf_field()`; every handler calls `check_csrf()`.
- [ ] All output escaped with `e()`; all SQL uses prepared statements.
- [ ] Destructive buttons ask first.

**Quotes**
- [ ] PDF and the on-screen quote match (headings, totals, sections, order).
- [ ] Cost, margin and profit appear nowhere on the PDF, the email or the customer view.
- [ ] Totals checked by hand on one quote with one-time, monthly and annual lines.

**Copy**
- [ ] Product names spelled exactly (section 3).
- [ ] Plain sentences, no filler (see the Ops Essentials style guide).

**After deploy**
- [ ] Opened the live site, logged in, checked the changed pages.
- [ ] Schema version in `settings` matches `SCHEMA_VERSION`.
