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
require_once __DIR__ . '/_sites.php';
const MAIL_ATTACH_MAX = 18 * 1024 * 1024;   // all attachments together (base64 adds a third; mail servers often cap at ~25 MB)
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // the upload was bigger than the server accepts, so nothing arrived
    header('Location: ' . mail_url(['compose' => 1, 'senderr' => 'big'])); exit;
}
/** Files to send with this email: uploads, saved customer pictures, and (forwarding) the original's attachments. [files, error] */
function compose_attachments(array $accounts): array {
    $files = []; $total = 0;
    $add = function (string $name, string $mime, string $data) use (&$files, &$total) { $files[] = ['name' => $name, 'mime' => $mime, 'data' => $data]; $total += strlen($data); };
    $up = $_FILES['files'] ?? null;
    if ($up && is_array($up['name'])) {
        $fi = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        foreach ($up['name'] as $i => $name) {
            $err = (int) $up['error'][$i];
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) return [[], $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'big' : 'upload'];
            $mime = $fi ? (string) finfo_file($fi, $up['tmp_name'][$i]) : 'application/octet-stream';
            $add((string) $name, $mime, (string) file_get_contents($up['tmp_name'][$i]));
        }
    }
    foreach ((array)($_POST['pics'] ?? []) as $pid) {
        $p = site_pic_get((int) $pid);
        if ($p) $add((string) $p['name'], (string) $p['mime'], (string) $p['data']);
    }
    $fa = (int)($_POST['fwd_a'] ?? -1); $ff = (string)($_POST['fwd_f'] ?? ''); $fu = (int)($_POST['fwd_uid'] ?? 0);
    if (!empty($_POST['fwd_att']) && $fa >= 0 && $fa < count($accounts) && $ff !== '' && $fu > 0 && imap_box_of($ff) !== null) {
        foreach ((array) $_POST['fwd_att'] as $ix) {
            try { $att = mbox_attachment($accounts[$fa], $ff, $fu, (int) $ix); } catch (\Throwable $e) { $att = null; }
            if ($att) $add($att['name'], $att['mime'], $att['content']);
        }
    }
    return $total > MAIL_ATTACH_MAX ? [[], 'big'] : [$files, ''];
}
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
        [$files, $attErr] = compose_attachments($accounts);
        if ($attErr !== '') { header('Location: ' . mail_url(['compose' => 1, 'senderr' => $attErr])); exit; }

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
            $doc = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
            $ok = $files ? send_mixed($to, $subject, $text, $doc, $files, $from, $from, $name, '', $extra)
                         : send_alt($to, $subject, $text, $doc, $from, $from, $name, '', $extra);
        } else {
            $ok = $files ? send_mixed($to, $subject, $body, null, $files, $from, $from, $name, '', $extra)
                         : send_plain($to, $subject, $body, $from, $from, $name, '', $extra);
        }
        if ($ok && !empty($GLOBALS['MOHQ_LAST_SENT'])) mbox_save_sent($from, $GLOBALS['MOHQ_LAST_SENT']);
        if ($ok) log_activity('email.sent', 'mail', 0, $from . ' → ' . $to . ($files ? ' (' . count($files) . ' attachment' . (count($files) === 1 ? '' : 's') . ')' : ''));
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

// ---------------- Download one attachment (?att=N on a message) ----------------
if (isset($_GET['att']) && ($r = msg_ref($accounts))) {
    try { $att = mbox_attachment($r[0], $r[1], $r[2], (int) $_GET['att']); } catch (\Throwable $e) { $att = null; }
    if (!$att) { http_response_code(404); exit('Attachment not found. It may have been moved.'); }
    $fn = preg_replace('/[^\w .()-]+/', '_', $att['name']) ?: 'attachment';
    header('Content-Type: application/octet-stream');           // always a download, never shown in HQ's own page
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($att['content']));
    echo $att['content'];
    exit;
}

// ---------------- What to show ----------------
$view = 'list';
$msg = null; $err = '';
$prefill = ['to' => '', 'subject' => '', 'body' => '', 'from' => $only ?: $accounts[0], 'irt' => '', 'fwd' => null, 'fwd_atts' => [],
            'pics' => array_map('intval', array_filter(explode(',', (string)($_GET['pics'] ?? ''))))];
if (isset($_GET['compose']) && filter_var((string)($_GET['to'] ?? ''), FILTER_VALIDATE_EMAIL)) $prefill['to'] = (string) $_GET['to'];
if (isset($_GET['compose']) && isset($_GET['subject'])) $prefill['subject'] = mb_substr((string) $_GET['subject'], 0, 200);
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
                if ($src['attachments']) { $prefill['fwd'] = msg_qs($accounts, $ref[0], $ref[1], $ref[2]); $prefill['fwd_atts'] = $src['attachments']; }
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

