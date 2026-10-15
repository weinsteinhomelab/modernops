<?php
/** Add or edit a person: role, login, permissions, mailboxes and customers. Admins only. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();
require_admin();

$id = (int)($_GET['id'] ?? 0);
$u = ['id' => 0, 'username' => '', 'name' => '', 'email' => '', 'role' => 'user', 'active' => 1, 'must_change_pw' => 0];
$have = []; $boxes = [];
foreach (perm_list() as $k => $p) if ($p[2]) $have[] = $k;   // defaults for a new user
if ($id) {
    $st = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) { http_response_code(404); layout_header('Not found', 'users.php'); echo '<div class="card">Person not found. <a href="users.php">Back</a></div>'; layout_footer(); exit; }
    $p = db()->prepare('SELECT perm FROM user_permissions WHERE user_id = ?'); $p->execute([$id]); $have = $p->fetchAll(PDO::FETCH_COLUMN);
    $m = db()->prepare('SELECT mailbox FROM user_mailboxes WHERE user_id = ?'); $m->execute([$id]); $boxes = $m->fetchAll(PDO::FETCH_COLUMN);
}
$allBoxes = array_values(array_filter(array_map('trim', (array) client('email.accounts', []))));
$customers = db()->query("SELECT b.id, b.name, b.assigned_to, COALESCE(NULLIF(u.name,''), u.username) AS owner
                          FROM businesses b LEFT JOIN admin_users u ON u.id = b.assigned_to ORDER BY b.name")->fetchAll();

$activeAdmins = (int) db()->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND active = 1")->fetchColumn();
$seats = (int) client('seats', 0);
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim($_POST['username'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role     = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $active   = isset($_POST['active']) ? 1 : 0;
    $pass     = (string)($_POST['password'] ?? '');
    $mustChange = isset($_POST['must_change_pw']) ? 1 : 0;
    $newPerms = array_values(array_intersect(array_keys(perm_list()), (array)($_POST['perms'] ?? [])));
    $newBoxes = array_values(array_intersect($allBoxes, (array)($_POST['mailboxes'] ?? [])));
    $assign   = array_map('intval', (array)($_POST['customers'] ?? []));
    $wasActiveAdmin = $id && $u['role'] === 'admin' && (int)$u['active'] === 1;
    $activeOthers = (int) db()->query('SELECT COUNT(*) FROM admin_users WHERE active = 1' . ($id ? ' AND id <> ' . (int)$id : ''))->fetchColumn();

    if (!preg_match('/^[A-Za-z0-9._@-]{3,80}$/', $username)) {
        $err = 'Username: 3 to 80 letters, numbers, dots, dashes, @ or underscores.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'That email address doesn\'t look right.';
    } elseif (!$id && strlen($pass) < 8) {
        $err = 'Give them a temporary password of at least 8 characters.';
    } elseif ($id && $pass !== '' && strlen($pass) < 8) {
        $err = 'New password must be at least 8 characters.';
    } elseif ($id === me_id() && (!$active || $role !== 'admin')) {
        $err = 'You can\'t turn off or demote your own account. Ask another admin.';
    } elseif ($wasActiveAdmin && $activeAdmins <= 1 && ($role !== 'admin' || !$active)) {
        $err = 'This is the only active admin. Make someone else an admin first.';
    } elseif ($seats && $active && $activeOthers + 1 > $seats && (!$id || !(int)$u['active'])) {
        $err = 'All ' . $seats . ' seats are in use. Turn someone off or add a seat first.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare('UPDATE admin_users SET username=?, name=?, email=?, role=?, active=? WHERE id=?')
                    ->execute([$username, $name ?: null, $email ?: null, $role, $active, $id]);
                if ($pass !== '') {
                    $pdo->prepare('UPDATE admin_users SET password_hash=?, must_change_pw=? WHERE id=?')
                        ->execute([password_hash($pass, PASSWORD_DEFAULT), $mustChange, $id]);
                }
            } else {
                $pdo->prepare('INSERT INTO admin_users (username, password_hash, role, name, email, active, must_change_pw) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $role, $name ?: null, $email ?: null, $active, $mustChange]);
                $id = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM user_mailboxes WHERE user_id = ?')->execute([$id]);
            if ($role === 'user') {
                $ins = $pdo->prepare('INSERT INTO user_permissions (user_id, perm) VALUES (?, ?)');
                foreach ($newPerms as $p) $ins->execute([$id, $p]);
                $insb = $pdo->prepare('INSERT INTO user_mailboxes (user_id, mailbox) VALUES (?, ?)');
                foreach ($newBoxes as $b) $insb->execute([$id, $b]);
            }
            // Customers: ticked ones are assigned to this person; unticked ones they had become unassigned (admins still see them).
            $pdo->prepare('UPDATE businesses SET assigned_to = NULL WHERE assigned_to = ?' . ($assign ? ' AND id NOT IN (' . implode(',', array_fill(0, count($assign), '?')) . ')' : ''))
                ->execute(array_merge([$id], $assign));
            if ($assign) {
                $pdo->prepare('UPDATE businesses SET assigned_to = ? WHERE id IN (' . implode(',', array_fill(0, count($assign), '?')) . ')')
                    ->execute(array_merge([$id], $assign));
            }
            $pdo->commit();
            log_activity($u['id'] ? 'user.updated' : 'user.created', 'user', $id, "$username ($role" . ($active ? '' : ', off') . ')');
            header('Location: users.php?saved=1'); exit;
        } catch (Exception $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $err = $ex->getCode() === '23000' ? 'That username is already taken.' : 'Could not save. Please try again.';
        }
    }
    // Re-show what they typed
    $u = array_merge($u, ['username' => $username, 'name' => $name, 'email' => $email, 'role' => $role, 'active' => $active]);
    $have = $newPerms; $boxes = $newBoxes;
    foreach ($customers as &$c) $c['assigned_to'] = in_array((int)$c['id'], $assign, true) ? ($id ?: -1) : ($c['assigned_to'] == $id ? null : $c['assigned_to']);
    unset($c);
}

layout_header($id ? 'Edit ' . user_label($u) : 'Add person', 'users.php');
?>
<p style="margin-bottom:.5rem"><a href="users.php">&larr; People</a></p>
<h1 class="page"><?= $id ? 'Edit ' . e(user_label($u)) : 'Add person' ?></h1>
<p class="sub"><?= $id && $u['last_login_at'] ? 'Last login ' . e(date('M j, Y g:ia', strtotime($u['last_login_at']))) . '.' : ($id ? 'Hasn\'t logged in yet.' : 'They log in at this same address with the username and temporary password you set.') ?></p>
<?php if ($err) flash('err', $err); ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <strong>Login</strong>
    <div class="grid-auto">
      <div><label>Name</label><input type="text" name="name" value="<?= e($u['name']) ?>" placeholder="e.g. Jamie Smith"></div>
      <div><label>Username *</label><input type="text" name="username" value="<?= e($u['username']) ?>" required autocomplete="off"></div>
      <div><label>Email (for approval notices)</label><input type="email" name="email" value="<?= e($u['email']) ?>"></div>
      <div><label><?= $id ? 'New password (leave blank to keep)' : 'Temporary password *' ?></label>
        <div style="display:flex;gap:.4rem"><input type="text" name="password" id="pw" autocomplete="new-password" <?= $id ? '' : 'required' ?>><button type="button" class="btn secondary small" onclick="var a='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789',s='',r=new Uint32Array(14);crypto.getRandomValues(r);for(var i=0;i<14;i++)s+=a[r[i]%a.length];document.getElementById('pw').value=s;">Make one</button></div>
      </div>
    </div>
    <label style="margin-top:.8rem"><input type="checkbox" name="must_change_pw" value="1" <?= ($id ? '' : 'checked') ?> style="width:auto;margin-right:.4rem">Make them choose their own password the first time they log in</label>
    <div class="grid-auto" style="margin-top:.4rem">
      <div><label>Role</label>
        <select name="role" id="role">
          <option value="user"<?= $u['role'] === 'user' ? ' selected' : '' ?>>User</option>
          <option value="admin"<?= $u['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
        </select>
        <div class="muted" style="font-size:.78rem;margin-top:.3rem">User: their own customers and quotes. Admin: everything, plus people and approvals.</div>
      </div>
      <div><label>Status</label><label style="margin:.6rem 0 0;font-weight:400;color:var(--text)"><input type="checkbox" name="active" value="1" <?= (int)$u['active'] ? 'checked' : '' ?> style="width:auto;margin-right:.4rem">Can log in</label></div>
    </div>
  </div>

  <div class="card user-only">
    <strong>What they can do</strong>
    <p class="muted" style="font-size:.85rem;margin-top:.2rem">Users always see only the customers assigned to them and the quotes they made. Without "Change prices without approval", new pricebook items and any price or billing that differs from the pricebook wait for an admin to approve before the quote can be sent.</p>
    <?php foreach (perm_list() as $k => [$label, $help]): ?>
      <label style="display:flex;gap:.6rem;align-items:flex-start;margin:.7rem 0 0;font-weight:400;color:var(--text)">
        <input type="checkbox" name="perms[]" value="<?= e($k) ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?> style="width:auto;margin-top:.25rem">
        <span><strong><?= e($label) ?></strong><br><span class="muted" style="font-size:.82rem"><?= e($help) ?></span></span>
      </label>
    <?php endforeach; ?>
  </div>

  <?php if (product_on('inbox') && $allBoxes): ?>
  <div class="card user-only">
    <strong>Inbox access</strong>
    <p class="muted" style="font-size:.85rem;margin-top:.2rem">The mailboxes they can read and send from. Quotes are always emailed from <?= e(client('email.quote_from')) ?>.</p>
    <div class="grid-auto">
      <?php foreach ($allBoxes as $b): ?>
        <label style="margin:.6rem 0 0;font-weight:400;color:var(--text)"><input type="checkbox" name="mailboxes[]" value="<?= e($b) ?>" <?= in_array($b, $boxes, true) ? 'checked' : '' ?> style="width:auto;margin-right:.4rem"><?= e($b) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
      <strong>Customers assigned to them</strong>
      <input type="text" id="cfilter" placeholder="Filter customers" style="max-width:260px">
    </div>
    <p class="muted" style="font-size:.85rem;margin-top:.2rem">Tick to assign. A customer has one owner, so ticking one here moves it from whoever has it now.</p>
    <?php if (!$customers): ?><div class="empty" style="padding:1rem">No customers yet.</div><?php endif; ?>
    <div class="grid-auto" id="clist">
      <?php foreach ($customers as $c): $mine = $id && (int)$c['assigned_to'] === (int)$id; ?>
        <label data-name="<?= e(mb_strtolower($c['name'])) ?>" style="margin:.5rem 0 0;font-weight:400;color:var(--text)">
          <input type="checkbox" name="customers[]" value="<?= (int)$c['id'] ?>" <?= $mine ? 'checked' : '' ?> style="width:auto;margin-right:.4rem"><?= e($c['name']) ?>
          <?php if (!$mine && $c['owner']): ?><span class="muted" style="font-size:.78rem"> · <?= e($c['owner']) ?></span><?php elseif (!$c['owner']): ?><span class="muted" style="font-size:.78rem"> · unassigned</span><?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="row-actions">
    <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Add person' ?></button>
    <a href="users.php" class="btn secondary">Cancel</a>
  </div>
</form>
<script>
  (function(){
    var role = document.getElementById('role');
    function sync(){ document.querySelectorAll('.user-only').forEach(function(c){ c.style.display = role.value === 'admin' ? 'none' : ''; }); }
    role.addEventListener('change', sync); sync();
    var f = document.getElementById('cfilter');
    f.addEventListener('input', function(){ var t=f.value.toLowerCase(); document.querySelectorAll('#clist label').forEach(function(l){ l.style.display = l.dataset.name.indexOf(t) >= 0 ? '' : 'none'; }); });
  })();
</script>
<?php
layout_footer();
