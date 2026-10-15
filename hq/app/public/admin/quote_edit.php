<?php
/** Build or edit a quote. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_billing.php';
require_login();
require_product('quotes');
require_perm('quotes.create');

$id = (int)($_GET['id'] ?? 0);
$quote = null;
$items = [];
if ($id) {
    $st = db()->prepare('SELECT * FROM quotes WHERE id = ?');
    $st->execute([$id]);
    $quote = $st->fetch();
    if (!$quote) { http_response_code(404); layout_header('Not found','quotes.php'); echo '<div class="card">Quote not found.</div>'; layout_footer(); exit; }
    if (!can_see_quote($quote)) deny('This quote belongs to someone else.');
    $it = db()->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY sort_order, id');
    $it->execute([$id]);
    $items = $it->fetchAll();
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $business_id = (int)($_POST['business_id'] ?? 0);
    $contact_id = (int)($_POST['contact_id'] ?? 0) ?: null;
    $quote_number = trim($_POST['quote_number'] ?? '');
    $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
    $valid_until = $_POST['valid_until'] ?? null;
    $tax_rate = (float)($_POST['tax_rate'] ?? 0);
    $tax_label = trim($_POST['tax_label'] ?? 'Sales Tax');
    $scope = trim($_POST['scope_of_work'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $terms_included = isset($_POST['terms_included']) ? 1 : 0;
    $status = $_POST['status'] ?? 'draft';
    if (!in_array($status, ['draft','sent','accepted','declined'], true)) $status = 'draft';

    $titles  = $_POST['item_title']   ?? [];
    $descs   = $_POST['item_desc']    ?? [];
    $qtys    = $_POST['item_qty']     ?? [];
    $prices  = $_POST['item_price']   ?? [];
    $bills   = $_POST['item_billing'] ?? [];
    $costs   = $_POST['item_cost']    ?? [];
    $margins = $_POST['item_margin']  ?? [];
    $secs    = $_POST['item_section'] ?? [];
    $opts    = $_POST['item_optional'] ?? [];

    if (!$business_id) {
        $err = 'Please choose a business.';
    } elseif (!can_see_business_id($business_id)) {
        $err = 'Choose one of your customers.';
    } elseif ($quote_number === '') {
        $err = 'Please enter a quote number.';
    } else {
        // People who can't see costs never send them; keep what was there before, matched by line title.
        $keepCost = [];
        if (!can('quotes.costs')) foreach ($items as $old) $keepCost[mb_strtolower(trim($old['title']))] = [$old['unit_cost'], $old['margin']];
        try {
            $pdo = db();
            $pdo->beginTransaction();
            if ($id) {
                $u = $pdo->prepare('UPDATE quotes SET business_id=?, contact_id=?, quote_number=?, issue_date=?, valid_until=?, status=?, tax_rate=?, tax_label=?, scope_of_work=?, notes=?, terms_included=? WHERE id=?');
                $u->execute([$business_id,$contact_id,$quote_number,$issue_date,$valid_until ?: null,$status,$tax_rate,$tax_label,$scope,$notes,$terms_included,$id]);
                $pdo->prepare('DELETE FROM quote_items WHERE quote_id = ?')->execute([$id]);
                $qid = $id;
            } else {
                $ins = $pdo->prepare('INSERT INTO quotes (created_by, business_id, contact_id, quote_number, issue_date, valid_until, status, tax_rate, tax_label, scope_of_work, notes, terms_included) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $ins->execute([me_id(), $business_id,$contact_id,$quote_number,$issue_date,$valid_until ?: null,$status,$tax_rate,$tax_label,$scope,$notes,$terms_included]);
                $qid = (int)$pdo->lastInsertId();
                // advance the auto quote number if this used the current default
                if ($quote_number === (string)setting('next_quote_number')) {
                    set_setting('next_quote_number', (string)((int)$quote_number + 1));
                }
            }
            $li = $pdo->prepare('INSERT INTO quote_items (quote_id, title, description, quantity, unit_price, unit_cost, margin, billing, is_section, is_optional, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $n = count($titles);
            $order = 0;
            for ($i = 0; $i < $n; $i++) {
                $t = trim((string)$titles[$i]);
                if ($t === '') continue;
                $cost = trim((string)($costs[$i] ?? '')); $mg = trim((string)($margins[$i] ?? ''));
                if (!can('quotes.costs')) {
                    [$cost, $mg] = array_map(fn($v) => $v === null ? '' : (string)$v, $keepCost[mb_strtolower($t)] ?? [null, null]);
                }
                $opt = empty($opts[$i]) ? 0 : 1;
                if (!empty($secs[$i])) {
                    // Section heading: title only; billing just records which area (one-time or recurring) it sits in.
                    $li->execute([$qid, $t, '', 0, 0, null, null, billing_clean($bills[$i] ?? 'one_time') === 'one_time' ? 'one_time' : 'monthly', 1, $opt, $order++]);
                    continue;
                }
                $li->execute([
                    $qid, $t, trim((string)($descs[$i] ?? '')),
                    (float)($qtys[$i] ?? 1), (float)($prices[$i] ?? 0),
                    $cost === '' ? null : (float)$cost, $mg === '' ? null : (float)$mg,
                    billing_clean($bills[$i] ?? 'one_time'),
                    0,
                    $opt,
                    $order++,
                ]);
            }
            $pdo->commit();
            log_activity($id ? 'quote.updated' : 'quote.created', 'quote', $qid, 'Quote #' . $quote_number);
            quote_after_save($qid);
            header('Location: quote_view.php?id=' . $qid . '&saved=1');
            exit;
        } catch (Exception $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $err = 'Could not save the quote. ' . ($ex->getCode() === '23000' ? 'That quote number is already used.' : '');
        }
    }
}

[$bs, $ba] = biz_scope('b');
$st = db()->prepare("SELECT b.id, b.name FROM businesses b WHERE $bs ORDER BY b.name");
$st->execute($ba);
$businesses = $st->fetchAll();
$canCosts = can('quotes.costs');
$st = db()->prepare("SELECT c.id, c.business_id, TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) AS name, c.title
                     FROM contacts c JOIN businesses b ON b.id = c.business_id WHERE $bs ORDER BY c.id");
$st->execute($ba);
$allContacts = $st->fetchAll();
$catalog = db()->query("SELECT title, description, unit_price, billing FROM catalog_items WHERE active=1 ORDER BY billing, sort_order, title")->fetchAll();

$preBusiness = (int)($_GET['business'] ?? ($quote['business_id'] ?? 0));
$preContact  = (int)($quote['contact_id'] ?? 0);
$defNumber = $quote['quote_number'] ?? (string)setting('next_quote_number', (string) client('quotes.first_number', '1001'));
$defIssue  = $quote['issue_date'] ?? date('Y-m-d');
$defValid  = $quote['valid_until'] ?? date('Y-m-d', strtotime('+' . (int) client('quotes.valid_days', 30) . ' days'));
$defTaxRate = $quote['tax_rate'] ?? client('quotes.tax_rate', '0');
$defTaxLabel = $quote['tax_label'] ?? client('quotes.tax_label', 'Sales Tax');
$defScope = $quote['scope_of_work'] ?? '';
$defNotes = $quote['notes'] ?? (string) client('quotes.notes', '');
$termsChecked = !isset($quote['terms_included']) || $quote['terms_included'] ? 'checked' : '';

layout_header($id ? 'Edit quote' : 'New quote', 'quotes.php');
?>
<p style="margin-bottom:.5rem"><a href="quotes.php">&larr; Quotes</a></p>
<h1 class="page"><?= $id ? 'Edit quote' : 'New quote' ?></h1>
<p class="sub">Add saved items or custom lines. Totals update as you go.</p>
<?php if ($err) flash('err', $err); ?>

<form method="post" id="quoteForm">
  <?= csrf_field() ?>
  <div class="card">
    <div class="grid3" style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:.75rem">
      <div>
        <label>Business</label>
        <select name="business_id" id="businessSel" required>
          <option value="">— choose —</option>
          <?php foreach ($businesses as $bz): ?>
            <option value="<?= (int)$bz['id'] ?>"<?= $preBusiness === (int)$bz['id'] ? ' selected' : '' ?>><?= e($bz['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$businesses): ?><div class="muted" style="font-size:.78rem;margin-top:.3rem">No businesses yet — <a href="customer_edit.php">add one</a> first.</div><?php endif; ?>
      </div>
      <div><label>Prepared for (contact)</label><select name="contact_id" id="contactSel"><option value="">— optional —</option></select></div>
      <div><label>Quote #</label><input type="text" name="quote_number" value="<?= e($defNumber) ?>" required></div>
    </div>
    <div class="grid3" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
      <div><label>Quote date</label><input type="date" name="issue_date" value="<?= e($defIssue) ?>"></div>
      <div><label>Valid until</label><input type="date" name="valid_until" value="<?= e($defValid) ?>"></div>
      <div><label>Status</label><select name="status"><?php foreach (['draft','sent','accepted','declined'] as $s): ?><option value="<?= $s ?>"<?= ($quote['status'] ?? 'draft')===$s?' selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
    </div>
  </div>

  <div class="card">
    <strong>Scope of work</strong>
    <label>Describe the work this quote covers (appears on the quote)</label>
    <textarea name="scope_of_work" rows="3" placeholder="e.g. Design and build a 5-page mobile-friendly website, configure hosting and SSL, set up a business email account, and submit the Google Business Profile."><?= e($defScope) ?></textarea>
  </div>

  <div class="card">
    <strong>Line items</strong>
    <div class="row-actions" style="justify-content:space-between;margin-top:.2rem">
      <span class="muted" style="font-size:.85rem">Add items from your pricebook, a custom line, or a section heading. Drag or use the arrows to reorder.</span>
      <button type="button" class="btn" id="openPicker">+ Add to quote</button>
    </div>
    <style>
      .line-row input,.line-row select,.sec-row input{padding:.45rem .5rem;font-size:.9rem;min-width:0}
      .line-row [name="item_desc[]"]{grid-column:2/-3;font-size:.82rem}
      .line-row .optbtn{grid-column:-3/-2;justify-self:end;background:transparent;border:1px solid var(--border);border-radius:6px;color:var(--muted);font-size:.72rem;padding:.25rem .5rem;cursor:pointer;white-space:nowrap}
      .line-row .optbtn:hover{color:var(--accent);border-color:var(--accent)}
      .zone-h{margin-top:1.2rem;font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--accent)}
      .zone-h span{font-weight:400;text-transform:none;letter-spacing:0;color:var(--muted)}
      .zone:empty::before{content:"Nothing here yet.";display:block;padding:.7rem 0;color:var(--muted);font-size:.85rem}
      .mv{display:flex;flex-direction:column;align-items:center;gap:0;line-height:1}
      .mv .grip{cursor:grab;color:var(--muted);font-size:1rem;padding:0 .15rem;user-select:none}
      .mv button{background:transparent;border:none;color:var(--muted);cursor:pointer;font-size:.7rem;padding:.05rem .3rem}
      .mv button:hover,.mv .grip:hover{color:var(--accent)}
      .line-row.dragging,.sec-row.dragging{opacity:.4}
      .sec-row input[name="item_title[]"]{font-weight:700;background:var(--bg,transparent)}
    </style>
    <div class="zone-h">One-time <span>— shown first on the quote</span></div>
    <div class="lhead"><span></span><span>Item</span><span style="text-align:center">Qty</span><?= $canCosts ? '<span>Our cost</span><span>Margin %</span>' : '' ?><span>Total cost</span><span>Billing</span><span style="text-align:right">Line total</span><span></span></div>
    <div class="zone" id="zoneOne" data-zone="one_time"></div>

    <div class="zone-h">Recurring <span>— always shown at the bottom of the quote</span></div>
    <div class="lhead"><span></span><span>Item</span><span style="text-align:center">Qty</span><?= $canCosts ? '<span>Our cost</span><span>Margin %</span>' : '' ?><span>Total cost</span><span>Billing</span><span style="text-align:right">Line total</span><span></span></div>
    <div class="zone" id="zoneRec" data-zone="monthly"></div>

    <div class="zone-h">Optional add-ons <span>— listed at the end of the quote, not counted in the totals</span></div>
    <div class="lhead"><span></span><span>Item</span><span style="text-align:center">Qty</span><?= $canCosts ? '<span>Our cost</span><span>Margin %</span>' : '' ?><span>Total cost</span><span>Billing</span><span style="text-align:right">Line total</span><span></span></div>
    <div class="zone" id="zoneOpt" data-zone="optional"></div>

    <!-- Totals: one row, no boxes or lines. Our cost and Profit are internal only. -->
    <div class="qtotals">
      <div><span class="l">One-time</span><div class="n" id="tOne">$0.00</div></div>
      <div><span class="l">Tax</span><div class="n" id="tTax">$0.00</div></div>
      <div><span class="l">One-time total</span><div class="n" id="tOneTotal">$0.00</div></div>
      <div><span class="l">Recurring</span><div class="n" id="tMo">$0.00/mo</div></div>
      <div><span class="l">Optional <em>not in totals</em></span><div class="n" id="tOpt" style="color:var(--muted)">$0.00</div></div>
      <div<?= $canCosts ? '' : ' hidden' ?>><span class="l">Our cost <em>internal</em></span><div class="n" id="tCost" style="color:var(--muted)">$0.00</div></div>
      <div<?= $canCosts ? '' : ' hidden' ?>><span class="l">Profit <em>internal</em></span><div class="n" id="tProfit">$0.00</div></div>
    </div>
    <style>
      .qtotals{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:1rem 1.5rem;margin-top:1.4rem;align-items:start}
      .qtotals .l{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:.3rem}
      .qtotals .l em{font-style:normal;text-transform:none;letter-spacing:0;opacity:.75}
      .qtotals .n{font-family:Georgia,serif;font-size:1.2rem;font-weight:700;color:var(--accent);line-height:1.35}
    </style>
  </div>

  <div class="card">
    <strong>Tax</strong>
    <div class="grid3" style="display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:.75rem">
      <div><label>Quick preset</label><select id="taxPreset"></select></div>
      <div><label>Rate (%)</label><input type="number" step="0.001" min="0" name="tax_rate" id="taxRate" value="<?= e($defTaxRate) ?>"></div>
      <div><label>Label (shown on quote)</label><input type="text" name="tax_label" id="taxLabel" value="<?= e($defTaxLabel) ?>"></div>
    </div>
    <div class="muted" style="font-size:.78rem;margin-top:.3rem">Tax applies to the taxable subtotal. Set rate to 0 to hide the tax line.</div>
  </div>

  <div class="card">
    <strong>Notes &amp; terms</strong>
    <label>Notes (shown on the quote)</label>
    <textarea name="notes" rows="2"><?= e($defNotes) ?></textarea>
    <label style="margin-top:.6rem"><input type="checkbox" name="terms_included" value="1" <?= $termsChecked ?> style="width:auto;margin-right:.4rem">Include Terms &amp; Conditions + signature page in the PDF</label>
  </div>

  <div class="row-actions">
    <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Create quote' ?></button>
    <a href="<?= $id ? 'quote_view.php?id='.$id : 'quotes.php' ?>" class="btn secondary">Cancel</a>
  </div>
</form>

<!-- Pricebook picker: one place to add anything to the quote -->
<dialog id="picker" aria-labelledby="pickerTitle">
  <div class="pk-head">
    <strong id="pickerTitle" style="font-family:Georgia,serif;font-size:1.15rem">Add to quote</strong>
    <button type="button" class="pk-x" id="pickerClose" aria-label="Close">×</button>
  </div>
  <div class="pk-target">
    <span class="muted" style="font-size:.85rem">Add to:</span>
    <label><input type="radio" name="pk_target" value="quote" checked> The quote</label>
    <label><input type="radio" name="pk_target" value="optional"> Optional add-ons</label>
  </div>
  <input type="search" id="pkSearch" placeholder="Search the pricebook" autocomplete="off">
  <div id="pkList" class="pk-list"></div>
  <div class="pk-foot">
    <button type="button" class="btn secondary small" id="pkCustom">+ New line item</button>
    <span class="pk-sec">
      <button type="button" class="btn secondary small" id="pkSection">+ Section heading</button>
      <select id="pkSecZone" style="width:auto;padding:.3rem .5rem;font-size:.82rem"><option value="one_time">in One-time</option><option value="monthly">in Recurring</option></select>
    </span>
    <button type="button" class="btn small" id="pkDone" style="margin-left:auto">Done</button>
  </div>
</dialog>
<style>
  #picker{border:0;padding:0;margin:auto;width:min(760px,94vw);max-height:86vh;background:var(--surface);color:var(--text);box-shadow:0 20px 60px rgba(0,0,0,.35);border-radius:10px}
  #picker::backdrop{background:rgba(0,0,0,.45)}
  #picker[open]{display:flex;flex-direction:column}
  .pk-head{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem .4rem}
  .pk-x{background:transparent;border:0;color:var(--muted);font-size:1.5rem;cursor:pointer;line-height:1}
  .pk-target{display:flex;gap:1rem;align-items:center;padding:0 1.25rem .6rem;flex-wrap:wrap}
  .pk-target label{margin:0;display:flex;gap:.35rem;align-items:center;font-weight:500;color:var(--text);font-size:.9rem}
  .pk-target input{width:auto}
  #pkSearch{margin:0 1.25rem;width:calc(100% - 2.5rem)}
  .pk-list{overflow:auto;padding:.4rem 1.25rem;flex:1;min-height:120px}
  .pk-group{font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--accent);margin:.9rem 0 .2rem}
  .pk-item{display:grid;grid-template-columns:1fr auto auto;gap:.8rem;align-items:center;width:100%;text-align:left;background:transparent;border:0;border-bottom:1px solid var(--border);padding:.6rem .2rem;color:var(--text);cursor:pointer;font:inherit}
  .pk-item:hover{background:color-mix(in srgb,var(--accent) 8%,transparent)}
  .pk-item .d{display:block;color:var(--muted);font-size:.8rem}
  .pk-item .p{font-weight:700;white-space:nowrap}
  .pk-item .ok{font-size:.75rem;color:var(--accent);font-weight:700;min-width:4.5rem;text-align:right}
  .pk-empty{color:var(--muted);padding:1rem 0;font-size:.9rem}
  .pk-foot{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;padding:.8rem 1.25rem 1rem;border-top:1px solid var(--border)}
  .pk-sec{display:flex;gap:.4rem;align-items:center}
</style>

<script>
  var CATALOG = <?= json_encode($catalog, JSON_UNESCAPED_SLASHES) ?>;
  // Cost and margin never reach the browser for people who can't see them.
  var CAN_COSTS = <?= $canCosts ? 'true' : 'false' ?>;
  var INITIAL = <?= json_encode(array_map(function($r) use ($canCosts){return ['title'=>$r['title'],'desc'=>$r['description'],'qty'=>$r['quantity'],'price'=>$r['unit_price'],'cost'=>$canCosts ? ($r['unit_cost'] ?? null) : null,'margin'=>$canCosts ? ($r['margin'] ?? null) : null,'billing'=>$r['billing'],'section'=>(int)($r['is_section'] ?? 0),'optional'=>(int)($r['is_optional'] ?? 0)];}, $items), JSON_UNESCAPED_SLASHES) ?>;
  var TAX_PRESETS = [
    {label:"Custom / none", rate:"", name:""},
    {label:"PA — 6%", rate:"6", name:"PA Sales Tax"},
    {label:"NJ — 6.625%", rate:"6.625", name:"NJ Sales Tax"},
    {label:"NY — 4% (state)", rate:"4", name:"NY Sales Tax"},
    {label:"DE — 0%", rate:"0", name:"Sales Tax"},
    {label:"CA — 7.25%", rate:"7.25", name:"CA Sales Tax"},
    {label:"TX — 6.25%", rate:"6.25", name:"TX Sales Tax"},
    {label:"FL — 6%", rate:"6", name:"FL Sales Tax"}
  ];

  var DEFAULT_MARGIN = <?= json_encode((string) client('quotes.margin', '30')) ?>;
  var PERIODS = <?= json_encode(billing_periods(), JSON_UNESCAPED_SLASHES) ?>; // key => [label, suffix, months, box title]
  function months(b){return (PERIODS[b]||PERIODS.one_time)[2];}
  function suffix(b){return (PERIODS[b]||PERIODS.one_time)[1];}

  var CONTACTS = <?= json_encode($allContacts, JSON_UNESCAPED_SLASHES) ?>;
  var PRE_CONTACT = <?= (int)$preContact ?>;

  function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');}
  function money(n){return '$'+(Number(n)||0).toFixed(2);}

  // Populate the "Prepared for (contact)" dropdown from the chosen business.
  var bizSel = document.getElementById('businessSel'), contactSel = document.getElementById('contactSel');
  function fillContacts(){
    var bid = parseInt(bizSel.value, 10) || 0;
    contactSel.innerHTML = '<option value="">— optional —</option>';
    CONTACTS.filter(function(c){return parseInt(c.business_id,10)===bid;}).forEach(function(c){
      var o=document.createElement('option'); o.value=c.id;
      o.textContent = (c.name||'(unnamed)') + (c.title? ' — '+c.title : '');
      if (parseInt(c.id,10)===PRE_CONTACT) o.selected=true;
      contactSel.appendChild(o);
    });
  }
  bizSel.addEventListener('change', fillContacts);
  fillContacts();


  var tp = document.getElementById('taxPreset');
  TAX_PRESETS.forEach(function(p,i){var o=document.createElement('option');o.value=i;o.textContent=p.label;tp.appendChild(o);});
  tp.addEventListener('change',function(){var p=TAX_PRESETS[tp.value];if(p && p.rate!==''){document.getElementById('taxRate').value=p.rate;document.getElementById('taxLabel').value=p.name;recalc();}});

  var zoneOne = document.getElementById('zoneOne'), zoneRec = document.getElementById('zoneRec'), zoneOpt = document.getElementById('zoneOpt');
  var COLS = CAN_COSTS ? 'grid-template-columns:auto 2fr .6fr .95fr .8fr .95fr 1.15fr .95fr auto' : 'grid-template-columns:auto 2.4fr .6fr 1.1fr 1.2fr 1fr auto';
  document.querySelectorAll('.lhead').forEach(function(h){ h.style.cssText = 'display:grid;'+COLS+';gap:.4rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);padding:.4rem 0 .2rem;border-bottom:1px solid var(--border)'; });
  function r2(n){return (Math.round(n*100)/100).toFixed(2);}
  function num(v){return v===null||v===undefined||String(v).trim()===''?'':String(parseFloat(v));}
  function zoneFor(billing, optional){return optional ? zoneOpt : (months(billing) ? zoneRec : zoneOne);}
  var MOVER = '<div class="mv"><button type="button" class="up" title="Move up">▲</button><span class="grip" title="Drag to reorder">⠿</span><button type="button" class="dn" title="Move down">▼</button></div>';
  var X = '<button type="button" class="x" title="Remove" style="background:transparent;border:none;color:#b02424;cursor:pointer;font-size:1.2rem">×</button>';

  // Up/down buttons, drag handle and remove, shared by item and section rows.
  function wireRow(row){
    row.querySelector('.up').addEventListener('click',function(){ var p=row.previousElementSibling; if(p) row.parentNode.insertBefore(row,p); });
    row.querySelector('.dn').addEventListener('click',function(){ var n=row.nextElementSibling; if(n) row.parentNode.insertBefore(n,row); });
    var grip = row.querySelector('.grip');
    grip.addEventListener('mousedown',function(){ row.draggable=true; });
    row.addEventListener('dragstart',function(e){ dragging=row; row.classList.add('dragging'); e.dataTransfer.effectAllowed='move'; try{e.dataTransfer.setData('text/plain','');}catch(x){} });
    row.addEventListener('dragend',function(){ row.draggable=false; row.classList.remove('dragging'); dragging=null; });
    row.querySelector('.x').addEventListener('click',function(){row.remove();recalc();});
  }
  var dragging = null;
  [zoneOne, zoneRec, zoneOpt].forEach(function(z){
    z.addEventListener('dragover',function(e){
      if(!dragging || dragging.parentNode!==z) return;   // reorder within its own area only
      e.preventDefault();
      var after = null;
      [].slice.call(z.children).forEach(function(c){ if(c!==dragging && !after){ var b=c.getBoundingClientRect(); if(e.clientY < b.top+b.height/2) after=c; } });
      z.insertBefore(dragging, after);
    });
  });

  function addSection(title, zone){
    var z = zone==='optional' ? zoneOpt : (zone==='one_time' ? zoneOne : zoneRec);
    var row = document.createElement('div');
    row.className='sec-row';
    row.style.cssText='display:grid;grid-template-columns:auto 1fr auto;gap:.4rem;align-items:center;padding:.6rem 0 .4rem;border-bottom:2px solid var(--accent)';
    row.innerHTML = MOVER +
      '<input name="item_title[]" placeholder="Section heading, e.g. Website build" style="font-weight:700;color:var(--accent)" value="'+esc(title||'')+'">'+ X +
      '<input type="hidden" name="item_section[]" value="1"><input type="hidden" name="item_desc[]" value=""><input type="hidden" name="item_qty[]" value="0">'+
      '<input type="hidden" name="item_cost[]" value=""><input type="hidden" name="item_margin[]" value=""><input type="hidden" name="item_price[]" value="0">'+
      '<input type="hidden" name="item_billing[]" value="'+(zone==='monthly'?'monthly':'one_time')+'">'+
      '<input type="hidden" name="item_optional[]" value="'+(zone==='optional'?1:0)+'">';
    z.appendChild(row);
    wireRow(row);
    return row;
  }

  function addLine(it){
    it = it || {title:'',desc:'',qty:1,price:0,cost:'',margin:DEFAULT_MARGIN,billing:'one_time'};
    var row = document.createElement('div');
    row.className='line-row';
    row.style.cssText='display:grid;'+COLS+';gap:.4rem;align-items:center;padding:.5rem 0;border-bottom:1px solid var(--border)';
    row.innerHTML = MOVER +
      '<input name="item_title[]" placeholder="Title" value="'+esc(it.title)+'">'+
      '<input name="item_qty[]" type="number" min="0" step="1" value="'+(parseFloat(it.qty)||1)+'" style="text-align:center">'+
      (CAN_COSTS ? '<input name="item_cost[]" type="number" min="0" step="0.01" placeholder="Our cost" title="What this costs <?= e(client('name')) ?> (per unit, never shown to the customer)" value="'+esc(num(it.cost))+'">'+
      '<input name="item_margin[]" type="number" step="0.1" placeholder="%" title="Margin % added on top of our cost" value="'+esc(num(it.margin))+'">' : '<input type="hidden" name="item_cost[]" value=""><input type="hidden" name="item_margin[]" value="">')+
      '<input name="item_price[]" type="number" min="0" step="0.01" title="Total cost to the customer, per unit. Editing this updates the margin." value="'+(it.price||0)+'">'+
      '<select name="item_billing[]">'+Object.keys(PERIODS).map(function(k){return '<option value="'+k+'"'+((it.billing||'one_time')===k?' selected':'')+'>'+PERIODS[k][0]+'</option>';}).join('')+'</select>'+
      '<div class="lt" style="text-align:right;font-weight:600;font-size:.88rem;white-space:nowrap"></div>'+ X +
      '<input name="item_desc[]" placeholder="Description (shown on the quote)" value="'+esc(it.desc)+'">'+
      '<button type="button" class="optbtn"></button>'+
      '<input type="hidden" name="item_section[]" value="0">'+
      '<input type="hidden" name="item_optional[]" value="'+(it.optional?1:0)+'">';
    zoneFor(it.billing||'one_time', it.optional).appendChild(row);
    wireRow(row);
    var bill = row.querySelector('[name="item_billing[]"]'), price = row.querySelector('[name="item_price[]"]'),
        cost = row.querySelector('[name="item_cost[]"]'), margin = row.querySelector('[name="item_margin[]"]');
    // Move a line in or out of the optional add-ons.
    var optIn = row.querySelector('[name="item_optional[]"]'), optBtn = row.querySelector('.optbtn');
    function optLabel(){ optBtn.textContent = optIn.value==='1' ? 'Include in quote' : 'Make optional'; }
    optLabel();
    optBtn.addEventListener('click',function(){
      optIn.value = optIn.value==='1' ? '0' : '1';
      zoneFor(bill.value, optIn.value==='1').appendChild(row);
      optLabel(); recalc();
    });
    // Our cost + margin -> total cost (price).
    function fromMargin(){
      var c=parseFloat(cost.value), m=parseFloat(margin.value);
      if(c>=0 && cost.value!=='' && !isNaN(m)) price.value=r2(c*(1+m/100));
    }
    cost.addEventListener('input',fromMargin);
    margin.addEventListener('input',fromMargin);
    // Editing the total cost recomputes the margin.
    price.addEventListener('input',function(){
      var c=parseFloat(cost.value), p=parseFloat(price.value);
      if(c>0 && !isNaN(p)) margin.value=(Math.round((p/c-1)*1000)/10).toString();
    });
    // Switching between recurring periods rescales the prices ($70/mo -> $210/qtr -> $840/yr);
    // switching between one-time and recurring moves the line to the other area.
    bill.dataset.prev = bill.value;
    bill.addEventListener('change',function(){
      var a=months(bill.dataset.prev), b=months(bill.value);
      if(a && b && a!==b){
        price.value=r2((parseFloat(price.value)||0)*b/a);
        if(cost.value!=='') cost.value=r2((parseFloat(cost.value)||0)*b/a);
      }
      var z = zoneFor(bill.value, optIn.value==='1');
      if(row.parentNode!==z) z.appendChild(row);
      bill.dataset.prev = bill.value;
    });
    row.addEventListener('input',recalc);
    row.addEventListener('change',recalc);
    return row;
  }
  function recalc(){
    var one=0,rec={},cst={},rev={},opt={};
    document.querySelectorAll('.line-row').forEach(function(r){
      var q=parseFloat(r.querySelector('[name="item_qty[]"]').value)||0;
      var p=parseFloat(r.querySelector('[name="item_price[]"]').value)||0;
      var c=parseFloat(r.querySelector('[name="item_cost[]"]').value)||0;
      var b=r.querySelector('[name="item_billing[]"]').value;
      r.querySelector('.lt').textContent=money(q*p)+suffix(b);
      // Optional add-ons are shown on the quote but never counted in totals or profit.
      if(r.querySelector('[name="item_optional[]"]').value==='1'){ opt[b]=(opt[b]||0)+q*p; return; }
      if(months(b)) rec[b]=(rec[b]||0)+q*p; else one+=q*p;
      cst[b]=(cst[b]||0)+q*c; rev[b]=(rev[b]||0)+q*p;
    });
    var rate=parseFloat(document.getElementById('taxRate').value)||0;
    var tax=one*rate/100;
    document.getElementById('tOne').textContent=money(one);
    document.getElementById('tTax').textContent=money(tax);
    document.getElementById('tOneTotal').textContent=money(one+tax);
    var parts=Object.keys(PERIODS).filter(function(k){return k in rec;}).map(function(k){return money(rec[k])+suffix(k);});
    document.getElementById('tMo').innerHTML=parts.length?parts.join('<br>'):'$0.00/mo';
    var oparts=Object.keys(PERIODS).filter(function(k){return k in opt;}).map(function(k){return money(opt[k])+suffix(k);});
    document.getElementById('tOpt').innerHTML=oparts.length?oparts.join('<br>'):'$0.00';
    // Internal: our cost and profit per billing period.
    var keys=Object.keys(PERIODS).filter(function(k){return k in rev;});
    document.getElementById('tCost').innerHTML=keys.length?keys.map(function(k){return money(cst[k])+suffix(k);}).join('<br>'):'$0.00';
    document.getElementById('tProfit').innerHTML=keys.length?keys.map(function(k){
      var pr=rev[k]-cst[k]; return money(pr)+suffix(k)+(cst[k]>0?' <span style="font-size:.75rem;opacity:.75">('+Math.round(pr/cst[k]*100)+'%)</span>':'');
    }).join('<br>'):'$0.00';
  }
  // ---- Pricebook picker ----
  var picker = document.getElementById('picker'), pkList = document.getElementById('pkList'), pkSearch = document.getElementById('pkSearch');
  function pkOptional(){ return document.querySelector('input[name="pk_target"]:checked').value === 'optional'; }
  function pkRender(){
    var t = pkSearch.value.trim().toLowerCase(), h = '', any = false;
    Object.keys(PERIODS).forEach(function(k){
      var items = CATALOG.map(function(c,i){ return [c,i]; }).filter(function(p){
        return p[0].billing === k && (!t || (p[0].title + ' ' + (p[0].description||'')).toLowerCase().indexOf(t) >= 0);
      });
      if (!items.length) return;
      any = true;
      h += '<div class="pk-group">' + esc(PERIODS[k][0]) + '</div>';
      items.forEach(function(p){
        var c = p[0];
        h += '<button type="button" class="pk-item" data-i="' + p[1] + '"><span><strong>' + esc(c.title) + '</strong>' + (c.description ? '<span class="d">' + esc(c.description) + '</span>' : '') +
             '</span><span class="p">' + money(c.unit_price) + suffix(c.billing) + '</span><span class="ok"></span></button>';
      });
    });
    pkList.innerHTML = any ? h : '<div class="pk-empty">' + (CATALOG.length ? 'Nothing in the pricebook matches "' + esc(pkSearch.value) + '". Use + New line item below.' : 'Your pricebook is empty. Use + New line item below, or add items on the Catalog page.') + '</div>';
  }
  pkList.addEventListener('click', function(e){
    var b = e.target.closest('.pk-item'); if (!b) return;
    var c = CATALOG[+b.dataset.i];
    addLine({title:c.title, desc:c.description, qty:1, price:c.unit_price, cost:'', margin:DEFAULT_MARGIN, billing:c.billing, optional:pkOptional()});
    recalc();
    var ok = b.querySelector('.ok'), n = (+b.dataset.n || 0) + 1; b.dataset.n = n;
    ok.textContent = 'Added' + (n > 1 ? ' ×' + n : '');
  });
  function openPicker(){
    pkSearch.value = ''; pkRender();
    if (picker.showModal) picker.showModal(); else picker.setAttribute('open', '');
    setTimeout(function(){ pkSearch.focus(); }, 30);
  }
  function closePicker(){ if (picker.close) picker.close(); else picker.removeAttribute('open'); }
  document.getElementById('openPicker').addEventListener('click', openPicker);
  document.getElementById('pickerClose').addEventListener('click', closePicker);
  document.getElementById('pkDone').addEventListener('click', closePicker);
  pkSearch.addEventListener('input', pkRender);
  picker.addEventListener('click', function(e){ if (e.target === picker) closePicker(); });   // click outside the panel
  document.getElementById('pkCustom').addEventListener('click', function(){
    var opt = pkOptional();
    closePicker();
    var row = addLine({title:'', desc:'', qty:1, price:0, cost:'', margin:DEFAULT_MARGIN, billing:'one_time', optional:opt});
    recalc(); if (row.scrollIntoView) row.scrollIntoView({block:'center'}); row.querySelector('[name="item_title[]"]').focus();
  });
  document.getElementById('pkSection').addEventListener('click', function(){
    var zone = pkOptional() ? 'optional' : document.getElementById('pkSecZone').value;
    closePicker();
    var row = addSection('', zone); if (row.scrollIntoView) row.scrollIntoView({block:'center'}); row.querySelector('input').focus();
  });
  document.querySelectorAll('input[name="pk_target"]').forEach(function(r){ r.addEventListener('change', function(){
    document.getElementById('pkSecZone').style.display = pkOptional() ? 'none' : '';
    document.getElementById('pickerTitle').textContent = pkOptional() ? 'Add optional add-ons' : 'Add to quote';
  }); });
  document.getElementById('taxRate').addEventListener('input',recalc);

  if(INITIAL.length){ INITIAL.forEach(function(it){ if(it.section) addSection(it.title, it.optional ? 'optional' : (it.billing==='one_time'?'one_time':'monthly')); else addLine(it); }); } else { addLine(); }
  recalc();
</script>
<?php
layout_footer();
