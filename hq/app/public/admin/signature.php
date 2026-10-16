<?php
/** Build your HTML email signature: live preview, copy into Gmail/Outlook/Apple Mail, use in Ops Inbox. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_signature.php';
require_once __DIR__ . '/_imap.php';   // mailbox colours
require_login();

$m = me();
$uid = me_id();
$canInbox = product_on('inbox');
$myBoxes = $canInbox ? my_mailboxes() : [];
$all = sig_all($uid, $m);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = $id > 0 || isset($_GET['new']);
$cur = $id > 0 ? sig_get($uid, $id, $m) : null;
if ($id > 0 && !$cur) { header('Location: signature.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? 'save';
    if ($a === 'preview') {
        header('Content-Type: application/json');
        $p = sig_clean($_POST, $m);
        echo json_encode(['html' => sig_html($p), 'text' => sig_text($p)]);
        exit;
    }
    if ($a === 'upload') {
        header('Content-Type: application/json');
        echo json_encode(sig_store_upload($_FILES['image'] ?? [], $uid));
        exit;
    }
    if ($a === 'delete' && $cur) {
        sig_delete($uid, $id);
        log_activity('signature.deleted', 'user', $uid, $cur['name']);
        header('Location: signature.php?deleted=1'); exit;
    }
    if ($a === 'duplicate' && $cur) {
        $nid = sig_save($uid, 0, $cur['name'] . ' (copy)', $cur['data'], [], false);
        header('Location: signature.php?id=' . $nid); exit;
    }
    $boxes = array_values(array_intersect((array)($_POST['mailboxes'] ?? []), $myBoxes));
    $nid = sig_save($uid, $cur ? $id : 0, (string)($_POST['sig_name'] ?? ''), sig_clean($_POST, $m), $boxes, !empty($_POST['is_default']));
    log_activity('signature.saved', 'user', $uid);
    header('Location: signature.php?saved=1&id=' . $nid); exit;
}

$d = $cur ? $cur['data'] : sig_defaults($m);
$first = !$all;
$sigName = $cur ? $cur['name'] : ($first ? 'My signature' : 'New signature');
$sigBoxes = $cur ? $cur['mailboxes'] : [];
$sigDefault = $cur ? $cur['is_default'] : $first;
$logo = sig_company_logo();

layout_header('Email signatures', $canInbox ? 'email.php' : 'account.php');
?>
<style>
  .sig-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr);gap:0 3rem;align-items:start}
  .sig-side{position:sticky;top:76px}
  .sig-paper{background:#fff;color:#333;padding:1.6rem 1.6rem 1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.12),0 8px 30px rgba(0,0,0,.06);overflow-x:auto}
  /* HQ's own table styles must not leak into the preview: it should look like the email */
  .sig-reset table{width:auto}
  .sig-reset td{padding:0;border:0}
  .sig-mock{font:13px/1.5 Arial,sans-serif;color:#555;border-bottom:1px solid #eee;padding-bottom:.9rem;margin-bottom:1.1rem}
  .seg{display:flex;flex-wrap:wrap;gap:.4rem}
  .seg label{margin:0;display:flex;align-items:center;gap:.4rem;padding:.45rem .8rem;border:1px solid var(--field-border);border-radius:7px;cursor:pointer;font-weight:500;color:var(--text);font-size:.86rem}
  .seg input{width:auto;margin:0}
  .seg label:has(input:checked){border-color:var(--accent);background:color-mix(in srgb,var(--accent) 12%,transparent)}
  .imgrow{display:flex;align-items:center;gap:.8rem;flex-wrap:wrap;margin-top:.3rem}
  .imgrow img{max-height:56px;max-width:160px;background:#fff;border:1px solid var(--border);padding:3px}
  .howto summary{cursor:pointer;font-weight:600;padding:.55rem 0;border-top:1px solid var(--border)}
  .howto ol{margin:.2rem 0 .8rem 1.2rem;font-size:.88rem}
  .howto li{margin:.15rem 0}
  input[type=color]{height:40px;padding:3px;width:64px}
  .colorrow{display:flex;gap:.5rem;align-items:center}
  .colorrow input[type=text]{width:110px;font-family:ui-monospace,monospace}
  .mbdot{display:inline-block;width:9px;height:9px;border-radius:50%}
  .mbchip{display:inline-block;font-size:.72rem;font-weight:700;padding:.1rem .5rem;border-radius:20px;color:var(--c);background:color-mix(in srgb,var(--c) 14%,transparent);white-space:nowrap}
  @media(max-width:980px){.sig-grid{grid-template-columns:1fr}.sig-side{position:static;margin-top:1rem}}
</style>

<?php if (!$editing): ?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <h1 class="page">Email signatures</h1>
  <a class="btn" href="signature.php?new=1">+ New signature</a>
</div>
<p class="sub">Make as many as you like, e.g. one for your own address and one for info@. <?= $canInbox ? 'Each one can switch on by itself for the mailboxes you choose, and you can change it on any email in the Inbox.' : '' ?>
  <?= $canInbox ? '<a href="email.php">&larr; Inbox</a>' : '<a href="account.php">&larr; Your account</a>' ?></p>
<?php if (isset($_GET['deleted'])) flash('ok', 'Signature deleted.'); ?>
<?php if (!$all): ?>
  <div class="card empty">You don't have a signature yet. <a href="signature.php?new=1">Make your first one</a>.</div>
<?php else: ?>
  <table>
    <thead><tr><th>Signature</th><?php if ($canInbox): ?><th>Switches on for</th><?php endif; ?><th style="width:1%"></th></tr></thead>
    <tbody>
    <?php foreach ($all as $s): ?>
      <tr>
        <td><a href="signature.php?id=<?= $s['id'] ?>" style="font-weight:600"><?= e($s['name']) ?></a>
          <div class="muted" style="font-size:.82rem"><?= e(implode(' · ', array_filter([$s['data']['name'], $s['data']['title'], $s['data']['email']], 'strlen'))) ?></div></td>
        <?php if ($canInbox): ?><td>
          <?php foreach ($s['mailboxes'] as $mb): ?><span class="mbchip" style="--c:<?= e(mailbox_color($mb)) ?>"><?= e($mb) ?></span> <?php endforeach; ?>
          <?php if ($s['is_default']): ?><span class="badge new">Default for everything else</span><?php endif; ?>
          <?php if (!$s['mailboxes'] && !$s['is_default']): ?><span class="muted" style="font-size:.85rem">Only when you pick it</span><?php endif; ?>
        </td><?php endif; ?>
        <td style="white-space:nowrap"><a class="btn secondary small" href="signature.php?id=<?= $s['id'] ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php layout_footer(); exit; endif; ?>

<h1 class="page"><?= $cur ? 'Edit signature' : 'New signature' ?></h1>
<p class="sub">Fill this in, watch the preview, then copy it into Gmail, Outlook or Apple Mail. <a href="signature.php">&larr; All signatures</a></p>
<?php if (isset($_GET['saved'])) flash('ok', 'Signature saved.'); ?>

<div class="sig-grid">
<form method="post" id="sigForm" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $cur ? (int) $cur['id'] : 0 ?>">

  <div class="card" style="border-top:0;padding-top:0">
    <label style="margin-top:0">Signature name (only you see this)</label>
    <input type="text" name="sig_name" value="<?= e($sigName) ?>" maxlength="120" required>
    <?php if ($canInbox && $myBoxes): ?>
      <label>Switch on by itself when I send from</label>
      <div class="seg">
        <?php foreach ($myBoxes as $mb): ?>
          <label><input type="checkbox" name="mailboxes[]" value="<?= e($mb) ?>"<?= in_array($mb, $sigBoxes, true) ? ' checked' : '' ?>> <span class="mbdot" style="background:<?= e(mailbox_color($mb)) ?>"></span><?= e($mb) ?></label>
        <?php endforeach; ?>
      </div>
      <label style="display:flex;gap:.5rem;align-items:center;font-weight:500;color:var(--text)">
        <input type="checkbox" name="is_default" value="1" style="width:auto"<?= $sigDefault ? ' checked' : '' ?>> Use it for any other mailbox too (default)
      </label>
      <p class="muted" style="font-size:.8rem;margin-top:.3rem">A mailbox can only switch on one signature; ticking it here takes it off your others. You can always pick a different signature on an email.</p>
    <?php endif; ?>
  </div>
  <input type="hidden" name="photo_key" id="photo_key" value="<?= e($d['photo_key']) ?>">
  <input type="hidden" name="logo_key" id="logo_key" value="<?= e($d['logo_key']) ?>">

  <div class="card">
    <strong>Look</strong>
    <label>Layout</label>
    <div class="seg">
      <?php foreach (SIG_LAYOUTS as $k => $l): ?>
        <label><input type="radio" name="layout" value="<?= e($k) ?>"<?= $d['layout'] === $k ? ' checked' : '' ?>> <?= e($l) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="grid-auto">
      <div><label>Font</label><select name="font"><?php foreach (SIG_FONTS as $k => $_): ?><option<?= $d['font'] === $k ? ' selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select></div>
      <div><label>Colour</label>
        <div class="colorrow"><input type="color" id="colorPick" value="<?= e($d['color']) ?>"><input type="text" name="color" id="colorText" value="<?= e($d['color']) ?>" maxlength="7"></div>
      </div>
    </div>
  </div>

  <div class="card">
    <strong>You</strong>
    <div class="grid-auto">
      <div><label>Name</label><input type="text" name="name" value="<?= e($d['name']) ?>"></div>
      <div><label>Job title</label><input type="text" name="title" value="<?= e($d['title']) ?>" placeholder="Owner"></div>
      <div><label>Company</label><input type="text" name="company" value="<?= e($d['company']) ?>"></div>
      <div><label>Tagline (optional)</label><input type="text" name="tagline" value="<?= e($d['tagline']) ?>" placeholder="<?= e((string) client('tagline', '')) ?>"></div>
    </div>
  </div>

  <div class="card">
    <strong>Contact</strong>
    <div class="grid-auto">
      <div><label>Office phone</label><input type="tel" name="phone" value="<?= e($d['phone']) ?>"></div>
      <div><label>Mobile</label><input type="tel" name="mobile" value="<?= e($d['mobile']) ?>"></div>
      <div><label>Email</label><input type="email" name="email" value="<?= e($d['email']) ?>"></div>
      <div><label>Website</label><input type="text" name="website" value="<?= e($d['website']) ?>"></div>
      <div class="span-all"><label>Address (optional)</label><input type="text" name="address" value="<?= e($d['address']) ?>"></div>
    </div>
  </div>

  <div class="card">
    <strong>Pictures</strong>
    <label>Your photo (optional, shown as a circle)</label>
    <div class="imgrow">
      <img id="photoThumb" alt="" src="<?= $d['photo_key'] ? e(sig_public_base() . '/api/sig_image.php?k=' . $d['photo_key']) : '' ?>"<?= $d['photo_key'] ? '' : ' hidden' ?>>
      <input type="file" accept="image/png,image/jpeg,image/gif" data-up="photo" style="width:auto">
      <button type="button" class="btn secondary small" id="photoRemove"<?= $d['photo_key'] ? '' : ' hidden' ?>>Remove</button>
    </div>
    <p class="muted" style="font-size:.8rem;margin-top:.3rem">A square photo works best. PNG or JPG, under 1.5 MB.</p>

    <label>Logo</label>
    <div class="seg">
      <label><input type="radio" name="logo" value="company"<?= $d['logo'] === 'company' ? ' checked' : '' ?><?= $logo ? '' : ' disabled' ?>> <?= e((string) client('name')) ?> logo</label>
      <label><input type="radio" name="logo" value="custom"<?= $d['logo'] === 'custom' ? ' checked' : '' ?>> Upload another</label>
      <label><input type="radio" name="logo" value="none"<?= $d['logo'] === 'none' ? ' checked' : '' ?>> No logo</label>
    </div>
    <div class="imgrow" id="logoUpRow"<?= $d['logo'] === 'custom' ? '' : ' hidden' ?>>
      <img id="logoThumb" alt="" src="<?= $d['logo_key'] ? e(sig_public_base() . '/api/sig_image.php?k=' . $d['logo_key']) : '' ?>"<?= $d['logo_key'] ? '' : ' hidden' ?>>
      <input type="file" accept="image/png,image/jpeg,image/gif" data-up="logo" style="width:auto">
    </div>
    <label>Logo width: <span id="lwVal"><?= (int) $d['logo_width'] ?></span>px</label>
    <input type="range" name="logo_width" min="60" max="260" step="5" value="<?= (int) $d['logo_width'] ?>" style="padding:0">
  </div>

  <div class="card">
    <strong>Links</strong>
    <div class="grid-auto">
      <?php foreach (SIG_SOCIALS as $k => $l): ?>
        <div><label><?= e($l) ?></label><input type="text" name="social[<?= e($k) ?>]" value="<?= e($d['social'][$k] ?? '') ?>" placeholder="https://"></div>
      <?php endforeach; ?>
    </div>
    <div class="grid-auto">
      <div><label>Button text (optional)</label><input type="text" name="cta_text" value="<?= e($d['cta_text']) ?>" placeholder="Book a call"></div>
      <div><label>Button link</label><input type="text" name="cta_url" value="<?= e($d['cta_url']) ?>" placeholder="https://"></div>
    </div>
  </div>

  <div class="card">
    <strong>Small print</strong>
    <label>Disclaimer or note (optional)</label>
    <textarea name="disclaimer" rows="3" placeholder="This email and any attachments are confidential…"><?= e($d['disclaimer']) ?></textarea>
  </div>

  <div class="row-actions" style="border-top:1px solid var(--border);padding-top:1.2rem">
    <button class="btn" type="submit">Save signature</button>
    <span class="muted" style="font-size:.84rem" id="dirty" hidden>Unsaved changes</span>
    <?php if ($cur): ?>
      <span style="flex:1"></span>
      <button class="btn secondary" type="submit" name="action" value="duplicate" formnovalidate>Duplicate</button>
      <button class="btn danger" type="submit" name="action" value="delete" formnovalidate onclick="return confirm('Delete this signature?')">Delete</button>
    <?php endif; ?>
  </div>
</form>

<div class="sig-side">
  <div class="card" style="border-top:0;padding-top:0">
    <strong>Preview</strong>
    <div class="sig-paper">
      <div class="sig-mock">Hi Sam,<br><br>Thanks for your time today. I've attached the notes from our call.<br><br>Best,</div>
      <div id="sigOut" class="sig-reset"><?= sig_html($d) ?></div>
    </div>
    <div class="row-actions" style="margin-top:1rem">
      <button type="button" class="btn" id="copyRich">Copy signature</button>
      <button type="button" class="btn secondary" id="copyHtml">Copy HTML code</button>
      <button type="button" class="btn secondary" id="dlHtm">Download .htm</button>
      <span class="muted" id="copyMsg" style="font-size:.84rem"></span>
    </div>
    <p class="muted" style="font-size:.8rem;margin-top:.6rem">Pictures load from <?= e(preg_replace('#^https?://([^/]+).*#', '$1', (string) client('email.signature_images', '') ?: sig_public_base())) ?>, so they show up for the people you email.</p>
  </div>

  <div class="card howto">
    <strong>Add it to your email</strong>
    <details open><summary>Gmail</summary><ol>
      <li>Click <b>Copy signature</b> above.</li>
      <li>In Gmail, click the gear, then <b>See all settings</b>.</li>
      <li>On the <b>General</b> tab, scroll to <b>Signature</b> and click <b>Create new</b>.</li>
      <li>Click in the box and paste (Ctrl+V or ⌘V).</li>
      <li>Under <b>Signature defaults</b>, pick it for new emails and replies, then <b>Save Changes</b> at the bottom.</li>
    </ol></details>
    <details><summary>Outlook (new Outlook, Outlook on the web)</summary><ol>
      <li>Click <b>Copy signature</b>.</li>
      <li>Settings (gear) → <b>Accounts</b> → <b>Signatures</b> → <b>New signature</b>.</li>
      <li>Name it, paste into the box, choose it for new messages and replies, then <b>Save</b>.</li>
      <li>Classic Outlook for Windows: <b>File → Options → Mail → Signatures → New</b>, then paste.</li>
    </ol></details>
    <details><summary>Apple Mail (Mac)</summary><ol>
      <li>Click <b>Copy signature</b>.</li>
      <li>Mail → <b>Settings</b> → <b>Signatures</b>, pick your account, click <b>+</b>.</li>
      <li>Untick <b>Always match my default message font</b>, then paste over the sample text.</li>
    </ol></details>
    <details><summary>iPhone Mail</summary><ol>
      <li>Open this page on your iPhone, tap <b>Copy signature</b>.</li>
      <li>Settings → <b>Apps</b> → <b>Mail</b> → <b>Signature</b>, delete the old text and paste.</li>
      <li>The iPhone keeps the text, links and colours; pictures usually don't come through.</li>
    </ol></details>
  </div>
</div>
</div>

<script>
(function(){
  var form = document.getElementById('sigForm'), out = document.getElementById('sigOut');
  var csrf = form.querySelector('[name=csrf]').value, timer = null, plain = '';
  function $(id){ return document.getElementById(id); }
  function refresh(){
    var fd = new FormData(form); fd.set('action', 'preview');
    fetch('signature.php', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){ out.innerHTML = j.html; plain = j.text; })
      .catch(function(){});
  }
  function changed(){ $('dirty').hidden = false; clearTimeout(timer); timer = setTimeout(refresh, 250); }
  form.addEventListener('input', changed);
  form.addEventListener('change', function(e){
    if (e.target.name === 'logo') $('logoUpRow').hidden = e.target.value !== 'custom';
    changed();
  });
  form.querySelector('[name=logo_width]').addEventListener('input', function(){ $('lwVal').textContent = this.value; });

  // Colour: picker and text box stay in step
  $('colorPick').addEventListener('input', function(){ $('colorText').value = this.value; changed(); });
  $('colorText').addEventListener('input', function(){ if (/^#[0-9a-f]{6}$/i.test(this.value)) $('colorPick').value = this.value; });

  // Uploads go straight up; the form then just carries the key.
  form.querySelectorAll('input[type=file][data-up]').forEach(function(inp){
    inp.addEventListener('change', function(){
      if (!inp.files.length) return;
      var which = inp.dataset.up, fd = new FormData();
      fd.append('csrf', csrf); fd.append('action', 'upload'); fd.append('image', inp.files[0]);
      $('copyMsg').textContent = 'Uploading…';
      fetch('signature.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
        inp.value = '';
        if (j.err) { $('copyMsg').textContent = j.err; return; }
        $('copyMsg').textContent = '';
        $(which + '_key').value = j.k;
        var th = $(which + 'Thumb'); th.src = <?= json_encode(sig_public_base() . '/api/sig_image.php?k=') ?> + j.k; th.hidden = false;
        if (which === 'photo') $('photoRemove').hidden = false;
        changed();
      }).catch(function(){ $('copyMsg').textContent = 'Upload failed. Try again.'; });
    });
  });
  $('photoRemove').addEventListener('click', function(){
    $('photo_key').value = ''; $('photoThumb').hidden = true; this.hidden = true; changed();
  });

  // Copying
  function say(t){ $('copyMsg').textContent = t; setTimeout(function(){ if ($('copyMsg').textContent === t) $('copyMsg').textContent = ''; }, 4000); }
  function selectCopy(node){
    var r = document.createRange(); r.selectNode(node);
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
    var ok = false; try { ok = document.execCommand('copy'); } catch(e){}
    s.removeAllRanges(); return ok;
  }
  $('copyRich').addEventListener('click', function(){
    var html = out.innerHTML;
    if (navigator.clipboard && window.ClipboardItem) {
      navigator.clipboard.write([new ClipboardItem({
        'text/html': new Blob([html], {type:'text/html'}),
        'text/plain': new Blob([plain || out.innerText], {type:'text/plain'})
      })]).then(function(){ say('Copied. Paste it into your email settings.'); },
                function(){ say(selectCopy(out) ? 'Copied. Paste it into your email settings.' : 'Select the signature and copy it by hand.'); });
    } else say(selectCopy(out) ? 'Copied. Paste it into your email settings.' : 'Select the signature and copy it by hand.');
  });
  $('copyHtml').addEventListener('click', function(){
    var html = out.innerHTML;
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(html).then(function(){ say('HTML copied.'); }, fallback);
    else fallback();
    function fallback(){ var t = document.createElement('textarea'); t.value = html; document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); say('HTML copied.'); } catch(e){ say('Could not copy.'); } t.remove(); }
  });
  $('dlHtm').addEventListener('click', function(){
    var doc = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Signature</title></head><body>' + out.innerHTML + '</body></html>';
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([doc], {type:'text/html'}));
    a.download = 'signature.htm'; document.body.appendChild(a); a.click(); a.remove();
  });
  window.addEventListener('beforeunload', function(e){ if (!$('dirty').hidden && !form.dataset.submitting) { e.preventDefault(); e.returnValue = ''; } });
  form.addEventListener('submit', function(){ form.dataset.submitting = '1'; });
  refresh();
})();
</script>
<?php
layout_footer();
