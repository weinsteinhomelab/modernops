<?php
/** Add or edit a contact (a person at a business). */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();
require_product('customers', 'quotes');

$id = (int)($_GET['id'] ?? 0);
$businessId = (int)($_GET['business'] ?? 0);
$c = ['id'=>0,'business_id'=>$businessId,'first_name'=>'','last_name'=>'','email'=>'','phone'=>'','title'=>''];
if ($id) {
    $st = db()->prepare('SELECT * FROM contacts WHERE id=?');
    $st->execute([$id]);
    $found = $st->fetch();
    if (!$found) { http_response_code(404); layout_header('Not found','customers.php'); echo '<div class="card">Contact not found.</div>'; layout_footer(); exit; }
    $c = $found; $businessId = (int)$c['business_id'];
}

// Confirm the business exists
$bst = db()->prepare('SELECT id, name FROM businesses WHERE id=?'); $bst->execute([$businessId]); $biz = $bst->fetch();
if (!$biz) { http_response_code(404); layout_header('Not found','customers.php'); echo '<div class="card">Business not found.</div>'; layout_footer(); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    foreach (['first_name','last_name','email','phone','title'] as $f) $c[$f] = trim($_POST[$f] ?? '');
    if ($c['first_name'] === '' && $c['last_name'] === '') {
        $err = 'Enter at least a first or last name.';
    } else {
        if ($id) {
            db()->prepare('UPDATE contacts SET first_name=?,last_name=?,email=?,phone=?,title=? WHERE id=?')
               ->execute([$c['first_name'],$c['last_name'],$c['email'],$c['phone'],$c['title'],$id]);
        } else {
            db()->prepare('INSERT INTO contacts (business_id,first_name,last_name,email,phone,title) VALUES (?,?,?,?,?,?)')
               ->execute([$businessId,$c['first_name'],$c['last_name'],$c['email'],$c['phone'],$c['title']]);
        }
        header('Location: customer.php?id=' . $businessId . '&saved=1'); exit;
    }
}

layout_header($id ? 'Edit contact' : 'New contact', 'customers.php');
?>
<p style="margin-bottom:.5rem"><a href="customer.php?id=<?= $businessId ?>">&larr; <?= e($biz['name']) ?></a></p>
<h1 class="page"><?= $id ? 'Edit contact' : 'New contact' ?></h1>
<p class="sub">At <?= e($biz['name']) ?></p>
<?php if ($err) flash('err', $err); ?>
<div class="card">
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid-auto">
      <div><label>First name</label><input type="text" name="first_name" value="<?= e($c['first_name']) ?>"></div>
      <div><label>Last name</label><input type="text" name="last_name" value="<?= e($c['last_name']) ?>"></div>
      <div><label>Title</label><input type="text" name="title" value="<?= e($c['title']) ?>" placeholder="e.g. Owner, Office Manager"></div>
      <div><label>Email</label><input type="email" name="email" value="<?= e($c['email']) ?>"></div>
      <div><label>Phone</label><input type="text" name="phone" value="<?= e($c['phone']) ?>"></div>
    </div>
    <div class="row-actions" style="margin-top:1.25rem">
      <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Add contact' ?></button>
      <a href="customer.php?id=<?= $businessId ?>" class="btn secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
layout_footer();