// Website pictures this person may attach, grouped by customer (newest customers' pictures first in each group).
$custPics = [];
if ($view === 'compose' && product_on('customers')) {
    try {
        [$bs, $ba] = biz_scope('b');
        $st = db()->prepare("SELECT f.id, f.name, f.business_id, b.name AS bname FROM site_files f JOIN businesses b ON b.id = f.business_id WHERE $bs ORDER BY b.name, f.created_at DESC");
        $st->execute($ba);
        foreach ($st->fetchAll() as $r) {
            $bid = (int) $r['business_id'];
            $custPics[$bid] ??= ['name' => $r['bname'], 'pics' => [], 'pre' => false];
            $custPics[$bid]['pics'][] = $r;
            if (in_array((int) $r['id'], $prefill['pics'], true)) $custPics[$bid]['pre'] = true;
        }
        if ($custPics) {
            $in = implode(',', array_map('intval', array_keys($custPics)));
            $people = [];
            foreach (db()->query("SELECT id, name, main_email FROM businesses WHERE id IN ($in)")->fetchAll() as $bz) {
                if (filter_var((string) $bz['main_email'], FILTER_VALIDATE_EMAIL)) $people[(int) $bz['id']][strtolower($bz['main_email'])] = ['label' => 'Main email', 'email' => $bz['main_email']];
            }
            foreach (db()->query("SELECT business_id, first_name, last_name, title, email FROM contacts WHERE business_id IN ($in) AND email <> '' ORDER BY id")->fetchAll() as $c) {
                if (!filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL)) continue;
                $nm = trim($c['first_name'] . ' ' . $c['last_name']);
                $people[(int) $c['business_id']][strtolower($c['email'])] = ['label' => ($nm ?: 'Contact') . ($c['title'] ? ' (' . $c['title'] . ')' : ''), 'email' => $c['email']];
            }
            foreach ($custPics as $bid => &$g) $g['people'] = array_values($people[$bid] ?? []);
            unset($g);
        }
    } catch (\Throwable $e) { $custPics = []; }
}

