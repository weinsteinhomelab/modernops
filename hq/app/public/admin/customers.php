<?php
/** Businesses list + search (the primary customer record). */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_verticals.php';
require_login();
require_product('customers', 'quotes');

$q = trim($_GET['q'] ?? '');
$vf = trim($_GET['vertical'] ?? '');
$owner = (string)($_GET['owner'] ?? '');   // admins: a person's id, or 'none' for unassigned
[$scope, $args] = biz_scope('b');
$where = [$scope];
if ($vf !== '') { $where[] = 'b.vertical = ?'; $args[] = $vf; }
if (is_admin() && $owner === 'none') { $where[] = 'b.assigned_to IS NULL'; }
elseif (is_admin() && ctype_digit($owner)) { $where[] = 'b.assigned_to = ?'; $args[] = (int)$owner; }
if ($q !== '') {
    $where[] = "(b.name LIKE ? OR b.vertical LIKE ? OR b.city LIKE ? OR b.main_email LIKE ? OR b.main_phone LIKE ?
                 OR c.email LIKE ? OR c.phone LIKE ? OR CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,'')) LIKE ?)";
    array_push($args, ...array_fill(0, 8, '%' . $q . '%'));
}
$st = db()->prepare("SELECT DISTINCT b.*, COALESCE(NULLIF(u.name,''), u.username) AS owner_name
                     FROM businesses b
                     LEFT JOIN contacts c ON c.business_id = b.id
                     LEFT JOIN admin_users u ON u.id = b.assigned_to
                     WHERE " . implode(' AND ', $where) . " ORDER BY b.name");
$st->execute($args);
$rows = $st->fetchAll();

function contact_count(int $bid): int {
    $st = db()->prepare('SELECT COUNT(*) FROM contacts WHERE business_id=?');
    $st->execute([$bid]);
    return (int)$st->fetchColumn();
}

function site_counts(int $bid): array {
    $out = ['demo' => 0, 'live' => 0];
    try {
        $st = db()->prepare('SELECT kind, COUNT(*) n FROM sites WHERE business_id=? GROUP BY kind');
        $st->execute([$bid]);
        foreach ($st->fetchAll() as $r) $out[$r['kind']] = (int)$r['n'];
    } catch (Exception $ex) {}
    return $out;
}

layout_header('Customers', 'customers.php');
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 class="page">Customers</h1>
    <p class="sub"><?= is_admin() ? 'Every business, and who looks after each one.' : 'The businesses assigned to you.' ?></p>
  </div>
  <?php if (can('customers.edit')): ?><a href="customer_edit.php" class="btn">+ New business</a><?php endif; ?>
</div>

<div class="card" style="padding:.75rem 1rem">
  <form method="get" style="display:flex;gap:.5rem">
    <input type="text" name="q" placeholder="Search business, contact, city, email, phone…" value="<?= e($q) ?>">
    <button class="btn small">Search</button>
  </form>
  <form method="get" style="display:flex;gap:.5rem;margin-top:.5rem">
    <?= vertical_select('vertical', $vf, 'All industries', 'onchange="this.form.submit()"') ?>
    <?php if (is_admin()): ?><select name="owner" onchange="this.form.submit()" style="max-width:220px"><option value="">Everyone's customers</option><option value="none"<?= $owner === 'none' ? ' selected' : '' ?>>Unassigned</option><?php foreach (people() as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $owner === (string)$p['id'] ? ' selected' : '' ?>><?= e(user_label($p)) ?></option><?php endforeach; ?></select><?php endif; ?>
    <?php if ($q || $vf || $owner !== ''): ?><a href="customers.php" class="btn secondary small">Clear</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><?= $q ? 'No businesses match &ldquo;' . e($q) . '&rdquo;.' : ($vf ? 'No ' . e($vf) . ' businesses yet.' : (is_admin() || $owner !== '' ? 'No businesses yet. Add one, or convert a website request.' : 'No customers are assigned to you yet. An admin can assign them.')) ?></div>
  <?php else: ?>
    <table>
      <thead><tr><th>Business</th><th>Vertical</th><th>Location</th><th>Contacts</th><th>Websites</th><?= is_admin() ? '<th>Owner</th>' : '' ?><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $b): ?>
        <tr>
          <td><a href="customer.php?id=<?= (int)$b['id'] ?>"><strong><?= e($b['name']) ?></strong></a><?= $b['main_phone'] ? '<br><span class="muted" style="font-size:.8rem">' . e($b['main_phone']) . '</span>' : '' ?></td>
          <td><?= e($b['vertical']) ?></td>
          <td class="muted" style="font-size:.85rem"><?= e(trim(($b['city'] ?? '') . ($b['state'] ? ', ' . $b['state'] : ''), ', ')) ?></td>
          <td><?= contact_count((int)$b['id']) ?></td>
          <td style="font-size:.8rem"><?php $sc = site_counts((int)$b['id']); ?><?= $sc['live'] ? '<span class="badge won">' . $sc['live'] . ' live</span> ' : '' ?><?= $sc['demo'] ? '<span class="badge quoted">' . $sc['demo'] . ' demo</span>' : '' ?><?= !$sc['live'] && !$sc['demo'] ? '<span class="muted">—</span>' : '' ?></td>
          <?php if (is_admin()): ?><td style="font-size:.85rem"><?= $b['owner_name'] ? e($b['owner_name']) : '<span class="muted">Unassigned</span>' ?></td><?php endif; ?>
          <td><a href="customer.php?id=<?= (int)$b['id'] ?>" class="btn secondary small">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
layout_footer();
