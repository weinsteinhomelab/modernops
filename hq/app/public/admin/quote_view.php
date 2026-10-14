<?php
/** View a quote as the customer will see it (mirrors the PDF), with actions. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_billing.php';
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

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'delete') {
        db()->prepare('DELETE FROM quote_items WHERE quote_id=?')->execute([$id]);
        db()->prepare('DELETE FROM quotes WHERE id=?')->execute([$id]);
        header('Location: quotes.php'); exit;
    } elseif ($a === 'status') {
        $s = $_POST['status'] ?? 'draft';
        if (in_array($s, ['draft','sent','accepted','declined'], true)) {
            db()->prepare('UPDATE quotes SET status=? WHERE id=?')->execute([$s, $id]);
            $q['status'] = $s;
            $flash = 'Status updated.';
        }
    }
}

$it = db()->prepare('SELECT * FROM quote_items WHERE quote_id=? ORDER BY sort_order, id');
$it->execute([$id]);
$items = $it->fetchAll();
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
    <a href="quote_edit.php?id=<?= $id ?>" class="btn secondary">Edit</a>
    <a href="quote_pdf.php?id=<?= $id ?>" class="btn secondary" target="_blank">Download PDF</a>
    <?php if ($toEmail): ?>
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

<div class="card" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
  <form method="post" style="display:flex;gap:.5rem;align-items:center">
    <?= csrf_field() ?><input type="hidden" name="action" value="status">
    <span class="muted" style="font-size:.85rem">Status:</span>
    <select name="status" onchange="this.form.submit()">
      <?php foreach (['draft','sent','accepted','declined'] as $s): ?><option value="<?= $s ?>"<?= $q['status']===$s?' selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php if ($toEmail): ?><span class="muted" style="font-size:.85rem">· Emails to: <?= e($toEmail) ?></span><?php else: ?><span class="muted" style="font-size:.85rem">· No email on file — add a contact email or the business main email to enable emailing.</span><?php endif; ?>
  <form method="post" onsubmit="return confirm('Delete this quote permanently?')" style="margin-left:auto">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn danger small">Delete quote</button>
  </form>
</div>

<?php
// Internal profit summary (only for lines where our cost was entered); never on the customer copy.
$costed = array_filter($items, fn($i) => $i['unit_cost'] !== null && empty($i['is_section']));
$lineCount = count(array_filter($items, fn($i) => empty($i['is_section'])));
if ($costed):
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

<!-- Paper preview (mirrors the PDF) -->
<?php $deep = e(brand_color('deep', '<?= $deep ?>')); $tint = e(brand_color('tint', '<?= $tint ?>')); ?>
<div class="card" style="background:#fff;color:#1a1a1a;max-width:780px;margin:0 auto;padding:2.2rem 2.4rem;font-family:Arial,Helvetica,sans-serif">
  <div style="font-weight:800;letter-spacing:.14em;color:<?= $deep ?>;font-size:1.3rem"><?= e(mb_strtoupper((string) client('name'))) ?></div>
  <div style="color:#555;font-size:.72rem;margin:.25rem 0 1.1rem;border-bottom:2px solid <?= $deep ?>;padding-bottom:.8rem"><?= implode(' &nbsp;|&nbsp; ', array_map('e', array_filter([client('tagline'), client('domain'), client('location')]))) ?></div>
  <div style="color:<?= $deep ?>;font-size:1.1rem;letter-spacing:.04em;margin:.3rem 0 1rem;font-weight:700">QUOTE</div>
  <div style="font-size:.82rem;line-height:1.9;margin-bottom:1rem">
    <?php if ($preparedFor): ?><div><b style="color:<?= $deep ?>;display:inline-block;width:110px">Prepared for:</b> <?= e($preparedFor) ?></div><?php endif; ?>
    <div><b style="color:<?= $deep ?>;display:inline-block;width:110px">Business:</b> <?= e($bizName) ?></div>
    <div><b style="color:<?= $deep ?>;display:inline-block;width:110px">Quote date:</b> <?= e(date('M j, Y', strtotime($q['issue_date']))) ?></div>
    <div><b style="color:<?= $deep ?>;display:inline-block;width:110px">Quote #:</b> <?= e($q['quote_number']) ?></div>
  </div>

  <?php if (trim((string)$q['scope_of_work']) !== ''): ?>
    <div style="background:<?= $tint ?>;color:<?= $deep ?>;font-weight:700;font-size:.72rem;letter-spacing:.05em;padding:.4rem .6rem">SCOPE OF WORK</div>
    <div style="font-size:.8rem;color:#333;padding:.6rem .2rem;white-space:pre-wrap"><?= e($q['scope_of_work']) ?></div>
  <?php endif; ?>

  <?php if ($one): ?>
    <div style="background:<?= $tint ?>;color:<?= $deep ?>;font-weight:700;font-size:.72rem;letter-spacing:.05em;padding:.4rem .6rem;margin-top:.8rem">ONE-TIME SERVICES</div>
    <table style="width:100%;border-collapse:collapse">
      <?php foreach ($one as $r): $lt=(float)$r['quantity']*(float)$r['unit_price']; ?>
        <?php if (!empty($r['is_section'])): ?><tr><td colspan="2" style="padding:.8rem .6rem .3rem;font-weight:700;color:<?= $deep ?>;font-size:.82rem;border-bottom:1.5px solid <?= $deep ?>"><?= e($r['title']) ?></td></tr><?php continue; endif; ?>
        <tr><td style="padding:.5rem .6rem;border-bottom:1px solid #ddd"><div style="font-weight:700"><?= e($r['title']) ?><?= (float)$r['quantity']>1 ? ' ×'.rtrim(rtrim(number_format((float)$r['quantity'],2),'0'),'.') : '' ?></div><div style="color:#555;font-size:.76rem"><?= e($r['description']) ?></div></td><td style="padding:.5rem .6rem;border-bottom:1px solid #ddd;text-align:right;font-weight:700;white-space:nowrap"><?= money($lt) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($mo): ?>
    <div style="background:<?= $tint ?>;color:<?= $deep ?>;font-weight:700;font-size:.72rem;letter-spacing:.05em;padding:.4rem .6rem;margin-top:1rem"><?= e($recBand) ?></div>
    <table style="width:100%;border-collapse:collapse">
      <?php foreach ($mo as $r): $lt=(float)$r['quantity']*(float)$r['unit_price']; ?>
        <?php if (!empty($r['is_section'])): ?><tr><td colspan="2" style="padding:.8rem .6rem .3rem;font-weight:700;color:<?= $deep ?>;font-size:.82rem;border-bottom:1.5px solid <?= $deep ?>"><?= e($r['title']) ?></td></tr><?php continue; endif; ?>
        <tr><td style="padding:.5rem .6rem;border-bottom:1px solid #ddd"><div style="font-weight:700"><?= e($r['title']) ?></div><div style="color:#555;font-size:.76rem"><?= e($r['description']) ?></div></td><td style="padding:.5rem .6rem;border-bottom:1px solid #ddd;text-align:right;font-weight:700;white-space:nowrap"><?= money($lt) ?><?= e(billing_suffix($r['billing'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <table style="width:100%;margin-top:1rem;font-size:.85rem"><tr>
    <td style="text-align:right;padding:.2rem .6rem;color:#555">Subtotal</td><td style="text-align:right;padding:.2rem .6rem;width:120px"><?= money($oneSub) ?></td>
  </tr>
  <?php if ($taxRate > 0): ?><tr><td style="text-align:right;padding:.2rem .6rem;color:#555"><?= e($q['tax_label']) ?> (<?= rtrim(rtrim(number_format($taxRate,3),'0'),'.') ?>%)</td><td style="text-align:right;padding:.2rem .6rem"><?= money($tax) ?></td></tr><?php endif; ?>
  </table>

  <div style="display:flex;justify-content:flex-end;gap:0;margin-top:.8rem;flex-wrap:wrap">
    <?php if ($one || $taxRate>0): ?><div style="background:<?= $tint ?>;color:<?= $deep ?>;padding:.7rem 1.1rem;font-weight:700;font-size:.8rem;text-align:center;min-width:150px">ONE-TIME TOTAL<br><?= money($oneTotal) ?></div><?php endif; ?>
    <?php foreach ($recTotals as $bk => $bt): ?><div style="background:<?= $deep ?>;color:#fff;padding:.7rem 1.1rem;font-weight:700;font-size:.8rem;text-align:center;min-width:150px;border-left:1px solid #2a5a45"><?= e(billing_periods()[$bk][3]) ?><br><?= money($bt) . e(billing_suffix($bk)) ?></div><?php endforeach; ?>
  </div>

  <?php if ($optItems): ?>
    <div style="background:<?= $tint ?>;color:<?= $deep ?>;font-weight:700;font-size:.72rem;letter-spacing:.05em;padding:.4rem .6rem;margin-top:1.4rem">OPTIONAL ADD-ONS</div>
    <div style="font-size:.74rem;color:#555;padding:.4rem .6rem 0">Not included in the totals above. Let us know if you would like to add any of these.</div>
    <table style="width:100%;border-collapse:collapse">
      <?php foreach ($optItems as $r): $lt=(float)$r['quantity']*(float)$r['unit_price']; ?>
        <?php if (!empty($r['is_section'])): ?><tr><td colspan="2" style="padding:.8rem .6rem .3rem;font-weight:700;color:<?= $deep ?>;font-size:.82rem;border-bottom:1.5px solid <?= $deep ?>"><?= e($r['title']) ?></td></tr><?php continue; endif; ?>
        <tr><td style="padding:.5rem .6rem;border-bottom:1px solid #ddd"><div style="font-weight:700"><?= e($r['title']) ?><?= (float)$r['quantity']>1 ? ' ×'.rtrim(rtrim(number_format((float)$r['quantity'],2),'0'),'.') : '' ?></div><div style="color:#555;font-size:.76rem"><?= e($r['description']) ?></div></td><td style="padding:.5rem .6rem;border-bottom:1px solid #ddd;text-align:right;font-weight:700;white-space:nowrap"><?= money($lt) ?><?= e(billing_suffix($r['billing'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if (trim((string)$q['notes']) !== ''): ?>
    <div style="margin-top:1.4rem;font-size:.78rem;color:#333"><div style="color:<?= $deep ?>;font-weight:700;letter-spacing:.05em;margin-bottom:.3rem">NOTES</div><div style="white-space:pre-wrap"><?= e($q['notes']) ?></div></div>
  <?php endif; ?>
  <?php if ($q['terms_included']): ?><div style="margin-top:.8rem;font-size:.74rem;color:#777">Terms &amp; Conditions and a signature page are included in the PDF.</div><?php endif; ?>
  <div style="text-align:center;color:#777;font-style:italic;font-size:.74rem;margin-top:1.4rem;border-top:1px solid #eee;padding-top:1rem"><?= e(client('quotes.thank_you')) ?><br>Questions? Contact us at <?= e(client('email.contact')) ?></div>
</div>
<?php
layout_footer();
