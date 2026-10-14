<?php
/** Dashboard. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$newReqs   = (int) db()->query("SELECT COUNT(*) FROM requests WHERE status='new'")->fetchColumn();
$allReqs   = (int) db()->query("SELECT COUNT(*) FROM requests")->fetchColumn();
$customers = (int) db()->query("SELECT COUNT(*) FROM businesses")->fetchColumn();
$openQuotes = 0;
try { $openQuotes = (int) db()->query("SELECT COUNT(*) FROM quotes WHERE status IN ('draft','sent')")->fetchColumn(); } catch (Exception $ex) {}

$recent = db()->query("SELECT * FROM requests ORDER BY created_at DESC LIMIT 8")->fetchAll();

layout_header('Dashboard', 'index.php');
?>
<h1 class="page">Dashboard</h1>
<p class="sub">Welcome back, <?= e(current_username()) ?>.</p>

<div class="stat-row">
  <div class="stat"><div class="n"><?= $newReqs ?></div><div class="l">New requests</div></div>
  <div class="stat"><div class="n"><?= $customers ?></div><div class="l">Customers</div></div>
  <div class="stat"><div class="n"><?= $allReqs ?></div><div class="l">Total requests</div></div>
  <div class="stat"><div class="n"><?= $openQuotes ?></div><div class="l">Open quotes</div></div>
</div>

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
<?php
layout_footer();
