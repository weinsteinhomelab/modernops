<?php
/** Business detail: info, address, contacts, touch points, notes, quotes, requests. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_sites.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM businesses WHERE id = ?');
$st->execute([$id]);
$b = $st->fetch();
if (!$b) { http_response_code(404); layout_header('Not found','customers.php'); echo '<div class="card">Business not found. <a href="customers.php">Back</a></div>'; layout_footer(); exit; }

$msg = '';
$siteErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
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
        $url = site_clean_url((string)($_POST['url'] ?? ''));
        if ($url === '') {
            $msg = '';
            $siteErr = 'Enter a valid website address.';
        } else {
            db()->prepare('INSERT INTO sites (business_id, kind, label, url) VALUES (?,?,?,?)')
               ->execute([$id, $kind, trim($_POST['label'] ?? '') ?: null, $url]);
            $msg = $kind === 'live' ? 'Live website added.' : 'Demo added.';
        }
    } elseif ($a === 'site_kind') {
        $kind = ($_POST['kind'] ?? '') === 'live' ? 'live' : 'demo';
        db()->prepare('UPDATE sites SET kind=? WHERE id=? AND business_id=?')->execute([$kind, (int)($_POST['site_id'] ?? 0), $id]);
        $msg = $kind === 'live' ? 'Marked as live.' : 'Moved back to demo.';
    } elseif ($a === 'del_site') {
        db()->prepare('DELETE FROM sites WHERE id=? AND business_id=?')->execute([(int)($_POST['site_id'] ?? 0), $id]);
        $msg = 'Website removed.';
    } elseif ($a === 'del_contact') {
        db()->prepare('DELETE FROM contacts WHERE id=? AND business_id=?')->execute([(int)($_POST['contact_id'] ?? 0), $id]);
        $msg = 'Contact removed.';
    }
}

$contacts = db()->prepare('SELECT * FROM contacts WHERE business_id=? ORDER BY id'); $contacts->execute([$id]); $contacts = $contacts->fetchAll();
$tps = db()->prepare('SELECT * FROM touchpoints WHERE business_id=? ORDER BY tp_date DESC, id DESC'); $tps->execute([$id]); $tps = $tps->fetchAll();
$quotes = db()->prepare('SELECT * FROM quotes WHERE business_id=? ORDER BY created_at DESC'); $quotes->execute([$id]); $quotes = $quotes->fetchAll();
$reqs = db()->prepare('SELECT * FROM requests WHERE business_id=? ORDER BY created_at DESC'); $reqs->execute([$id]); $reqs = $reqs->fetchAll();
$sites = sites_for($id);
$demoUrls = demo_suggestions();

$tpBadge = ['Call'=>'quoted','Email'=>'new','Text'=>'sent','Voicemail'=>'contacted','Meeting'=>'won','Other'=>'closed'];

layout_header($b['name'], 'customers.php');
?>
<p style="margin-bottom:.5rem"><a href="customers.php">&larr; All customers</a></p>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 class="page"><?= e($b['name']) ?> <?= $b['vertical'] ? '<span class="badge won" style="vertical-align:middle">' . e($b['vertical']) . '</span>' : '' ?></h1>
    <p class="sub">Customer since <?= e(date('M Y', strtotime($b['created_at']))) ?></p>
  </div>
  <div class="row-actions">
    <a href="customer_edit.php?id=<?= $id ?>" class="btn secondary">Edit business</a>
    <a href="quote_edit.php?business=<?= $id ?>" class="btn">+ New quote</a>
  </div>
</div>
<?php if (isset($_GET['from']) && $_GET['from']==='request') flash('ok','Business created from the request.'); ?>
<?php if (isset($_GET['saved'])) flash('ok','Saved.'); ?>
<?php if ($msg) flash('ok', $msg); ?>
<?php if ($siteErr) flash('err', $siteErr); ?>

<div class="grid2">
  <div class="card">
    <strong>Business details</strong>
    <table style="margin-top:.5rem">
      <?php if ($b['vertical']): ?><tr><th style="border:none;width:120px">Vertical</th><td style="border:none"><?= e($b['vertical']) ?></td></tr><?php endif; ?>
      <?php if ($b['main_phone']): ?><tr><th style="border:none">Main number</th><td style="border:none"><?= e($b['main_phone']) ?></td></tr><?php endif; ?>
      <?php if ($b['main_email']): ?><tr><th style="border:none">Main email</th><td style="border:none"><a href="mailto:<?= e($b['main_email']) ?>"><?= e($b['main_email']) ?></a></td></tr><?php endif; ?>
    </table>
  </div>
  <div class="card">
    <strong>Address</strong>
    <?php $addr = array_filter([$b['address'],$b['address2'],trim(($b['city']??'').($b['state']?', '.$b['state']:'').' '.($b['zip']??'')),$b['country']]); ?>
    <?php if ($addr): ?>
      <div style="margin-top:.5rem;line-height:1.7"><?php foreach ($addr as $ln): ?><div><?= e($ln) ?></div><?php endforeach; ?></div>
    <?php else: ?><div class="muted" style="margin-top:.5rem">No address yet. <a href="customer_edit.php?id=<?= $id ?>">Add one</a>.</div><?php endif; ?>
  </div>
</div>

<div class="card">
  <strong>Websites</strong>
  <?php if ($sites): ?>
    <table style="margin-top:.5rem">
      <tbody>
      <?php foreach ($sites as $s): $live = $s['kind'] === 'live'; ?>
        <tr>
          <td style="width:70px"><span class="badge <?= $live ? 'won' : 'quoted' ?>"><?= $live ? 'Live' : 'Demo' ?></span></td>
          <td>
            <?php if ($s['label']): ?><strong><?= e($s['label']) ?></strong><br><?php endif; ?>
            <a href="<?= e($s['url']) ?>" target="_blank" rel="noopener" style="font-size:.85rem;word-break:break-all"><?= e(preg_replace('#^https?://#', '', rtrim($s['url'], '/'))) ?> &#8599;</a>
          </td>
          <td style="width:1%;white-space:nowrap">
            <div class="row-actions">
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="site_kind"><input type="hidden" name="site_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="kind" value="<?= $live ? 'demo' : 'live' ?>"><button class="btn secondary small"><?= $live ? 'Back to demo' : 'Mark live' ?></button></form>
              <form method="post" onsubmit="return confirm('Remove this website from the customer?')" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="del_site"><input type="hidden" name="site_id" value="<?= (int)$s['id'] ?>"><button class="btn danger small" title="Remove">×</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="muted" style="margin:.5rem 0 0;font-size:.9rem">No demo or live website linked yet.</div>
  <?php endif; ?>
  <form method="post" class="site-add" style="display:grid;grid-template-columns:110px 1fr 1fr auto;gap:.5rem;align-items:center;margin-top:1rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_site">
    <select name="kind"><option value="demo">Demo</option><option value="live">Live</option></select>
    <input type="text" name="url" list="demo-urls" placeholder="Website address, e.g. modern-ops.net/demos/doggy-spa/" required>
    <input type="text" name="label" placeholder="Label (optional)">
    <button class="btn" type="submit">Add</button>
    <datalist id="demo-urls"><?php foreach ($demoUrls as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist>
  </form>
  <style>@media(max-width:640px){.site-add{grid-template-columns:1fr !important}}</style>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
    <strong>Contacts</strong>
    <a href="contact_edit.php?business=<?= $id ?>" class="btn secondary small">+ Add contact</a>
  </div>
  <?php if (!$contacts): ?>
    <div class="empty" style="padding:1.25rem">No contacts yet.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Name</th><th>Title</th><th>Email</th><th>Phone</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($contacts as $c): ?>
        <tr>
          <td><strong><?= e(trim(($c['first_name']??'').' '.($c['last_name']??''))) ?></strong></td>
          <td><?= e($c['title']) ?></td>
          <td style="font-size:.85rem"><?= $c['email'] ? '<a href="mailto:'.e($c['email']).'">'.e($c['email']).'</a>' : '' ?></td>
          <td style="font-size:.85rem"><?= e($c['phone']) ?></td>
          <td>
            <div class="row-actions">
              <a href="contact_edit.php?id=<?= (int)$c['id'] ?>" class="btn secondary small">Edit</a>
              <form method="post" onsubmit="return confirm('Remove this contact?')" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="del_contact"><input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>"><button class="btn danger small">×</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <strong>Touch points</strong>
  <form method="post" style="display:grid;grid-template-columns:150px 150px 1fr auto;gap:.5rem;align-items:center;margin:.7rem 0 1rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_tp">
    <input type="date" name="tp_date" value="<?= e(date('Y-m-d')) ?>">
    <select name="tp_type"><?php foreach (['Call','Email','Text','Voicemail','Meeting','Other'] as $t): ?><option><?= $t ?></option><?php endforeach; ?></select>
    <input type="text" name="note" placeholder="What happened / what you tried…">
    <button class="btn" type="submit">Log it</button>
  </form>
  <?php if (!$tps): ?>
    <div class="empty" style="padding:1rem">No contact history yet.</div>
  <?php else: ?>
    <table>
      <tbody>
      <?php foreach ($tps as $t): ?>
        <tr>
          <td class="muted" style="width:90px"><?= e(date('M j, Y', strtotime($t['tp_date']))) ?></td>
          <td style="width:90px"><span class="badge <?= $tpBadge[$t['tp_type']] ?? 'closed' ?>"><?= e($t['tp_type']) ?></span></td>
          <td><?= e($t['note']) ?></td>
          <td style="width:30px"><form method="post" onsubmit="return confirm('Delete this entry?')"><?= csrf_field() ?><input type="hidden" name="action" value="del_tp"><input type="hidden" name="tp_id" value="<?= (int)$t['id'] ?>"><button class="btn danger small" title="Delete">×</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <strong>Notes</strong>
  <p style="margin-top:.5rem;white-space:pre-wrap"><?= $b['notes'] ? e($b['notes']) : '<span class="muted">(none) — <a href="customer_edit.php?id='.$id.'">add notes</a></span>' ?></p>
</div>

<div class="grid2">
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center"><strong>Quotes</strong><a href="quote_edit.php?business=<?= $id ?>" class="btn small">+ New</a></div>
    <?php if (!$quotes): ?><div class="empty" style="padding:1rem">None yet.</div><?php else: ?>
    <table style="margin-top:.5rem"><tbody>
      <?php foreach ($quotes as $qr): ?><tr><td><strong><?= e($qr['quote_number']) ?></strong></td><td class="muted"><?= e(date('M j', strtotime($qr['issue_date']))) ?></td><td><span class="badge <?= e($qr['status']) ?>"><?= e($qr['status']) ?></span></td><td><a href="quote_view.php?id=<?= (int)$qr['id'] ?>" class="btn secondary small">Open</a></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
  </div>
  <div class="card">
    <strong>Website requests</strong>
    <?php if (!$reqs): ?><div class="empty" style="padding:1rem">None linked.</div><?php else: ?>
    <table style="margin-top:.5rem"><tbody>
      <?php foreach ($reqs as $r): ?><tr><td class="muted"><?= e(date('M j', strtotime($r['created_at']))) ?></td><td><?= e(ucfirst($r['type'])) ?></td><td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td><td><a href="request.php?id=<?= (int)$r['id'] ?>" class="btn secondary small">Open</a></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
  </div>
</div>
<?php
layout_footer();
