<?php
/** First-run setup: create tables and the single admin login. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

// If an admin already exists, setup is locked.
if (schema_ready()) {
    try {
        $n = (int) db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        if ($n > 0) {
            layout_header('Setup', '');
            echo '<h1 class="page">Setup complete</h1>';
            echo '<div class="card">This dashboard is already set up. <a href="login.php">Go to login &rarr;</a></div>';
            layout_footer();
            exit;
        }
    } catch (Exception $ex) { /* fall through to setup */ }
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $user = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if ($user === '' || strlen($user) < 3) {
        $err = 'Choose a username of at least 3 characters.';
    } elseif (strlen($pass) < 8) {
        $err = 'Choose a password of at least 8 characters.';
    } elseif ($pass !== $pass2) {
        $err = 'The two passwords do not match.';
    } else {
        try {
            // Create tables
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') db()->exec($stmt);
            }
            // Create admin
            $st = db()->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)');
            $st->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
            // Seed a starting quote number
            set_setting('next_quote_number', '1001');
            header('Location: login.php?setup=1');
            exit;
        } catch (Exception $ex) {
            $err = 'Could not create the database tables. Check that the DB user has full privileges. (' . e($ex->getMessage()) . ')';
        }
    }
}

layout_header('Setup', '');
?>
<h1 class="page">Welcome — let's set up your dashboard</h1>
<p class="sub">This one-time step creates your database tables and your private login. It locks itself once done.</p>
<?php if ($err) flash('err', $err); ?>
<div class="card" style="max-width:460px">
  <form method="post">
    <?= csrf_field() ?>
    <label>Choose a username</label>
    <input type="text" name="username" autocomplete="username" required value="<?= e($_POST['username'] ?? '') ?>">
    <label>Choose a password (8+ characters)</label>
    <input type="password" name="password" autocomplete="new-password" required>
    <label>Confirm password</label>
    <input type="password" name="password2" autocomplete="new-password" required>
    <div style="margin-top:1.25rem"><button class="btn" type="submit">Create my dashboard</button></div>
  </form>
</div>
<?php
layout_footer();
