<?php
/** People: everyone who can log in, their role, and what they can do. Admins only. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();
require_admin();

$rows = db()->query(
    "SELECT u.*,
            (SELECT COUNT(*) FROM businesses b WHERE b.assigned_to = u.id) AS customers,
            (SELECT COUNT(*) FROM quotes q WHERE q.created_by = u.id) AS quotes
     FROM admin_users u
     ORDER BY u.active DESC, u.role, COALESCE(NULLIF(u.name,''), u.username)")->fetchAll();
$seats = (int) client('seats', 0);
$activeCount = count(array_filter($rows, fn($r) => (int)$r['active'] === 1));
$perms = perm_list();

layout_header('People', 'users.php');
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 class="page">People</h1>
    <p class="sub">Admins see and do everything. Users see their own customers and quotes, and only what you allow.<?= $seats ? ' Seats: ' . $activeCount . ' of ' . $seats . ' in use.' : '' ?></p>
  </div>
  <a href="user_edit.php" class="btn">+ Add person</a>
</div>
<?php if (isset($_GET['saved'])) flash('ok', 'Saved.'); ?>

<div class="card">
  <table>
    <thead><tr><th>Name</th><th>Role</th><th>Can</th><th>Customers</th><th>Quotes</th><th>Last login</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u):
        $up = db()->prepare('SELECT perm FROM user_permissions WHERE user_id = ?'); $up->execute([(int)$u['id']]); $have = $up->fetchAll(PDO::FETCH_COLUMN);
        $mb = db()->prepare('SELECT COUNT(*) FROM user_mailboxes WHERE user_id = ?'); $mb->execute([(int)$u['id']]); $mbn = (int)$mb->fetchColumn(); ?>
      <tr style="<?= (int)$u['active'] ? '' : 'opacity:.55' ?>">
        <td><strong><?= e(user_label($u)) ?></strong><?= (int)$u['id'] === me_id() ? ' <span class="muted">(you)</span>' : '' ?><br><span class="muted" style="font-size:.82rem"><?= e($u['username']) ?><?= $u['email'] ? ' · ' . e($u['email']) : '' ?></span></td>
        <td>
          <span class="badge <?= $u['role'] === 'admin' ? 'won' : 'sent' ?>"><?= e($u['role']) ?></span>
          <?= (int)$u['active'] ? '' : '<br><span class="badge closed" style="margin-top:.3rem">off</span>' ?>
        </td>
        <td style="font-size:.82rem">
          <?php if ($u['role'] === 'admin'): ?><span class="muted">Everything</span>
          <?php else: ?>
            <?= $have ? e(implode(', ', array_map(fn($p) => $perms[$p][0] ?? $p, $have))) : '<span class="muted">View only</span>' ?>
            <br><span class="muted"><?= $mbn ? $mbn . ' mailbox' . ($mbn === 1 ? '' : 'es') : 'No mailboxes' ?></span>
          <?php endif; ?>
        </td>
        <td><a href="customers.php?owner=<?= (int)$u['id'] ?>"><?= (int)$u['customers'] ?></a></td>
        <td><?= (int)$u['quotes'] ?></td>
        <td class="muted" style="font-size:.82rem"><?= $u['last_login_at'] ? e(date('M j, g:ia', strtotime($u['last_login_at']))) : 'Never' ?></td>
        <td><a href="user_edit.php?id=<?= (int)$u['id'] ?>" class="btn secondary small">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
layout_footer();
