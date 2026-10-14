<?php
/** Dashboard: one row of numbers per product the client has, then the latest requests. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$count = function (string $sql): int {
    try { return (int) db()->query($sql)->fetchColumn(); } catch (Exception $ex) { return 0; }
};

$groups = [];
if (product_on('customers')) {
    $groups['customers'] = [
        ['New requests', $count("SELECT COUNT(*) FROM requests WHERE status='new'"), 'requests.php'],
        ['Customers', $count("SELECT COUNT(*) FROM businesses"), 'customers.php'],
        ['Total requests', $count("SELECT COUNT(*) FROM requests"), 'requests.php'],
    ];
}
if (product_on('quotes')) {
    $groups['quotes'] = [
        ['Open quotes', $count("SELECT COUNT(*) FROM quotes WHERE status IN ('draft','sent')"), 'quotes.php'],
        ['Accepted', $count("SELECT COUNT(*) FROM quotes WHERE status='accepted'"), 'quotes.php'],
        ['Saved items', $count("SELECT COUNT(*) FROM catalog_items WHERE active=1"), 'catalog.php'],
    ];
}

$recent = product_on('customers') ? db()->query("SELECT * FROM requests ORDER BY created_at DESC LIMIT 8")->fetchAll() : [];

layout_header('Dashboard', 'index.php');
?>
<h1 class="page">Dashboard</h1>
<p class="sub">Welcome back, <?= e(current_username()) ?>.</p>

<?php foreach ($groups as $p => $stats): ?>
  <div class="muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin:0 0 .5rem"><?= e(product_name($p)) ?></div>
  <div class="stat-row">
    <?php foreach ($stats as [$label, $n, $href]): ?>
      <a class="stat" href="<?= e($href) ?>" style="text-decoration:none;color:inherit"><div class="n"><?= $n ?></div><div class="l"><?= e($label) ?></div></a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<?php if (product_on('inbox')): ?>
  <div class="muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin:0 0 .5rem"><?= e(product_name('inbox')) ?></div>
  <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
    <span><?= count(imap_accounts_list()) ?> mailbox<?= count(imap_accounts_list()) === 1 ? '' : 'es' ?> connected</span>
    <a href="email.php" class="btn secondary small">Open Inbox</a>
  </div>
<?php endif; ?>

<?php if (product_on('customers')): ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
    <strong>Latest requests</strong>
    <a href="requests.php" class="btn secondary small">View all</a>
  </div>
  <?php if (!$recent): ?>
    <div class="empty">No requests yet. When someone submits your website form, it shows up here.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>When</th><th>Name</th><th>Type</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td class="muted"><?= e(date('M j, g:ia', strtotime($r['created_at']))) ?></td>
          <td><?= e($r['name']) ?><?= $r['business'] ? '<br><span class="muted">' . e($r['business']) . '</span>' : '' ?></td>
          <td><?= e(ucfirst($r['type'])) ?></td>
          <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
          <td><a href="request.php?id=<?= (int)$r['id'] ?>" class="btn secondary small">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
layout_footer();
