<?php
/** Customer list + search. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $st = db()->prepare("SELECT * FROM customers WHERE name LIKE :t OR business LIKE :t OR email LIKE :t OR phone LIKE :t ORDER BY name");
    $st->execute([':t' => '%' . $q . '%']);
    $rows = $st->fetchAll();
} else {
    $rows = db()->query("SELECT * FROM customers ORDER BY name")->fetchAll();
}

layout_header('Customers', 'customers.php');
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 class="page">Customers</h1>
    <p class="sub">Look up anyone you've worked with.</p>
  </div>
  <a href="customer_edit.php" class="btn">+ New customer</a>
</div>

<div class="card" style="padding:.75rem 1rem">
  <form method="get" style="display:flex;gap:.5rem">
    <input type="text" name="q" placeholder="Search name, business, email, or phone…" value="<?= e($q) ?>">
    <button class="btn small">Search</button>
    <?php if ($q): ?><a href="customers.php" class="btn secondary small">Clear</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><?= $q ? 'No customers match &ldquo;' . e($q) . '&rdquo;.' : 'No customers yet. Add one, or convert a request into a customer.' ?></div>
  <?php else: ?>
    <table>
      <thead><tr><th>Name</th><th>Business</th><th>Contact</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr>
          <td><a href="customer.php?id=<?= (int)$c['id'] ?>"><strong><?= e($c['name']) ?></strong></a></td>
          <td><?= e($c['business']) ?></td>
          <td style="font-size:.82rem"><?= $c['email'] ? e($c['email']) : '' ?><?= $c['phone'] ? '<br>' . e($c['phone']) : '' ?></td>
          <td><a href="customer.php?id=<?= (int)$c['id'] ?>" class="btn secondary small">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
layout_footer();