// Names spell check should accept: customers, contacts and team members this person can see, plus the business name.
$spellNames = [];
if ($view === 'compose') {
    $spellNames = [(string) client('name'), (string) ($me['name'] ?? '')];
    try {
        [$bs, $ba] = biz_scope('b');
        $st = db()->prepare("SELECT b.name, c.first_name, c.last_name FROM businesses b LEFT JOIN contacts c ON c.business_id = b.id WHERE $bs");
        $st->execute($ba);
        foreach ($st->fetchAll() as $r) array_push($spellNames, (string) $r['name'], (string) $r['first_name'], (string) $r['last_name']);
        foreach (people() as $p) $spellNames[] = (string) ($p['name'] ?? '');
    } catch (\Throwable $e) {}
    $spellNames = array_values(array_unique(array_filter($spellNames)));
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
  .mnav{position:sticky;top:20px;display:flex;flex-direction:column;gap:.15rem}
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
  #mlist{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);overflow:hidden}
  #mlist .mrow:last-child{border-bottom:0}
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
    <?php if (($_GET['senderr'] ?? '') === 'big') flash('err', 'The attachments are too big. Keep them under 18 MB in total.'); elseif (($_GET['senderr'] ?? '') === 'upload') flash('err', "A file didn't upload. Try attaching it again."); ?>
    <form method="post" id="composeForm" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="send">
      <input type="hidden" name="in_reply_to" value="<?= e($prefill['irt']) ?>">
      <div class="grid-auto">
        <div><label>From</label>
          <select name="from" id="fromSel"><?php foreach ($accounts as $acct): ?><option value="<?= e($acct) ?>"<?= $acct === $prefill['from'] ? ' selected' : '' ?>><?= e($acct) ?></option><?php endforeach; ?></select></div>
        <div class="span-2"><label for="toIn">To</label>
          <div class="to-row">
            <input type="email" name="to" id="toIn" value="<?= e($prefill['to']) ?>" required>
            <?php foreach ($custPics as $bid => $g): if (empty($g['people'])) continue; ?>
              <select class="to-pick" data-biz="<?= (int) $bid ?>" aria-label="Send to someone at <?= e($g['name']) ?>"<?= $g['pre'] ? '' : ' hidden' ?>>
                <option value="">People at <?= e($g['name']) ?>…</option>
                <?php foreach ($g['people'] as $pp): ?><option value="<?= e($pp['email']) ?>"<?= strcasecmp($pp['email'], $prefill['to']) === 0 ? ' selected' : '' ?>><?= e($pp['label']) ?> · <?= e($pp['email']) ?></option><?php endforeach; ?>
              </select>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <label>Subject</label><input type="text" name="subject" value="<?= e($prefill['subject']) ?>" spellcheck="true" autocorrect="on" autocapitalize="sentences" lang="en">
      <label>Message</label><textarea name="body" rows="14" spellcheck="true" autocorrect="on" autocapitalize="sentences" lang="en"><?= e($prefill['body']) ?></textarea>
      <div class="spellbar"><button type="button" class="btn secondary small" id="spellBtn">Check spelling</button></div>
      <div class="spellbox" id="spell" hidden aria-live="polite"></div>
      <label>Attachments</label>
      <div class="attbox">
        <label class="attpick"><input type="file" name="files[]" id="attFiles" multiple><span>+ Attach files</span></label>
        <ul class="attlist" id="attList"></ul>
        <?php if ($prefill['fwd']): ?>
          <input type="hidden" name="fwd_a" value="<?= (int) $prefill['fwd']['a'] ?>"><input type="hidden" name="fwd_f" value="<?= e($prefill['fwd']['f']) ?>"><input type="hidden" name="fwd_uid" value="<?= (int) $prefill['fwd']['uid'] ?>">
          <div class="attgroup"><span class="muted">From the original email</span>
            <?php foreach ($prefill['fwd_atts'] as $i => $an): ?><label class="attchk"><input type="checkbox" name="fwd_att[]" value="<?= (int) $i ?>" checked> <?= e($an) ?></label><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($custPics): ?>
          <div class="attgroup"><span class="muted">Website pictures from a customer</span>
            <select id="picBiz" style="max-width:320px"><option value="">Pick a customer…</option><?php foreach ($custPics as $bid => $g): ?><option value="<?= (int) $bid ?>"<?= $g['pre'] ? ' selected' : '' ?>><?= e($g['name']) ?> (<?= count($g['pics']) ?>)</option><?php endforeach; ?></select>
            <?php foreach ($custPics as $bid => $g): ?>
              <div class="picrow" data-biz="<?= (int) $bid ?>"<?= $g['pre'] ? '' : ' hidden' ?>>
                <?php foreach ($g['pics'] as $p): ?>
                  <label class="picchk"><input type="checkbox" name="pics[]" value="<?= (int) $p['id'] ?>"<?= in_array((int) $p['id'], $prefill['pics'], true) ? ' checked' : '' ?>><img src="site_file.php?id=<?= (int) $p['id'] ?>" alt="" loading="lazy"><span><?= e($p['name']) ?></span></label>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="muted" style="font-size:.8rem">Up to 18 MB in total.</div>
      </div>
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
    <style>
      .spellbar{margin-top:.5rem}
      .spellbox{margin-top:.6rem;padding:.8rem 1rem;border:1px solid var(--border);border-radius:6px;display:grid;gap:.5rem;font-size:.9rem}
      .spellbox[hidden]{display:none}
      .spell-msg{font-weight:600}
      .spell-msg.good{color:var(--accent)}
      .spell-item{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem .5rem;padding-top:.45rem;border-top:1px solid var(--border)}
      .spell-word{font-weight:600;text-decoration:underline wavy #d33;text-underline-offset:3px;margin-right:.3rem}
      .spellbox .linkbtn{background:none;border:none;padding:0 .2rem;color:var(--muted);font:inherit;font-size:.85rem;cursor:pointer;text-decoration:underline}
      .to-row{display:flex;gap:.5rem;flex-wrap:wrap}
      .to-row input{flex:1 1 220px;min-width:0}
      .to-row select{flex:1 1 220px;min-width:0;max-width:100%}
      .to-row [hidden]{display:none}
      .attbox{display:grid;gap:.6rem;margin-bottom:.4rem}
      .attpick{display:inline-flex;width:fit-content;cursor:pointer;margin:0}
      .attpick input{position:absolute;width:1px;height:1px;opacity:0}
      .attpick span{border:1px dashed var(--border);border-radius:6px;padding:.45rem .8rem;color:var(--accent);font-weight:600;font-size:.88rem}
      .attpick:focus-within span,.attpick:hover span{border-color:var(--accent)}
      .attlist{list-style:none;margin:0;padding:0;display:grid;gap:.2rem;font-size:.85rem}
      .attlist:empty{display:none}
      .attgroup{display:grid;gap:.4rem;font-size:.85rem}
      .attchk{display:flex;gap:.4rem;align-items:center;margin:0;font-weight:400}
      .picrow{display:flex;flex-wrap:wrap;gap:.6rem}
      .picrow[hidden]{display:none}
      .picchk{display:grid;gap:.25rem;width:132px;margin:0;font-weight:400;font-size:.75rem;cursor:pointer;position:relative}
      .picchk input{position:absolute;top:6px;left:6px;width:18px;height:18px}
      .picchk img{width:132px;height:88px;object-fit:cover;object-position:top;border:2px solid var(--border);border-radius:4px}
      .picchk input:checked ~ img{border-color:var(--accent)}
      .picchk span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    </style>
    <script>
    (function(){
      var inp = document.getElementById('attFiles'), list = document.getElementById('attList');
      function kb(n){ return n >= 1048576 ? (n/1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n/1024)) + ' KB'; }
      if (inp) inp.addEventListener('change', function(){
        list.innerHTML = '';
        Array.prototype.forEach.call(inp.files, function(f){ var li = document.createElement('li'); li.textContent = f.name + ' · ' + kb(f.size); list.appendChild(li); });
      });
      var biz = document.getElementById('picBiz');
      var toIn = document.getElementById('toIn');
      function showBiz(){ document.querySelectorAll('.picrow, .to-pick').forEach(function(r){ r.hidden = r.dataset.biz !== biz.value; }); }
      if (biz) biz.addEventListener('change', showBiz);
      document.querySelectorAll('.to-pick').forEach(function(sel){
        sel.addEventListener('change', function(){ if (sel.value) { toIn.value = sel.value; toIn.dispatchEvent(new Event('input')); } });
      });
    })();
    /* ---- Spell check: the browser's own red underlines are on for Subject + Message; "Check spelling" lists each
       misspelled word with one-tap fixes (Typo.js + en_US in spell/, loaded the first time). Quoted replies, links and
       email addresses are skipped; customer, contact and team names count as real words, plus any word you keep. ---- */
    (function(){
      var KEY = 'hqSpellWords', speller = null, loading = null;
      var NAMES = <?= json_encode($spellNames) ?>;
      var subj = document.querySelector('#composeForm input[name=subject]'), body = document.querySelector('#composeForm textarea[name=body]');
      var box = document.getElementById('spell'), btn = document.getElementById('spellBtn');
      function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
      function myWords(){ try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
      function keep(w){ var l = myWords(); w = w.toLowerCase(); if (l.indexOf(w) < 0) { l.push(w); try { localStorage.setItem(KEY, JSON.stringify(l)); } catch (e) {} } }
      function load(){
        if (speller) return Promise.resolve(speller);
        if (loading) return loading;
        loading = new Promise(function(res, rej){
          var s = document.createElement('script'); s.src = 'spell/typo.js?v=1.2.4';
          s.onload = function(){
            Promise.all(['aff','dic'].map(function(x){ return fetch('spell/en_US.' + x + '?v=1').then(function(r){ if (!r.ok) throw new Error('dict'); return r.text(); }); }))
              .then(function(t){ speller = new Typo('en_US', t[0], t[1]); res(speller); }, rej);
          };
          s.onerror = rej; document.head.appendChild(s);
        });
        loading.catch(function(){ loading = null; });
        return loading;
      }
      function known(){
        var set = {};
        myWords().concat(NAMES).forEach(function(s){ String(s || '').replace(/[’‘]/g, "'").split(/[^A-Za-z']+/).forEach(function(w){ if (w) set[w.toLowerCase()] = 1; }); });
        return set;
      }
      function ok(w, k){
        if (k[w.toLowerCase()] || w.length < 2 || (w === w.toUpperCase() && w.length <= 6)) return true;
        if (speller.check(w)) return true;
        return /'s$/i.test(w) && speller.check(w.slice(0, -2));
      }
      /* only what you wrote: stop at a quoted reply or forward, skip "> " lines */
      function ownText(v){
        var cut = v.search(/\n(--- On |-------- Forwarded message)/);
        return (cut < 0 ? v : v.slice(0, cut)).split('\n').filter(function(l){ return !/^\s*>/.test(l); }).join('\n');
      }
      function mistakes(){
        var k = known(), out = [], seen = {};
        [[subj, subj.value], [body, ownText(body.value)]].forEach(function(p){
          var text = p[1].replace(/(https?:\/\/|www\.)\S+|\S+@\S+/g, ' ').replace(/[’‘]/g, "'");
          (text.match(/[A-Za-z]+(?:'[A-Za-z]+)*/g) || []).forEach(function(w){
            var key = w.toLowerCase(); if (seen[key] || ok(w, k)) return;
            seen[key] = 1; out.push({ word: w, el: p[0] });
          });
        });
        return out;
      }
      function fixCase(orig, s){ var c = orig.charAt(0); return c !== c.toLowerCase() ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
      function replaceWord(el, from, to){
        var re = new RegExp("(^|[^A-Za-z'’])(" + from.replace(/'/g, "['’]") + ")(?![A-Za-z])", 'gi');
        if (el === body) {   // leave the quoted part alone
          var v = el.value, cut = v.search(/\n(--- On |-------- Forwarded message)/), head = cut < 0 ? v : v.slice(0, cut), tail = cut < 0 ? '' : v.slice(cut);
          el.value = head.split('\n').map(function(l){ return /^\s*>/.test(l) ? l : l.replace(re, function(_, pre, w){ return pre + fixCase(w, to); }); }).join('\n') + tail;
        } else el.value = el.value.replace(re, function(_, pre, w){ return pre + fixCase(w, to); });
      }
      function dist(a, b){
        var d = [], i, j; for (i = 0; i <= a.length; i++) d[i] = [i]; for (j = 0; j <= b.length; j++) d[0][j] = j;
        for (i = 1; i <= a.length; i++) for (j = 1; j <= b.length; j++) {
          d[i][j] = Math.min(d[i-1][j] + 1, d[i][j-1] + 1, d[i-1][j-1] + (a[i-1] === b[j-1] ? 0 : 1));
          if (i > 1 && j > 1 && a[i-1] === b[j-2] && a[i-2] === b[j-1]) d[i][j] = Math.min(d[i][j], d[i-2][j-2] + 1);
        }
        return d[a.length][b.length];
      }
      function suggestions(word){
        var w = word.toLowerCase(), seen = {}, srt = function(x){ return x.split('').sort().join(''); }, all = speller.suggest(word, 8);
        all.forEach(function(s){ var k = s.toLowerCase(); if (!seen[k] || s === k) seen[k] = s; });
        return Object.keys(seen).map(function(k){ return { s: seen[k], i: all.indexOf(seen[k]), d: dist(w, k), a: srt(w) === srt(k) ? 0 : 1, f: w[0] === k[0] ? 0 : 1 }; })
          .sort(function(x, y){ return x.d - y.d || x.a - y.a || x.f - y.f || x.i - y.i; })
          .filter(function(x, i){ return i === 0 || x.d <= 2; }).slice(0, 3).map(function(x){ return fixCase(word, x.s); });
      }
      function draw(){
        var list = mistakes();
        box.hidden = false;
        if (!list.length) { box.innerHTML = '<div class="spell-msg good">No spelling mistakes found.</div>'; return; }
        box.innerHTML = '<div class="spell-msg">' + (list.length === 1 ? '1 word to check' : list.length + ' words to check') + '</div>' + list.map(function(m, i){
          var sug = suggestions(m.word);
          return '<div class="spell-item"><span class="spell-word">' + esc(m.word) + '</span>'
            + (sug.length ? sug.map(function(s){ return '<button type="button" class="btn secondary small" data-fix="' + i + '" data-to="' + esc(s) + '">' + esc(s) + '</button>'; }).join('') : '<span class="muted">No suggestions. Fix it in the message, or keep it.</span>')
            + '<button type="button" class="linkbtn" data-keep="' + i + '">Keep it</button></div>';
        }).join('');
        box.querySelectorAll('[data-fix]').forEach(function(b){ b.onclick = function(){ var m = list[+b.dataset.fix]; replaceWord(m.el, m.word, b.dataset.to); draw(); }; });
        box.querySelectorAll('[data-keep]').forEach(function(b){ b.onclick = function(){ keep(list[+b.dataset.keep].word); draw(); }; });
      }
      btn.addEventListener('click', function(){
        box.hidden = false;
        box.innerHTML = '<div class="spell-msg">Checking spelling…' + (speller ? '' : ' <span class="muted">(the first time takes a few seconds)</span>') + '</div>';
        load().then(function(){ setTimeout(draw, 20); }, function(){ box.innerHTML = '<div class="spell-msg">Spell check couldn’t load. Try again in a moment.</div>'; });
      });
    })();
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
      <?php if ($msg['attachments']): ?><div class="atts" style="font-size:.84rem;margin-top:.4rem;display:flex;flex-wrap:wrap;gap:.3rem .9rem;align-items:center"><span class="muted">Attachments</span><?php foreach ($msg['attachments'] as $i => $an): ?><a href="<?= e(mail_url($qs + ['att' => $i])) ?>" download>&#8681; <?= e($an ?: 'attachment') ?></a><?php endforeach; ?></div><?php endif; ?>
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
