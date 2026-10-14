<?php
/** Add or edit a business. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$b = ['id'=>0,'name'=>'','vertical'=>'','main_phone'=>'','main_email'=>'','address'=>'','address2'=>'','city'=>'','state'=>'','country'=>'United States','zip'=>'','notes'=>''];
if ($id) {
    $st = db()->prepare('SELECT * FROM businesses WHERE id = ?');
    $st->execute([$id]);
    $found = $st->fetch();
    if ($found) $b = $found;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    foreach (['name','vertical','main_phone','main_email','address','address2','city','state','country','zip','notes'] as $f) {
        $b[$f] = trim($_POST[$f] ?? '');
    }
    if ($b['name'] === '') {
        $err = 'A business name is required.';
    } else {
        $cols = ['name','vertical','main_phone','main_email','address','address2','city','state','country','zip','notes'];
        $vals = array_map(fn($c) => $b[$c], $cols);
        if ($id) {
            $set = implode(',', array_map(fn($c) => "$c=?", $cols));
            $vals[] = $id;
            db()->prepare("UPDATE businesses SET $set WHERE id=?")->execute($vals);
            header('Location: customer.php?id=' . $id . '&saved=1'); exit;
        } else {
            $ph = implode(',', array_fill(0, count($cols), '?'));
            db()->prepare("INSERT INTO businesses (" . implode(',', $cols) . ") VALUES ($ph)")->execute($vals);
            header('Location: customer.php?id=' . (int)db()->lastInsertId() . '&saved=1'); exit;
        }
    }
}

layout_header($id ? 'Edit business' : 'New business', 'customers.php');
?>
<p style="margin-bottom:.5rem"><a href="<?= $id ? 'customer.php?id='.$id : 'customers.php' ?>">&larr; Back</a></p>
<h1 class="page"><?= $id ? 'Edit business' : 'New business' ?></h1>
<p class="sub">&nbsp;</p>
<?php if ($err) flash('err', $err); ?>
<div class="card" style="max-width:680px">
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid2">
      <div><label>Business name *</label><input type="text" name="name" required value="<?= e($b['name']) ?>"></div>
      <div><label>Vertical / industry</label><input type="text" name="vertical" value="<?= e($b['vertical']) ?>" placeholder="e.g. Restaurant, Contractor"></div>
    </div>
    <div class="grid2">
      <div><label>Main number</label><input type="text" name="main_phone" value="<?= e($b['main_phone']) ?>"></div>
      <div><label>Main email</label><input type="email" name="main_email" value="<?= e($b['main_email']) ?>"></div>
    </div>
    <label>Address</label>
    <input type="text" name="address" value="<?= e($b['address']) ?>">
    <label>Address 2</label>
    <input type="text" name="address2" value="<?= e($b['address2']) ?>" placeholder="Suite, unit, floor (optional)">
    <div class="grid2">
      <div><label>City</label><input type="text" name="city" value="<?= e($b['city']) ?>"></div>
      <div><label>State</label><input type="text" name="state" value="<?= e($b['state']) ?>"></div>
    </div>
    <div class="grid2">
      <div><label>Zip code</label><input type="text" name="zip" value="<?= e($b['zip']) ?>"></div>
      <div><label>Country</label><input type="text" name="country" value="<?= e($b['country']) ?>"></div>
    </div>
    <label>Notes</label>
    <textarea name="notes" rows="4"><?= e($b['notes']) ?></textarea>
    <div class="row-actions" style="margin-top:1.25rem">
      <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Create business' ?></button>
      <a href="<?= $id ? 'customer.php?id='.$id : 'customers.php' ?>" class="btn secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
layout_footer();
