<?php
/** Business detail: info, address, contacts, touch points, notes, quotes, requests. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_sites.php';
require_login();
require_product('customers', 'quotes');

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM businesses WHERE id = ?');
$st->execute([$id]);
$b = $st->fetch();
if (!$b) { http_response_code(404); layout_header('Not found','customers.php'); echo '<div class="card">Business not found. <a href="customers.php">Back</a></div>'; layout_footer(); exit; }
if (!can_see_business($b)) deny('This customer isn' . "'" . 't assigned to you.');

$msg = '';
$siteErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // a too-big upload arrives with an empty POST (no CSRF token): say so instead of failing the token check
    header('Location: customer.php?id=' . $id . '&toobig=1#websites'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'assign') {
        if (!is_admin()) deny();
        $to = (int)($_POST['assigned_to'] ?? 0) ?: null;
        db()->prepare('UPDATE businesses SET assigned_to = ? WHERE id = ?')->execute([$to, $id]);
        $b['assigned_to'] = $to;
        log_activity('customer.assigned', 'customer', $id, $b['name'] . ' to ' . ($to ? (string)(array_column(people(), null, 'id')[$to]['username'] ?? $to) : 'nobody'));
        header('Location: customer.php?id=' . $id . '&saved=1'); exit;
    }
    if (!can('customers.edit')) deny('You can view this customer but not change it.');
    if ($a === 'add_tp') {
        $d = $_POST['tp_date'] ?: date('Y-m-d');
        $t = $_POST['tp_type'] ?? 'Call';
        if (!in_array($t, ['Call','Email','Text','Voicemail','Meeting','Other'], true)) $t = 'Call';
        db()->prepare('INSERT INTO touchpoints (business_id, tp_date, tp_type, note) VALUES (?,?,?,?)')
           ->execute([$id, $d, $t, trim($_POST['note'] ?? '')]);
        $msg = 'Touch point logged.';
    } elseif ($a === 'del_tp') {
        db()->prepare('DELETE FROM touchpoints WHERE id=? AND business_id=?')->execute([(int)($_POST['tp_id'] ?? 0), $id]);
    } elseif ($a === 'add_site') {
        $kind = ($_POST['kind'] ?? '') === 'live' ? 'live' : 'demo';
        $rawUrl = trim((string)($_POST['url'] ?? ''));
        $rawLocal = $kind === 'demo' ? trim((string)($_POST['local_url'] ?? '')) : '';
        $url = site_clean_url($rawUrl);
        $local = site_clean_local($rawLocal);
        if (($rawUrl !== '' && $url === '') || ($rawLocal !== '' && $local === '')) {
            $siteErr = "One of those website addresses doesn't look right.";
        } elseif ($url === '' && $local === '') {
            $siteErr = $kind === 'live' ? 'Enter the website address.' : 'Enter the online demo address, the local demo address, or both.';
        } else {
            db()->prepare('INSERT INTO sites (business_id, kind, label, url, local_url) VALUES (?,?,?,?,?)')
               ->execute([$id, $kind, trim($_POST['label'] ?? '') ?: null, $url, $local ?: null]);
            $msg = $kind === 'live' ? 'Live website added.' : 'Demo added.';
        }
    } elseif ($a === 'edit_site') {
        $sid = (int)($_POST['site_id'] ?? 0);
        $rawUrl = trim((string)($_POST['url'] ?? ''));
        $rawLocal = trim((string)($_POST['local_url'] ?? ''));
        $url = site_clean_url($rawUrl);
        $local = site_clean_local($rawLocal);
        if (($rawUrl !== '' && $url === '') || ($rawLocal !== '' && $local === '')) {
            $siteErr = "One of those website addresses doesn't look right.";
        } elseif ($url === '' && $local === '') {
            $siteErr = 'Keep at least one address, or remove the website.';
        } else {
            db()->prepare('UPDATE sites SET url=?, local_url=?, label=? WHERE id=? AND business_id=?')
               ->execute([$url, $local ?: null, trim($_POST['label'] ?? '') ?: null, $sid, $id]);
            $msg = 'Website updated.';
        }
    } elseif ($a === 'site_kind') {
        $kind = ($_POST['kind'] ?? '') === 'live' ? 'live' : 'demo';
        db()->prepare('UPDATE sites SET kind=? WHERE id=? AND business_id=?')->execute([$kind, (int)($_POST['site_id'] ?? 0), $id]);
        $msg = $kind === 'live' ? 'Marked as live.' : 'Moved back to demo.';
    } elseif ($a === 'del_site') {
        $sid = (int)($_POST['site_id'] ?? 0);
        $n = db()->prepare('DELETE FROM sites WHERE id=? AND business_id=?'); $n->execute([$sid, $id]);
        if ($n->rowCount()) { try { db()->prepare('DELETE FROM site_files WHERE site_id=? AND business_id=?')->execute([$sid, $id]); } catch (Exception $ex) {} }
        $msg = 'Website removed.';
    } elseif ($a === 'add_pics') {
        $sid = (int)($_POST['site_id'] ?? 0);
        $own = db()->prepare('SELECT id FROM sites WHERE id=? AND business_id=?'); $own->execute([$sid, $id]);
        if (!$own->fetch()) {
            $siteErr = 'That website is no longer on this customer.';
        } else {
            [$saved, $errs] = site_pics_save($id, $sid, 'pics');
            if ($saved) { $msg = $saved === 1 ? 'Picture added.' : "$saved pictures added."; log_activity('site.pictures', 'customer', $id, $b['name'] . ': ' . $saved . ' added'); }
            if ($errs) $siteErr = implode(' ', $errs);
        }
    } elseif ($a === 'del_pic') {
        try { db()->prepare('DELETE FROM site_files WHERE id=? AND business_id=?')->execute([(int)($_POST['pic_id'] ?? 0), $id]); } catch (Exception $ex) {}
        $msg = 'Picture removed.';
    } elseif ($a === 'del_contact') {
        db()->prepare('DELETE FROM contacts WHERE id=? AND business_id=?')->execute([(int)($_POST['contact_id'] ?? 0), $id]);
        $msg = 'Contact removed.';
    }
}

$contacts = db()->prepare('SELECT * FROM contacts WHERE business_id=? ORDER BY id'); $contacts->execute([$id]); $contacts = $contacts->fetchAll();
$tps = db()->prepare('SELECT * FROM touchpoints WHERE business_id=? ORDER BY tp_date DESC, id DESC'); $tps->execute([$id]); $tps = $tps->fetchAll();
[$qs, $qa] = quote_scope('q');
$quotes = db()->prepare("SELECT q.* FROM quotes q WHERE q.business_id=? AND $qs ORDER BY q.created_at DESC"); $quotes->execute(array_merge([$id], $qa)); $quotes = $quotes->fetchAll();
$reqs = db()->prepare('SELECT * FROM requests WHERE business_id=? ORDER BY created_at DESC'); $reqs->execute([$id]); $reqs = $reqs->fetchAll();
$sites = sites_for($id);
$pics = site_pics_for($id);
$demoUrls = demo_suggestions();



// Money and the timeline: quotes, touch points and website requests together, newest first.
$qval = db()->prepare("SELECT COALESCE(SUM(quantity*unit_price),0) FROM quote_items WHERE quote_id=? AND billing='one_time' AND is_optional=0");
$won = 0.0; $open = 0.0;
foreach ($quotes as &$qr) {
    $qval->execute([(int)$qr['id']]); $qr['v'] = (float)$qval->fetchColumn();
    if ($qr['status'] === 'accepted') $won += $qr['v'];
    if (in_array($qr['status'], ['draft', 'sent'], true)) $open += $qr['v'];
}
unset($qr);
$timeline = [];
foreach ($tps as $t) $timeline[] = ['ts' => strtotime($t['tp_date'] . ' 12:00'), 'date' => $t['tp_date'], 'kind' => 'tp', 'row' => $t];
foreach ($quotes as $qr) {
    if ($qr['status'] === 'accepted' && $qr['accepted_at']) $timeline[] = ['ts' => strtotime($qr['accepted_at']), 'kind' => 'accepted', 'row' => $qr];
    $timeline[] = ['ts' => strtotime($qr['created_at']), 'kind' => 'quote', 'row' => $qr];
}
foreach ($reqs as $r) $timeline[] = ['ts' => strtotime($r['created_at']), 'kind' => 'request', 'row' => $r];
usort($timeline, fn($a, $c) => $c['ts'] <=> $a['ts']);
$lastTp = $tps ? strtotime($tps[0]['tp_date']) : 0;
$lastContact = !$lastTp ? 'Never' : (date('Y-m-d', $lastTp) === date('Y-m-d') ? 'Today' : (date('Y-m-d', $lastTp) === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('M j', $lastTp)));
$isCustomer = $won > 0;
$owner = null;
foreach (people(false) as $p) if ((int)$p['id'] === (int)$b['assigned_to']) $owner = $p;
$words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $b['name']))) ?: [];
$ini = strtoupper(mb_substr($words[0] ?? '?', 0, 1) . mb_substr($words[1] ?? '', 0, 1));
$place = trim(($b['city'] ?? '') . ($b['state'] ? ', ' . $b['state'] : ''), ', ');
$mailTo = $b['main_email'] ?: (string) (array_values(array_filter(array_column($contacts, 'email')))[0] ?? '');
$canEdit = can('customers.edit');
// After a website change, reopen the Websites tab.
$startTab = ($siteErr !== '' || in_array($_POST['action'] ?? '', ['add_site', 'edit_site', 'site_kind', 'del_site', 'add_pics', 'del_pic'], true)) ? 'websites' : '';
$tpIcon = ['Call' => 'phone', 'Email' => 'mail', 'Text' => 'note', 'Voicemail' => 'phone', 'Meeting' => 'users', 'Other' => 'note'];

layout_header($b['name'], 'customers.php');
?>
<style>
  .back{display:inline-flex;gap:6px;align-items:center;font-size:13.5px;font-weight:600;margin-bottom:.9rem}
  .c-head{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}
  .c-hero{display:flex;gap:16px;align-items:center;min-width:0}
  .c-av{width:56px;height:56px;border-radius:14px;background:linear-gradient(140deg,#dbe8ff,#bcd2fb);color:#1d4fb8;display:grid;place-items:center;font:700 20px 'Inter Tight',Inter,sans-serif;flex:none}
  .c-hero h1.page{margin:0;display:inline}
  .c-meta{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:6px;font-size:13.5px;color:var(--text-2)}
  .c-meta span{display:flex;align-items:center;gap:6px}
  .c-meta svg.i{width:15px;height:15px;color:var(--muted)}
  .c-head .row-actions .btn svg.i{width:16px;height:16px}
  .c-grid{display:grid;grid-template-columns:330px minmax(0,1fr);gap:1.15rem;align-items:start}
  .c-col{display:flex;flex-direction:column;gap:1.15rem;min-width:0}
  .panel{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);overflow:hidden}
  .ph{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid var(--border)}
  .ph h2{font-size:15px;font-weight:650;display:flex;gap:8px;align-items:center}
  .ph .ct{font-size:12px;font-weight:600;color:var(--muted)}
  .ph a.more{font-size:13px;font-weight:600}
  .pb{padding:16px 18px}
  .kv{display:grid;grid-template-columns:104px minmax(0,1fr);gap:10px 12px;font-size:13.5px}
  .kv dt{color:var(--muted)}
  .kv dd{min-width:0;overflow-wrap:anywhere}
  .kv select{min-height:32px;padding:.25rem .5rem;font-size:13px}
  .person{display:flex;gap:12px;align-items:center;padding:12px 18px;border-bottom:1px solid var(--border)}
  .person:last-child{border-bottom:0}
  .person .av-c{width:32px;height:32px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:700;background:var(--blue-soft);color:var(--blue);flex:none}
  .person b{font-weight:600;font-size:13.5px;color:var(--text)}
  .person .s{font-size:12.5px;color:var(--muted);overflow-wrap:anywhere}
  .person .go{margin-left:auto;display:flex;gap:6px;flex:none}
  .person .go a,.person .go button{width:30px;height:30px;border-radius:8px;border:1px solid var(--border);display:grid;place-items:center;color:var(--text-2);background:var(--surface);cursor:pointer;padding:0}
  .person .go svg.i{width:15px;height:15px}
  .sum{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid var(--border)}
  .sum div{padding:14px 18px;border-right:1px solid var(--border)}
  .sum div:last-child{border-right:0}
  .sum small{font-size:12px;color:var(--muted);font-weight:500}
  .sum b{display:block;font:700 19px 'Inter Tight',Inter,sans-serif;margin-top:2px;font-variant-numeric:tabular-nums}
  .tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);padding:0 6px;overflow-x:auto}
  .tab{padding:12px;font-weight:600;font-size:13.5px;color:var(--muted);border:0;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap;display:flex;gap:6px;align-items:center;background:none;cursor:pointer;font-family:inherit}
  .tab.on{color:var(--text);border-bottom-color:var(--accent)}
  .tab .ct{font-size:11.5px;background:var(--gray-soft);color:var(--text-2);border-radius:999px;padding:0 7px}
  .tabp[hidden]{display:none}
  .composer{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;padding:14px 18px;border-bottom:1px solid var(--border);background:var(--surface-2);align-items:center}
  .seg{display:flex;border:1px solid var(--border-2);border-radius:8px;overflow:hidden;background:var(--surface)}
  .seg label{margin:0;padding:0 10px;height:36px;display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--text-2);border-right:1px solid var(--border);cursor:pointer}
  .seg label:last-child{border-right:0}
  .seg input{position:absolute;opacity:0;width:1px;height:1px}
  .seg label:has(input:checked){background:var(--tint);color:var(--accent)}
  .seg svg.i{width:15px;height:15px}
  .composer input[type=date]{min-height:36px;width:160px}
  .composer .seg{width:max-content;max-width:100%}
  .composer input[type=text]{min-height:36px}
  .feed{padding:6px 18px 14px}
  .ev{display:grid;grid-template-columns:28px minmax(0,1fr) auto;gap:12px;padding:10px 0;position:relative;font-size:13.5px}
  .ev:not(:last-child)::after{content:"";position:absolute;left:13.5px;top:38px;bottom:-4px;width:1px;background:var(--border)}
  .ev .dot{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:var(--surface-2);border:1px solid var(--border);color:var(--text-2)}
  .ev .dot.ok{background:var(--tint);color:var(--accent);border-color:transparent}
  .ev .dot svg.i{width:14px;height:14px}
  .ev .note{margin-top:6px;padding:9px 12px;border-radius:8px;background:var(--surface-2);border:1px solid var(--border);color:var(--text-2);white-space:pre-wrap}
  .ev .side-r{display:flex;gap:6px;align-items:flex-start;font-size:12px;color:var(--muted);white-space:nowrap}
  .ev form{margin:0}
  .xbtn{background:none;border:0;color:var(--muted);cursor:pointer;font-size:15px;line-height:1;padding:0 2px}
  .xbtn:hover{color:var(--red)}
  .tabp table td,.tabp table th{padding-left:18px;padding-right:18px;vertical-align:middle}
  .tabp tbody tr{cursor:pointer}
  .tabp tbody tr:hover td{background:var(--surface-2)}
  .r{text-align:right}
  /* websites */
  .sites{display:grid;gap:0}
  .site{padding:16px 18px;border-bottom:1px solid var(--border)}
  .site-top{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
  .site-acts{margin-left:auto;flex-wrap:nowrap}
  .site-links{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.4rem 1.5rem;margin-top:.6rem}
  .site-link{display:flex;flex-direction:column;gap:.1rem;min-width:0;font-size:13.5px}
  .site-link a{word-break:break-all}
  .site-link .muted{font-size:12px;font-weight:600}
  .site-edit{margin-top:.6rem;font-size:13.5px}
  .site-edit summary{cursor:pointer;color:var(--accent);font-weight:600;width:fit-content}
  .site-form,.site-add{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.5rem .7rem;align-items:end;margin-top:.6rem}
  .site-form label,.site-add label{display:flex;flex-direction:column;gap:.3rem;margin:0}
  .site-add{padding:16px 18px;margin:0;background:var(--surface-2)}
  .site-add [hidden]{display:none}
  .pics{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:.9rem;align-items:stretch}
  .pic{margin:0;width:168px;display:flex;flex-direction:column;gap:.35rem}
  .pic img{width:168px;height:105px;object-fit:cover;object-position:top;border:1px solid var(--border);border-radius:8px;display:block;background:var(--surface-2)}
  .pic figcaption{display:flex;justify-content:space-between;gap:.4rem;font-size:12px;min-width:0;color:var(--text-2)}
  .pic figcaption span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
  .pic form{margin:0}
  .linkbtn{background:none;border:none;padding:0;color:var(--red);font:inherit;cursor:pointer}
  .pic-add{margin:0}
  .pic-drop{width:168px;height:105px;border:1.5px dashed var(--border-2);border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.2rem;cursor:pointer;text-align:center;padding:.4rem;margin:0}
  .pic-drop:hover,.pic-drop:focus-within{border-color:var(--accent)}
  .pic-drop input{position:absolute;width:1px;height:1px;opacity:0}
  .pic-drop span{color:var(--accent);font-weight:600;font-size:13px}
  .pic-drop small{color:var(--muted);font-size:11px;line-height:1.25}
  .empty-s{padding:1.4rem 18px;color:var(--muted);font-size:13.5px}
  @media(max-width:1100px){.c-grid{grid-template-columns:minmax(0,1fr)}}
  @media(max-width:860px){
    .c-head .row-actions{width:100%}.c-head .row-actions .btn{flex:1}
    .ph,.pb,.person,.site,.site-add,.feed{padding-left:14px;padding-right:14px}
    .composer{grid-template-columns:minmax(0,1fr);padding:12px 14px}
    .composer .seg{display:grid;grid-template-columns:repeat(3,1fr);width:100%}
    .composer .seg label{justify-content:center;padding:0 6px;border-bottom:1px solid var(--border)}
    .composer input[type=date]{width:100%}
    .c-av{display:none}
    .ev{grid-template-columns:28px minmax(0,1fr)}
    .ev .dot{grid-row:1/3}
    .ev .side-r{grid-column:2;grid-row:1}
    .sum div{padding:12px 14px}.sum b{font-size:17px}
    .site-links{grid-template-columns:1fr}
    .pic,.pic img,.pic-drop{width:calc(50vw - 46px)}
    .tabp table td,.tabp table th{padding-left:14px;padding-right:14px}
    .hide-m{display:none}
  }
</style>

<a class="back" href="customers.php">&larr; All customers</a>
<div class="c-head">
  <div class="c-hero">
    <div class="c-av" aria-hidden="true"><?= e($ini) ?></div>
    <div style="min-width:0">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><h1 class="page"><?= e($b['name']) ?></h1><span class="badge <?= $isCustomer ? 'won' : 'quoted' ?>"><?= $isCustomer ? 'Customer' : 'Prospect' ?></span></div>
      <div class="c-meta">
        <?php if ($b['vertical']): ?><span><?= oe_icon('tag') ?><?= e($b['vertical']) ?></span><?php endif; ?>
        <?php if ($place): ?><span><?= oe_icon('pin') ?><?= e($place) ?></span><?php endif; ?>
        <span><?= oe_icon('team') ?><?= $owner ? 'Looked after by ' . e(user_label($owner)) : 'Not assigned' ?></span>
      </div>
    </div>
  </div>
  <div class="row-actions">
    <?php if (product_on('inbox') && $mailTo && my_mailboxes()): ?><a href="email.php?<?= e(http_build_query(['compose' => 1, 'to' => $mailTo])) ?>" class="btn secondary"><?= oe_icon('mail') ?>Email</a><?php endif; ?>
    <?php if ($canEdit): ?><a href="customer_edit.php?id=<?= $id ?>" class="btn secondary"><?= oe_icon('edit') ?>Edit</a><?php endif; ?>
    <?php if (product_on('quotes') && can('quotes.create')): ?><a href="quote_edit.php?business=<?= $id ?>" class="btn"><?= oe_icon('plus') ?>New quote</a><?php endif; ?>
  </div>
</div>
<?php if (isset($_GET['from']) && $_GET['from']==='request') flash('ok','Business created from the request.'); ?>
<?php if (isset($_GET['saved'])) flash('ok','Saved.'); ?>
<?php if ($msg) flash('ok', $msg); ?>
<?php if ($siteErr) flash('err', $siteErr); ?>
<?php if (isset($_GET['toobig'])) flash('err', 'Those pictures are too big to upload at once. Add up to 10 MB per picture, a few at a time.'); ?>

<div class="c-grid">
  <div class="c-col">
    <div class="panel">
      <div class="ph"><h2>Details</h2><?php if ($canEdit): ?><a class="more" href="customer_edit.php?id=<?= $id ?>">Edit</a><?php endif; ?></div>
      <div class="pb">
        <dl class="kv">
          <dt>Phone</dt><dd><?= $b['main_phone'] ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $b['main_phone'])) . '">' . e($b['main_phone']) . '</a>' : '<span class="muted">Not added</span>' ?></dd>
          <dt>Email</dt><dd><?= $b['main_email'] ? '<a href="mailto:' . e($b['main_email']) . '">' . e($b['main_email']) . '</a>' : '<span class="muted">Not added</span>' ?></dd>
          <dt>Their website</dt><dd><?php if (!empty($b['website'])): ?><a href="<?= e($b['website']) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://(www\.)?#i', '', rtrim($b['website'], '/'))) ?> &#8599;</a><?php elseif ($canEdit): ?><a href="customer_edit.php?id=<?= $id ?>">Add their website</a><?php else: ?><span class="muted">Not added</span><?php endif; ?></dd>
          <?php $addr = array_filter([$b['address'],$b['address2'],trim(($b['city']??'').($b['state']?', '.$b['state']:'').' '.($b['zip']??'')),$b['country']]); ?>
          <dt>Address</dt><dd><?= $addr ? implode('<br>', array_map('e', $addr)) : ($canEdit ? '<a href="customer_edit.php?id=' . $id . '">Add one</a>' : '<span class="muted">Not added</span>') ?></dd>
          <?php if ($b['vertical']): ?><dt>Industry</dt><dd><?= e($b['vertical']) ?></dd><?php endif; ?>
          <dt>Added</dt><dd><?= e(date('M j, Y', strtotime($b['created_at']))) ?></dd>
          <?php if (is_admin()): ?>
          <dt>Looked after by</dt><dd>
            <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="assign">
              <select name="assigned_to" onchange="this.form.submit()" title="The person you pick sees this customer and can quote it.">
                <option value="">Nobody (admins only)</option>
                <?php foreach (people() as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$b['assigned_to'] === (int)$p['id'] ? ' selected' : '' ?>><?= e(user_label($p)) ?><?= $p['role'] === 'admin' ? ' (admin)' : '' ?></option><?php endforeach; ?>
              </select>
            </form></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <div class="panel">
      <div class="ph"><h2>People <span class="ct"><?= count($contacts) ?></span></h2><a class="more" href="contact_edit.php?business=<?= $id ?>">+ Add</a></div>
      <?php if (!$contacts): ?><div class="empty-s">No people yet.</div><?php endif; ?>
      <?php foreach ($contacts as $c): $nm = trim(($c['first_name']??'').' '.($c['last_name']??'')); ?>
        <div class="person">
          <span class="av-c"><?= e(strtoupper(mb_substr($c['first_name'] ?? '', 0, 1) . mb_substr($c['last_name'] ?? '', 0, 1)) ?: '?') ?></span>
          <div style="min-width:0"><a href="contact_edit.php?id=<?= (int)$c['id'] ?>"><b><?= e($nm) ?></b></a><div class="s"><?= e(implode(' · ', array_filter([$c['title'], $c['email'] ?: $c['phone']]))) ?></div></div>
          <div class="go">
            <?php if ($c['phone']): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>" title="Call <?= e($c['phone']) ?>"><?= oe_icon('phone') ?></a><?php endif; ?>
            <?php if ($c['email']): ?><a href="<?= product_on('inbox') && my_mailboxes() ? 'email.php?' . e(http_build_query(['compose' => 1, 'to' => $c['email']])) : 'mailto:' . e($c['email']) ?>" title="Email <?= e($c['email']) ?>"><?= oe_icon('mail') ?></a><?php endif; ?>
            <form method="post" onsubmit="return confirm('Remove this contact?')" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="del_contact"><input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>"><button title="Remove contact" aria-label="Remove contact">×</button></form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="panel">
      <div class="ph"><h2>Notes</h2><?php if ($canEdit): ?><a class="more" href="customer_edit.php?id=<?= $id ?>">Edit</a><?php endif; ?></div>
      <div class="pb" style="font-size:13.5px;color:var(--text-2);white-space:pre-wrap"><?= $b['notes'] ? e($b['notes']) : '<span class="muted">No notes yet.</span>' ?></div>
    </div>
  </div>

  <div class="c-col">
    <div class="panel">
      <div class="sum">
        <div><small>Won</small><b><?= money($won) ?></b></div>
        <div><small>Open quotes</small><b><?= money($open) ?></b></div>
        <div><small>Last contact</small><b><?= e($lastContact) ?></b></div>
      </div>
      <div class="tabs" role="tablist">
        <button class="tab on" type="button" data-tab="activity">Activity</button>
        <button class="tab" type="button" data-tab="quotes">Quotes <span class="ct"><?= count($quotes) ?></span></button>
        <button class="tab" type="button" data-tab="websites">Websites <span class="ct"><?= count($sites) ?></span></button>
        <button class="tab" type="button" data-tab="requests">Requests <span class="ct"><?= count($reqs) ?></span></button>
      </div>

      <div class="tabp" id="tab-activity">
        <?php if ($canEdit): ?>
        <form method="post" class="composer">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_tp">
          <div class="seg" role="radiogroup" aria-label="Type">
            <?php foreach (['Call','Email','Text','Voicemail','Meeting','Other'] as $i => $t): ?>
              <label title="<?= $t ?>"><input type="radio" name="tp_type" value="<?= $t ?>"<?= $i === 0 ? ' checked' : '' ?>><?= oe_icon($tpIcon[$t]) ?><span><?= $t ?></span></label>
            <?php endforeach; ?>
          </div>
          <input type="date" name="tp_date" value="<?= e(date('Y-m-d')) ?>" aria-label="Date">
          <input type="text" name="note" placeholder="What happened?" aria-label="Note">
          <button class="btn" type="submit">Log it</button>
        </form>
        <?php endif; ?>
        <?php if (!$timeline): ?><div class="empty-s">No history yet. Log a call, email or meeting above.</div><?php else: ?>
        <div class="feed">
          <?php foreach ($timeline as $ev): $r = $ev['row']; ?>
            <?php if ($ev['kind'] === 'tp'): ?>
              <div class="ev"><span class="dot"><?= oe_icon($tpIcon[$r['tp_type']] ?? 'note') ?></span>
                <div><b><?= e($r['tp_type']) ?></b><?php if ($r['note'] !== ''): ?><div class="note"><?= e($r['note']) ?></div><?php endif; ?></div>
                <div class="side-r"><?= e(date('M j, Y', strtotime($r['tp_date']))) ?>
                  <?php if ($canEdit): ?><form method="post" onsubmit="return confirm('Delete this entry?')"><?= csrf_field() ?><input type="hidden" name="action" value="del_tp"><input type="hidden" name="tp_id" value="<?= (int)$r['id'] ?>"><button class="xbtn" title="Delete" aria-label="Delete entry">×</button></form><?php endif; ?>
                </div></div>
            <?php elseif ($ev['kind'] === 'accepted'): ?>
              <div class="ev"><span class="dot ok"><?= oe_icon('check') ?></span>
                <div><a href="quote_view.php?id=<?= (int)$r['id'] ?>"><b>Quote <?= e($r['quote_number']) ?> accepted</b></a><?= $r['accepted_name'] ? ' by ' . e($r['accepted_name']) : '' ?> · <?= money($r['v']) ?></div>
                <div class="side-r"><?= e(date('M j, Y', $ev['ts'])) ?></div></div>
            <?php elseif ($ev['kind'] === 'quote'): ?>
              <div class="ev"><span class="dot"><?= oe_icon('quote') ?></span>
                <div>Quote <a href="quote_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['quote_number']) ?></b></a> created · <?= money($r['v']) ?> <span class="badge <?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></div>
                <div class="side-r"><?= e(date('M j, Y', $ev['ts'])) ?></div></div>
            <?php else: ?>
              <div class="ev"><span class="dot"><?= oe_icon('inbox2') ?></span>
                <div>Website request from <a href="request.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['name']) ?></b></a><?php if ($r['message']): ?><div class="note"><?= e(mb_strimwidth($r['message'], 0, 240, '…')) ?></div><?php endif; ?></div>
                <div class="side-r"><?= e(date('M j, Y', $ev['ts'])) ?></div></div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="tabp" id="tab-quotes" hidden>
        <?php if (!$quotes): ?><div class="empty-s">No quotes yet.<?php if (product_on('quotes') && can('quotes.create')): ?> <a href="quote_edit.php?business=<?= $id ?>">Make one</a>.<?php endif; ?></div><?php else: ?>
        <table>
          <thead><tr><th>Quote</th><th class="hide-m">Date</th><th>Status</th><th class="r">Total</th></tr></thead>
          <tbody>
          <?php foreach ($quotes as $qr): ?>
            <tr onclick="location.href='quote_view.php?id=<?= (int)$qr['id'] ?>'"><td><a href="quote_view.php?id=<?= (int)$qr['id'] ?>" style="color:var(--text);font-weight:600"><?= e($qr['quote_number']) ?></a></td><td class="muted hide-m"><?= e(date('M j, Y', strtotime($qr['issue_date']))) ?></td><td><span class="badge <?= e($qr['status']) ?>"><?= e(ucfirst($qr['status'])) ?></span></td><td class="r"><?= money($qr['v']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <div class="tabp" id="tab-websites" hidden>
        <?php if ($sites): ?>
          <div class="sites">
          <?php foreach ($sites as $s): $live = $s['kind'] === 'live'; $sp = $pics[(int)$s['id']] ?? []; ?>
            <div class="site">
              <div class="site-top">
                <span class="badge <?= $live ? 'won' : 'quoted' ?>"><?= $live ? 'Live' : 'Demo' ?></span>
                <?php if ($s['label']): ?><strong><?= e($s['label']) ?></strong><?php endif; ?>
                <?php if ($canEdit): ?>
                <div class="row-actions site-acts">
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="site_kind"><input type="hidden" name="site_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="kind" value="<?= $live ? 'demo' : 'live' ?>"><button class="btn secondary small"><?= $live ? 'Back to demo' : 'Mark live' ?></button></form>
                  <form method="post" onsubmit="return confirm('Remove this website and its pictures from the customer?')" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="del_site"><input type="hidden" name="site_id" value="<?= (int)$s['id'] ?>"><button class="btn danger small" title="Remove">×</button></form>
                </div>
                <?php endif; ?>
              </div>
              <div class="site-links">
                <?php foreach ($live ? [['Website', $s['url']]] : [['Online demo', $s['url']], ['Local demo', $s['local_url'] ?? '']] as [$lbl, $u]): ?>
                  <div class="site-link">
                    <span class="muted"><?= e($lbl) ?></span>
                    <?php if ($u): ?><a href="<?= e($u) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://#', '', rtrim($u, '/'))) ?> &#8599;</a>
                    <?php else: ?><span class="muted">Not set</span><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($canEdit): ?>
              <details class="site-edit">
                <summary>Edit addresses</summary>
                <form method="post" class="site-form">
                  <?= csrf_field() ?><input type="hidden" name="action" value="edit_site"><input type="hidden" name="site_id" value="<?= (int)$s['id'] ?>">
                  <label><?= $live ? 'Website' : 'Online demo' ?><input type="text" name="url" value="<?= e($s['url']) ?>" placeholder="<?= $live ? e(client('domain')) : 'modern-ops.net/demos/…' ?>"></label>
                  <?php if (!$live): ?><label>Local demo<input type="text" name="local_url" value="<?= e($s['local_url'] ?? '') ?>" placeholder="10.0.0.232:5196"></label><?php else: ?><input type="hidden" name="local_url" value="<?= e($s['local_url'] ?? '') ?>"><?php endif; ?>
                  <label>Label<input type="text" name="label" value="<?= e($s['label'] ?? '') ?>" placeholder="Optional"></label>
                  <button class="btn small" type="submit">Save</button>
                </form>
              </details>
              <?php endif; ?>
              <div class="pics">
                <?php foreach ($sp as $p): ?>
                  <figure class="pic">
                    <a href="site_file.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener" title="<?= e($p['name']) ?>"><img src="site_file.php?id=<?= (int)$p['id'] ?>" alt="<?= e($p['name']) ?>" loading="lazy"></a>
                    <figcaption><span><?= e($p['name']) ?></span>
                      <?php if ($canEdit): ?><form method="post" onsubmit="return confirm('Remove this picture?')"><?= csrf_field() ?><input type="hidden" name="action" value="del_pic"><input type="hidden" name="pic_id" value="<?= (int)$p['id'] ?>"><button class="linkbtn" title="Remove picture">Remove</button></form><?php endif; ?>
                    </figcaption>
                  </figure>
                <?php endforeach; ?>
                <?php if ($canEdit): ?>
                <form method="post" enctype="multipart/form-data" class="pic-add">
                  <?= csrf_field() ?><input type="hidden" name="action" value="add_pics"><input type="hidden" name="site_id" value="<?= (int)$s['id'] ?>">
                  <label class="pic-drop"><input type="file" name="pics[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple onchange="this.form.submit()"><span>+ Add pictures</span><small>JPG, PNG, WebP or GIF · up to 10 MB each</small></label>
                </form>
                <?php elseif (!$sp): ?>
                  <span class="muted" style="font-size:13px">No pictures yet.</span>
                <?php endif; ?>
              </div>
              <?php if ($sp && product_on('inbox')): ?>
                <a class="btn secondary small" style="margin-top:.8rem" href="email.php?<?= e(http_build_query(['compose' => 1, 'to' => $mailTo, 'subject' => 'A new website for ' . $b['name'], 'pics' => implode(',', array_column($sp, 'id'))])) ?>"><?= oe_icon('mail') ?>Email these pictures</a>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty-s">No demo or live website linked yet.</div>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <form method="post" class="site-add" id="siteAdd">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_site">
          <label>Type<select name="kind" id="siteKind"><option value="demo">Demo</option><option value="live">Live website</option></select></label>
          <label><span id="urlLbl">Online demo</span><input type="text" name="url" list="demo-urls" placeholder="modern-ops.net/demos/…"></label>
          <label id="localWrap">Local demo<input type="text" name="local_url" placeholder="10.0.0.232:5196"></label>
          <label>Label<input type="text" name="label" placeholder="Optional"></label>
          <button class="btn" type="submit">Add website</button>
          <datalist id="demo-urls"><?php foreach ($demoUrls as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist>
        </form>
        <?php endif; ?>
      </div>

      <div class="tabp" id="tab-requests" hidden>
        <?php if (!$reqs): ?><div class="empty-s">No website requests linked.</div><?php else: ?>
        <table>
          <thead><tr><th>Date</th><th>Type</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($reqs as $r): ?><tr onclick="location.href='request.php?id=<?= (int)$r['id'] ?>'"><td class="muted"><a href="request.php?id=<?= (int)$r['id'] ?>"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></a></td><td><?= e(ucfirst($r['type'])) ?></td><td><span class="badge <?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td></tr><?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var tabs = document.querySelectorAll('.tab');
  function show(name){
    tabs.forEach(function(t){ var on = t.dataset.tab === name; t.classList.toggle('on', on); t.setAttribute('aria-selected', on); });
    document.querySelectorAll('.tabp').forEach(function(p){ p.hidden = p.id !== 'tab-' + name; });
  }
  tabs.forEach(function(t){ t.addEventListener('click', function(){ show(t.dataset.tab); history.replaceState(null, '', '#' + t.dataset.tab); }); });
  var h = location.hash.slice(1) || '<?= $startTab ?>';
  if (h && document.getElementById('tab-' + h)) show(h);
  var k = document.getElementById('siteKind');
  if (k) { var lw = document.getElementById('localWrap'), ul = document.getElementById('urlLbl');
    function upd(){ var live = k.value === 'live'; lw.hidden = live; ul.textContent = live ? 'Website' : 'Online demo'; }
    k.addEventListener('change', upd); upd(); }
})();
</script>
<?php
layout_footer();
