<?php
/** List of website requests (leads), filterable by status. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$statuses = ['new','contacted','quoted','won','closed'];
$filter = $_GET['status'] ?? '';
if ($filter !== '' && !in_array($filter, $statuses, true)) $filter = '';

if ($filter === '') {
    $rows = db()->query("SELECT * FROM requests ORDER BY created_at DESC")->fetchAll();
} else {
    $st = db()->prepare("SELECT * FROM requests WHERE status = ? ORDER BY created_at DESC");
    $st->execute([$filter]);
    $rows = $st->fetchAll();
}

layout_header('Requests', 'requests.php');
?>
<h1 class="page">Requests</h1>
<p class="sub">Quote and service-call requests from your website.</p>

<div class="card" style="padding:.75rem 1rem">
  <div class="row-actions">
    <a href="requests.php" class="btn <?= $filter===''?'':'secondary' ?> small">All</a>
    <?php foreach ($statuses as $s): ?>
      <a href="requests.php?status=<?= $s ?>" class="btn <?= $filter===$s?'':'secondary' ?> small"><?= ucfirst($s) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">No requests<?= $filter ? ' with status &ldquo;' . e($filter) . '&rdquo;' : ' yet' ?>.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>When</th><th>Name</th><th>Contact</th><th>Type</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="muted"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
          <td><?= e($r['name']) ?><?= $r['business'] ? '<br><span class="muted">' . e($r['business']) . '</span>' : '' ?></td>
          <td style="font-size:.82rem"><?= $r['email'] ? e($r['email']) : '' ?><?= $r['phone'] ? '<br>' . e($r['phone']) : '' ?></td>
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
