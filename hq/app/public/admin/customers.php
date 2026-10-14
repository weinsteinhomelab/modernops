<?php
/** Businesses list + search (the primary customer record). */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $st = db()->prepare(
        "SELECT DISTINCT b.* FROM businesses b
         LEFT JOIN contacts c ON c.business_id = b.id
         WHERE b.name LIKE :t OR b.vertical LIKE :t OR b.city LIKE :t OR b.main_email LIKE :t
            OR b.main_phone LIKE :t OR c.email LIKE :t OR c.phone LIKE :t
            OR CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,'')) LIKE :t
         ORDER BY b.name"
    );
    $st->execute([':t' => '%' . $q . '%']);
    $rows = $st->fetchAll();
} else {
    $rows = db()->query("SELECT * FROM businesses ORDER BY name")->fetchAll();
}

function contact_count(int $bid): int {
    $st = db()->prepare('SELECT COUNT(*) FROM contacts WHERE business_id=?');
    $st->execute([$bid]);
    return (int)$st->fetchColumn();
}

layout_header('Customers', 'customers.php');
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 class="page">Customers</h1>
    <p class="sub">Businesses you work with.</p>
  </div>
  <a href="customer_edit.php" class="btn">+ New business</a>
</div>

<div class="card" style="padding:.75rem 1rem">
  <form method="get" style="display:flex;gap:.5rem">
    <input type="text" name="q" placeholder="Search business, contact, city, email, phone…" value="<?= e($q) ?>">
    <button class="btn small">Search</button>
    <?php if ($q): ?><a href="customers.php" class="btn secondary small">Clear</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><?= $q ? 'No businesses match &ldquo;' . e($q) . '&rdquo;.' : 'No businesses yet. Add one, or convert a website request.' ?></div>
  <?php else: ?>
    <table>
      <thead><tr><th>Business</th><th>Vertical</th><th>Location</th><th>Contacts</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $b): ?>
        <tr>
          <td><a href="customer.php?id=<?= (int)$b['id'] ?>"><strong><?= e($b['name']) ?></strong></a><?= $b['main_phone'] ? '<br><span class="muted" style="font-size:.8rem">' . e($b['main_phone']) . '</span>' : '' ?></td>
          <td><?= e($b['vertical']) ?></td>
          <td class="muted" style="font-size:.85rem"><?= e(trim(($b['city'] ?? '') . ($b['state'] ? ', ' . $b['state'] : ''), ', ')) ?></td>
          <td><?= contact_count((int)$b['id']) ?></td>
          <td><a href="customer.php?id=<?= (int)$b['id'] ?>" class="btn secondary small">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
layout_footer();
