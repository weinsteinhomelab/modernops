<?php
/** Single customer: details, request history, (quotes in Phase 2). */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM customers WHERE id = ?');
$st->execute([$id]);
$c = $st->fetch();
if (!$c) { http_response_code(404); layout_header('Not found','customers.php'); echo '<div class="card">Customer not found. <a href="customers.php">Back</a></div>'; layout_footer(); exit; }

$rq = db()->prepare('SELECT * FROM requests WHERE customer_id = ? ORDER BY created_at DESC');
$rq->execute([$id]);
$requests = $rq->fetchAll();

$quotes = [];
try {
    $qq = db()->prepare('SELECT * FROM quotes WHERE customer_id = ? ORDER BY created_at DESC');
    $qq->execute([$id]);
    $quotes = $qq->fetchAll();
} catch (Exception $ex) {}

layout_header($c['name'], 'customers.php');
?>
<p style="margin-bottom:.5rem"><a href="customers.php">&larr; All customers</a></p>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 class="page"><?= e($c['name']) ?></h1>
    <p class="sub"><?= $c['business'] ? e($c['business']) : 'Customer since ' . e(date('M Y', strtotime($c['created_at']))) ?></p>
  </div>
  <a href="customer_edit.php?id=<?= (int)$c['id'] ?>" class="btn secondary">Edit</a>
</div>
<?php if (isset($_GET['from']) && $_GET['from']==='request') flash('ok','Customer created from the request.'); ?>
<?php if (isset($_GET['saved'])) flash('ok','Customer saved.'); ?>

<div class="grid2">
  <div class="card">
    <strong>Contact</strong>
    <table style="margin-top:.5rem">
      <?php if ($c['email']): ?><tr><th style="border:none;width:90px">Email</th><td style="border:none"><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></td></tr><?php endif; ?>
      <?php if ($c['phone']): ?><tr><th style="border:none">Phone</th><td style="border:none"><?= e($c['phone']) ?></td></tr><?php endif; ?>
      <?php if ($c['address']): ?><tr><th style="border:none">Address</th><td style="border:none"><?= e($c['address']) ?></td></tr><?php endif; ?>
      <?php if (!$c['email'] && !$c['phone'] && !$c['address']): ?><tr><td style="border:none" class="muted">No contact details yet. <a href="customer_edit.php?id=<?= (int)$c['id'] ?>">Add some</a>.</td></tr><?php endif; ?>
    </table>
  </div>
  <div class="card">
    <strong>Notes</strong>
    <p style="margin-top:.5rem;white-space:pre-wrap"><?= $c['notes'] ? e($c['notes']) : '<span class="muted">(none)</span>' ?></p>
  </div>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
    <strong>Quotes</strong>
    <span class="muted" style="font-size:.82rem">Quote builder arrives in Phase 2</span>
  </div>
  <?php if (!$quotes): ?>
    <div class="empty" style="padding:1.25rem">No quotes yet.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Quote #</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($quotes as $qrow): ?>
        <tr><td><?= e($qrow['quote_number']) ?></td><td><?= e($qrow['issue_date']) ?></td><td><span class="badge <?= e($qrow['status']) ?>"><?= e($qrow['status']) ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <strong>Request history</strong>
  <?php if (!$requests): ?>
    <div class="empty" style="padding:1.25rem">No website requests linked to this customer.</div>
  <?php else: ?>
    <table style="margin-top:.5rem">
      <thead><tr><th>When</th><th>Type</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td class="muted"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
          <td><?= e(ucfirst($r['type'])) ?></td>
          <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
          <td><a href="request.php?id=<?= (int)$r['id'] ?>" class="btn secondary small">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
layout_footer();
