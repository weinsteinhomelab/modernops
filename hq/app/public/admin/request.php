<?php
/** Single request: view, change status, convert to a customer. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM requests WHERE id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r) { http_response_code(404); layout_header('Not found','requests.php'); echo '<div class="card">Request not found. <a href="requests.php">Back</a></div>'; layout_footer(); exit; }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'status') {
        $new = $_POST['status'] ?? 'new';
        if (in_array($new, ['new','contacted','quoted','won','closed'], true)) {
            $u = db()->prepare('UPDATE requests SET status = ? WHERE id = ?');
            $u->execute([$new, $id]);
            $r['status'] = $new;
            $msg = 'Status updated.';
        }
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM requests WHERE id = ?')->execute([$id]);
        header('Location: requests.php');
        exit;
    } elseif ($action === 'make_customer') {
        // Reuse a business by main email or an existing contact email
        $bid = null;
        if (!empty($r['email'])) {
            $f = db()->prepare('SELECT b.id FROM businesses b LEFT JOIN contacts c ON c.business_id=b.id WHERE b.main_email=? OR c.email=? LIMIT 1');
            $f->execute([$r['email'], $r['email']]);
            $bid = $f->fetchColumn() ?: null;
        }
        if (!$bid) {
            $bname = trim((string)($r['business'] ?: $r['name'])) ?: 'New customer';
            $ins = db()->prepare('INSERT INTO businesses (name, main_email, main_phone) VALUES (?,?,?)');
            $ins->execute([$bname, $r['email'], $r['phone']]);
            $bid = (int) db()->lastInsertId();
            $parts = preg_split('/\s+/', trim((string)$r['name']), 2);
            db()->prepare('INSERT INTO contacts (business_id, first_name, last_name, email, phone) VALUES (?,?,?,?,?)')
               ->execute([$bid, $parts[0] ?? '', $parts[1] ?? '', $r['email'], $r['phone']]);
        }
        db()->prepare('UPDATE requests SET business_id = ? WHERE id = ?')->execute([$bid, $id]);
        header('Location: customer.php?id=' . (int)$bid . '&from=request');
        exit;
    }
}

// Linked business (if any)
$cust = null;
if (!empty($r['business_id'])) {
    $c = db()->prepare('SELECT * FROM businesses WHERE id = ?');
    $c->execute([$r['business_id']]);
    $cust = $c->fetch();
}

layout_header('Request', 'requests.php');
?>
<p style="margin-bottom:.5rem"><a href="requests.php">&larr; All requests</a></p>
<h1 class="page"><?= e($r['name']) ?></h1>
<p class="sub"><?= e(date('l, M j, Y \a\t g:ia', strtotime($r['created_at']))) ?> · <?= e(ucfirst($r['type'])) ?> request</p>
<?php if ($msg) flash('ok', $msg); ?>

<div class="grid2">
  <div class="card">
    <strong>Contact</strong>
    <table style="margin-top:.5rem">
      <tr><th style="border:none;width:90px">Name</th><td style="border:none"><?= e($r['name']) ?></td></tr>
      <?php if ($r['business']): ?><tr><th style="border:none">Business</th><td style="border:none"><?= e($r['business']) ?></td></tr><?php endif; ?>
      <?php if ($r['email']): ?><tr><th style="border:none">Email</th><td style="border:none"><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></td></tr><?php endif; ?>
      <?php if ($r['phone']): ?><tr><th style="border:none">Phone</th><td style="border:none"><?= e($r['phone']) ?></td></tr><?php endif; ?>
    </table>
  </div>

  <div class="card">
    <strong>Status</strong>
    <form method="post" style="margin-top:.5rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="status">
      <select name="status" onchange="this.form.submit()">
        <?php foreach (['new','contacted','quoted','won','closed'] as $s): ?>
          <option value="<?= $s ?>"<?= $r['status']===$s?' selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn small" style="margin-top:.5rem">Update</button></noscript>
    </form>

    <div style="margin-top:1.1rem">
      <strong>Customer</strong>
      <?php if ($cust): ?>
        <p style="margin-top:.4rem">Linked to <a href="customer.php?id=<?= (int)$cust['id'] ?>"><?= e($cust['name']) ?></a>.</p>
      <?php else: ?>
        <form method="post" style="margin-top:.4rem">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="make_customer">
          <button class="btn small">Add as customer</button>
          <span class="muted" style="font-size:.8rem">Creates a customer record from this request.</span>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <strong>Message</strong>
  <p style="margin-top:.5rem;white-space:pre-wrap"><?= $r['message'] ? e($r['message']) : '<span class="muted">(no message)</span>' ?></p>
</div>

<div style="text-align:right">
  <form method="post" onsubmit="return confirm('Delete this request permanently?')">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <button class="btn danger small">Delete request</button>
  </form>
</div>
<?php
layout_footer();
