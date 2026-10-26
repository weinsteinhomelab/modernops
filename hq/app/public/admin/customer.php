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
    <?php if (can('customers.edit')): ?><a href="customer_edit.php?id=<?= $id ?>" class="btn secondary">Edit business</a><?php endif; ?>
    <?php if (product_on('quotes') && can('quotes.create')): ?><a href="quote_edit.php?business=<?= $id ?>" class="btn">+ New quote</a><?php endif; ?>
  </div>
</div>
<?php if (is_admin()): ?>
<form method="post" class="card" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;padding:.7rem 1.1rem">
  <?= csrf_field() ?><input type="hidden" name="action" value="assign">
  <span class="muted" style="font-size:.85rem">Looked after by</span>
  <select name="assigned_to" onchange="this.form.submit()" style="max-width:260px">
    <option value="">Nobody (admins only)</option>
    <?php foreach (people() as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$b['assigned_to'] === (int)$p['id'] ? ' selected' : '' ?>><?= e(user_label($p)) ?><?= $p['role'] === 'admin' ? ' (admin)' : '' ?></option><?php endforeach; ?>
  </select>
  <span class="muted" style="font-size:.8rem">The person you pick sees this customer and can quote it.</span>
</form>
<?php endif; ?>
<?php if (isset($_GET['from']) && $_GET['from']==='request') flash('ok','Business created from the request.'); ?>
<?php if (isset($_GET['saved'])) flash('ok','Saved.'); ?>
<?php if ($msg) flash('ok', $msg); ?>
<?php if ($siteErr) flash('err', $siteErr); ?>
<?php if (isset($_GET['toobig'])) flash('err', 'Those pictures are too big to upload at once. Add up to 10 MB per picture, a few at a time.'); ?>

<div class="grid2">
  <div class="card">
    <strong>Business details</strong>
    <table style="margin-top:.5rem">
      <?php if ($b['vertical']): ?><tr><th style="border:none;width:120px">Vertical</th><td style="border:none"><?= e($b['vertical']) ?></td></tr><?php endif; ?>
      <?php if ($b['main_phone']): ?><tr><th style="border:none">Main number</th><td style="border:none"><?= e($b['main_phone']) ?></td></tr><?php endif; ?>
      <?php if ($b['main_email']): ?><tr><th style="border:none">Main email</th><td style="border:none"><a href="mailto:<?= e($b['main_email']) ?>"><?= e($b['main_email']) ?></a></td></tr><?php endif; ?>
      <tr><th style="border:none">Their website</th><td style="border:none"><?php if (!empty($b['website'])): ?><a href="<?= e($b['website']) ?>" target="_blank" rel="noopener" style="word-break:break-all"><?= e(preg_replace('#^https?://(www\.)?#i', '', rtrim($b['website'], '/'))) ?> &#8599;</a><?php elseif (can('customers.edit')): ?><a href="customer_edit.php?id=<?= $id ?>">Add their website</a><?php else: ?><span class="muted">Not added</span><?php endif; ?></td></tr>
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

<div class="card" id="websites">
  <strong>Websites</strong>
  <?php $canEdit = can('customers.edit'); ?>
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
            <span class="muted" style="font-size:.85rem">No pictures yet.</span>
          <?php endif; ?>
        </div>
        <?php if ($sp && product_on('inbox')):
          $mailTo = $b['main_email'] ?: (string) (array_values(array_filter(array_column($contacts, 'email')))[0] ?? ''); ?>
          <a class="btn secondary small" style="margin-top:.6rem" href="email.php?<?= e(http_build_query(['compose' => 1, 'to' => $mailTo, 'subject' => 'A new website for ' . $b['name'], 'pics' => implode(',', array_column($sp, 'id'))])) ?>">Email these pictures</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="muted" style="margin:.5rem 0 0;font-size:.9rem">No demo or live website linked yet.</div>
  <?php endif; ?>
  <?php if ($canEdit): ?>
  <form method="post" class="site-add" id="siteAdd">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_site">
    <label>Type<select name="kind" id="siteKind"><option value="demo">Demo</option><option value="live">Live website</option></select></label>
    <label><span id="urlLbl">Online demo</span><input type="text" name="url" list="demo-urls" placeholder="modern-ops.net/demos/…"></label>
    <label id="localWrap">Local demo<input type="text" name="local_url" placeholder="10.0.0.232:5196"></label>
    <label>Label<input type="text" name="label" placeholder="Optional"></label>
    <button class="btn" type="submit">Add</button>
    <datalist id="demo-urls"><?php foreach ($demoUrls as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist>
  </form>
  <script>
  (function(){
    var k = document.getElementById('siteKind'), lw = document.getElementById('localWrap'), ul = document.getElementById('urlLbl');
    function upd(){ var live = k.value === 'live'; lw.hidden = live; ul.textContent = live ? 'Website' : 'Online demo'; }
    k.addEventListener('change', upd); upd();
  })();
  </script>
  <?php endif; ?>
  <style>
    .sites{display:grid;gap:0;margin-top:.6rem}
    .site{padding:.9rem 0;border-top:1px solid var(--border)}
    .site:first-child{border-top:none;padding-top:.3rem}
    .site-top{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
    .site-acts{margin-left:auto;flex-wrap:nowrap}
    .site-links{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.4rem 1.5rem;margin-top:.5rem}
    .site-link{display:flex;flex-direction:column;gap:.1rem;min-width:0;font-size:.9rem}
    .site-link a{word-break:break-all}
    .site-link .muted{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em}
    .site-edit{margin-top:.5rem;font-size:.88rem}
    .site-edit summary{cursor:pointer;color:var(--accent);width:fit-content}
    .site-form,.site-add{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.5rem .7rem;align-items:end;margin-top:.6rem}
    .site-form label,.site-add label{display:flex;flex-direction:column;gap:.25rem;font-size:.8rem;margin:0}
    .site-add{margin-top:1.1rem;padding-top:1rem;border-top:1px solid var(--border)}
    .site-add [hidden]{display:none}
    .pics{display:flex;flex-wrap:wrap;gap:.7rem;margin-top:.8rem;align-items:stretch}
    .pic{margin:0;width:150px;display:flex;flex-direction:column;gap:.3rem}
    .pic img{width:150px;height:100px;object-fit:cover;object-position:top;border:1px solid var(--border);border-radius:4px;display:block;background:var(--border)}
    .pic figcaption{display:flex;justify-content:space-between;gap:.4rem;font-size:.75rem;min-width:0}
    .pic figcaption span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
    .pic form{margin:0}
    .linkbtn{background:none;border:none;padding:0;color:var(--danger,#c0392b);font:inherit;cursor:pointer}
    .pic-add{margin:0}
    .pic-drop{width:150px;height:100px;border:1px dashed var(--border);border-radius:4px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.2rem;cursor:pointer;text-align:center;padding:.4rem;margin:0}
    .pic-drop:hover,.pic-drop:focus-within{border-color:var(--accent)}
    .pic-drop input{position:absolute;width:1px;height:1px;opacity:0}
    .pic-drop span{color:var(--accent);font-weight:600;font-size:.85rem}
    .pic-drop small{color:var(--muted);font-size:.68rem;line-height:1.2}
    @media(max-width:640px){.site-links{grid-template-columns:1fr}}
  </style>
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
