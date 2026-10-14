<?php
/** Quotes list. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$rows = db()->query(
    "SELECT q.*, b.name AS customer_name
     FROM quotes q JOIN businesses b ON b.id = q.business_id
     ORDER BY q.created_at DESC"
)->fetchAll();

// Totals per quote (one-time) for the list
function quote_onetime_total(int $qid): float {
    $st = db()->prepare("SELECT COALESCE(SUM(quantity*unit_price),0) FROM quote_items WHERE quote_id=? AND billing='one_time'");
    $st->execute([$qid]);
    return (float)$st->fetchColumn();
}

layout_header('Quotes', 'quotes.php');
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 class="page">Quotes</h1>
    <p class="sub">Create, send, and track quotes.</p>
  </div>
  <a href="quote_edit.php" class="btn">+ New quote</a>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">No quotes yet. Click <strong>New quote</strong> to build your first.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Quote #</th><th>Customer</th><th>Date</th><th>One-time</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $q): ?>
        <tr>
          <td><strong><?= e($q['quote_number']) ?></strong></td>
          <td><?= e($q['customer_name']) ?></td>
          <td class="muted"><?= e(date('M j, Y', strtotime($q['issue_date']))) ?></td>
          <td><?= money(quote_onetime_total((int)$q['id'])) ?></td>
          <td><span class="badge <?= e($q['status']) ?>"><?= e($q['status']) ?></span></td>
          <td><a href="quote_view.php?id=<?= (int)$q['id'] ?>" class="btn secondary small">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
layout_footer();
