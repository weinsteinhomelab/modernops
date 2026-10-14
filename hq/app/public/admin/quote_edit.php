<?php
/** Build or edit a quote. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$quote = null;
$items = [];
if ($id) {
    $st = db()->prepare('SELECT * FROM quotes WHERE id = ?');
    $st->execute([$id]);
    $quote = $st->fetch();
    if (!$quote) { http_response_code(404); layout_header('Not found','quotes.php'); echo '<div class="card">Quote not found.</div>'; layout_footer(); exit; }
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

    if (!$business_id) {
        $err = 'Please choose a business.';
    } elseif ($quote_number === '') {
        $err = 'Please enter a quote number.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            if ($id) {
                $u = $pdo->prepare('UPDATE quotes SET business_id=?, contact_id=?, quote_number=?, issue_date=?, valid_until=?, status=?, tax_rate=?, tax_label=?, scope_of_work=?, notes=?, terms_included=? WHERE id=?');
                $u->execute([$business_id,$contact_id,$quote_number,$issue_date,$valid_until ?: null,$status,$tax_rate,$tax_label,$scope,$notes,$terms_included,$id]);
                $pdo->prepare('DELETE FROM quote_items WHERE quote_id = ?')->execute([$id]);
                $qid = $id;
            } else {
                $ins = $pdo->prepare('INSERT INTO quotes (business_id, contact_id, quote_number, issue_date, valid_until, status, tax_rate, tax_label, scope_of_work, notes, terms_included) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $ins->execute([$business_id,$contact_id,$quote_number,$issue_date,$valid_until ?: null,$status,$tax_rate,$tax_label,$scope,$notes,$terms_included]);
                $qid = (int)$pdo->lastInsertId();
                // advance the auto quote number if this used the current default
                if ($quote_number === (string)setting('next_quote_number')) {
                    set_setting('next_quote_number', (string)((int)$quote_number + 1));
                }
            }
            $li = $pdo->prepare('INSERT INTO quote_items (quote_id, title, description, quantity, unit_price, billing, sort_order) VALUES (?,?,?,?,?,?,?)');
            $n = count($titles);
            $order = 0;
            for ($i = 0; $i < $n; $i++) {
                $t = trim((string)$titles[$i]);
                if ($t === '') continue;
                $li->execute([
                    $qid, $t, trim((string)($descs[$i] ?? '')),
                    (float)($qtys[$i] ?? 1), (float)($prices[$i] ?? 0),
                    ($bills[$i] ?? 'one_time') === 'monthly' ? 'monthly' : 'one_time',
                    $order++,
                ]);
            }
            $pdo->commit();
            header('Location: quote_view.php?id=' . $qid . '&saved=1');
            exit;
        } catch (Exception $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $err = 'Could not save the quote. ' . ($ex->getCode() === '23000' ? 'That quote number is already used.' : '');
        }
    }
}

$businesses = db()->query('SELECT id, name FROM businesses ORDER BY name')->fetchAll();
$allContacts = db()->query("SELECT id, business_id, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name, title FROM contacts ORDER BY id")->fetchAll();
$catalog = db()->query("SELECT title, description, unit_price, billing FROM catalog_items WHERE active=1 ORDER BY billing, sort_order, title")->fetchAll();

$preBusiness = (int)($_GET['business'] ?? ($quote['business_id'] ?? 0));
$preContact  = (int)($quote['contact_id'] ?? 0);
$defNumber = $quote['quote_number'] ?? (string)setting('next_quote_number', '1001');
$defIssue  = $quote['issue_date'] ?? date('Y-m-d');
$defValid  = $quote['valid_until'] ?? date('Y-m-d', strtotime('+30 days'));
$defTaxRate = $quote['tax_rate'] ?? setting('default_tax_rate', '0');
$defTaxLabel = $quote['tax_label'] ?? setting('default_tax_label', 'Sales Tax');
$defScope = $quote['scope_of_work'] ?? '';
$defNotes = $quote['notes'] ?? "Quote valid for 30 days from issue date. Payment due upon acceptance.";
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
    <div class="grid2" style="grid-template-columns:2fr auto;align-items:end;margin-top:.5rem">
      <div><label>Add a saved item</label><select id="catalogPick"></select></div>
      <div><button type="button" class="btn" id="addCat">+ Add</button></div>
    </div>
    <div style="margin-top:.5rem"><button type="button" class="btn secondary small" id="addCustom">+ Add custom line</button></div>

    <div id="lines" style="margin-top:1rem"></div>

    <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1rem;flex-wrap:wrap">
      <div class="stat" style="padding:.6rem 1rem"><span class="l">One-time</span><div class="n" id="tOne" style="font-size:1.2rem">$0.00</div></div>
      <div class="stat" style="padding:.6rem 1rem"><span class="l">Tax</span><div class="n" id="tTax" style="font-size:1.2rem">$0.00</div></div>
      <div class="stat" style="padding:.6rem 1rem"><span class="l">One-time total</span><div class="n" id="tOneTotal" style="font-size:1.2rem">$0.00</div></div>
      <div class="stat" style="padding:.6rem 1rem"><span class="l">Monthly</span><div class="n" id="tMo" style="font-size:1.2rem">$0.00</div></div>
    </div>
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

<script>
  var CATALOG = <?= json_encode($catalog, JSON_UNESCAPED_SLASHES) ?>;
  var INITIAL = <?= json_encode(array_map(function($r){return ['title'=>$r['title'],'desc'=>$r['description'],'qty'=>$r['quantity'],'price'=>$r['unit_price'],'billing'=>$r['billing']];}, $items), JSON_UNESCAPED_SLASHES) ?>;
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

  var cp = document.getElementById('catalogPick');
  CATALOG.forEach(function(c,i){var o=document.createElement('option');o.value=i;o.textContent=c.title+' — $'+Number(c.unit_price).toFixed(2)+(c.billing==='monthly'?'/mo':'');cp.appendChild(o);});
  if(!CATALOG.length){var o=document.createElement('option');o.textContent='(no saved items yet)';cp.appendChild(o);}

  var tp = document.getElementById('taxPreset');
  TAX_PRESETS.forEach(function(p,i){var o=document.createElement('option');o.value=i;o.textContent=p.label;tp.appendChild(o);});
  tp.addEventListener('change',function(){var p=TAX_PRESETS[tp.value];if(p && p.rate!==''){document.getElementById('taxRate').value=p.rate;document.getElementById('taxLabel').value=p.name;recalc();}});

  var lines = document.getElementById('lines');
  function addLine(it){
    it = it || {title:'',desc:'',qty:1,price:0,billing:'one_time'};
    var row = document.createElement('div');
    row.className='line-row';
    row.style.cssText='display:grid;grid-template-columns:1.5fr 2.1fr .5fr .8fr .9fr auto;gap:.4rem;align-items:center;padding:.5rem 0;border-bottom:1px solid var(--border)';
    row.innerHTML =
      '<input name="item_title[]" placeholder="Title" value="'+esc(it.title)+'">'+
      '<input name="item_desc[]" placeholder="Description" value="'+esc(it.desc)+'">'+
      '<input name="item_qty[]" type="number" min="0" step="1" value="'+(it.qty||1)+'" style="text-align:center">'+
      '<input name="item_price[]" type="number" min="0" step="0.01" value="'+(it.price||0)+'">'+
      '<select name="item_billing[]"><option value="one_time"'+(it.billing!=='monthly'?' selected':'')+'>One-time</option><option value="monthly"'+(it.billing==='monthly'?' selected':'')+'>Monthly</option></select>'+
      '<button type="button" class="x" title="Remove" style="background:transparent;border:none;color:#b02424;cursor:pointer;font-size:1.2rem">×</button>';
    lines.appendChild(row);
    row.querySelector('.x').addEventListener('click',function(){row.remove();recalc();});
    row.addEventListener('input',recalc);
    row.addEventListener('change',recalc);
  }
  function recalc(){
    var one=0,mo=0;
    lines.querySelectorAll('.line-row').forEach(function(r){
      var q=parseFloat(r.querySelector('[name="item_qty[]"]').value)||0;
      var p=parseFloat(r.querySelector('[name="item_price[]"]').value)||0;
      var b=r.querySelector('[name="item_billing[]"]').value;
      if(b==='monthly') mo+=q*p; else one+=q*p;
    });
    var rate=parseFloat(document.getElementById('taxRate').value)||0;
    var tax=one*rate/100;
    document.getElementById('tOne').textContent=money(one);
    document.getElementById('tTax').textContent=money(tax);
    document.getElementById('tOneTotal').textContent=money(one+tax);
    document.getElementById('tMo').textContent=money(mo)+'/mo';
  }
  document.getElementById('addCat').addEventListener('click',function(){if(CATALOG.length){var c=CATALOG[cp.value||0];addLine({title:c.title,desc:c.description,qty:1,price:c.unit_price,billing:c.billing});recalc();}});
  document.getElementById('addCustom').addEventListener('click',function(){addLine();});
  document.getElementById('taxRate').addEventListener('input',recalc);

  if(INITIAL.length){INITIAL.forEach(addLine);} else {addLine();}
  recalc();
</script>
<?php
layout_footer();
