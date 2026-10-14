<?php
/** Quotes list. Phase 1 placeholder — full builder arrives in Phase 2. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$rows = [];
try { $rows = db()->query("SELECT q.*, c.name AS customer FROM quotes q JOIN customers c ON c.id=q.customer_id ORDER BY q.created_at DESC")->fetchAll(); }
catch (Exception $ex) {}

layout_header('Quotes', 'quotes.php');
?>
<h1 class="page">Quotes</h1>
<p class="sub">Create and send quotes to customers.</p>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">
      The quote builder is coming in <strong>Phase 2</strong> — create a quote with line items, generate a branded PDF, and email it to the customer from info@modern-ops.net.<br><br>
      For now, Phase 1 gives you the dashboard, website requests, and customer records.
    </div>
  <?php else: ?>
    <table>
      <thead><tr><th>Quote #</th><th>Customer</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $q): ?>
        <tr><td><?= e($q['quote_number']) ?></td><td><?= e($q['customer']) ?></td><td><?= e($q['issue_date']) ?></td><td><span class="badge <?= e($q['status']) ?>"><?= e($q['status']) ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
layout_footer();
