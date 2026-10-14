# Modern Ops — System Handoff & Control Guide

Purpose: give the ZimaOS box (and its Claude Code) a complete picture of how
everything fits together, where every credential and config lives, and a
roadmap for consolidating control on the Zima box with Cloudflare for networking.

---

## 1. The big picture

There are **two independent systems** sharing one brand:

| System | What it is | Where it runs | How it's reached |
|---|---|---|---|
| **Public website** | Static marketing site (`index.html`, `login/`) | **SiteGround** shared hosting | `https://modern-ops.net/` |
| **Modern Ops HQ** (CRM) | PHP + MariaDB app in Docker | **ZimaOS box** (`/DATA/modernops/hq`) | `https://hq.kobylab.dev` (Cloudflare Tunnel) |

They are linked by **one data flow**: the website's contact form POSTs leads to
the CRM's API, and the CRM also emails a notification.

```
Visitor ──> modern-ops.net (SiteGround, static)
               │  contact form (fetch)
               ▼
         hq.kobylab.dev/api/submit.php  (Cloudflare Tunnel ──> Zima box :8090)
               │                                   │
               ├─> MariaDB (requests table)        └─> SMTP notify to info@
               ▼
         You log in at hq.kobylab.dev (Cloudflare Access + app login)
```

Fallback: if the Zima box is offline, the form falls back to Formspree so a lead
is never lost.

---

## 2. Components, hosts, and credentials

### A. Public website (SiteGround)
- **Source of truth:** GitHub repo `weinsteinhomelab/modernops`, branch `main`.
- **Files that go live:** everything in the repo root EXCEPT `hq/` and `.github/`
  (the deploy excludes those). Today that's `index.html`, `login/`,
  `modernops_logo.svg`, `thankyou.html`, `config-v1.1.xml`.
- **Deploy pipeline:** push to `main` → GitHub Actions (`.github/workflows/deploy.yml`)
  → `rsync` over SSH to `~/www/modern-ops.net/public_html/` on SiteGround.
  - The SSH key has a passphrase; the workflow unlocks it via `ssh-agent`.
  - **GitHub repo secrets** (Settings → Secrets → Actions): `SSH_HOST`, `SSH_PORT`,
    `SSH_USER`, `SSH_PRIVATE_KEY`, `SSH_PASSPHRASE`.
- **Gotcha:** SiteGround SuperCacher can serve stale HTML — flush cache after deploys.

### B. Modern Ops HQ / CRM (ZimaOS box)
- **Location on box:** `/DATA/modernops` (git clone), app under `hq/`.
- **Runs via:** `hq/docker-compose.yml` — three services:
  - `app` (php:8.2-apache, built from `hq/app/Dockerfile`, serves `hq/app/public`)
  - `db` (mariadb:11, volume `db_data`)
  - `backup` (nightly `mysqldump` into `hq/backup/`)
  - `cloudflared` (the tunnel) lives in `hq/docker-compose.override.yml` (gitignored).
- **Secrets/config:** `hq/.env` (gitignored) — DB passwords, `APP_SECRET`,
  `SMTP_PASS` (shared mailbox password), `TUNNEL_TOKEN`, IMAP/accounts, quote mailbox.
  A copy of DB creds is saved at `/DATA/.secrets/modernops-hq.env`.
- **App login:** single admin (`peter` / password), created at first-run setup;
  password hash in the `admin_users` table. Brute-force throttle + security headers.
- **Database:** self-migrating (`hq/app/public/admin/_migrate.php`) — schema upgrades
  run automatically on login; current `schema_version` = 3.
- **PHP deps:** `webklex/php-imap` via Composer (pinned in `hq/app/composer.lock`),
  installed during the Docker build. Dockerfile also installs `mbstring` + `zip`.

### C. Email (SiteGround mailboxes)
- Mailboxes: `peter@`, `info@`, `service@`, `quote@` — all share ONE password
  (stored once as `SMTP_PASS`).
- **Sending (SMTP):** `mail.modern-ops.net:465` SSL. Lead notifications from `info@`;
  quotes from `quote@`. Code: `hq/app/public/admin/_mail.php`.
- **Reading (IMAP):** `mail.modern-ops.net:993` SSL, via webklex. Code:
  `hq/app/public/admin/_imap.php`. The Email tab reads/sends all four accounts.

