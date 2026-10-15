<?php
/** Ops Inbox — read and send across the client's mailboxes (email.accounts in the client file). */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_imap.php';
require_once __DIR__ . '/_mail.php';
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
$ai = (int)($_GET['acct'] ?? 0);
if ($ai < 0 || $ai >= count($accounts)) $ai = 0;
$account = $accounts[$ai];

// --- Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'send') {
        $from = $_POST['from'] ?? $account;
        if (!in_array($from, $accounts, true)) $from = $account;
        $to = trim($_POST['to'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = (string)($_POST['body'] ?? '');
        if ($to === '') { header('Location: email.php?acct=' . $ai . '&senderr=1'); exit; }
        $ok = send_plain($to, $subject, $body, $from, $from, (string) client('name'));
        header('Location: email.php?acct=' . $ai . ($ok ? '&sent=1' : '&senderr=1'));
        exit;
    } elseif ($a === 'delete') {
        imap_delete($account, (int)($_POST['uid'] ?? 0));
        header('Location: email.php?acct=' . $ai . '&deleted=1');
        exit;
    }
}

$view = 'list';
$msg = null; $err = '';
$prefill = ['to'=>'','subject'=>'','body'=>'','from'=>$account];

if (isset($_GET['compose'])) {
    $view = 'compose';
} elseif (isset($_GET['reply'])) {
    $view = 'compose';
    try {
        $src = imap_open($account, (int)$_GET['reply']);
        if ($src) {
            $prefill['to'] = $src['from_mail'];
            $prefill['subject'] = (stripos($src['subject'], 're:') === 0 ? '' : 'Re: ') . $src['subject'];
            $q = "\n\n--- On " . $src['date'] . ', ' . $src['from_name'] . " wrote: ---\n";
            foreach (explode("\n", $src['body']) as $ln) $q .= '> ' . $ln . "\n";
            $prefill['body'] = $q;
        }
    } catch (\Throwable $e) { $err = 'Could not load the message to reply to.'; }
} elseif (isset($_GET['uid'])) {
    $view = 'read';
    try { $msg = imap_open($account, (int)$_GET['uid']); }
    catch (\Throwable $e) { $err = 'Could not open that message.'; }
    if (!$msg && $err === '') $err = 'Message not found.';
}

$list = [];
if ($view === 'list') {
    try { $list = imap_list($account, 30); }
    catch (\Throwable $e) {
        $err = 'Could not connect to ' . $account . '. Every mailbox here has to use the same password (SMTP_PASS); if this one is different, that\'s likely why.';
    }
}

layout_header('Inbox', 'email.php');
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
  <h1 class="page">Inbox</h1>
  <a href="email.php?acct=<?= $ai ?>&compose=1" class="btn">✎ Compose</a>
</div>
<?php
if (isset($_GET['sent'])) flash('ok', 'Message sent.');
if (isset($_GET['deleted'])) flash('ok', 'Message deleted.');
if (isset($_GET['senderr'])) flash('err', 'Could not send. Check the recipient and try again.');
if ($err) flash('err', $err);
?>

<div style="display:grid;grid-template-columns:190px 1fr;gap:1rem">
  <div class="card" style="padding:.5rem">
    <?php foreach ($accounts as $i => $acc): $label = explode('@', $acc)[0] . '@'; ?>
      <a href="email.php?acct=<?= $i ?>" style="display:block;padding:.55rem .6rem;border-radius:7px;text-decoration:none;font-size:.9rem;<?= $i === $ai ? 'background:var(--accent);color:var(--accent-text);font-weight:600' : 'color:var(--text)' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <?php if ($view === 'compose'): ?>
      <a href="email.php?acct=<?= $ai ?>" style="font-size:.85rem">&larr; Back to inbox</a>
      <form method="post" style="margin-top:.8rem">
        <?= csrf_field() ?><input type="hidden" name="action" value="send">
        <label>From</label>
        <select name="from"><?php foreach ($accounts as $acc): ?><option value="<?= e($acc) ?>"<?= $acc === $prefill['from'] ? ' selected' : '' ?>><?= e($acc) ?></option><?php endforeach; ?></select>
        <label>To</label><input type="email" name="to" value="<?= e($prefill['to']) ?>" required>
        <label>Subject</label><input type="text" name="subject" value="<?= e($prefill['subject']) ?>">
        <label>Message</label><textarea name="body" rows="12"><?= e($prefill['body']) ?></textarea>
        <div style="margin-top:1rem"><button class="btn" type="submit">Send</button></div>
      </form>

    <?php elseif ($view === 'read' && $msg): ?>
      <a href="email.php?acct=<?= $ai ?>" style="font-size:.85rem">&larr; Back to inbox</a>
      <h2 style="font-family:Georgia,serif;margin:.6rem 0 .4rem"><?= e($msg['subject'] ?: '(no subject)') ?></h2>
      <div class="muted" style="font-size:.84rem"><strong>From:</strong> <?= e($msg['from_name']) ?> &lt;<?= e($msg['from_mail']) ?>&gt;</div>
      <div class="muted" style="font-size:.84rem"><strong>To:</strong> <?= e($msg['to']) ?></div>
      <div class="muted" style="font-size:.84rem"><strong>Date:</strong> <?= e($msg['date']) ?></div>
      <?php if ($msg['attachments']): ?><div class="muted" style="font-size:.84rem;margin-top:.3rem"><strong>Attachments:</strong> <?= e(implode(', ', $msg['attachments'])) ?></div><?php endif; ?>
      <div class="row-actions" style="margin:.9rem 0">
        <a href="email.php?acct=<?= $ai ?>&reply=<?= (int)$msg['uid'] ?>" class="btn secondary small">Reply</a>
        <form method="post" onsubmit="return confirm('Delete this message?')" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="uid" value="<?= (int)$msg['uid'] ?>"><button class="btn danger small">Delete</button></form>
      </div>
      <?php if ($msg['html'] !== ''):
        $showImg = !empty($_GET['img']);
        $remote = preg_match('#<img\b[^>]*\ssrc\s*=\s*["\']?https?://#i', $msg['html']);
        $frameSrc = 'email_body.php?acct=' . $ai . '&uid=' . (int)$msg['uid'] . ($showImg ? '&img=1' : ''); ?>
        <?php if ($remote && !$showImg): ?>
          <div class="muted" style="font-size:.82rem;margin:.2rem 0 .6rem;padding:.5rem .7rem;border:1px dashed var(--border);border-radius:7px">
            Pictures from the internet are hidden (they can tell the sender you opened it).
            <a href="email.php?acct=<?= $ai ?>&uid=<?= (int)$msg['uid'] ?>&img=1">Show pictures</a>
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
      <?php if (!$list): ?>
        <div class="empty"><?= $err ? '' : 'Inbox is empty, or still loading.' ?></div>
      <?php else: ?>
        <table>
          <tbody>
          <?php foreach ($list as $row): ?>
            <tr style="cursor:pointer" onclick="location='email.php?acct=<?= $ai ?>&uid=<?= (int)$row['uid'] ?>'">
              <td style="width:170px;<?= $row['seen'] ? '' : 'font-weight:700' ?>"><?= e($row['from_name']) ?></td>
              <td style="<?= $row['seen'] ? '' : 'font-weight:700' ?>"><?= e($row['subject'] ?: '(no subject)') ?></td>
              <td class="muted" style="width:110px;text-align:right;font-size:.8rem"><?= e($row['date'] ? date('M j', strtotime($row['date'])) : '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php
layout_footer();
