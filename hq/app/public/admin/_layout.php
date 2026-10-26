<?php
/** Shared page chrome: Ops Essentials sidebar (bottom tabs + drawer on phones), light/dark. */
require_once __DIR__ . '/_brand.php';

/** Outline icon (24px grid, stroke = currentColor). */
function oe_icon(string $name): string {
    static $p = [
        'home'    => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.4 3.2-5.5 6.5-5.5s5.9 2.1 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.8c2 .7 3.2 2.5 3.5 5.2"/>',
        'inbox2'  => '<path d="M4 4h16v16H4zM4 13h4l1.5 3h5l1.5-3h4"/>',
        'quote'   => '<path d="M6 2.5h9l4 4V21a.5.5 0 0 1-.5.5h-12A.5.5 0 0 1 6 21z"/><path d="M14.5 2.5v4.5H19M9 12h7M9 15.5h7M9 8.5h3"/>',
        'tag'     => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'mail'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 7 8.5-7"/>',
        'globe'   => '<circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19M12 2.5c2.5 2.6 3.8 5.8 3.8 9.5s-1.3 6.9-3.8 9.5c-2.5-2.6-3.8-5.8-3.8-9.5s1.3-6.9 3.8-9.5z"/>',
        'shield'  => '<path d="M12 2.5 4 5.5v6c0 5 3.4 8.8 8 10 4.6-1.2 8-5 8-10v-6z"/><path d="m8.8 12 2.2 2.2 4.2-4.4"/>',
        'team'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.7-4 3.8-6.5 8-6.5s7.3 2.5 8 6.5"/>',
        'pulse'   => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'gear'    => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
        'moon'    => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
        'logout'  => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 16l-4-4 4-4M6 12h10"/>',
        'menu'    => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'check'   => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'clock'   => '<circle cx="12" cy="12" r="9.5"/><path d="M12 7v5l3.5 2"/>',
        'phone'   => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
        'pin'     => '<path d="M12 21.5s-7-6.2-7-11.5a7 7 0 0 1 14 0c0 5.3-7 11.5-7 11.5z"/><circle cx="12" cy="10" r="2.5"/>',
        'send'    => '<path d="M21.5 2.5 10.5 13.5M21.5 2.5l-7 19-4-8-8-4z"/>',
        'eye'     => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'note'    => '<path d="M4 4h16v12l-4 4H4z"/><path d="M16 20v-4h4M8 9h8M8 13h5"/>',
        'file'    => '<path d="M14 2.5H6a.5.5 0 0 0-.5.5v18a.5.5 0 0 0 .5.5h12a.5.5 0 0 0 .5-.5V7z"/><path d="M14 2.5V7h4.5"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'edit'    => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'x'       => '<path d="M6 6l12 12M18 6 6 18"/>',
        'pen'     => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

/** The OpsE mark: green tile, "Ops" in black, "E" in white. */
function oe_mark(string $cls = 'mark'): string {
    return '<span class="' . $cls . '" aria-hidden="true"><span class="mk-ops">Ops</span>E</span>';
}

function layout_header(string $title, string $active = ''): void {
    $who = me();   // null on setup/login pages
    $count = function (string $sql): int {
        try { return (int) db()->query($sql)->fetchColumn(); } catch (Exception $ex) { return 0; }
    };
    // Nav follows the products this client has. Each item: [href, label, icon, count, hot].
    $groups = [['', [['index.php', 'Dashboard', 'home', 0, false]]]];
    $cust = [];
    if (product_on('customers') || product_on('quotes')) $cust[] = ['customers.php', 'Customers', 'users', 0, false];
    if (product_on('customers') && (!$who || can('requests.view'))) {
        $cust[] = ['requests.php', 'Requests', 'inbox2', $who ? $count("SELECT COUNT(*) FROM requests WHERE status='new'") : 0, true];
    }
    if ($cust) $groups[] = [product_on('customers') ? product_name('customers') : 'Customers', $cust];
    if (product_on('quotes')) {
        $groups[] = [product_name('quotes'), [['quotes.php', 'Quotes', 'quote', 0, false], ['catalog.php', 'Catalog', 'tag', 0, false]]];
    }
    $more = [];
    if (product_on('inbox') && (!$who || my_mailboxes())) $more[] = ['email.php', 'Inbox', 'mail', 0, false];
    if (client('previews') && (product_on('customers') || product_on('quotes'))) $more[] = ['previews.php', 'Previews', 'globe', 0, false];
    if ($more) $groups[] = [$more[0][0] === 'email.php' ? product_name('inbox') : '', $more];
    if ($who && is_admin()) {
        $groups[] = ['Admin', [
            ['approvals.php', 'Approvals', 'shield', pending_approvals(), true],
            ['users.php', 'People', 'team', 0, false],
            ['activity.php', 'Activity', 'pulse', 0, false],
        ]];
    }
    $mono = (string) client('monogram', 'HQ');
    $uname = $who ? user_label($who) : current_username();
    $initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($uname)) ?: [], 0, 2)))) ?: '?';
    $navItem = function (array $it) use ($active): string {
        [$href, $label, $icon, $n, $hot] = $it;
        return '<a class="nav' . ($active === $href ? ' on' : '') . '" href="' . e($href) . '">' . oe_icon($icon) . '<span>' . e($label) . '</span>'
             . ($n ? '<span class="ct' . ($hot ? ' hot' : '') . '">' . (int) $n . '</span>' : '') . '</a>';
    };
    // Phone tab bar: the four everyday screens + More (opens the sidebar).
    $tabs = [['index.php', 'Home', 'home']];
    if (product_on('customers') || product_on('quotes')) $tabs[] = ['customers.php', 'Customers', 'users'];
    if (product_on('quotes')) $tabs[] = ['quotes.php', 'Quotes', 'quote'];
    if (product_on('inbox') && (!$who || my_mailboxes())) $tabs[] = ['email.php', 'Inbox', 'mail'];
    $tabActive = in_array($active, array_column($tabs, 0), true);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Ops Essentials</title>
