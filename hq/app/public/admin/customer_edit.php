<?php
/** Add or edit a business, and its contacts (people) on the same page. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_verticals.php';
require_login();
require_product('customers', 'quotes');
require_perm('customers.edit');

$id = (int)($_GET['id'] ?? 0);
$b = ['id'=>0,'name'=>'','vertical'=>'','main_phone'=>'','main_email'=>'','address'=>'','address2'=>'','city'=>'','state'=>'','country'=>'United States','zip'=>'','notes'=>''];
$contacts = [];
if ($id) {
    $st = db()->prepare('SELECT * FROM businesses WHERE id = ?');
    $st->execute([$id]);
    $found = $st->fetch();
    if (!$found) { http_response_code(404); layout_header('Not found', 'customers.php'); echo '<div class="card">Business not found. <a href="customers.php">Back</a></div>'; layout_footer(); exit; }
    if (!can_see_business($found)) deny('This customer isn' . "'" . 't assigned to you.');
    $b = $found;
    $cs = db()->prepare('SELECT * FROM contacts WHERE business_id = ? ORDER BY id');
    $cs->execute([$id]);
    $contacts = $cs->fetchAll();
}
$contactFields = ['first_name', 'last_name', 'title', 'email', 'phone'];

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    foreach (['name','vertical','main_phone','main_email','address','address2','city','state','country','zip','notes'] as $f) {
        $b[$f] = trim($_POST[$f] ?? '');
    }
    // Contact rows from the form (c_id is blank for new people)
    $rows = [];
    $n = count((array)($_POST['c_first_name'] ?? []));
    for ($i = 0; $i < $n; $i++) {
        $r = ['id' => (int)($_POST['c_id'][$i] ?? 0)];
        foreach ($contactFields as $f) $r[$f] = trim((string)($_POST['c_' . $f][$i] ?? ''));
        $rows[] = $r;
    }
    $removed = array_map('intval', (array)($_POST['c_removed'] ?? []));
    $badEmail = '';
    foreach ($rows as $r) if ($r['email'] !== '' && !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) $badEmail = $r['email'];

    if ($b['name'] === '') {
        $err = 'A business name is required.';
    } elseif ($b['main_email'] !== '' && !filter_var($b['main_email'], FILTER_VALIDATE_EMAIL)) {
        $err = 'The main email doesn\'t look right.';
    } elseif ($badEmail !== '') {
        $err = 'This contact email doesn\'t look right: ' . $badEmail;
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $cols = ['name','vertical','main_phone','main_email','address','address2','city','state','country','zip','notes'];
            $vals = array_map(fn($c) => $b[$c], $cols);
            if ($id) {
                $set = implode(',', array_map(fn($c) => "$c=?", $cols));
                $pdo->prepare("UPDATE businesses SET $set WHERE id=?")->execute(array_merge($vals, [$id]));
                $bid = $id;
            } else {
                $ph = implode(',', array_fill(0, count($cols), '?'));
                // New customers belong to whoever adds them (admins can reassign on the customer page).
                $pdo->prepare("INSERT INTO businesses (" . implode(',', $cols) . ", assigned_to) VALUES ($ph, ?)")->execute(array_merge($vals, [me_id()]));
                $bid = (int)$pdo->lastInsertId();
            }
            // Contacts: update the ones that were here, add new ones, remove the ones taken off the list.
            foreach ($removed as $rid) $pdo->prepare('DELETE FROM contacts WHERE id = ? AND business_id = ?')->execute([$rid, $bid]);
            $upd = $pdo->prepare('UPDATE contacts SET first_name=?, last_name=?, title=?, email=?, phone=? WHERE id=? AND business_id=?');
            $ins = $pdo->prepare('INSERT INTO contacts (business_id, first_name, last_name, title, email, phone) VALUES (?,?,?,?,?,?)');
            $added = 0;
            foreach ($rows as $r) {
                $empty = implode('', array_map(fn($f) => $r[$f], $contactFields)) === '';
                if ($r['id'] && !in_array($r['id'], $removed, true)) {
                    if (!$empty) $upd->execute([$r['first_name'], $r['last_name'], $r['title'], $r['email'], $r['phone'], $r['id'], $bid]);
                } elseif (!$r['id'] && !$empty) {
                    $ins->execute([$bid, $r['first_name'], $r['last_name'], $r['title'], $r['email'], $r['phone']]);
                    $added++;
                }
            }
            $pdo->commit();
            log_activity($id ? 'customer.updated' : 'customer.created', 'customer', $bid, $b['name'] . ($added ? " (+$added contact" . ($added === 1 ? '' : 's') . ')' : ''));
            header('Location: customer.php?id=' . $bid . '&saved=1'); exit;
        } catch (Exception $ex) {
            $pdo->rollBack();
            $err = 'Could not save. Please try again.';
        }
    }
    // Re-show what they typed
    $contacts = array_values(array_filter($rows, fn($r) => !in_array($r['id'], $removed, true)));
}
if (!$contacts) $contacts = [array_fill_keys(array_merge(['id'], $contactFields), '')];   // start with one empty row

layout_header($id ? 'Edit business' : 'New business', 'customers.php');
?>
<p style="margin-bottom:.5rem"><a href="<?= $id ? 'customer.php?id='.$id : 'customers.php' ?>">&larr; Back</a></p>
<h1 class="page"><?= $id ? 'Edit ' . e($b['name']) : 'New business' ?></h1>
<p class="sub"><?= $id ? 'Business details and the people you deal with there.' : 'Add the business and the people you deal with there.' ?></p>
<?php if ($err) flash('err', $err); ?>

<form method="post" id="bizForm">
  <?= csrf_field() ?>
  <div class="card">
    <strong>Business</strong>
    <div class="grid-auto">
      <div><label>Business name *</label><input type="text" name="name" required value="<?= e($b['name']) ?>"></div>
      <div><label>Vertical / industry</label><?= vertical_select('vertical', (string)$b['vertical']) ?></div>
      <div><label>Main number</label><input type="text" name="main_phone" value="<?= e($b['main_phone']) ?>"></div>
      <div><label>Main email</label><input type="email" name="main_email" value="<?= e($b['main_email']) ?>"></div>
      <div><label>Address</label><input type="text" name="address" value="<?= e($b['address']) ?>"></div>
      <div><label>Address 2</label><input type="text" name="address2" value="<?= e($b['address2']) ?>" placeholder="Suite, unit, floor (optional)"></div>
      <div><label>City</label><input type="text" name="city" value="<?= e($b['city']) ?>"></div>
      <div><label>State</label><input type="text" name="state" value="<?= e($b['state']) ?>"></div>
      <div><label>Zip code</label><input type="text" name="zip" value="<?= e($b['zip']) ?>"></div>
      <div><label>Country</label><input type="text" name="country" value="<?= e($b['country']) ?>"></div>
      <div class="span-all"><label>Notes</label><textarea name="notes" rows="3"><?= e($b['notes']) ?></textarea></div>
    </div>
  </div>

  <div class="card">
    <strong>Contacts</strong>
    <p class="muted" style="font-size:.85rem;margin:0 0 .4rem">The people at this business. Leave a row blank to skip it.</p>
    <div id="contacts">
      <?php foreach ($contacts as $c): ?>
        <div class="contact-row">
          <input type="hidden" name="c_id[]" value="<?= (int)($c['id'] ?: 0) ?: '' ?>">
          <div><label>First name</label><input type="text" name="c_first_name[]" value="<?= e($c['first_name']) ?>"></div>
          <div><label>Last name</label><input type="text" name="c_last_name[]" value="<?= e($c['last_name']) ?>"></div>
          <div><label>Title</label><input type="text" name="c_title[]" value="<?= e($c['title']) ?>" placeholder="e.g. Owner"></div>
          <div><label>Email</label><input type="email" name="c_email[]" value="<?= e($c['email']) ?>"></div>
          <div><label>Phone</label><input type="text" name="c_phone[]" value="<?= e($c['phone']) ?>"></div>
          <button type="button" class="c-remove" title="Remove this contact">×</button>
        </div>
      <?php endforeach; ?>
    </div>
    <div id="removed"></div>
    <button type="button" class="btn secondary small" id="addContact" style="margin-top:.8rem">+ Add another contact</button>
  </div>

  <div class="card">
    <div class="row-actions">
      <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Create business' ?></button>
      <a href="<?= $id ? 'customer.php?id='.$id : 'customers.php' ?>" class="btn secondary">Cancel</a>
    </div>
  </div>
</form>

<style>
  .contact-row{display:grid;grid-template-columns:repeat(5,minmax(0,1fr)) auto;gap:0 1rem;align-items:end;padding:.2rem 0 .8rem;border-bottom:1px dashed var(--border)}
  .contact-row:last-child{border-bottom:0}
  .c-remove{background:transparent;border:0;color:#b02424;font-size:1.3rem;cursor:pointer;padding:0 .3rem .45rem}
  @media(max-width:900px){.contact-row{grid-template-columns:1fr 1fr}.c-remove{justify-self:start}}
</style>
<script>
  (function(){
    var list = document.getElementById('contacts'), removed = document.getElementById('removed');
    function wire(row){
      row.querySelector('.c-remove').addEventListener('click', function(){
        var id = row.querySelector('[name="c_id[]"]').value;
        if (id) {
          if (!confirm('Remove this contact from the business?')) return;
          var h = document.createElement('input'); h.type = 'hidden'; h.name = 'c_removed[]'; h.value = id; removed.appendChild(h);
        }
        row.remove();
        if (!list.children.length) add();
      });
    }
    function add(){
      var row = tpl.cloneNode(true);
      row.querySelectorAll('input').forEach(function(i){ i.value = ''; });
      list.appendChild(row); wire(row);
      row.querySelector('[name="c_first_name[]"]').focus();
    }
    // keep a blank template even if every row is removed
    var tpl = list.querySelector('.contact-row').cloneNode(true);
    list.querySelectorAll('.contact-row').forEach(wire);
    document.getElementById('addContact').addEventListener('click', add);
  })();
</script>
<?php
layout_footer();
