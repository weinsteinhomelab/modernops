# Modern Ops HQ — self-hosted CRM

A private dashboard (customers, website requests, quotes) that runs entirely on
your own home server via Docker. Your data never leaves your hardware.

## What's here
- `app/` — the PHP dashboard (Dockerfile + `public/` web root)
- `docker-compose.yml` — runs the app + a MariaDB database + nightly backups
- `.env.sample` — copy to `.env` and fill in your settings
- `backup/` — nightly database dumps land here (kept ~14 days)

## Quick start (on the ZimaOS box)
1. `cp .env.sample .env` and edit the values (set strong passwords).
2. `docker compose up -d --build`
3. Open `http://<zima-ip>:8090/` on your LAN → first visit runs a one-time
   setup to create your login.
4. Expose it with a Cloudflare Tunnel (see below) so your website can send
   leads in and you can log in from anywhere.

## Cloudflare Tunnel
Create a tunnel in the Cloudflare Zero Trust dashboard, route a hostname such as
`hq.modern-ops.net` to `http://app:80` (or `http://<zima-ip>:8090`), then either
run `cloudflared` on the box or uncomment the `cloudflared` service in
`docker-compose.yml` and put the tunnel token in `.env`.

## Connecting the website
Once the tunnel URL exists, the Modern Ops website form posts new requests to
`https://<your-tunnel-host>/api/submit.php`, and they appear under **Requests**.

## Backups
`backup/` gets a dated `.sql` dump nightly. Copy these somewhere off the box
periodically (another drive, cloud storage) for real safety.