### D. Networking / Cloudflare
- `kobylab.dev` is on **Cloudflare** (your account). `hq.kobylab.dev` is a named
  Cloudflare Tunnel → `http://app:80` on the box, protected by **Cloudflare Access**
  (one-time email PIN, allow-list = your email). `/api/*` has a **Bypass** policy so
  the website can POST leads without an Access prompt.
- `modern-ops.net` DNS is still at **SiteGround** (ns1/ns2.siteground.net). This is
  why the dashboard is on `kobylab.dev`, not `hq.modern-ops.net`.

---

## 3. How the main workflows work

- **New lead:** website form → `POST /api/submit.php` → saved to `requests` + email to
  `info@`. Appears under **Requests** in the dashboard; convert to a Business + Contact.
- **Quote:** build in the dashboard (Business + Contact + saved/custom line items +
  tax + scope of work) → generates a branded PDF (FPDF, `_quote_pdf.php`) → emails it
  from `quote@` (`quote_send.php`).
- **Website change:** edit files, push to `main` → auto-deploys to SiteGround.
  (The box has no GitHub login today — see §5.)
- **CRM change:** edit under `hq/`, commit, `git pull` on the box, `docker compose up -d --build`.

---

## 4. What the Zima box controls today
- ✅ The entire CRM (app, database, backups) — fully on the box.
- ✅ The Cloudflare Tunnel + Access for `hq.kobylab.dev`.
- ❌ The public website deploy (that's GitHub Actions → SiteGround; the box has no
  GitHub auth yet).
- ❌ `modern-ops.net` DNS (still at SiteGround).

---

## 5. Roadmap to consolidate control on the Zima box + Cloudflare

Do these in order; each is independent and safe.

### Step 1 — Give the box GitHub control (so it can deploy the website)
- Authenticate git on the box: `gh auth login` (or a deploy token / SSH key for the repo).
- After that the box can `git push` and trigger website deploys itself.

### Step 2 — Move `modern-ops.net` DNS to Cloudflare (enables everything else)
- In Cloudflare: **Add site → modern-ops.net** (Free).
- **Verify imported DNS** — especially the website A/CNAME (SiteGround) and the
  **MX records for email** (so mail keeps working). Add anything missing BEFORE switching.
- Change nameservers at the registrar to Cloudflare's. Zero downtime if records are correct.
- Keeps website on SiteGround and email on SiteGround — Cloudflare just does DNS + edge.

### Step 3 — Put the dashboard on your own domain
- Add a Cloudflare Tunnel public hostname `hq.modern-ops.net` (or `app.modern-ops.net`)
  → `http://app:80`, with Cloudflare Access (same email-PIN policy, keep `/api/*` bypass).
- Update the repo: point `login/index.html` `DASHBOARD_URL` and the website form's
  `HQ_ENDPOINT` to the new hostname, and set `SITE_ORIGIN`/CORS accordingly.

### Step 4 (optional) — Host the website on the box too
- Possible (serve `index.html` via the same tunnel under `modern-ops.net`), but it ties
  the PUBLIC site's uptime to home internet. Recommended to KEEP the marketing site on
  SiteGround (reliable) and only self-host the private dashboard. Revisit only if desired.

### Step 5 — Hygiene
- Rotate the shared mailbox password and the SSH deploy key (both passed through chat
  during setup); update `SMTP_PASS` in `.env` and the `SSH_*` GitHub secrets.
- Copy `hq/backup/*.sql` off the box periodically (another drive / cloud).

---

## 6. Handy commands (on the box)
```bash
# Update & rebuild the CRM
cd /DATA/modernops && git pull && cd hq && docker compose up -d --build

# Logs / status
docker compose ps
docker compose logs -f app
docker compose logs db

# Manual DB backup now
docker compose exec db sh -c 'mysqldump -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > hq/backup/manual-$(date +%F).sql

# Current public tunnel URL (named tunnel = stable)
#   configured in hq/docker-compose.override.yml via TUNNEL_TOKEN
```

## 7. Known gotchas (already handled, keep in mind)
- **IMAP:** no `ext-imap` in the image → webklex with the iconv decoder; SiteGround IMAP
  needs `query()->all()` (explicit search criteria); use the webklex Attribute API
  (`->count()/->first()/->toArray()/->toDate()`), not array access.
- **Composer:** `ext-zip` is required by webklex 5.5 (installed in the Dockerfile).
- **docker-compose:** keep the `cloudflared` service in `docker-compose.override.yml`
  (gitignored) so `git pull` never conflicts on the tracked compose file.
- **SiteGround cache:** flush after website deploys.
- **Repo branch:** all work is on `claude/focused-planck-k0grn8`.
