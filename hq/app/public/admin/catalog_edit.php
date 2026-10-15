<?php
/** Edit a saved catalog item. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_billing.php';
require_login();
require_product('quotes');

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM catalog_items WHERE id = ?');
$st->execute([$id]);
$c = $st->fetch();
if (!$c) { http_response_code(404); layout_header('Not found','catalog.php'); echo '<div class="card">Item not found. <a href="catalog.php">Back</a></div>'; layout_footer(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $new = ['title' => trim($_POST['title'] ?? ''), 'description' => trim($_POST['description'] ?? ''),
            'unit_price' => (float)($_POST['unit_price'] ?? 0), 'billing' => billing_clean($_POST['billing'] ?? 'one_time')];
    if (!can('pricing.free')) {
        // Users suggest; an admin applies it from the Approvals page.
        $new['before'] = ['title' => $c['title'], 'description' => $c['description'], 'unit_price' => (float)$c['unit_price'], 'billing' => $c['billing']];
        approval_request('catalog_edit', 'Pricebook change: ' . $c['title'] . ' → ' . $new['title'] . ' at ' . money($new['unit_price']) . billing_suffix($new['billing']), $new, 0, $id);
        header('Location: catalog.php?asked=1'); exit;
    }
    $st = db()->prepare('UPDATE catalog_items SET title=?, description=?, unit_price=?, billing=? WHERE id=?');
    $st->execute([$new['title'], $new['description'], $new['unit_price'], $new['billing'], $id]);
    log_activity('catalog.updated', 'catalog', $id, $new['title'] . ' ' . money($new['unit_price']) . billing_suffix($new['billing']));
    header('Location: catalog.php'); exit;
}

layout_header('Edit item', 'catalog.php');
?>
<p style="margin-bottom:.5rem"><a href="catalog.php">&larr; Catalog</a></p>
<h1 class="page"><?= can('pricing.free') ? 'Edit saved item' : 'Suggest a change' ?></h1>
<p class="sub">&nbsp;</p>
<div class="card">
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid-auto">
      <div><label>Title</label><input type="text" name="title" required value="<?= e($c['title']) ?>"></div>
      <div class="span-2"><label>Description</label><input type="text" name="description" value="<?= e($c['description']) ?>"></div>
      <div><label>Unit price ($)</label><input type="number" step="0.01" min="0" name="unit_price" value="<?= e($c['unit_price']) ?>"></div>
      <div><label>Billing</label><select name="billing"><?= billing_options((string)$c['billing']) ?></select></div>
    </div>
    <div class="row-actions" style="margin-top:1.25rem">
      <button class="btn" type="submit"><?= can('pricing.free') ? 'Save changes' : 'Send for approval' ?></button>
      <a href="catalog.php" class="btn secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
layout_footer();
