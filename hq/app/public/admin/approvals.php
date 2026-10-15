<?php
/** Approval queue: pricebook additions/edits and quote pricing changes made by users. Admins only. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_billing.php';
require_login();
require_admin();

$flashMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $aid = (int)($_POST['id'] ?? 0);
    $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approved' : 'rejected';
    $note = mb_substr(trim($_POST['note'] ?? ''), 0, 500);
    $st = db()->prepare("SELECT * FROM approvals WHERE id = ? AND status = 'pending'");
    $st->execute([$aid]);
    $a = $st->fetch();
    if ($a) {
        $p = json_decode((string)$a['payload'], true) ?: [];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($decision === 'approved' && $a['type'] === 'catalog_add') {
                $pdo->prepare('INSERT INTO catalog_items (title, description, unit_price, billing, sort_order) VALUES (?,?,?,?, (SELECT COALESCE(MAX(sort_order)+1,0) FROM catalog_items c))')
                    ->execute([$p['title'], $p['description'] ?? '', (float)$p['unit_price'], billing_clean($p['billing'] ?? 'one_time')]);
            } elseif ($decision === 'approved' && $a['type'] === 'catalog_edit') {
                $pdo->prepare('UPDATE catalog_items SET title=?, description=?, unit_price=?, billing=? WHERE id=?')
                    ->execute([$p['title'], $p['description'] ?? '', (float)$p['unit_price'], billing_clean($p['billing'] ?? 'one_time'), (int)$a['catalog_item_id']]);
            } elseif ($a['type'] === 'quote_pricing') {
                // Only if the quote still has exactly the lines that were asked about
                $pdo->prepare("UPDATE quotes SET approval_status = ? WHERE id = ? AND approval_hash = ?")
                    ->execute([$decision, (int)$a['quote_id'], (string)($p['hash'] ?? '')]);
            }
            $pdo->prepare('UPDATE approvals SET status=?, decided_by=?, decided_at=NOW(), decision_note=? WHERE id=?')
                ->execute([$decision, me_id(), $note ?: null, $aid]);
            $pdo->commit();
        } catch (Exception $ex) {
            $pdo->rollBack();
            $decision = '';
        }
        if ($decision) {
            log_activity('approval.' . $decision, 'approval', $aid, $a['summary']);
            $what = $decision === 'approved' ? 'approved' : 'turned down';
            $link = $a['type'] === 'quote_pricing' ? "\n\nOpen the quote: " . hq_url('quote_view.php?id=' . (int)$a['quote_id']) : '';
            notify_user((int)$a['requested_by'], "Your request was $what: " . $a['summary'],
                user_label(me()) . " $what your request:\n\n" . $a['summary'] . ($note ? "\n\nNote: $note" : '') . $link);
            $flashMsg = 'Request ' . $what . '.';
        }
    }
}

$pending = db()->query("SELECT a.*, COALESCE(NULLIF(u.name,''), u.username) AS who, q.quote_number, q.business_id, b.name AS biz
                        FROM approvals a
                        LEFT JOIN admin_users u ON u.id = a.requested_by
                        LEFT JOIN quotes q ON q.id = a.quote_id
                        LEFT JOIN businesses b ON b.id = q.business_id
                        WHERE a.status = 'pending' ORDER BY a.created_at")->fetchAll();
$done = db()->query("SELECT a.*, COALESCE(NULLIF(u.name,''), u.username) AS who, COALESCE(NULLIF(d.name,''), d.username) AS decider
                     FROM approvals a
                     LEFT JOIN admin_users u ON u.id = a.requested_by
                     LEFT JOIN admin_users d ON d.id = a.decided_by
                     WHERE a.status <> 'pending' ORDER BY COALESCE(a.decided_at, a.created_at) DESC LIMIT 40")->fetchAll();
$typeLabel = ['catalog_add' => 'New pricebook item', 'catalog_edit' => 'Pricebook change', 'quote_pricing' => 'Quote pricing'];

layout_header('Approvals', 'approvals.php');
?>
<h1 class="page">Approvals</h1>
<p class="sub">Pricebook additions and price or billing changes from users wait here. A quote can't be sent until its pricing is approved.</p>
<?php if ($flashMsg) flash('ok', $flashMsg); ?>

<?php if (!$pending): ?>
  <div class="card"><div class="empty">Nothing waiting. You're all caught up.</div></div>
<?php endif; ?>
<?php foreach ($pending as $a): $p = json_decode((string)$a['payload'], true) ?: []; ?>
  <div class="card">
    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
      <div>
        <span class="badge quoted"><?= e($typeLabel[$a['type']] ?? $a['type']) ?></span>
        <strong style="margin-left:.4rem"><?= e($a['summary']) ?></strong>
        <div class="muted" style="font-size:.82rem;margin-top:.3rem">From <?= e($a['who'] ?: 'someone') ?> · <?= e(date('M j, g:ia', strtotime($a['created_at']))) ?></div>
      </div>
      <?php if ($a['type'] === 'quote_pricing' && $a['quote_id']): ?><a class="btn secondary small" href="quote_view.php?id=<?= (int)$a['quote_id'] ?>">Open quote</a><?php endif; ?>
    </div>
    <div style="margin-top:.7rem;font-size:.9rem">
      <?php if ($a['type'] === 'quote_pricing'): ?>
        <div class="muted" style="font-size:.82rem">Quote <?= e($a['quote_number']) ?> for <?= e($a['biz']) ?>. Lines that differ from the pricebook:</div>
        <ul style="margin:.3rem 0 0 1.2rem"><?php foreach ((array)($p['diffs'] ?? []) as $d): ?><li><?= e($d) ?></li><?php endforeach; ?></ul>
      <?php else: ?>
        <table style="max-width:640px">
          <tr><td class="muted" style="width:120px">Title</td><td><?= e($p['title'] ?? '') ?><?php if (!empty($p['before']) && ($p['before']['title'] ?? '') !== ($p['title'] ?? '')): ?> <span class="muted">(was <?= e($p['before']['title']) ?>)</span><?php endif; ?></td></tr>
          <tr><td class="muted">Description</td><td><?= e($p['description'] ?? '') ?></td></tr>
          <tr><td class="muted">Price</td><td><strong><?= money($p['unit_price'] ?? 0) . e(billing_suffix($p['billing'] ?? 'one_time')) ?></strong> <?= e(billing_label($p['billing'] ?? 'one_time')) ?><?php if (!empty($p['before'])): ?> <span class="muted">(was <?= money($p['before']['unit_price']) . e(billing_suffix($p['before']['billing'])) ?> <?= e(billing_label($p['before']['billing'])) ?>)</span><?php endif; ?></td></tr>
        </table>
      <?php endif; ?>
    </div>
    <form method="post" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.8rem">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
      <input type="text" name="note" placeholder="Note for <?= e($a['who'] ?: 'them') ?> (optional)" style="flex:1;min-width:220px">
      <button class="btn" name="decision" value="approve">Approve</button>
      <button class="btn danger" name="decision" value="reject">Turn down</button>
    </form>
  </div>
<?php endforeach; ?>

<?php if ($done): ?>
<div class="card">
  <strong>Recent decisions</strong>
  <table style="margin-top:.5rem">
    <thead><tr><th>When</th><th>Request</th><th>From</th><th>Decision</th></tr></thead>
    <tbody>
    <?php foreach ($done as $a): ?>
      <tr>
        <td class="muted" style="font-size:.82rem;white-space:nowrap"><?= e(date('M j, g:ia', strtotime($a['decided_at'] ?: $a['created_at']))) ?></td>
        <td><?= e($a['summary']) ?><?= $a['decision_note'] ? '<br><span class="muted" style="font-size:.82rem">Note: ' . e($a['decision_note']) . '</span>' : '' ?></td>
        <td><?= e($a['who'] ?: '') ?></td>
        <td><span class="badge <?= $a['status'] === 'approved' ? 'won' : ($a['status'] === 'rejected' ? 'declined' : 'closed') ?>"><?= e($a['status']) ?></span><?= $a['decider'] ? '<br><span class="muted" style="font-size:.78rem">by ' . e($a['decider']) . '</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
layout_footer();