<link rel="icon" href="<?= e(brand_favicon()) ?>">
<link rel="preload" href="fonts/inter.woff2" as="font" type="font/woff2" crossorigin>
<style>
  @font-face{font-family:'Inter';font-style:normal;font-weight:400 700;font-display:swap;src:url(fonts/inter.woff2) format('woff2')}
  @font-face{font-family:'Inter Tight';font-style:normal;font-weight:600 800;font-display:swap;src:url(fonts/inter-tight.woff2) format('woff2')}
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{
    --bg:#f3f4f1;--surface:#fff;--surface-2:#f8f9f6;--text:#16201b;--text-2:#4d5a53;--muted:#6f7c76;--border:#e3e6e0;--border-2:#d5dad3;
    --accent:#0f6e52;--accent-text:#fff;--tint:#e3f1ea;--field:#fff;--field-border:#d5dad3;
    --side:#0f1a16;--side-2:#18261f;--side-on:#1f3329;--side-text:#b9c6bf;--side-mute:#6f8279;--side-line:#1f2b26;
    --blue:#2459c9;--blue-soft:#e6edfb;--amber:#a86400;--amber-soft:#fdf1dc;--red:#b42828;--red-soft:#fbe6e4;--gray-soft:#eef0ec;--violet:#6a45c2;--violet-soft:#efe9fb;
    --shadow:0 1px 2px rgba(16,26,22,.05),0 1px 1px rgba(16,26,22,.03);--shadow-lg:0 12px 32px -12px rgba(16,26,22,.25);
    /* old names, kept so page styles keep working */
    --topbar:var(--side);--topbar-text:#fff;--topbar-dim:var(--side-text)
  }
  :root[data-theme="dark"]{
    --bg:#0e1311;--surface:#161d1a;--surface-2:#1b2420;--text:#e8eeea;--text-2:#b3c0b9;--muted:#8a9993;--border:#26312c;--border-2:#33413a;
    --accent:#3cbf8f;--accent-text:#04130d;--tint:#173a2d;--field:#121916;--field-border:#33413a;
    --side:#0a0f0d;--side-2:#131b17;--side-on:#1a2b23;--side-line:#1a2420;
    --blue:#7da2f0;--blue-soft:#1b2a45;--amber:#e8a94a;--amber-soft:#3a2a10;--red:#f08a80;--red-soft:#3d1b19;--gray-soft:#222b27;--violet:#b59bf0;--violet-soft:#2a2240
  }
  @media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
    --bg:#0e1311;--surface:#161d1a;--surface-2:#1b2420;--text:#e8eeea;--text-2:#b3c0b9;--muted:#8a9993;--border:#26312c;--border-2:#33413a;
    --accent:#3cbf8f;--accent-text:#04130d;--tint:#173a2d;--field:#121916;--field-border:#33413a;
    --side:#0a0f0d;--side-2:#131b17;--side-on:#1a2b23;--side-line:#1a2420;
    --blue:#7da2f0;--blue-soft:#1b2a45;--amber:#e8a94a;--amber-soft:#3a2a10;--red:#f08a80;--red-soft:#3d1b19;--gray-soft:#222b27;--violet:#b59bf0;--violet-soft:#2a2240
  }}
  body{font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);font-size:14.5px;line-height:1.55;-webkit-font-smoothing:antialiased}
  a{color:var(--accent);text-decoration:none}
  a:hover{text-decoration:underline}
  svg.i{width:18px;height:18px;flex:none;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}

  /* ---- shell ---- */
  .shell{display:grid;grid-template-columns:244px minmax(0,1fr);min-height:100vh;background:linear-gradient(90deg,var(--side) 244px,var(--bg) 244px)}
  .side{background:var(--side);color:var(--side-text);padding:18px 12px 14px;display:flex;flex-direction:column;gap:2px;position:sticky;top:0;height:100vh;overflow-y:auto;z-index:60}
  .side a{text-decoration:none}
  .brand{display:flex;align-items:center;gap:10px;padding:2px 8px 16px}
  .mark{height:30px;min-width:30px;padding:0 7px;border-radius:8px;background:linear-gradient(140deg,#2fb383,#0f6e52);display:inline-flex;align-items:center;justify-content:center;color:#fff;font:800 12.5px/1 'Inter Tight',Inter,sans-serif;letter-spacing:-.01em;flex:none}
  .mk-ops{color:#000}
  .brand b{display:block;color:#fff;font:700 15.5px/1.15 'Inter Tight',Inter,sans-serif;letter-spacing:-.01em}
  .brand small{display:block;color:var(--side-mute);font-size:11.5px;font-weight:500;margin-top:2px}
  .ws{display:flex;align-items:center;gap:10px;margin:0 2px 10px;padding:9px 10px;border-radius:10px;background:var(--side-2);color:#e4ece8;font-size:13.5px;font-weight:600}
  .ws .av{width:24px;height:24px;border-radius:6px;background:#2e4a3e;color:#cfe9dc;font-size:10.5px;display:grid;place-items:center;font-weight:700;flex:none}
  .grp{font-size:10.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--side-mute);padding:14px 10px 6px}
  .nav{display:flex;align-items:center;gap:11px;padding:8px 10px;border-radius:8px;font-size:14px;font-weight:500;color:var(--side-text);background:none;border:0;width:100%;cursor:pointer;font-family:inherit;text-align:left}
  .nav:hover{background:var(--side-2);color:#fff;text-decoration:none}
  .nav.on{background:var(--side-on);color:#fff}
  .nav.on svg.i{color:#5fd3a6}
  .nav .ct{margin-left:auto;font-size:11.5px;font-weight:700;background:#2b4237;color:#bfeed8;border-radius:999px;padding:1px 8px}
  .nav .ct.hot{background:#2fb383;color:#04130d}
  .side .bottom{margin-top:auto;border-top:1px solid var(--side-line);padding-top:10px;display:flex;flex-direction:column;gap:2px}
  .me{display:flex;align-items:center;gap:10px;padding:10px;font-size:13.5px;color:#e4ece8}
  .me .av{width:30px;height:30px;border-radius:50%;background:#e3f1ea;color:#0f6e52;display:grid;place-items:center;font-weight:700;font-size:12px;flex:none}
  .me small{display:block;color:var(--side-mute);font-size:11.5px}
  .me .out{margin-left:auto;color:var(--side-mute);display:grid;place-items:center;width:32px;height:32px;border-radius:8px}
  .me .out:hover{background:var(--side-2);color:#fff}
  .side .close{display:none}
  .main{min-width:0}
  .wrap{padding:28px 32px 56px;max-width:1500px}

  /* ---- page parts ---- */
  .grid-auto{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:0 1rem}
  .grid-auto .span-all{grid-column:1/-1}
  .grid-auto .span-2{grid-column:span 2}
  h1.page{font:700 26px/1.2 'Inter Tight',Inter,sans-serif;letter-spacing:-.02em;margin-bottom:.2rem}
  .sub{color:var(--text-2);margin-bottom:1.4rem;font-size:14.5px}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);padding:1.15rem 1.3rem;margin:0 0 1.15rem}
  .card > strong:first-child{display:block;font-size:15px;font-weight:650;margin-bottom:.6rem}
  .stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:1.25rem}
  .stat{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);padding:1rem 1.15rem;display:block}
  .stat:hover{text-decoration:none;border-color:var(--border-2)}
  .stat .n{font:700 28px/1.1 'Inter Tight',Inter,sans-serif;letter-spacing:-.02em;color:var(--text);font-variant-numeric:tabular-nums}
  .stat .l{font-size:13px;color:var(--text-2);font-weight:500;margin-top:.35rem}
  .stat .s{font-size:12.5px;color:var(--muted);margin-top:.15rem}
  .notice{border:1px solid color-mix(in srgb,var(--amber) 30%,transparent);background:var(--amber-soft);border-radius:10px;padding:.9rem 1.1rem;margin:0 0 1.15rem}
  .notice.err{border-color:color-mix(in srgb,var(--red) 30%,transparent);background:var(--red-soft)}
  .paper{background:#fff;color:#1a1a1a;max-width:820px;margin:1.4rem auto 0;padding:2.2rem 2.4rem;border-radius:6px;box-shadow:var(--shadow-lg)}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;font-size:12px;font-weight:600;color:var(--muted);padding:.6rem .7rem;border-bottom:1px solid var(--border);background:var(--surface-2)}
  td{padding:.75rem .7rem;border-bottom:1px solid var(--border);vertical-align:top}
  tr:last-child td{border-bottom:none}
  .card table{margin:0 -.2rem}
  .badge{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:.12rem .6rem;border-radius:999px;white-space:nowrap;background:var(--gray-soft);color:var(--text-2)}
  .badge::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
  .badge.new,.badge.sent,.badge.quoted{background:var(--blue-soft);color:var(--blue)}
  .badge.contacted,.badge.pending{background:var(--amber-soft);color:var(--amber)}
  .badge.won,.badge.accepted,.badge.approved{background:var(--tint);color:var(--accent)}
  .badge.closed,.badge.draft{background:var(--gray-soft);color:var(--text-2)}
  .badge.declined,.badge.rejected{background:var(--red-soft);color:var(--red)}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;background:var(--accent);color:var(--accent-text);min-height:38px;padding:.45rem 1rem;border-radius:9px;font-weight:600;font-size:14px;border:1px solid transparent;cursor:pointer;font-family:inherit;line-height:1.2;white-space:nowrap}
  .btn:hover{filter:brightness(1.06);text-decoration:none}
  .btn.secondary{background:var(--surface);color:var(--text);border-color:var(--border-2)}
  .btn.secondary:hover{background:var(--surface-2);filter:none}
  .btn.small{min-height:32px;padding:.3rem .75rem;font-size:13px;border-radius:8px}
  .btn.danger{background:var(--surface);color:var(--red);border-color:color-mix(in srgb,var(--red) 30%,transparent)}
  input,select,textarea{font-family:inherit;font-size:14px;width:100%;padding:.55rem .7rem;min-height:38px;border:1px solid var(--field-border);border-radius:8px;background:var(--field);color:var(--text);outline:none}
  input[type=checkbox],input[type=radio]{width:auto;min-height:0;accent-color:var(--accent)}
  input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent)}
  label{display:block;font-size:12.5px;font-weight:600;color:var(--text-2);margin:.9rem 0 .3rem}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 1rem}
  .row-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
  .flash{padding:.75rem 1rem;border-radius:10px;margin-bottom:1.15rem;font-size:14px}
  .flash.ok{background:var(--tint);color:var(--accent);border:1px solid color-mix(in srgb,var(--accent) 25%,transparent)}
  .flash.err{background:var(--red-soft);color:var(--red);border:1px solid color-mix(in srgb,var(--red) 25%,transparent)}
  .muted{color:var(--muted)}
  .empty{text-align:center;color:var(--muted);padding:2.5rem 1rem}
  .navcount{display:inline-block;min-width:1.2em;padding:0 .35em;border-radius:9px;background:#2fb383;color:#04130d;font-size:.72rem;font-weight:700;line-height:1.5;text-align:center}

  /* ---- phones: bottom tabs, sidebar becomes a drawer ---- */
  .tabbar,.scrim{display:none}
  @media(max-width:860px){
    .shell{grid-template-columns:minmax(0,1fr);background:var(--bg)}
    .side{position:fixed;left:0;top:0;bottom:0;width:284px;max-width:86vw;height:auto;transform:translateX(-102%);transition:transform .22s ease;box-shadow:var(--shadow-lg)}
    body.drawer .side{transform:none}
    body.drawer .scrim{display:block;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:55}
    .side .close{display:grid;place-items:center;margin-left:auto;width:34px;height:34px;border-radius:8px;color:var(--side-text);background:var(--side-2);border:0;cursor:pointer}
    .wrap{padding:20px 16px calc(96px + env(safe-area-inset-bottom))}
    .tabbar{display:grid;grid-auto-flow:column;grid-auto-columns:1fr;position:fixed;left:0;right:0;bottom:0;z-index:50;background:var(--surface);border-top:1px solid var(--border);padding:6px 4px calc(6px + env(safe-area-inset-bottom))}
    .tabbar a,.tabbar button{display:flex;flex-direction:column;align-items:center;gap:3px;font:600 10.5px Inter,sans-serif;color:var(--muted);padding:4px 0;background:none;border:0;cursor:pointer;text-decoration:none}
    .tabbar .on{color:var(--accent)}
    .tabbar svg.i{width:22px;height:22px}
    h1.page{font-size:23px}
  }
  @media(max-width:640px){
    .grid2{grid-template-columns:1fr}.grid-auto .span-2{grid-column:1/-1}.card{padding:1rem}
    .wrap > div[style*="space-between"] > .btn, .wrap > div[style*="space-between"] > .row-actions{margin-bottom:1.1rem}
    /* list tables become compact rows on phones: first cell on top, the rest flowing after it */
    .card > table, .card > table > tbody{display:block;width:100%}
    .card > table{margin:0}
    .card > table > thead{display:none}
    .card > table > tbody > tr{display:flex;flex-wrap:wrap;align-items:center;gap:.15rem .8rem;padding:.7rem 0;border-bottom:1px solid var(--border)}
    .card > table > tbody > tr:last-child{border-bottom:0}
    .card > table > tbody > tr > td{display:block;border:0;padding:0;font-size:13.5px;overflow-wrap:anywhere}
    .card > table > tbody > tr > td:empty{display:none}
    .card > table > tbody > tr > td:first-child{flex-basis:100%;font-size:14.5px;font-weight:600}
    .card > table > tbody > tr > td:last-child:not(:first-child){margin-left:auto}
  }
</style>
</head>
<body>
<div class="shell">
<aside class="side" id="side" aria-label="Main menu">
  <div class="brand"><?= oe_mark() ?><div><b>Ops Essentials</b><small>by Modern Ops</small></div><button class="close" type="button" id="sideClose" aria-label="Close menu"><?= oe_icon('x') ?></button></div>
  <div class="ws" title="Your business"><span class="av"><?= e($mono) ?></span><?= e(client('name')) ?></div>
  <?php foreach ($groups as [$g, $items]): ?>
    <?php if ($g !== ''): ?><div class="grp"><?= e($g) ?></div><?php endif; ?>
    <?php foreach ($items as $it) echo $navItem($it); ?>
  <?php endforeach; ?>
  <div class="bottom">
    <button class="nav" id="themebtn" type="button"><?= oe_icon('moon') ?><span id="themelbl">Dark mode</span></button>
    <?php if ($who): ?>
    <?= $navItem(['account.php', 'Your account', 'gear', 0, false]) ?>
    <div class="me"><span class="av"><?= e($initials) ?></span><div><?= e($uname) ?><small><?= is_admin() ? 'Admin' : 'User' ?></small></div><a class="out" href="logout.php" title="Log out" aria-label="Log out"><?= oe_icon('logout') ?></a></div>
    <?php endif; ?>
  </div>
</aside>
<div class="scrim" id="scrim"></div>
<div class="main">
<div class="wrap">
<?php
    $GLOBALS['__oe_tabs'] = [$tabs, $active, $tabActive];
}

function layout_footer(): void {
    [$tabs, $active, $tabActive] = $GLOBALS['__oe_tabs'] ?? [[], '', false];
    ?>
</div>
</div>
</div>
<?php if ($tabs): ?>
<nav class="tabbar" aria-label="Quick menu">
  <?php foreach ($tabs as [$href, $label, $icon]): ?>
    <a href="<?= e($href) ?>"<?= $active === $href ? ' class="on"' : '' ?>><?= oe_icon($icon) ?><?= e($label) ?></a>
  <?php endforeach; ?>
  <button type="button" id="moreBtn"<?= $tabActive ? '' : ' class="on"' ?>><?= oe_icon('menu') ?>More</button>
</nav>
<?php endif; ?>
<script>
  (function(){
    var root=document.documentElement, btn=document.getElementById('themebtn'), lbl=document.getElementById('themelbl');
    try{var s=localStorage.getItem('mohq-theme'); if(s) root.setAttribute('data-theme',s);}catch(e){}
    function cur(){return root.getAttribute('data-theme')|| (window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');}
    function paint(){if(lbl) lbl.textContent=cur()==='dark'?'Light mode':'Dark mode';}
    paint();
    if(btn){btn.addEventListener('click',function(){
      var next=cur()==='dark'?'light':'dark';
      root.setAttribute('data-theme',next);
      try{localStorage.setItem('mohq-theme',next);}catch(e){}
      paint();
    });}
    var b=document.body;
    function open(){b.classList.add('drawer');}
    function close(){b.classList.remove('drawer');}
    var m=document.getElementById('moreBtn'); if(m) m.addEventListener('click',open);
    var c=document.getElementById('sideClose'); if(c) c.addEventListener('click',close);
    var sc=document.getElementById('scrim'); if(sc) sc.addEventListener('click',close);
    document.addEventListener('keydown',function(e){if(e.key==='Escape') close();});
  })();
</script>
</body>
</html>
<?php
}

function flash(string $type, string $msg): void {
    echo '<div class="flash ' . e($type) . '">' . e($msg) . '</div>';
}
