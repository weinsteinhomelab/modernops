<?php
/** Your own account: name, email, password. Everyone can use this. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$m = me();
$err = ''; $ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $cur   = (string)($_POST['current'] ?? '');
    $new   = (string)($_POST['new'] ?? '');
    $new2  = (string)($_POST['new2'] ?? '');
    $first = !empty($m['must_change_pw']);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'That email address doesn\'t look right.';
    } elseif ($first && $new === '') {
        $err = 'Please choose your own password to continue.';
    } elseif ($new !== '' && !password_verify($cur, $m['password_hash'])) {
        $err = 'Your current password isn\'t right.';
    } elseif ($new !== '' && strlen($new) < 8) {
        $err = 'Choose a password of at least 8 characters.';
    } elseif ($new !== $new2) {
        $err = 'The two new passwords don\'t match.';
    } else {
        db()->prepare('UPDATE admin_users SET name=?, email=? WHERE id=?')->execute([$name ?: null, $email ?: null, me_id()]);
        if ($new !== '') {
            db()->prepare('UPDATE admin_users SET password_hash=?, must_change_pw=0 WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), me_id()]);
            log_activity('password.changed', 'user', me_id());
            if ($first) { header('Location: index.php'); exit; }
        }
        header('Location: account.php?saved=1'); exit;
    }
}

layout_header('Your account', 'account.php');
$perms = perm_list();
?>
<h1 class="page">Your account</h1>
<p class="sub"><?= is_admin() ? 'You are an admin.' : 'You are a user.' ?> Logged in as <?= e($m['username']) ?>.</p>
<?php if (isset($_GET['first']) || !empty($m['must_change_pw'])) flash('err', 'Welcome! Please choose your own password before you start.'); ?>
<?php if (isset($_GET['saved'])) flash('ok', 'Saved.'); ?>
<?php if ($err) flash('err', $err); ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <strong>Your details</strong>
    <div class="grid-auto">
      <div><label>Name</label><input type="text" name="name" value="<?= e($_POST['name'] ?? $m['name']) ?>"></div>
      <div><label>Email (for approval notices)</label><input type="email" name="email" value="<?= e($_POST['email'] ?? $m['email']) ?>"></div>
    </div>
  </div>
  <div class="card">
    <strong>Change password</strong>
    <div class="grid-auto">
      <div><label>Current password</label><input type="password" name="current" autocomplete="current-password"></div>
      <div><label>New password</label><input type="password" name="new" autocomplete="new-password"></div>
      <div><label>New password again</label><input type="password" name="new2" autocomplete="new-password"></div>
    </div>
  </div>
  <div class="row-actions"><button class="btn" type="submit">Save</button></div>
</form>

<div class="card" style="margin-top:1.25rem">
  <strong>Email signature</strong>
  <p class="muted" style="font-size:.9rem;margin-bottom:.8rem">Make an HTML signature with your details, photo and logo, then copy it into Gmail, Outlook or Apple Mail<?= product_on('inbox') ? ' — it can also go on emails you send from the Inbox' : '' ?>.</p>
  <a class="btn secondary" href="signature.php">Edit your email signature</a>
</div>

<?php if (!is_admin()): ?>
<div class="card" style="margin-top:1.25rem">
  <strong>What you can do</strong>
  <ul style="margin:.5rem 0 0 1.2rem;font-size:.9rem">
    <li>See the customers assigned to you and the quotes you made.</li>
    <?php foreach ($perms as $k => [$label]): if (can($k)): ?><li><?= e($label) ?></li><?php endif; endforeach; ?>
    <?php if (!can('pricing.free')): ?><li>New pricebook items and price or billing changes go to an admin for approval.</li><?php endif; ?>
    <?php $mb = my_mailboxes(); if ($mb): ?><li>Inbox: <?= e(implode(', ', $mb)) ?></li><?php endif; ?>
  </ul>
</div>
<?php endif; ?>
<?php
layout_footer();
