<?php
/** Dashboard: what needs you today, the money in play, latest email and activity. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$seeRequests = product_on('customers') && can('requests.view');
$seeInbox = product_on('inbox') && my_mailboxes();

// Latest inbox emails, loaded after the page so a slow mailbox never holds up the dashboard.
if (isset($_GET['panel']) && $_GET['panel'] === 'inbox') {
    require_once __DIR__ . '/_imap.php';
    header('Content-Type: text/html; charset=utf-8');
    if (!$seeInbox || !imap_ready()) exit;
    $accounts = my_mailboxes();
    $rows = [];
    foreach ($accounts as $acct) {
        try { $rows = array_merge($rows, mbox_list($acct, 'inbox')); } catch (\Throwable $e) {}
    }
    usort($rows, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $unread = count(array_filter($rows, fn($r) => empty($r['seen'])));
    echo '<div class="ph"><h2>Inbox' . ($unread ? ' <span class="ct">' . $unread . ' unread</span>' : '') . '</h2><a class="more" href="email.php">Open Inbox</a></div>';
    if (!$rows) { echo '<div class="empty" style="padding:1.5rem">No email yet.</div>'; exit; }
    foreach (array_slice($rows, 0, 5) as $r) {
        $name = $r['from_name'] ?: $r['from_mail'];
        $ini = strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N} ]/u', '', $name) ?: '?', 0, 1));
        $href = 'email.php?' . http_build_query(['a' => array_search($r['account'], $accounts, true), 'f' => $r['folder'], 'uid' => (int) $r['uid']]);
        $ts = (int) $r['ts'];
        $when = !$ts ? '' : (date('Y-m-d', $ts) === date('Y-m-d') ? date('g:i A', $ts) : date('M j', $ts));
        echo '<a class="mailrow' . (empty($r['seen']) ? ' unread' : '') . '" href="' . e($href) . '">'
           . '<span class="av-c" style="--c:' . e(mailbox_color($r['account'])) . '">' . e($ini) . '</span>'
           . '<span class="mid"><b>' . e($name) . '</b><span>' . e($r['subject'] ?: '(no subject)') . '</span></span>'
           . '<time>' . e($when) . '</time></a>';
    }
    exit;
}

$one = function (string $sql, array $args = []) {
    try { $st = db()->prepare($sql); $st->execute($args); return $st->fetchColumn(); } catch (Exception $ex) { return 0; }
};
$all = function (string $sql, array $args = []): array {
    try { $st = db()->prepare($sql); $st->execute($args); return $st->fetchAll(); } catch (Exception $ex) { return []; }
};
// Users only count what they can see: their customers and their quotes.
[$qs, $qa] = quote_scope('q');
// What a quote is worth on the list: its one-time lines, not counting optional add-ons.
$val = "(SELECT COALESCE(SUM(i.quantity*i.unit_price),0) FROM quote_items i WHERE i.quote_id=q.id AND i.billing='one_time' AND i.is_optional=0)";
$monthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));

$stats = [];
if (product_on('quotes')) {
    $openN = (int) $one("SELECT COUNT(*) FROM quotes q WHERE q.status IN ('draft','sent') AND $qs", $qa);
    $openV = (float) $one("SELECT COALESCE(SUM($val),0) FROM quotes q WHERE q.status IN ('draft','sent') AND $qs", $qa);
    $sentN = (int) $one("SELECT COUNT(*) FROM quotes q WHERE q.status='sent' AND $qs", $qa);
    $wonV  = (float) $one("SELECT COALESCE(SUM($val),0) FROM quotes q WHERE q.status='accepted' AND COALESCE(q.accepted_at,q.updated_at) >= ? AND $qs", array_merge([$monthStart], $qa));
    $wonPrev = (float) $one("SELECT COALESCE(SUM($val),0) FROM quotes q WHERE q.status='accepted' AND COALESCE(q.accepted_at,q.updated_at) >= ? AND COALESCE(q.accepted_at,q.updated_at) < ? AND $qs", array_merge([$lastMonthStart, $monthStart], $qa));
    $acc90 = (int) $one("SELECT COUNT(*) FROM quotes q WHERE q.status='accepted' AND q.issue_date >= ? AND $qs", array_merge([date('Y-m-d', strtotime('-90 days'))], $qa));
    $dec90 = (int) $one("SELECT COUNT(*) FROM quotes q WHERE q.status='declined' AND q.issue_date >= ? AND $qs", array_merge([date('Y-m-d', strtotime('-90 days'))], $qa));
    $stats[] = ['Open quotes', money_short($openV), $openN . ' quote' . ($openN === 1 ? '' : 's') . ($sentN ? ' · ' . $sentN . ' waiting on a reply' : ''), 'var(--blue)', 'quotes.php'];
    $trend = $wonPrev > 0 ? round(($wonV - $wonPrev) / $wonPrev * 100) : null;
    $stats[] = ['Won this month', money_short($wonV), $trend === null ? date('F') : (($trend >= 0 ? '↑ ' : '↓ ') . abs($trend) . '% vs ' . date('F', strtotime($lastMonthStart))), 'var(--accent)', 'quotes.php'];
    $stats[] = ['Win rate', ($acc90 + $dec90) ? round($acc90 / ($acc90 + $dec90) * 100) . '%' : '—', ($acc90 + $dec90) ? 'Last 90 days · ' . $acc90 . ' of ' . ($acc90 + $dec90) : 'No answers in the last 90 days', 'var(--violet)', 'quotes.php'];
}
if ($seeRequests) {
    $newReq = (int) $one("SELECT COUNT(*) FROM requests WHERE status='new'");
    $stats[] = ['New requests', (string) $newReq, 'From your website form', 'var(--amber)', 'requests.php'];
} elseif (product_on('customers') || product_on('quotes')) {
    [$bs, $ba] = biz_scope('b');
    $stats[] = [is_admin() ? 'Customers' : 'Your customers', (string) (int) $one("SELECT COUNT(*) FROM businesses b WHERE $bs", $ba), 'Businesses you work with', 'var(--amber)', 'customers.php'];
}

// ---- Needs you: one line per thing to act on ----
$todo = [];
if ($seeRequests) {
    foreach ($all("SELECT * FROM requests WHERE status='new' ORDER BY created_at DESC LIMIT 3") as $r) {
        $todo[] = ['inbox2', 'amber', 'New request from ' . ($r['business'] ?: $r['name']),
            ($r['message'] ? '"' . mb_strimwidth(preg_replace('/\s+/', ' ', $r['message']), 0, 70, '…') . '" · ' : '') . ago($r['created_at']),
            'request.php?id=' . (int) $r['id'], 'Open'];
    }
}
if (product_on('quotes')) {
    if (is_admin()) {
        foreach ($all("SELECT q.id, q.quote_number, b.name, $val AS v FROM quotes q JOIN businesses b ON b.id=q.business_id WHERE q.approval_status='pending' ORDER BY q.updated_at DESC LIMIT 3") as $q) {
            $todo[] = ['shield', 'violet', 'Quote ' . $q['quote_number'] . ' needs your approval', $q['name'] . ' · ' . money($q['v']), 'quote_view.php?id=' . (int) $q['id'], 'Review'];
        }
        $pend = pending_approvals();
        if ($pend) $todo[] = ['shield', 'violet', $pend . ' pricing change' . ($pend === 1 ? '' : 's') . ' waiting for you', 'Pricebook and price changes your team asked for', 'approvals.php', 'Review'];
    }
    $stale = $all("SELECT q.id, q.quote_number, b.name, $val AS v FROM quotes q JOIN businesses b ON b.id=q.business_id WHERE q.status='sent' AND q.issue_date <= ? AND $qs ORDER BY q.issue_date", array_merge([date('Y-m-d', strtotime('-7 days'))], $qa));
    if (count($stale) === 1) {
        $todo[] = ['clock', 'blue', 'Follow up on quote ' . $stale[0]['quote_number'], $stale[0]['name'] . ' · sent over a week ago · ' . money($stale[0]['v']), 'quote_view.php?id=' . (int) $stale[0]['id'], 'Open'];
    } elseif ($stale) {
        $todo[] = ['clock', 'blue', 'Follow up on ' . count($stale) . ' quotes', 'Sent over a week ago with no answer · ' . money(array_sum(array_column($stale, 'v'))) . ' total', 'quotes.php', 'See them'];
    }
    foreach ($all("SELECT q.id, q.quote_number, q.accepted_at, b.name, $val AS v FROM quotes q JOIN businesses b ON b.id=q.business_id WHERE q.status='accepted' AND q.accepted_at >= ? AND $qs ORDER BY q.accepted_at DESC LIMIT 3", array_merge([date('Y-m-d H:i:s', strtotime('-3 days'))], $qa)) as $q) {
        $todo[] = ['check', 'green', $q['name'] . ' accepted quote ' . $q['quote_number'], money($q['v']) . ' · ' . ago($q['accepted_at']), 'quote_view.php?id=' . (int) $q['id'], 'Open'];
    }
}

// ---- Quotes this month ----
$pipe = []; $recentQ = [];
if (product_on('quotes')) {
    foreach (['draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined'] as $k => $label) {
        $pipe[$k] = [$label, (float) $one("SELECT COALESCE(SUM($val),0) FROM quotes q WHERE q.status=? AND q.issue_date >= ? AND $qs", array_merge([$k, $monthStart], $qa))];
    }
    $recentQ = $all("SELECT q.id, q.quote_number, q.status, q.approval_status, q.issue_date, b.name, $val AS v FROM quotes q JOIN businesses b ON b.id=q.business_id WHERE $qs ORDER BY q.updated_at DESC LIMIT 6", $qa);
}
$pipeTotal = array_sum(array_column($pipe, 1));

$recentReq = $seeRequests ? $all("SELECT * FROM requests ORDER BY created_at DESC LIMIT 6") : [];
$acts = is_admin() ? $all("SELECT a.*, COALESCE(NULLIF(u.name,''), u.username) AS person FROM activity a LEFT JOIN admin_users u ON u.id = a.user_id WHERE a.action <> 'login' ORDER BY a.id DESC LIMIT 6") : [];

/** "$18.4k" style for the big numbers. */
function money_short(float $n): string {
    return $n >= 100000 ? '$' . number_format($n / 1000) . 'k' : '$' . number_format($n);
}
/** "2 h ago" / "Oct 9". */
function ago(?string $dt): string {
    if (!$dt) return '';
    $s = time() - strtotime($dt);
    if ($s < 3600) return max(1, (int) floor($s / 60)) . ' min ago';
    if ($s < 86400) return (int) floor($s / 3600) . ' h ago';
    if ($s < 172800) return 'yesterday';
    return date('M j', strtotime($dt));
}

