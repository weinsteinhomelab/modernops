<?php
/**
 * Ops Inbox — one mailbox view across every address the person can use
 * (email.accounts in the client file, narrowed by my_mailboxes()).
 * Inbox / Sent / Drafts / Archive / Spam / Deleted, colour-coded by the
 * address each email came in to (or went out from).
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_imap.php';
require_once __DIR__ . '/_mail.php';
require_once __DIR__ . '/_signature.php';
require_login();
require_product('inbox');

if (!imap_ready()) {
    layout_header('Inbox', 'email.php');
    echo '<h1 class="page">Inbox</h1>';
    echo '<div class="card"><strong>Inbox isn\'t ready yet.</strong><p class="muted" style="margin-top:.5rem">This needs the IMAP library and your mailbox password. Rebuild the app (<code>docker compose up -d --build</code>) and make sure <code>SMTP_PASS</code> is set in <code>.env</code>.</p></div>';
    layout_footer();
    exit;
}

$accounts = my_mailboxes();   // admins: all; users: the ones an admin gave them
if (!$accounts) deny('No mailboxes have been shared with you yet.');
$me = me();
$uid0 = me_id();

$box = $_GET['box'] ?? 'inbox';
if (!isset(MAIL_BOXES[$box])) $box = 'inbox';
$only = (string)($_GET['mb'] ?? '');                 // show one address only
if (!in_array($only, $accounts, true)) $only = '';

/** URL for this page, keeping the box and filter unless overridden. */
function mail_url(array $p = []): string {
    global $box, $only;
    $q = array_filter(array_merge(['box' => $box, 'mb' => $only], $p), fn($v) => $v !== '' && $v !== null);
    return 'email.php' . ($q ? '?' . http_build_query($q) : '');
}
/** Message reference from the request: [address, server folder, uid]. */
function msg_ref(array $accounts): ?array {
    $a = (int)($_REQUEST['a'] ?? -1);
    $f = (string)($_REQUEST['f'] ?? '');
    $u = (int)($_REQUEST['uid'] ?? 0);
    if ($a < 0 || $a >= count($accounts) || $f === '' || $u <= 0 || imap_box_of($f) === null) return null;
    return [$accounts[$a], $f, $u];
}
function msg_qs(array $accounts, string $account, string $folder, int $uid): array {
    return ['a' => array_search($account, $accounts, true), 'f' => $folder, 'uid' => $uid];
}
function when(int $ts): string {
    if (!$ts) return '';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('g:i A', $ts);
    if (date('Y', $ts) === date('Y')) return date('M j', $ts);
    return date('n/j/y', $ts);
}

