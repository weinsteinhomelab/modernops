<?php
/** Admin login. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

// If not set up yet, send to setup.
if (!schema_ready()) { header('Location: setup.php'); exit; }
try {
    if ((int) db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 0) {
        header('Location: setup.php'); exit;
    }
} catch (Exception $ex) { header('Location: setup.php'); exit; }

if (current_user()) { header('Location: index.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $user = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT * FROM admin_users WHERE username = ?');
    $st->execute([$user]);
    $row = $st->fetch();
    if ($row && password_verify($pass, $row['password_hash'])) {
        login_user($row);
        header('Location: index.php');
        exit;
    }
    $err = 'Incorrect username or password.';
}

layout_header('Log in', '');
?>
<h1 class="page">Log in</h1>
<p class="sub">Modern Ops HQ — your private dashboard.</p>
<?php
if (isset($_GET['setup'])) flash('ok', 'Dashboard created. Log in with the account you just made.');
if ($err) flash('err', $err);
?>
<div class="card" style="max-width:380px">
  <form method="post">
    <?= csrf_field() ?>
    <label>Username</label>
    <input type="text" name="username" autocomplete="username" required autofocus>
    <label>Password</label>
    <input type="password" name="password" autocomplete="current-password" required>
    <div style="margin-top:1.25rem"><button class="btn" type="submit">Log in</button></div>
  </form>
</div>
<?php
layout_footer();