$h = (int) date('G');
$hello = $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', user_label(me() ?? ['username' => current_username()]))[0];

layout_header('Dashboard', 'index.php');
?>
<style>
  .dash-head{display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap;margin-bottom:1.4rem}
  .dash-head .sub{margin:0}
  .dash-head .row-actions .btn svg.i{width:16px;height:16px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.15rem}
  .kpis .stat .l{margin:0;display:flex;align-items:center;gap:7px}
  .kpis .stat .l i{width:8px;height:8px;border-radius:2px;background:var(--sw)}
  .kpis .stat .n{margin-top:.55rem}
  .dash{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(0,1fr);gap:1.15rem;align-items:start}
  .dash .col{display:flex;flex-direction:column;gap:1.15rem;min-width:0}
  .panel{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);overflow:hidden}
  .ph{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid var(--border)}
  .ph h2{font-size:15px;font-weight:650;display:flex;align-items:center;gap:8px}
  .ph .ct{font-size:12px;font-weight:600;color:var(--muted)}
  .ph a.more{font-size:13px;font-weight:600}
  .todo{display:flex;align-items:center;gap:14px;padding:13px 18px;border-bottom:1px solid var(--border)}
  .todo:last-child{border-bottom:0}
  .todo .ic{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;flex:none}
  .todo .ic.amber{background:var(--amber-soft);color:var(--amber)} .todo .ic.violet{background:var(--violet-soft);color:var(--violet)}
  .todo .ic.blue{background:var(--blue-soft);color:var(--blue)} .todo .ic.green{background:var(--tint);color:var(--accent)}
  .todo b{font-weight:600}
  .todo .s{font-size:13px;color:var(--muted)}
  .todo .btn{margin-left:auto}
  .allgood{padding:22px 18px;color:var(--muted);display:flex;gap:12px;align-items:center}
  .allgood .ic{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;background:var(--tint);color:var(--accent)}
  .pipe{display:flex;height:12px;border-radius:999px;overflow:hidden;gap:3px;margin:4px 0 16px;background:var(--gray-soft)}
  .legend{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
  .legend div{font-size:12.5px;color:var(--text-2)}
  .legend b{display:block;font:700 17px 'Inter Tight',Inter,sans-serif;color:var(--text);margin-top:2px;font-variant-numeric:tabular-nums}
  .legend i{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:6px}
  .panel table th{padding-left:18px;padding-right:18px}
  .panel table td{padding:12px 18px;vertical-align:middle}
  .panel table tbody tr{cursor:pointer}
  .panel table tbody tr:hover td{background:var(--surface-2)}
  .r{text-align:right}
  .mailrow{display:grid;grid-template-columns:32px minmax(0,1fr) auto;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border);align-items:start;color:var(--text)}
  .mailrow:last-child{border-bottom:0}
  .mailrow:hover{background:var(--surface-2);text-decoration:none}
  .av-c{width:32px;height:32px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:700;color:var(--c);background:color-mix(in srgb,var(--c) 14%,transparent)}
  .mailrow .mid{min-width:0}
  .mailrow b{font-weight:600;font-size:13.5px;display:block}
  .mailrow .mid span{display:block;font-size:13px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .mailrow.unread b::after{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--blue);margin-left:7px;vertical-align:1px}
  .mailrow time{font-size:12px;color:var(--muted);white-space:nowrap}
  .feed{padding:6px 18px 12px}
  .ev{display:grid;grid-template-columns:28px minmax(0,1fr) auto;gap:12px;padding:9px 0;position:relative;font-size:13.5px}
  .ev:not(:last-child)::after{content:"";position:absolute;left:13.5px;top:38px;bottom:-4px;width:1px;background:var(--border)}
  .ev .dot{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:var(--surface-2);border:1px solid var(--border);color:var(--text-2)}
  .ev .dot svg.i{width:14px;height:14px}
  .ev time{font-size:12px;color:var(--muted);white-space:nowrap}
  .ev a{color:var(--text);font-weight:600}
  .loading{padding:18px;color:var(--muted);font-size:13.5px}
  @media(max-width:1100px){.dash{grid-template-columns:minmax(0,1fr)}}
  @media(max-width:860px){
    .dash-head .row-actions{width:100%}.dash-head .row-actions .btn{flex:1}
    .kpis{grid-template-columns:1fr 1fr;gap:.75rem}.kpis .stat{padding:.85rem .9rem}.kpis .stat .n{font-size:23px}
    .ph,.todo,.mailrow{padding-left:14px;padding-right:14px}.feed{padding-left:14px;padding-right:14px}
    .panel table th,.panel table td{padding-left:14px;padding-right:14px}
    .legend{grid-template-columns:1fr 1fr}.hide-m{display:none}
  }
