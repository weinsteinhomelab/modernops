<?php
/** Add or edit a customer. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$c = ['id'=>0,'name'=>'','business'=>'','email'=>'','phone'=>'','address'=>'','notes'=>''];
if ($id) {
    $st = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $st->execute([$id]);
    $found = $st->fetch();
    if ($found) $c = $found;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $c['name']     = trim($_POST['name'] ?? '');
    $c['business'] = trim($_POST['business'] ?? '');
    $c['email']    = trim($_POST['email'] ?? '');
    $c['phone']    = trim($_POST['phone'] ?? '');
    $c['address']  = trim($_POST['address'] ?? '');
    $c['notes']    = trim($_POST['notes'] ?? '');

    if ($c['name'] === '') {
        $err = 'A name is required.';
    } else {
        if ($id) {
            $st = db()->prepare('UPDATE customers SET name=?,business=?,email=?,phone=?,address=?,notes=? WHERE id=?');
            $st->execute([$c['name'],$c['business'],$c['email'],$c['phone'],$c['address'],$c['notes'],$id]);
            header('Location: customer.php?id=' . $id . '&saved=1'); exit;
        } else {
            $st = db()->prepare('INSERT INTO customers (name,business,email,phone,address,notes) VALUES (?,?,?,?,?,?)');
            $st->execute([$c['name'],$c['business'],$c['email'],$c['phone'],$c['address'],$c['notes']]);
            header('Location: customer.php?id=' . (int)db()->lastInsertId() . '&saved=1'); exit;
        }
    }
}

layout_header($id ? 'Edit customer' : 'New customer', 'customers.php');
?>
<p style="margin-bottom:.5rem"><a href="<?= $id ? 'customer.php?id='.$id : 'customers.php' ?>">&larr; Back</a></p>
<h1 class="page"><?= $id ? 'Edit customer' : 'New customer' ?></h1>
<p class="sub">&nbsp;</p>
<?php if ($err) flash('err', $err); ?>
<div class="card" style="max-width:620px">
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid2">
      <div><label>Name *</label><input type="text" name="name" required value="<?= e($c['name']) ?>"></div>
      <div><label>Business</label><input type="text" name="business" value="<?= e($c['business']) ?>"></div>
    </div>
    <div class="grid2">
      <div><label>Email</label><input type="email" name="email" value="<?= e($c['email']) ?>"></div>
      <div><label>Phone</label><input type="text" name="phone" value="<?= e($c['phone']) ?>"></div>
    </div>
    <label>Address</label>
    <input type="text" name="address" value="<?= e($c['address']) ?>">
    <label>Notes</label>
    <textarea name="notes" rows="4"><?= e($c['notes']) ?></textarea>
    <div style="margin-top:1.25rem" class="row-actions">
      <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Create customer' ?></button>
      <a href="<?= $id ? 'customer.php?id='.$id : 'customers.php' ?>" class="btn secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
layout_footer();
