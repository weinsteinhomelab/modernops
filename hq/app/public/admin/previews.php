<?php
/** Every demo and live website across the customers this person can see: online and local demos side by side. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_sites.php';
require_login();
require_product('customers', 'quotes');

[$bs, $ba] = biz_scope('b');
$rows = [];
try {
    $st = db()->prepare("SELECT s.*, b.name AS bname, (SELECT COUNT(*) FROM site_files f WHERE f.site_id = s.id) AS pics
                         FROM sites s JOIN businesses b ON b.id = s.business_id
                         WHERE $bs ORDER BY s.kind = 'live', s.updated_at DESC");
    $st->execute($ba);
    $rows = $st->fetchAll();
} catch (Exception $ex) {}
$short = fn(string $u) => preg_replace('#^https?://#', '', rtrim($u, '/'));

layout_header('Previews', 'previews.php');
?>
<h1 class="page">Previews</h1>
<p class="sub">Demo websites for customers, with the online and the locally hosted version next to each other. Add them on a customer's page under Websites.</p>
<div class="card">
  <?php if (!$rows): ?>
    <div class="muted">No demos yet. Open a customer and add one under Websites.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table>
    <thead><tr><th>Customer</th><th></th><th>Online demo</th><th>Local demo</th><th>Pictures</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $live = $r['kind'] === 'live'; ?>
      <tr>
        <td><a href="customer.php?id=<?= (int)$r['business_id'] ?>#websites"><strong><?= e($r['bname']) ?></strong></a><?php if ($r['label']): ?><br><span class="muted" style="font-size:.85rem"><?= e($r['label']) ?></span><?php endif; ?></td>
        <td><span class="badge <?= $live ? 'won' : 'quoted' ?>"><?= $live ? 'Live' : 'Demo' ?></span></td>
        <td><?php if ($r['url']): ?><a href="<?= e($r['url']) ?>" target="_blank" rel="noopener" style="word-break:break-all"><?= e($short($r['url'])) ?> &#8599;</a><?php else: ?><span class="muted">Not set</span><?php endif; ?></td>
        <td><?php if (!$live && !empty($r['local_url'])): ?><a href="<?= e($r['local_url']) ?>" target="_blank" rel="noopener" style="word-break:break-all"><?= e($short($r['local_url'])) ?> &#8599;</a><?php else: ?><span class="muted"><?= $live ? '—' : 'Not set' ?></span><?php endif; ?></td>
        <td><?= (int)$r['pics'] ?: '<span class="muted">0</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted" style="font-size:.82rem;margin:.8rem 0 0">Local demos only open on the office network.</p>
  <?php endif; ?>
</div>
<?php layout_footer();