</style>

<div class="dash-head">
  <div><h1 class="page"><?= e($hello) ?>, <?= e($first) ?></h1>
  <p class="sub"><?= e(date('l, F j')) ?><?= $todo ? ' · ' . count($todo) . ' thing' . (count($todo) === 1 ? '' : 's') . ' need' . (count($todo) === 1 ? 's' : '') . ' you' : ' · you\'re all caught up' ?></p></div>
  <div class="row-actions">
    <?php if (can('customers.edit')): ?><a class="btn secondary" href="customer_edit.php"><?= oe_icon('users') ?>New customer</a><?php endif; ?>
    <?php if (product_on('quotes') && can('quotes.create')): ?><a class="btn" href="quote_edit.php"><?= oe_icon('quote') ?>New quote</a><?php endif; ?>
  </div>
</div>

<?php if ($stats): ?>
<div class="kpis">
  <?php foreach ($stats as [$l, $n, $s, $sw, $href]): ?>
    <a class="stat" href="<?= e($href) ?>" style="--sw:<?= $sw ?>;color:inherit"><div class="l"><i></i><?= e($l) ?></div><div class="n"><?= e($n) ?></div><div class="s"><?= e($s) ?></div></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="dash">
  <div class="col">
    <div class="panel">
      <div class="ph"><h2>Needs you<?= $todo ? ' <span class="ct">' . count($todo) . '</span>' : '' ?></h2></div>
      <?php if (!$todo): ?>
        <div class="allgood"><span class="ic"><?= oe_icon('check') ?></span>Nothing waiting on you right now.</div>
      <?php else: foreach ($todo as [$ic, $tone, $t, $s, $href, $btn]): ?>
        <div class="todo"><span class="ic <?= $tone ?>"><?= oe_icon($ic) ?></span><div><b><?= e($t) ?></b><div class="s"><?= e($s) ?></div></div><a class="btn secondary small" href="<?= e($href) ?>"><?= e($btn) ?></a></div>
      <?php endforeach; endif; ?>
    </div>

    <?php if (product_on('quotes')): ?>
    <div class="panel">
      <div class="ph"><h2>Quotes this month</h2><a class="more" href="quotes.php">All quotes</a></div>
      <div style="padding:16px 18px 6px">
        <div class="pipe">
          <?php $colors = ['draft' => '#c3cac3', 'sent' => 'var(--blue)', 'accepted' => 'var(--accent)', 'declined' => '#e07a6b'];
          if ($pipeTotal > 0) foreach ($pipe as $k => [$l, $v]) if ($v > 0) echo '<span style="flex:' . round($v, 2) . ';background:' . $colors[$k] . '"></span>'; ?>
        </div>
        <div class="legend">
          <?php foreach ($pipe as $k => [$l, $v]): ?><div><i style="background:<?= $colors[$k] ?>"></i><?= e($l) ?><b><?= e(money_short($v)) ?></b></div><?php endforeach; ?>
        </div>
      </div>
      <?php if ($recentQ): ?>
      <table>
        <thead><tr><th>Quote</th><th>Customer</th><th class="hide-m">Date</th><th>Status</th><th class="r">Total</th></tr></thead>
        <tbody>
        <?php foreach ($recentQ as $q): $st = $q['approval_status'] === 'pending' ? ['contacted', 'Needs approval'] : [$q['status'], ucfirst($q['status'])]; ?>
          <tr onclick="location.href='quote_view.php?id=<?= (int) $q['id'] ?>'">
            <td><a href="quote_view.php?id=<?= (int) $q['id'] ?>" style="color:var(--text);font-weight:600;white-space:nowrap"><?= e($q['quote_number']) ?></a></td>
            <td><?= e($q['name']) ?></td>
            <td class="muted hide-m" style="white-space:nowrap"><?= e(date('M j', strtotime($q['issue_date']))) ?></td>
            <td><span class="badge <?= e($st[0]) ?>"><?= e($st[1]) ?></span></td>
            <td class="r" style="font-variant-numeric:tabular-nums"><?= money($q['v']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?><div class="empty">No quotes yet. <a href="quote_edit.php">Make your first one</a>.</div><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($seeRequests): ?>
    <div class="panel">
      <div class="ph"><h2>Latest requests</h2><a class="more" href="requests.php">View all</a></div>
      <?php if (!$recentReq): ?>
        <div class="empty">No requests yet. When someone submits your website form, it shows up here.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>When</th><th>Name</th><th class="hide-m">Type</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recentReq as $r): ?>
          <tr onclick="location.href='request.php?id=<?= (int) $r['id'] ?>'">
            <td class="muted" style="white-space:nowrap"><?= e(date('M j, g:ia', strtotime($r['created_at']))) ?></td>
            <td><a href="request.php?id=<?= (int) $r['id'] ?>" style="color:var(--text);font-weight:600"><?= e($r['name']) ?></a><?= $r['business'] ? '<br><span class="muted" style="font-size:12.5px">' . e($r['business']) . '</span>' : '' ?></td>
            <td class="hide-m"><?= e(ucfirst($r['type'])) ?></td>
            <td><span class="badge <?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="col">
    <?php if ($seeInbox): ?>
    <div class="panel" id="inboxPanel">
      <div class="ph"><h2>Inbox</h2><a class="more" href="email.php">Open Inbox</a></div>
      <div class="loading">Checking your email…</div>
    </div>
    <script>
      fetch('index.php?panel=inbox', {credentials: 'same-origin'}).then(function (r) { return r.ok ? r.text() : ''; }).then(function (h) {
        var p = document.getElementById('inboxPanel');
        if (h) p.innerHTML = h; else p.querySelector('.loading').textContent = 'Couldn\'t reach your email right now.';
      }).catch(function () {});
    </script>
    <?php endif; ?>

    <?php if ($acts): $labels = activity_labels(); $links = activity_links();
      $icons = ['quote' => 'quote', 'customer' => 'users', 'request' => 'inbox2', 'user' => 'team', 'catalog' => 'tag', 'approval' => 'shield']; ?>
    <div class="panel">
      <div class="ph"><h2>Recent activity</h2><a class="more" href="activity.php">See all</a></div>
      <div class="feed">
        <?php foreach ($acts as $a):
          $href = isset($links[$a['entity']]) && $a['entity_id'] ? $links[$a['entity']] . (int) $a['entity_id'] : '';
          $who = $a['person'] ?: ($a['action'] === 'quote.accepted' ? 'Customer' : 'Someone');
          $what = $labels[$a['action']] ?? $a['action'];
          $ic = $a['action'] === 'quote.accepted' ? 'check' : ($icons[explode('.', $a['action'])[0]] ?? 'pulse'); ?>
          <div class="ev"><span class="dot"><?= oe_icon($ic) ?></span>
            <div><?= e($who) ?> · <?= e(lcfirst($what)) ?><?= $a['detail'] !== '' ? ': ' . ($href ? '<a href="' . e($href) . '">' . e($a['detail']) . '</a>' : e($a['detail'])) : '' ?></div>
            <time><?= e(ago($a['created_at'])) ?></time></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php
layout_footer();
