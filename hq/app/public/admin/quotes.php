<?php
/** Quotes list. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();
require_product('quotes');

[$qs, $qa] = quote_scope('q');
$st = db()->prepare(
    "SELECT q.*, b.name AS customer_name, COALESCE(NULLIF(u.name,''), u.username) AS made_by
     FROM quotes q JOIN businesses b ON b.id = q.business_id
     LEFT JOIN admin_users u ON u.id = q.created_by
     WHERE $qs ORDER BY q.created_at DESC");
$st->execute($qa);
$rows = $st->fetchAll();

// Totals per quote (one-time) for the list
function quote_onetime_total(int $qid): float {
    $st = db()->prepare("SELECT COALESCE(SUM(quantity*unit_price),0) FROM quote_items WHERE quote_id=? AND billing='one_time' AND is_optional=0");
    $st->execute([$qid]);
    return (float)$st->fetchColumn();
}

layout_header('Quotes', 'quotes.php');
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 class="page">Quotes</h1>
    <p class="sub"><?= is_admin() ? 'Everyone' . "'" . 's quotes.' : 'The quotes you made.' ?></p>
  </div>
  <?php if (can('quotes.create')): ?><a href="quote_edit.php" class="btn">+ New quote</a><?php endif; ?>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">No quotes yet. Click <strong>New quote</strong> to build your first.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Quote #</th><th>Customer</th><th>Date</th><th>One-time</th><th>Status</th><?= is_admin() ? '<th>Made by</th>' : '' ?><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $q): ?>
        <tr>
          <td><strong><?= e($q['quote_number']) ?></strong></td>
          <td><?= e($q['customer_name']) ?></td>
          <td class="muted"><?= e(date('M j, Y', strtotime($q['issue_date']))) ?></td>
          <td><?= money(quote_onetime_total((int)$q['id'])) ?></td>
          <td><span class="badge <?= e($q['status']) ?>"><?= e($q['status']) ?></span><?= $q['approval_status'] === 'pending' ? ' <span class="badge contacted">needs approval</span>' : ($q['approval_status'] === 'rejected' ? ' <span class="badge declined">pricing turned down</span>' : '') ?></td>
          <?php if (is_admin()): ?><td style="font-size:.85rem"><?= e($q['made_by'] ?? '') ?></td><?php endif; ?>
          <td><a href="quote_view.php?id=<?= (int)$q['id'] ?>" class="btn secondary small">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
layout_footer();
