<?php
/** Activity log: who did what. Admins only. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();
require_admin();

$who = (int)($_GET['user'] ?? 0);
$sql = "SELECT a.*, COALESCE(NULLIF(u.name,''), u.username) AS person FROM activity a LEFT JOIN admin_users u ON u.id = a.user_id"
     . ($who ? ' WHERE a.user_id = ?' : '') . ' ORDER BY a.id DESC LIMIT 300';
$st = db()->prepare($sql);
$st->execute($who ? [$who] : []);
$rows = $st->fetchAll();

$labels = [
    'login' => 'Logged in', 'password.changed' => 'Changed their password',
    'user.created' => 'Added a person', 'user.updated' => 'Updated a person',
    'customer.created' => 'Added a customer', 'customer.updated' => 'Edited a customer', 'customer.assigned' => 'Assigned a customer',
    'request.converted' => 'Turned a request into a customer',
    'quote.created' => 'Created a quote', 'quote.updated' => 'Edited a quote', 'quote.sent' => 'Emailed a quote',
    'quote.deleted' => 'Deleted a quote', 'quote.status' => 'Changed a quote status', 'quote.link' => 'Created a customer link',
    'quote.accepted' => 'Customer accepted a quote',
    'catalog.created' => 'Added a pricebook item', 'catalog.updated' => 'Changed a pricebook item',
    'approval.requested' => 'Asked for approval', 'approval.approved' => 'Approved a request', 'approval.rejected' => 'Turned down a request',
];
$links = ['quote' => 'quote_view.php?id=', 'customer' => 'customer.php?id=', 'user' => 'user_edit.php?id=', 'request' => 'request.php?id='];

layout_header('Activity', 'activity.php');
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <div><h1 class="page">Activity</h1><p class="sub">The last 300 things people did in HQ.</p></div>
  <form method="get"><select name="user" onchange="this.form.submit()"><option value="">Everyone</option>
    <?php foreach (people(false) as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $who === (int)$p['id'] ? ' selected' : '' ?>><?= e(user_label($p)) ?></option><?php endforeach; ?>
  </select></form>
</div>
<div class="card">
  <?php if (!$rows): ?><div class="empty">Nothing yet. Actions show up here as people use HQ.</div><?php else: ?>
  <table>
    <thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $href = isset($links[$r['entity']]) && $r['entity_id'] ? $links[$r['entity']] . (int)$r['entity_id'] : ''; ?>
      <tr>
        <td class="muted" style="font-size:.82rem;white-space:nowrap"><?= e(date('M j, g:ia', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['person'] ?: ($r['action'] === 'quote.accepted' ? 'Customer' : '')) ?></td>
        <td><?= e($labels[$r['action']] ?? $r['action']) ?></td>
        <td style="font-size:.85rem"><?= $href ? '<a href="' . e($href) . '">' . e($r['detail'] ?: 'Open') . '</a>' : e($r['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php
layout_footer();
