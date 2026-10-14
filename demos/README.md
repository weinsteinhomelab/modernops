# Client preview area (`/demos/`)

A private staging area for showing clients their draft website before it goes
live. Everything in this folder is published by the normal deploy pipeline
(the `deploy.yml` workflow rsyncs the repo root to SiteGround), so once it's on
`main` it's reachable at:

```
https://modern-ops.net/demos/            → the preview hub (lists all drafts)
https://modern-ops.net/demos/<client>/   → an individual client's draft site
```

## What's here

- `index.html` — the preview **hub**. A branded landing page that lists every
  client draft with a "View preview" link. Hand clients the hub link, or link
  them straight to their own folder.
- `doggy-spa/` — the first client draft (a dog-grooming shop).

## Adding a new client preview

1. Make a folder: `demos/<client-slug>/` (e.g. `demos/rivera-bakery/`).
2. Put the site in it as `index.html` (plus any `images/`, CSS, etc.).
3. Add a `<meta name="robots" content="noindex, nofollow">` tag in the
   `<head>` so search engines don't index the draft.
4. (Recommended) Paste the slim **preview bar** from `doggy-spa/index.html`
   (the `.preview-bar` block, right after `<body>`) so clients always know
   they're looking at a draft, with a link back to `../`.
5. Add a card for it in `index.html` — copy the Doggy Spa `<article class="card">`
   block and change the name, blurb, and `href`.
6. Commit and push to `main`. It's live under `/demos/` within a minute or two.

## Privacy

These pages are **unlisted and no-indexed**, but not password-protected — anyone
with the link can open them. That's usually fine for client review. If a client
needs real protection, add an `.htaccess` basic-auth rule in their folder
(SiteGround is Apache), e.g.:

```apache
# demos/<client>/.htaccess
AuthType Basic
AuthName "Private preview"
AuthUserFile /home/<user>/www/modern-ops.net/.htpasswd-demos
Require valid-user
```

...and create the `.htpasswd-demos` file with `htpasswd` on the server.

## Going live

When a client approves, move their site to its own real domain/hosting and
**delete their folder from `/demos/`** (and remove its card from `index.html`)
so the draft doesn't linger publicly.

## Note on the deploy pipeline

`deploy.yml` currently excludes `.git*`, `.github`, and `hq` — **not** `demos`.
So `demos/` ships to production as-is. If you ever want previews to stay off
production, add `--exclude='demos'` to the rsync line in the workflow, or host
them somewhere separate.
