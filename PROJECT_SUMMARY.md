# Modern Ops — Build Summary

Repo: github.com/weinsteinhomelab/modernops
Branch: `claude/focused-planck-k0grn8`

## 1. Public website (modern-ops.net, hosted on SiteGround)
- Added a 5th service card ("AI & Automation Setup").
- Added tasteful scroll/hero animations (IntersectionObserver, reduced-motion aware).
- Added an inline SVG "MO" favicon.
- Set up automatic deploys: push to GitHub `main` → GitHub Actions → rsync to SiteGround
  over SSH (the key had a passphrase; the workflow unlocks it via ssh-agent).
- Rewired the contact form to send leads to the CRM (`hq.kobylab.dev/api/submit.php`),
  with Formspree as an automatic fallback if the home server is offline.
- Added a private `/login` gateway page (noindex) that forwards to the dashboard.

## 2. Self-hosted CRM — "Modern Ops HQ" (ZimaOS home server, via Docker)
- Data lives entirely on the home server (MariaDB in a container) + nightly backups.
- Reached securely via a Cloudflare Tunnel (`hq.kobylab.dev`) behind Cloudflare Access
  (email-code login) — only `peter.weinstein00@gmail.com` can reach it.
- Single admin login with brute-force throttling, CSRF, security headers, bcrypt.

**Features**
- Dashboard with live counts.
- Requests: website quote/service leads flow in automatically; convert to a customer.
- Customers = Businesses (by name): vertical, main number/email, full address, notes,
  multiple Contacts (people), and a Touch-points contact log (date + type).
- Catalog: reusable saved line items (pre-seeded from the quote template).
- Quotes: builder with saved/custom line items, one-time vs monthly, per-quote tax,
  Scope of Work, and a branded PDF (matches the .docx template incl. Terms & signature
  page). Emailed to the customer from `quote@modern-ops.net`.
- Email tab: read + send across `peter@` / `info@` / `service@` / `quote@`
  (IMAP via webklex/php-imap; sending via SMTP).

## 3. Email / sending
- Lead notifications and replies from `info@`; quotes from `quote@` (shared mailbox password).
- All sending via authenticated SMTP (`mail.modern-ops.net:465` SSL).

## 4. Other
- `STYLE_GUIDE.md` added (brand, colors, theming, components, conventions).
- Dark/light theme toggle across the dashboard; mobile-responsive throughout.

## Still optional / later
- Rotate the mailbox password + SSH deploy key (both passed through chat during setup).
- Move `modern-ops.net` DNS to Cloudflare if you later want the dashboard at a
  `modern-ops.net` address instead of `hq.kobylab.dev`.
- Copy DB backups off the box periodically (`hq/backup`).
