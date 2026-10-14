<?php
/** Build or edit a quote. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_billing.php';
require_login();
require_product('quotes');

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
    $costs   = $_POST['item_cost']    ?? [];
    $margins = $_POST['item_margin']  ?? [];
    $secs    = $_POST['item_section'] ?? [];
    $opts    = $_POST['item_optional'] ?? [];

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
            $li = $pdo->prepare('INSERT INTO quote_items (quote_id, title, description, quantity, unit_price, unit_cost, margin, billing, is_section, is_optional, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $n = count($titles);
            $order = 0;
            for ($i = 0; $i < $n; $i++) {
                $t = trim((string)$titles[$i]);
                if ($t === '') continue;
                $cost = trim((string)($costs[$i] ?? '')); $mg = trim((string)($margins[$i] ?? ''));
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
    <div class="grid2" style="grid-template-columns:2fr auto;align-items:end;margin-top:.5rem">
      <div><label>Add a saved item</label><select id="catalogPick"></select></div>
      <div><button type="button" class="btn" id="addCat">+ Add</button></div>
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
    <div class="lhead"><span></span><span>Item</span><span style="text-align:center">Qty</span><span>Our cost</span><span>Margin %</span><span>Total cost</span><span>Billing</span><span style="text-align:right">Line total</span><span></span></div>
    <div class="zone" id="zoneOne" data-zone="one_time"></div>
    <div class="row-actions" style="margin-top:.5rem">
      <button type="button" class="btn secondary small" data-add="line" data-zone="one_time">+ Line</button>
      <button type="button" class="btn secondary small" data-add="section" data-zone="one_time">+ Section heading</button>
    </div>

    <div class="zone-h">Recurring <span>— always shown at the bottom of the quote</span></div>
    <div class="lhead"><span></span><span>Item</span><span style="text-align:center">Qty</span><span>Our cost</span><span>Margin %</span><span>Total cost</span><span>Billing</span><span style="text-align:right">Line total</span><span></span></div>
    <div class="zone" id="zoneRec" data-zone="monthly"></div>
    <div class="row-actions" style="margin-top:.5rem">
      <button type="button" class="btn secondary small" data-add="line" data-zone="monthly">+ Line</button>
      <button type="button" class="btn secondary small" data-add="section" data-zone="monthly">+ Section heading</button>
    </div>

    <div class="zone-h">Optional add-ons <span>— listed at the end of the quote, not counted in the totals</span></div>
    <div class="lhead"><span></span><span>Item</span><span style="text-align:center">Qty</span><span>Our cost</span><span>Margin %</span><span>Total cost</span><span>Billing</span><span style="text-align:right">Line total</span><span></span></div>
    <div class="zone" id="zoneOpt" data-zone="optional"></div>
    <div class="row-actions" style="margin-top:.5rem">
      <button type="button" class="btn secondary small" data-add="line" data-zone="optional">+ Line</button>
      <button type="button" class="btn secondary small" data-add="section" data-zone="optional">+ Section heading</button>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1rem;flex-wrap:wrap">
      <div class="stat" style="padding:.6rem 1rem"><span class="l">One-time</span><div class="n" id="tOne" style="font-size:1.2rem">$0.00</div></div>
      <div class="stat" style="padding:.6rem 1rem"><span class="l">Tax</span><div class="n" id="tTax" style="font-size:1.2rem">$0.00</div></div>
      <div class="stat" style="padding:.6rem 1rem"><span class="l">One-time total</span><div class="n" id="tOneTotal" style="font-size:1.2rem">$0.00</div></div>
      <div class="stat" style="padding:.6rem 1rem"><span class="l">Recurring</span><div class="n" id="tMo" style="font-size:1.2rem;line-height:1.35">$0.00/mo</div></div>
      <div class="stat" style="padding:.6rem 1rem"><span class="l">Optional (not in totals)</span><div class="n" id="tOpt" style="font-size:1.2rem;line-height:1.35;color:var(--muted)">$0.00</div></div>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:.6rem;flex-wrap:wrap;align-items:center">
      <span class="muted" style="font-size:.78rem">Internal only, never shown to the customer:</span>
      <div class="stat" style="padding:.6rem 1rem"><span class="l">Our cost</span><div class="n" id="tCost" style="font-size:1.2rem;line-height:1.35;color:var(--muted)">$0.00</div></div>
      <div class="stat" style="padding:.6rem 1rem"><span class="l">Profit</span><div class="n" id="tProfit" style="font-size:1.2rem;line-height:1.35">$0.00</div></div>
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
  var INITIAL = <?= json_encode(array_map(function($r){return ['title'=>$r['title'],'desc'=>$r['description'],'qty'=>$r['quantity'],'price'=>$r['unit_price'],'cost'=>$r['unit_cost'] ?? null,'margin'=>$r['margin'] ?? null,'billing'=>$r['billing'],'section'=>(int)($r['is_section'] ?? 0),'optional'=>(int)($r['is_optional'] ?? 0)];}, $items), JSON_UNESCAPED_SLASHES) ?>;
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

  var cp = document.getElementById('catalogPick');
  CATALOG.forEach(function(c,i){var o=document.createElement('option');o.value=i;o.textContent=c.title+' — $'+Number(c.unit_price).toFixed(2)+suffix(c.billing);cp.appendChild(o);});
  if(!CATALOG.length){var o=document.createElement('option');o.textContent='(no saved items yet)';cp.appendChild(o);}

  var tp = document.getElementById('taxPreset');
  TAX_PRESETS.forEach(function(p,i){var o=document.createElement('option');o.value=i;o.textContent=p.label;tp.appendChild(o);});
  tp.addEventListener('change',function(){var p=TAX_PRESETS[tp.value];if(p && p.rate!==''){document.getElementById('taxRate').value=p.rate;document.getElementById('taxLabel').value=p.name;recalc();}});

  var zoneOne = document.getElementById('zoneOne'), zoneRec = document.getElementById('zoneRec'), zoneOpt = document.getElementById('zoneOpt');
  var COLS = 'grid-template-columns:auto 2fr .6fr .95fr .8fr .95fr 1.15fr .95fr auto';
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
      '<input name="item_cost[]" type="number" min="0" step="0.01" placeholder="Our cost" title="What this costs <?= e(client('name')) ?> (per unit, never shown to the customer)" value="'+esc(num(it.cost))+'">'+
      '<input name="item_margin[]" type="number" step="0.1" placeholder="%" title="Margin % added on top of our cost" value="'+esc(num(it.margin))+'">'+
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
  document.getElementById('addCat').addEventListener('click',function(){if(CATALOG.length){var c=CATALOG[cp.value||0];addLine({title:c.title,desc:c.description,qty:1,price:c.unit_price,cost:'',margin:DEFAULT_MARGIN,billing:c.billing});recalc();}});
  document.querySelectorAll('[data-add]').forEach(function(b){ b.addEventListener('click',function(){
    if(b.dataset.add==='section'){ addSection('', b.dataset.zone).querySelector('input').focus(); return; }
    var optional = b.dataset.zone==='optional';
    addLine({title:'',desc:'',qty:1,price:0,cost:'',margin:DEFAULT_MARGIN,billing:optional?'one_time':b.dataset.zone,optional:optional}); recalc();
  }); });
  document.getElementById('taxRate').addEventListener('input',recalc);

  if(INITIAL.length){ INITIAL.forEach(function(it){ if(it.section) addSection(it.title, it.optional ? 'optional' : (it.billing==='one_time'?'one_time':'monthly')); else addLine(it); }); } else { addLine(); }
  recalc();
</script>
<?php
layout_footer();
