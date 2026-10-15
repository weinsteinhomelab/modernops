<?php
/** Saved line items (service catalog) — add, edit, retire. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_billing.php';
require_login();
require_product('quotes');

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $item = ['title' => $title, 'description' => trim($_POST['description'] ?? ''),
                 'unit_price' => (float)($_POST['unit_price'] ?? 0), 'billing' => billing_clean($_POST['billing'] ?? 'one_time')];
        if ($title === '') {
            // nothing to add
        } elseif (!can('pricing.free')) {
            // Users ask; an admin adds it from the Approvals page.
            approval_request('catalog_add', 'New pricebook item: ' . $title . ' at ' . money($item['unit_price']) . billing_suffix($item['billing']), $item);
            $msg = 'Sent to an admin for approval. It appears here once approved.';
        } else {
            $st = db()->prepare('INSERT INTO catalog_items (title, description, unit_price, billing, sort_order)
                                 VALUES (?,?,?,?, (SELECT COALESCE(MAX(sort_order)+1,0) FROM catalog_items c))');
            $st->execute([$item['title'], $item['description'], $item['unit_price'], $item['billing']]);
            log_activity('catalog.created', 'catalog', (int)db()->lastInsertId(), $title);
            $msg = 'Item added.';
        }
    } elseif ($action === 'toggle') {
        if (!is_admin()) deny('Only admins can retire or restore pricebook items.');
        db()->prepare('UPDATE catalog_items SET active = 1 - active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    } elseif ($action === 'delete') {
        if (!is_admin()) deny('Only admins can delete pricebook items.');
        db()->prepare('DELETE FROM catalog_items WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        $msg = 'Item deleted.';
    }
}

$rows = db()->query('SELECT * FROM catalog_items ORDER BY billing, sort_order, title')->fetchAll();

layout_header('Catalog', 'catalog.php');
?>
<h1 class="page">Service catalog</h1>
<p class="sub">Your reusable line items. Add them to any quote with one click.<?= can('pricing.free') ? '' : ' New items and changes go to an admin for approval.' ?></p>
<?php
if (!is_admin()) {
    $mine = db()->prepare("SELECT summary, created_at FROM approvals WHERE requested_by = ? AND status = 'pending' AND type IN ('catalog_add','catalog_edit') ORDER BY id DESC");
    $mine->execute([me_id()]);
    $mine = $mine->fetchAll();
    if ($mine) {
        echo '<div class="card"><strong>Waiting for approval</strong><ul style="margin:.4rem 0 0 1.2rem;font-size:.9rem">';
        foreach ($mine as $r) echo '<li>' . e($r['summary']) . ' <span class="muted">(' . e(date('M j', strtotime($r['created_at']))) . ')</span></li>';
        echo '</ul></div>';
    }
}
?>
<?php if ($msg) flash('ok', $msg); ?>
<?php if (isset($_GET['asked'])) flash('ok', 'Sent to an admin for approval. The change applies once approved.'); ?>

<div class="card">
  <strong>Add a saved item</strong>
  <form method="post" style="margin-top:.6rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="grid2" style="grid-template-columns:1.4fr 2fr">
      <div><label>Title</label><input type="text" name="title" required placeholder="e.g. Logo Design"></div>
      <div><label>Description</label><input type="text" name="description" placeholder="Short description shown on the quote"></div>
    </div>
    <div class="grid2" style="grid-template-columns:1fr 1fr auto;align-items:end">
      <div><label>Unit price ($)</label><input type="number" step="0.01" min="0" name="unit_price" value="0"></div>
      <div><label>Billing</label><select name="billing"><?= billing_options('one_time') ?></select></div>
      <div><button class="btn" type="submit">Add item</button></div>
    </div>
  </form>
</div>

<div class="card">
  <strong>Saved items</strong>
  <?php if (!$rows): ?>
    <div class="empty" style="padding:1.5rem">No items yet. Add your first above.</div>
  <?php else: ?>
    <table style="margin-top:.6rem">
      <thead><tr><th>Title</th><th>Description</th><th>Price</th><th>Billing</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr style="<?= $c['active'] ? '' : 'opacity:.5' ?>">
          <td><strong><?= e($c['title']) ?></strong></td>
          <td class="muted" style="font-size:.82rem"><?= e($c['description']) ?></td>
          <td><?= money($c['unit_price']) ?><?= e(billing_suffix($c['billing'])) ?></td>
          <td><?= e(billing_label($c['billing'])) ?></td>
          <td><?= $c['active'] ? '<span class="badge won">Active</span>' : '<span class="badge closed">Retired</span>' ?></td>
          <td>
            <div class="row-actions">
              <a href="catalog_edit.php?id=<?= (int)$c['id'] ?>" class="btn secondary small"><?= can('pricing.free') ? 'Edit' : 'Suggest a change' ?></a>
              <?php if (is_admin()): ?>
              <form method="post" onsubmit="return true" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn secondary small"><?= $c['active'] ? 'Retire' : 'Restore' ?></button></form>
              <form method="post" onsubmit="return confirm('Delete this saved item?')" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn danger small">Delete</button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
layout_footer();
