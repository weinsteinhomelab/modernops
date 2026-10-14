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
    $st = db()->prepare('UPDATE catalog_items SET title=?, description=?, unit_price=?, billing=? WHERE id=?');
    $st->execute([
        trim($_POST['title'] ?? ''),
        trim($_POST['description'] ?? ''),
        (float)($_POST['unit_price'] ?? 0),
        billing_clean($_POST['billing'] ?? 'one_time'),
        $id,
    ]);
    header('Location: catalog.php'); exit;
}

layout_header('Edit item', 'catalog.php');
?>
<p style="margin-bottom:.5rem"><a href="catalog.php">&larr; Catalog</a></p>
<h1 class="page">Edit saved item</h1>
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
      <button class="btn" type="submit">Save changes</button>
      <a href="catalog.php" class="btn secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
layout_footer();