// ---------------- Actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'send') {
        $from = (string)($_POST['from'] ?? $accounts[0]);
        if (!in_array($from, $accounts, true)) $from = $accounts[0];
        $to = trim((string)($_POST['to'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body = str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { header('Location: ' . mail_url(['compose' => 1, 'senderr' => 1])); exit; }
        $extra = [];
        $irt = trim((string)($_POST['in_reply_to'] ?? ''), "<> \t");
        if ($irt !== '' && preg_match('/^[^\s<>]+$/', $irt)) { $extra['In-Reply-To'] = '<' . $irt . '>'; $extra['References'] = '<' . $irt . '>'; }
        $name = trim((string)($me['name'] ?? '')) ?: (string) client('name');

        $sigId = (int)($_POST['sig'] ?? 0);
        $sig = $sigId > 0 ? sig_get($uid0, $sigId, $me) : null;
        if ($sig) {
            // Signature goes above the quoted message on replies and forwards.
            $cut = preg_match('/\n(--- On |-------- Forwarded message)/', $body, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : false;
            $top = rtrim($cut === false ? $body : substr($body, 0, $cut));
            $quoted = $cut === false ? '' : ltrim(substr($body, $cut), "\n");
            $font = 'font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222222';
            $html = '<div style="' . $font . '">' . nl2br(e($top)) . '</div><br>' . sig_html($sig['data'])
                  . ($quoted !== '' ? '<div style="' . $font . ';color:#555555;margin-top:16px">' . nl2br(e($quoted)) . '</div>' : '');
            $text = $top . "\n\n-- \n" . sig_text($sig['data']) . ($quoted !== '' ? "\n\n" . $quoted : '');
            $ok = send_alt($to, $subject, $text, '<!DOCTYPE html><html><body>' . $html . '</body></html>', $from, $from, $name, '', $extra);
        } else {
            $ok = send_plain($to, $subject, $body, $from, $from, $name, '', $extra);
        }
        if ($ok && !empty($GLOBALS['MOHQ_LAST_SENT'])) mbox_save_sent($from, $GLOBALS['MOHQ_LAST_SENT']);
        if ($ok) log_activity('email.sent', 'mail', 0, $from . ' → ' . $to);
        header('Location: ' . mail_url($ok ? ['sent' => 1] : ['compose' => 1, 'senderr' => 1]));
        exit;
    }
    $ref = msg_ref($accounts);
    if ($ref) {
        [$acct, $folder, $u] = $ref;
        if ($a === 'move' && isset(MAIL_BOXES[$_POST['to'] ?? ''])) {
            $ok = mbox_move($acct, $folder, $u, $_POST['to']);
            header('Location: ' . mail_url(['done' => $ok ? 'moved-' . $_POST['to'] : 'err'])); exit;
        }
        if ($a === 'forever') {
            $ok = mbox_delete_forever($acct, $folder, $u);
            header('Location: ' . mail_url(['done' => $ok ? 'gone' : 'err'])); exit;
        }
        if ($a === 'unread') {
            mbox_set_seen($acct, $folder, $u, false);
            header('Location: ' . mail_url()); exit;
        }
    }
    header('Location: ' . mail_url()); exit;
}

// ---------------- What to show ----------------
$view = 'list';
$msg = null; $err = '';
$prefill = ['to' => '', 'subject' => '', 'body' => '', 'from' => $only ?: $accounts[0], 'irt' => ''];
$ref = msg_ref($accounts);

if (isset($_GET['compose'])) {
    $view = 'compose';
} elseif ($ref && (isset($_GET['reply']) || isset($_GET['fwd']))) {
    $view = 'compose';
    try {
        $src = mbox_open($ref[0], $ref[1], $ref[2]);
        if ($src) {
            $prefill['from'] = $ref[0];
            if (isset($_GET['reply'])) {
                $prefill['to'] = $src['box'] === 'sent' ? explode(', ', $src['to'])[0] : $src['from_mail'];
                $prefill['subject'] = (stripos($src['subject'], 're:') === 0 ? '' : 'Re: ') . $src['subject'];
                $prefill['irt'] = $src['message_id'];
                $q = "\n\n--- On " . $src['date'] . ', ' . $src['from_name'] . " wrote: ---\n";
                foreach (explode("\n", $src['body']) as $ln) $q .= '> ' . $ln . "\n";
            } else {
                $prefill['subject'] = (stripos($src['subject'], 'fwd:') === 0 ? '' : 'Fwd: ') . $src['subject'];
                $q = "\n\n-------- Forwarded message --------\nFrom: " . $src['from_name'] . ' <' . $src['from_mail'] . ">\nDate: " . $src['date']
                   . "\nSubject: " . $src['subject'] . "\nTo: " . $src['to'] . "\n\n" . $src['body'] . "\n";
                if ($src['attachments']) $q .= "\n(Attachments not included: " . implode(', ', $src['attachments']) . ")\n";
            }
            $prefill['body'] = $q;
        }
    } catch (\Throwable $e) { $err = 'Could not load that message.'; }
} elseif ($ref) {
    $view = 'read';
    try { $msg = mbox_open($ref[0], $ref[1], $ref[2]); }
    catch (\Throwable $e) { $err = 'Could not open that message.'; }
    if (!$msg && $err === '') $err = 'Message not found. It may have been moved.';
}

$limit = isset($_GET['more']) ? 120 : 40;
$list = []; $failed = [];
if ($view === 'list') {
    foreach ($only ? [$only] : $accounts as $acct) {
        try { $list = array_merge($list, mbox_list($acct, $box, $limit, isset($_GET['fresh']))); }
        catch (\Throwable $e) { $failed[] = $acct; }
    }
    usort($list, fn($x, $y) => $y['ts'] <=> $x['ts']);
}
// Unread count for the Inbox link, from the inbox lists (fresh or cached).
$unread = 0;
foreach ($accounts as $acct) {
    if ($box === 'inbox' && $view === 'list') $rows = array_filter($list, fn($r) => $r['account'] === $acct);
    else { $cf = mbox_cache_file($acct, 'inbox', 40); $rows = is_file($cf) ? (array) json_decode((string) file_get_contents($cf), true) : []; }
    foreach ($rows as $r) if (empty($r['seen'])) $unread++;
}

// Signatures for compose, and which one each From address switches on.
$sigs = []; $sigAuto = []; $sigDefault = 0;
if ($view === 'compose') {
    $sigs = sig_all($uid0, $me);
    foreach ($sigs as $s) {
        foreach ($s['mailboxes'] as $mb) $sigAuto[$mb] = $s['id'];
        if ($s['is_default']) $sigDefault = $s['id'];
    }
}

layout_header('Inbox', 'email.php');
?>
<style>
  .mail{display:grid;grid-template-columns:210px minmax(0,1fr);gap:0 2rem;align-items:start}
  .mnav{position:sticky;top:76px;display:flex;flex-direction:column;gap:.15rem}
  .mnav .compose{margin-bottom:.9rem;text-align:center}
  .mnav a.box{display:flex;justify-content:space-between;align-items:center;gap:.6rem;padding:.5rem .7rem;border-radius:7px;color:var(--text);font-size:.92rem;text-decoration:none}
  .mnav a.box:hover,.mnav a.mb:hover{background:color-mix(in srgb,var(--text) 6%,transparent)}
  .mnav a.box.on{background:color-mix(in srgb,var(--accent) 14%,transparent);color:var(--accent);font-weight:700}
  .mnav .cnt{font-size:.78rem;font-weight:700}
  .mnav .sect{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:1.2rem .7rem .4rem}
  .mnav a.mb{display:flex;align-items:center;gap:.55rem;padding:.38rem .7rem;border-radius:7px;color:var(--text);font-size:.86rem;text-decoration:none}
  .mnav a.mb.on{font-weight:700;background:color-mix(in srgb,var(--c) 14%,transparent)}
  .mdot{width:10px;height:10px;border-radius:50%;background:var(--c);flex:none}
  .mbchip{display:inline-block;font-size:.72rem;font-weight:700;padding:.1rem .5rem;border-radius:20px;color:var(--c);background:color-mix(in srgb,var(--c) 14%,transparent);white-space:nowrap}
  .mtool{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin-bottom:.6rem}
  .mtool input{max-width:300px}
  .mrow{display:grid;grid-template-columns:4px minmax(120px,210px) minmax(0,1fr) auto 72px;gap:0 .9rem;align-items:center;padding:.62rem .6rem .62rem 0;border-bottom:1px solid var(--border);color:var(--text);text-decoration:none;font-size:.9rem}
  .mrow:hover{background:color-mix(in srgb,var(--text) 4%,transparent);text-decoration:none}
  .mrow[hidden]{display:none}
  .mrow .bar{align-self:stretch;background:var(--c);border-radius:2px}
  .mrow .who,.mrow .subj{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .mrow .date{text-align:right;color:var(--muted);font-size:.8rem;white-space:nowrap}
  .mrow.unread .who,.mrow.unread .subj{font-weight:700}
  .mrow.unread .date{color:var(--text);font-weight:700}
  .mhead{border-left:4px solid var(--c);padding-left:1rem}
  .mact{display:flex;gap:.4rem;flex-wrap:wrap;margin:1rem 0}
  .mact form{display:inline}
  .sig-reset table{width:auto}.sig-reset td{padding:0;border:0}
  @media(max-width:820px){
    .mail{grid-template-columns:1fr}
    .mnav{position:static;flex-direction:row;flex-wrap:nowrap;overflow-x:auto;gap:.3rem;padding-bottom:.6rem;margin-bottom:.6rem;border-bottom:1px solid var(--border)}
    .mnav .compose{margin:0;flex:none}
    .mnav a.box,.mnav a.mb{flex:none;white-space:nowrap}
    .mnav .sect{display:none}
    .mrow{grid-template-columns:4px minmax(0,1fr) 64px;row-gap:.1rem}
    .mrow .bar{grid-row:1/3}
    .mrow .subj{grid-column:2/4;grid-row:2}
    .mrow .chipcell{display:none}
    .mrow .date{grid-row:1;grid-column:3}
  }
</style>

<?php
if (isset($_GET['sent'])) flash('ok', 'Message sent. A copy is in Sent.');
if (isset($_GET['senderr'])) flash('err', 'Could not send. Check the email address and try again.');
$done = (string)($_GET['done'] ?? '');
if ($done === 'gone') flash('ok', 'Deleted for good.');
elseif ($done === 'err') flash('err', 'That didn\'t work. Try again.');
elseif (strpos($done, 'moved-') === 0) flash('ok', ['deleted' => 'Moved to Deleted.', 'spam' => 'Moved to Spam.', 'archive' => 'Archived.', 'inbox' => 'Moved to Inbox.'][substr($done, 6)] ?? 'Moved.');
if ($err) flash('err', $err);
if ($failed) flash('err', 'Could not reach ' . implode(', ', $failed) . '. Every mailbox here has to use the same password (SMTP_PASS).');
?>

<div class="mail">
  <nav class="mnav">
    <a class="btn compose" href="<?= e(mail_url(['compose' => 1])) ?>">✎ Compose</a>
    <?php foreach (MAIL_BOXES as $k => $label): ?>
      <a class="box<?= $box === $k && $view !== 'compose' ? ' on' : '' ?>" href="<?= e(mail_url(['box' => $k])) ?>"><span><?= e($label) ?></span><?php if ($k === 'inbox' && $unread): ?><span class="cnt"><?= $unread ?></span><?php endif; ?></a>
    <?php endforeach; ?>
    <?php if (count($accounts) > 1): ?>
      <div class="sect">Addresses</div>
      <a class="mb<?= $only === '' ? ' on' : '' ?>" style="--c:var(--accent)" href="<?= e(mail_url(['mb' => ''])) ?>"><span class="mdot" style="background:conic-gradient(<?= e(implode(',', array_map('mailbox_color', $accounts))) ?>)"></span>All addresses</a>
      <?php foreach ($accounts as $acct): ?>
        <a class="mb<?= $only === $acct ? ' on' : '' ?>" style="--c:<?= e(mailbox_color($acct)) ?>" href="<?= e(mail_url(['mb' => $acct])) ?>"><span class="mdot"></span><?= e($acct) ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
    <div class="sect">Settings</div>
    <a class="mb" style="--c:var(--muted)" href="signature.php">✎ Signatures</a>
  </nav>

  <section>
  <?php if ($view === 'compose'): ?>
    <h1 class="page" style="margin-bottom:.4rem"><?= isset($_GET['reply']) ? 'Reply' : (isset($_GET['fwd']) ? 'Forward' : 'New message') ?></h1>
    <form method="post" id="composeForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="send">
      <input type="hidden" name="in_reply_to" value="<?= e($prefill['irt']) ?>">
      <div class="grid-auto">
        <div><label>From</label>
          <select name="from" id="fromSel"><?php foreach ($accounts as $acct): ?><option value="<?= e($acct) ?>"<?= $acct === $prefill['from'] ? ' selected' : '' ?>><?= e($acct) ?></option><?php endforeach; ?></select></div>
        <div class="span-2"><label>To</label><input type="email" name="to" value="<?= e($prefill['to']) ?>" required></div>
      </div>
      <label>Subject</label><input type="text" name="subject" value="<?= e($prefill['subject']) ?>">
      <label>Message</label><textarea name="body" rows="14"><?= e($prefill['body']) ?></textarea>
      <label>Signature</label>
      <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
        <select name="sig" id="sigSel" style="max-width:320px">
          <option value="0">No signature</option>
          <?php foreach ($sigs as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
        <a href="signature.php" style="font-size:.85rem"><?= $sigs ? 'Edit signatures' : 'Make a signature' ?></a>
      </div>
      <?php foreach ($sigs as $s): ?>
        <div class="sig-reset sigprev" data-sig="<?= $s['id'] ?>" hidden style="background:#fff;color:#333;padding:1rem 1.2rem;margin-top:.6rem;border:1px solid var(--border)"><?= sig_html($s['data']) ?></div>
      <?php endforeach; ?>
      <div class="row-actions" style="margin-top:1.1rem"><button class="btn" type="submit">Send</button><a class="btn secondary" href="<?= e(mail_url()) ?>">Cancel</a></div>
    </form>
    <script>
    (function(){
      var auto = <?= json_encode((object) $sigAuto) ?>, def = <?= (int) $sigDefault ?>;
      var from = document.getElementById('fromSel'), sel = document.getElementById('sigSel'), touched = false;
      function show(){ document.querySelectorAll('.sigprev').forEach(function(d){ d.hidden = d.dataset.sig !== sel.value; }); }
      function pick(){ if (!touched) sel.value = String(auto[from.value] || def || 0); show(); }
      from.addEventListener('change', pick);
      sel.addEventListener('change', function(){ touched = true; show(); });
      pick();
    })();
    </script>

  <?php elseif ($view === 'read' && $msg):
      $c = mailbox_color($msg['account']);
      $qs = msg_qs($accounts, $msg['account'], $msg['folder'], (int) $msg['uid']);
      $inBox = $msg['box'] ?: 'inbox'; ?>
    <a href="<?= e(mail_url()) ?>" style="font-size:.85rem">&larr; <?= e(MAIL_BOXES[$box]) ?></a>
    <div class="mhead" style="--c:<?= e($c) ?>;margin-top:.8rem">
      <h1 class="page" style="margin-bottom:.5rem"><?= e($msg['subject'] ?: '(no subject)') ?></h1>
      <div style="font-size:.9rem"><strong><?= e($msg['from_name']) ?></strong> <span class="muted">&lt;<?= e($msg['from_mail']) ?>&gt;</span></div>
      <div class="muted" style="font-size:.84rem">To <?= e($msg['to']) ?><?= $msg['cc'] !== '' ? ' · Cc ' . e($msg['cc']) : '' ?> · <?= e($msg['date']) ?></div>
      <div style="margin-top:.35rem"><span class="mbchip" style="--c:<?= e($c) ?>"><?= $inBox === 'sent' ? 'Sent from ' : 'Came in to ' ?><?= e($msg['account']) ?></span></div>
      <?php if ($msg['attachments']): ?><div class="muted" style="font-size:.84rem;margin-top:.4rem">Attachments: <?= e(implode(', ', $msg['attachments'])) ?></div><?php endif; ?>
    </div>
    <div class="mact">
      <?php if ($inBox !== 'drafts'): ?><a class="btn small" href="<?= e(mail_url($qs + ['reply' => 1])) ?>">Reply</a><?php endif; ?>
      <a class="btn secondary small" href="<?= e(mail_url($qs + ['fwd' => 1])) ?>">Forward</a>
      <?php
        $btn = function (string $label, string $action, string $to = '', string $cls = 'secondary', string $confirm = '') use ($qs) {
            echo '<form method="post"' . ($confirm ? ' onsubmit="return confirm(' . e(json_encode($confirm)) . ')"' : '') . '>' . csrf_field()
               . '<input type="hidden" name="action" value="' . e($action) . '">' . ($to ? '<input type="hidden" name="to" value="' . e($to) . '">' : '')
               . '<input type="hidden" name="a" value="' . (int) $qs['a'] . '"><input type="hidden" name="f" value="' . e($qs['f']) . '"><input type="hidden" name="uid" value="' . (int) $qs['uid'] . '">'
               . '<button class="btn ' . $cls . ' small">' . e($label) . '</button></form>';
        };
        if ($inBox === 'inbox') $btn('Archive', 'move', 'archive');
        if (in_array($inBox, ['archive', 'spam', 'deleted'], true)) $btn($inBox === 'spam' ? 'Not spam' : 'Move to Inbox', 'move', 'inbox');
        if (in_array($inBox, ['inbox', 'archive'], true)) $btn('Spam', 'move', 'spam');
        $btn('Mark unread', 'unread');
        if (in_array($inBox, ['deleted', 'spam'], true)) $btn('Delete forever', 'forever', '', 'danger', 'Delete this email for good? This can\'t be undone.');
        else $btn('Delete', 'move', 'deleted', 'danger');
      ?>
    </div>
    <?php if ($msg['html'] !== ''):
      $showImg = !empty($_GET['img']);
      $remote = preg_match('#<img\b[^>]*\ssrc\s*=\s*["\']?https?://#i', $msg['html']);
      $frameSrc = 'email_body.php?' . http_build_query($qs + ($showImg ? ['img' => 1] : [])); ?>
      <?php if ($remote && !$showImg): ?>
        <div class="muted" style="font-size:.82rem;margin:.2rem 0 .6rem;padding:.5rem .7rem;border:1px dashed var(--border);border-radius:7px">
          Pictures from the internet are hidden (they can tell the sender you opened it).
          <a href="<?= e(mail_url($qs + ['img' => 1])) ?>">Show pictures</a>
        </div>
      <?php endif; ?>
      <iframe id="mailframe" src="<?= e($frameSrc) ?>" title="Message" sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin"
              style="width:100%;min-height:320px;border:1px solid var(--border);border-radius:8px;background:#fff;display:block"></iframe>
      <script>
        (function(){
          var f = document.getElementById('mailframe');
          function fit(){ try { var d = f.contentDocument; if (d && d.documentElement) f.style.height = (d.documentElement.scrollHeight + 4) + 'px'; } catch(e){} }
          f.addEventListener('load', function(){ fit(); setTimeout(fit, 400); setTimeout(fit, 1500); });
          window.addEventListener('resize', fit);
        })();
      </script>
    <?php else: ?>
      <div style="margin-top:.8rem;line-height:1.6;white-space:pre-wrap;font-size:.92rem;overflow-wrap:anywhere"><?= e($msg['body']) ?></div>
    <?php endif; ?>

  <?php else: ?>
    <div class="mtool">
      <h1 class="page" style="margin:0 auto 0 0"><?= e(MAIL_BOXES[$box]) ?><?= $only ? ' <span class="mbchip" style="--c:' . e(mailbox_color($only)) . ';vertical-align:middle">' . e($only) . '</span>' : '' ?></h1>
      <input type="search" id="mfilter" placeholder="Search these emails">
      <a class="btn secondary small" href="<?= e(mail_url(['fresh' => 1])) ?>" title="Check for new mail">↻ Refresh</a>
    </div>
    <?php if (!$list): ?>
      <div class="empty"><?= $failed ? '' : 'Nothing in ' . e(MAIL_BOXES[$box]) . '.' ?></div>
    <?php else: ?>
      <div id="mlist">
      <?php $outgoing = in_array($box, ['sent', 'drafts'], true);
      foreach ($list as $r):
        $c = mailbox_color($r['account']);
        $who = $outgoing ? 'To: ' . ($r['to'] ?: '(no recipient)') : $r['from_name'];
        $href = mail_url(msg_qs($accounts, $r['account'], $r['folder'], (int) $r['uid'])); ?>
        <a class="mrow<?= empty($r['seen']) && !$outgoing ? ' unread' : '' ?>" style="--c:<?= e($c) ?>" href="<?= e($href) ?>" data-s="<?= e(mb_strtolower($who . ' ' . $r['from_mail'] . ' ' . $r['subject'] . ' ' . $r['account'])) ?>" title="<?= e(($outgoing ? 'From ' : 'To ') . $r['account']) ?>">
          <span class="bar"></span>
          <span class="who"><?= e($who) ?></span>
          <span class="subj"><?= e($r['subject'] ?: '(no subject)') ?></span>
          <span class="chipcell"><span class="mbchip" style="--c:<?= e($c) ?>"><?= e(explode('@', $r['account'])[0]) ?>@</span></span>
          <span class="date"><?= e(when((int) $r['ts'])) ?></span>
        </a>
      <?php endforeach; ?>
      </div>
      <p class="muted" style="font-size:.84rem;margin-top:.8rem"><?= count($list) ?> emails<?= $limit < 120 && count($list) >= $limit ? ' · <a href="' . e(mail_url(['more' => 1])) . '">Show older</a>' : '' ?></p>
      <script>
        document.getElementById('mfilter').addEventListener('input', function(){
          var q = this.value.trim().toLowerCase();
          document.querySelectorAll('#mlist .mrow').forEach(function(r){ r.hidden = !!q && r.dataset.s.indexOf(q) < 0; });
        });
      </script>
    <?php endif; ?>
  <?php endif; ?>
  </section>
</div>
<?php
layout_footer();
