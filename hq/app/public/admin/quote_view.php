<?php
/** View a quote as the customer will see it (mirrors the PDF), with actions. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_billing.php';
require_once __DIR__ . '/_quote_render.php';
require_login();
require_product('quotes');

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare(
    'SELECT q.*, b.name AS biz_name, b.main_email AS biz_email,
            ct.first_name, ct.last_name, ct.email AS contact_email
     FROM quotes q
     JOIN businesses b ON b.id = q.business_id
     LEFT JOIN contacts ct ON ct.id = q.contact_id
     WHERE q.id = ?');
$st->execute([$id]);
$q = $st->fetch();
if (!$q) { http_response_code(404); layout_header('Not found','quotes.php'); echo '<div class="card">Quote not found.</div>'; layout_footer(); exit; }
if (!can_see_quote($q)) deny('This quote belongs to someone else.');

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'delete') {
        // Users can delete their own drafts; admins can delete anything.
        if (!is_admin() && $q['status'] !== 'draft') deny('Only drafts can be deleted. Ask an admin to remove this quote.');
        db()->prepare('DELETE FROM quote_items WHERE quote_id=?')->execute([$id]);
        db()->prepare('DELETE FROM quotes WHERE id=?')->execute([$id]);
        db()->prepare("UPDATE approvals SET status='withdrawn', decided_at=NOW(), decision_note='Quote deleted' WHERE quote_id=? AND status='pending'")->execute([$id]);
        log_activity('quote.deleted', 'quote', 0, 'Quote #' . $q['quote_number']);
        header('Location: quotes.php'); exit;
    } elseif ($a === 'make_link') {
        if (!can('quotes.send')) deny();
        if (!quote_cleared($q) && !is_admin()) { header('Location: quote_view.php?id=' . $id . '&blocked=1'); exit; }
        quote_accept_url($id);
        log_activity('quote.link', 'quote', $id, 'Quote #' . $q['quote_number']);
        header('Location: quote_view.php?id=' . $id); exit;
    } elseif ($a === 'status') {
        $s = $_POST['status'] ?? 'draft';
        if (in_array($s, ['draft','sent','accepted','declined'], true)) {
            db()->prepare('UPDATE quotes SET status=? WHERE id=?')->execute([$s, $id]);
            $q['status'] = $s;
            $flash = 'Status updated.';
            log_activity('quote.status', 'quote', $id, 'Quote #' . $q['quote_number'] . ' → ' . $s);
        }
    }
}

$it = db()->prepare('SELECT * FROM quote_items WHERE quote_id=? ORDER BY sort_order, id');
$it->execute([$id]);
$items = $it->fetchAll();
$allItems = $items;
// Re-read the quote so the link and acceptance fields are current
[$q] = quote_load($id);
$q['biz_email'] = $q['biz_email'] ?? '';
// Optional add-ons are listed separately and never counted in totals.
$optItems = array_filter($items, fn($i) => !empty($i['is_optional']));
$items = array_filter($items, fn($i) => empty($i['is_optional']));
$one = array_filter($items, fn($i) => billing_clean($i['billing']) === 'one_time');
$mo  = array_filter($items, fn($i) => billing_clean($i['billing']) !== 'one_time');
$recTotals = billing_recurring_totals($items);
$recBand = billing_band($recTotals);
$sum = function($rows) { $t = 0; foreach ($rows as $r) $t += (float)$r['quantity'] * (float)$r['unit_price']; return $t; };
$oneSub = $sum($one); $moSub = $sum($mo);
$taxRate = (float)$q['tax_rate'];
$tax = $oneSub * $taxRate / 100;
$oneTotal = $oneSub + $tax;

$preparedFor = trim(($q['first_name'] ?? '') . ' ' . ($q['last_name'] ?? ''));
$bizName = $q['biz_name'];
$toEmail = $q['contact_email'] ?: $q['biz_email'];

layout_header('Quote ' . $q['quote_number'], 'quotes.php');
?>
<p style="margin-bottom:.5rem"><a href="quotes.php">&larr; Quotes</a></p>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 class="page">Quote <?= e($q['quote_number']) ?> · <span class="badge <?= e($q['status']) ?>"><?= e($q['status']) ?></span></h1>
    <p class="sub">For <?= e($bizName) ?><?= $preparedFor ? ' · ' . e($preparedFor) : '' ?></p>
  </div>
  <div class="row-actions">
    <?php if (can('quotes.create')): ?><a href="quote_edit.php?id=<?= $id ?>" class="btn secondary">Edit</a><?php endif; ?>
    <?php $cleared = quote_cleared($q) || is_admin(); ?>
    <?php if (can('quotes.send') && $cleared): ?><a href="quote_pdf.php?id=<?= $id ?>" class="btn secondary" target="_blank">Download PDF</a><?php endif; ?>
    <?php if ($toEmail && can('quotes.send') && $cleared): ?>
      <form method="post" action="quote_send.php" onsubmit="return confirm('Email this quote to <?= e($toEmail) ?>?')" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn">Email to customer</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php if (isset($_GET['saved'])) flash('ok','Quote saved.'); ?>
<?php if (isset($_GET['sent'])) flash('ok','Quote emailed to the customer.'); ?>
<?php if (isset($_GET['mailerr'])) flash('err','Could not send the email. Check SMTP settings, or download the PDF and send it manually.'); ?>
<?php if ($flash) flash('ok', $flash); ?>
<?php if (isset($_GET['blocked'])) flash('err', 'This quote can\'t go to the customer until an admin approves its pricing.'); ?>
<?php
// Approval state for pricing that differs from the pricebook
$appr = null;
if (in_array($q['approval_status'] ?? 'none', ['pending', 'rejected', 'approved'], true)) {
    $as = db()->prepare("SELECT a.*, COALESCE(NULLIF(d.name,''), d.username) AS decider FROM approvals a LEFT JOIN admin_users d ON d.id = a.decided_by
                         WHERE a.quote_id = ? AND a.status <> 'withdrawn' ORDER BY a.id DESC LIMIT 1");
    $as->execute([$id]);
    $appr = $as->fetch() ?: null;
}
$apprDiffs = $appr ? (array)(json_decode((string)$appr['payload'], true)['diffs'] ?? []) : [];
?>
<?php if (($q['approval_status'] ?? '') === 'pending'): ?>
  <div class="notice">
    <strong>Waiting for admin approval.</strong> Some pricing differs from the pricebook, so this quote can't be emailed or shared yet<?= is_admin() ? '' : '. You\'ll get an email when it\'s decided' ?>.
    <?php if ($apprDiffs): ?><ul style="margin:.4rem 0 0 1.2rem;font-size:.9rem"><?php foreach ($apprDiffs as $d): ?><li><?= e($d) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if (is_admin()): ?><div style="margin-top:.6rem"><a class="btn small" href="approvals.php">Review in Approvals</a></div><?php endif; ?>
  </div>
<?php elseif (($q['approval_status'] ?? '') === 'rejected'): ?>
  <div class="notice err">
    <strong>Pricing was turned down<?= $appr && $appr['decider'] ? ' by ' . e($appr['decider']) : '' ?>.</strong>
    <?= $appr && $appr['decision_note'] ? 'Note: ' . e($appr['decision_note']) . '. ' : '' ?>Edit the quote to change the pricing; saving sends it back for approval.
  </div>
<?php elseif (($q['approval_status'] ?? '') === 'approved' && $appr): ?>
  <div class="muted" style="font-size:.85rem;margin:-.4rem 0 1rem">Pricing approved<?= $appr['decider'] ? ' by ' . e($appr['decider']) : '' ?><?= $appr['decided_at'] ? ' on ' . e(date('M j', strtotime($appr['decided_at']))) : '' ?>.</div>
<?php endif; ?>

<div class="card" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
  <form method="post" style="display:flex;gap:.5rem;align-items:center">
    <?= csrf_field() ?><input type="hidden" name="action" value="status">
    <span class="muted" style="font-size:.85rem">Status:</span>
    <select name="status" onchange="this.form.submit()">
      <?php foreach (['draft','sent','accepted','declined'] as $s): ?><option value="<?= $s ?>"<?= $q['status']===$s?' selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php if ($toEmail): ?><span class="muted" style="font-size:.85rem">· Emails to: <?= e($toEmail) ?></span><?php else: ?><span class="muted" style="font-size:.85rem">· No email on file — add a contact email or the business main email to enable emailing.</span><?php endif; ?>
  <?php if (is_admin() || $q['status'] === 'draft'): ?>
  <form method="post" onsubmit="return confirm('Delete this quote permanently?')" style="margin-left:auto">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn danger small">Delete quote</button>
  </form>
  <?php endif; ?>
</div>

<?php
// Internal profit summary (only for lines where our cost was entered); never on the customer copy.
$costed = array_filter($items, fn($i) => $i['unit_cost'] !== null && empty($i['is_section']));
$lineCount = count(array_filter($items, fn($i) => empty($i['is_section'])));
if ($costed && can('quotes.costs')):
    $byP = [];
    foreach ($costed as $r) { $k = billing_clean($r['billing']); $byP[$k][0] = ($byP[$k][0] ?? 0) + (float)$r['quantity'] * (float)$r['unit_cost']; $byP[$k][1] = ($byP[$k][1] ?? 0) + (float)$r['quantity'] * (float)$r['unit_price']; }
    uksort($byP, fn($a, $b) => array_search($a, array_keys(billing_periods())) <=> array_search($b, array_keys(billing_periods())));
?>
<div class="card" style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:center;font-size:.88rem">
  <span class="muted" style="font-size:.78rem">Internal only (not on the customer copy):</span>
  <?php foreach ($byP as $k => [$c, $p]): ?>
    <span><strong><?= e(billing_label($k)) ?>:</strong> our cost <?= money($c) . e(billing_suffix($k)) ?> · profit <strong><?= money($p - $c) . e(billing_suffix($k)) ?></strong><?= $c > 0 ? ' (' . round(($p - $c) / $c * 100) . '%)' : '' ?></span>
  <?php endforeach; ?>
  <?php if (count($costed) < $lineCount): ?><span class="muted" style="font-size:.78rem"><?= $lineCount - count($costed) ?> line(s) have no cost entered.</span><?php endif; ?>
</div>
<?php endif; ?>

<?php
// Customer link + online acceptance
$acceptOn = trim((string) client('quotes.accept_page', '')) !== '' && can('quotes.send');
$link = ($acceptOn && !empty($q['public_token'])) ? quote_accept_url($id) : '';
$chosen = [];
if (!empty($q['accepted_optional'])) {
    $ids = array_map('intval', (array) json_decode((string) $q['accepted_optional'], true));
    foreach ($optItems as $r) if (in_array((int)$r['id'], $ids, true)) $chosen[] = $r['title'];
}
?>
<?php if ($acceptOn): ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
    <strong>Customer link</strong>
    <?php if (!$link): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="make_link"><button class="btn secondary small">Create link</button></form>
    <?php endif; ?>
  </div>
  <?php if ($link): ?>
    <div style="display:flex;gap:.5rem;margin-top:.6rem;flex-wrap:wrap">
      <input type="text" id="qlink" value="<?= e($link) ?>" readonly style="flex:1;min-width:240px;font-size:.85rem">
      <button type="button" class="btn secondary small" onclick="var i=document.getElementById('qlink');i.select();try{navigator.clipboard.writeText(i.value);}catch(e){document.execCommand('copy');}this.textContent='Copied';">Copy</button>
      <a href="<?= e($link) ?>" target="_blank" rel="noopener" class="btn secondary small">Open</a>
    </div>
    <div class="muted" style="font-size:.82rem;margin-top:.5rem">
      The customer can view the quote, choose optional add-ons and accept it online. The link is in the quote email and on the PDF.
      <?= $q['viewed_at'] ? ' First opened ' . e(date('M j, g:ia', strtotime($q['viewed_at']))) . '.' : ' Not opened yet.' ?>
    </div>
  <?php else: ?>
    <div class="muted" style="font-size:.82rem;margin-top:.4rem">A link is created automatically when you email the quote or download the PDF.</div>
  <?php endif; ?>
  <?php if (!empty($q['accepted_at'])): ?>
    <div class="flash ok" style="margin:.8rem 0 0">
      Accepted online by <strong><?= e($q['accepted_name']) ?></strong> on <?= e(date('M j, Y \a\t g:ia', strtotime($q['accepted_at']))) ?>
      <?= $q['accepted_ip'] ? ' · IP ' . e($q['accepted_ip']) : '' ?>.
      <?= $chosen ? '<br>Also chose these optional add-ons: <strong>' . e(implode(', ', $chosen)) . '</strong>. Move them into the quote if you want them in the totals.' : '' ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- The quote as the customer sees it (same layout as the PDF and the accept page) -->
<div class="paper">
  <?= quote_paper_html($q, $allItems, $link) ?>
</div>
<?php
layout_footer();
