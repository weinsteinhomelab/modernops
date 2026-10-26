# Client previews (`/demos/`)

Demo websites for prospects, each behind its own password. **The demo sites are not in this repository**
(it's public). They live on the SiteGround server next to `public_html`:

```
~/www/modern-ops.net/demos-private/
  demo-admin.php          add a demo / new password / list (run over SSH)
  <slug>/site/            the demo's files (index.html, img/, …)
  <slug>/password.hash    that company's password (hashed)
  <slug>/name.txt         company name for the sign-in page
  _secret, _attempts/, _views.log   created by the gate
```

`demos/index.php` (this folder) is the gate. Every request under `/demos/` goes through it:

- `https://modern-ops.net/demos/` and unknown pages → "nothing here" (no list of demos).
- `https://modern-ops.net/demos/<slug>/` → sign-in page for that company. The right password sets a
  7-day cookie for that demo only.
- 5 wrong passwords from one IP address → that address is locked out for 1 hour (all demos).
- Every page: `noindex` (header + meta), no framing, no caching, a tiled "Made by Modern Ops · not for
  production use" watermark with a viewer code (traceable in `_views.log`), right-click/copy/save/print
  blocked, the watermark comes back if removed, and a copy saved to disk shows only a notice.

## Add a demo

1. Pick an unguessable slug: `<business>-<6 random letters/digits>`.
2. Over SSH: `cd ~/www/modern-ops.net/demos-private && php demo-admin.php add <slug> "<Company>"` →
   prints the password (give it only to that company).
3. Upload the site into `<slug>/site/` (scp/rsync/tar over SSH).
4. Link: `https://modern-ops.net/demos/<slug>/`. Record it in HQ under the customer's Websites.

New password: `php demo-admin.php password <slug>`. Remove a demo: delete its folder.
