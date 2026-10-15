<?php
/**
 * People, roles and permissions.
 *  - admin: sees and does everything, manages people, approves requests.
 *  - user:  sees the customers assigned to them and the quotes they made,
 *           does only what they've been allowed, and needs approval for
 *           pricebook and pricing changes.
 */
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_billing.php';

/** Permission key => [label, help, on by default for new users]. */
function perm_list(): array {
    return [
        'customers.edit' => ['Add and edit customers', 'Create customers (assigned to themselves) and edit their assigned customers, contacts and history.', true],
        'requests.view'  => ['See website requests', 'See new website requests and turn them into customers (assigned to themselves).', false],
        'quotes.create'  => ['Build quotes', 'Create and edit quotes for their customers.', true],
        'quotes.send'    => ['Send quotes', 'Email quotes, download the PDF and share the customer link.', true],
        'quotes.costs'   => ['See cost and profit', 'See our cost, margin and profit on quotes.', false],
        'pricing.free'   => ['Change prices without approval', 'Pricebook additions and price or billing changes on quotes skip the approval queue.', false],
    ];
}

/** The logged-in person (fresh from the database once per request), or null. */
function me(): ?array {
    static $me = false;
    if ($me !== false) return $me;
    $uid = (int) ($_SESSION['uid'] ?? 0);
    $me = null;
    if ($uid) {
        try {
            $st = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
            $st->execute([$uid]);
            $me = $st->fetch() ?: null;
            if ($me) {
                $p = db()->prepare('SELECT perm FROM user_permissions WHERE user_id = ?');
                $p->execute([$uid]);
                $me['perms'] = $p->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (Exception $ex) { $me = null; }
    }
    return $me;
}

function me_id(): int { return (int) (me()['id'] ?? 0); }
function is_admin(): bool { $m = me(); return $m && (($m['role'] ?? 'admin') === 'admin'); }
function can(string $perm): bool {
    if (is_admin()) return true;
    $m = me();
    return $m && in_array($perm, $m['perms'] ?? [], true);
}
function user_label(array $u): string { return trim((string)($u['name'] ?? '')) ?: (string)$u['username']; }

/** Stop with a friendly page if the person may not do this. */
function deny(string $why = 'You don\'t have access to this.'): void {
    http_response_code(403);
    layout_header('No access', 'index.php');
    echo '<h1 class="page">No access</h1><div class="card">' . e($why) . ' Ask an admin if you need it. <a href="index.php">Back to the dashboard</a></div>';
    layout_footer();
    exit;
}
function require_admin(): void { if (!is_admin()) deny('Only admins can open this page.'); }
function require_perm(string $perm): void { if (!can($perm)) deny(); }

/** SQL to limit a businesses query (alias b) to what this person can see. */
function biz_scope(string $alias = 'b'): array {
    return is_admin() ? ['1=1', []] : ["$alias.assigned_to = ?", [me_id()]];
}
/** SQL to limit a quotes query (alias q) to what this person can see. */
function quote_scope(string $alias = 'q'): array {
    return is_admin() ? ['1=1', []] : ["$alias.created_by = ?", [me_id()]];
}

function can_see_business(array $b): bool { return is_admin() || (int)($b['assigned_to'] ?? 0) === me_id(); }
function can_see_quote(array $q): bool { return is_admin() || (int)($q['created_by'] ?? 0) === me_id(); }

/** Mailboxes this person may open in Ops Inbox. Admins: all of them. */
function my_mailboxes(): array {
    $all = array_values(array_filter(array_map('trim', (array) client('email.accounts', []))));
    if (is_admin()) return $all;
    try {
        $st = db()->prepare('SELECT mailbox FROM user_mailboxes WHERE user_id = ?');
        $st->execute([me_id()]);
        $mine = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $ex) { $mine = []; }
    return array_values(array_intersect($all, $mine));
}

/** Everyone who can log in, for dropdowns. */
function people(bool $activeOnly = true): array {
    try {
        return db()->query('SELECT * FROM admin_users' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY role, COALESCE(NULLIF(name,\'\'), username)')->fetchAll();
    } catch (Exception $ex) { return []; }
}

/** Record who did what. Best effort; never breaks the page. */
function log_activity(string $action, string $entity = '', int $entityId = 0, string $detail = ''): void {
    try {
        db()->prepare('INSERT INTO activity (user_id, action, entity, entity_id, detail) VALUES (?,?,?,?,?)')
            ->execute([me_id() ?: null, $action, $entity, $entityId ?: null, mb_substr($detail, 0, 500)]);
    } catch (Exception $ex) {}
}

function pending_approvals(): int {
    try { return (int) db()->query("SELECT COUNT(*) FROM approvals WHERE status = 'pending'")->fetchColumn(); }
    catch (Exception $ex) { return 0; }
}

/** Email every active admin who has an address (best effort). */
function notify_admins(string $subject, string $body): void {
    if (!function_exists('send_mail')) require_once __DIR__ . '/_mail.php';
    foreach (people() as $p) {
        if (($p['role'] ?? '') === 'admin' && filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            try { send_mail($p['email'], $subject, $body); } catch (Throwable $e) {}
        }
    }
}
function notify_user(int $uid, string $subject, string $body): void {
    if (!function_exists('send_mail')) require_once __DIR__ . '/_mail.php';
    try {
        $st = db()->prepare('SELECT email FROM admin_users WHERE id = ? AND active = 1');
        $st->execute([$uid]);
        $to = (string) $st->fetchColumn();
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) send_mail($to, $subject, $body);
    } catch (Throwable $e) {}
}

function hq_url(string $path): string {
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/admin/' . ltrim($path, '/');
}

/** Create an approval request and tell the admins. */
function approval_request(string $type, string $summary, array $payload, int $quoteId = 0, int $catalogId = 0): int {
    db()->prepare("INSERT INTO approvals (type, status, requested_by, quote_id, catalog_item_id, summary, payload) VALUES (?, 'pending', ?, ?, ?, ?, ?)")
        ->execute([$type, me_id(), $quoteId ?: null, $catalogId ?: null, $summary, json_encode($payload)]);
    $id = (int) db()->lastInsertId();
    log_activity('approval.requested', 'approval', $id, $summary);
    notify_admins('Approval needed: ' . $summary,
        user_label(me() ?? ['username' => 'Someone']) . " asked for approval:\n\n$summary\n\nReview it here: " . hq_url('approvals.php'));
    return $id;
}

/**
 * Lines on a quote whose price or billing don't match the pricebook, or that
 * aren't in the pricebook at all. Section headings are ignored.
 * Returns [list of human-readable differences, hash of exactly those lines].
 */
function quote_pricing_changes(array $lines): array {
    $cat = [];
    foreach (db()->query('SELECT title, unit_price, billing FROM catalog_items WHERE active = 1')->fetchAll() as $c) {
        $cat[mb_strtolower(trim($c['title']))][] = $c;
    }
    $diffs = []; $sig = [];
    foreach ($lines as $l) {
        if (!empty($l['is_section'])) continue;
        $key = mb_strtolower(trim((string) $l['title']));
        $price = round((float) $l['unit_price'], 2);
        $bill = (string) $l['billing'];
        $match = false; $closest = null;
        foreach ($cat[$key] ?? [] as $c) {
            $closest = $closest ?? $c;
            if (round((float) $c['unit_price'], 2) === $price && $c['billing'] === $bill) { $match = true; break; }
        }
        if ($match) continue;
        $label = billing_label($bill);
        if ($closest === null) {
            $diffs[] = '"' . $l['title'] . '" is not in the pricebook (' . money($price) . billing_suffix($bill) . ')';
        } else {
            $parts = [];
            if (round((float) $closest['unit_price'], 2) !== $price) $parts[] = 'price ' . money($closest['unit_price']) . ' → ' . money($price);
            if ($closest['billing'] !== $bill) $parts[] = 'billing ' . billing_label($closest['billing']) . ' → ' . $label;
            $diffs[] = '"' . $l['title'] . '": ' . implode(', ', $parts);
        }
        $sig[] = $key . '|' . $price . '|' . $bill . '|' . (float) $l['quantity'];
    }
    sort($sig);
    return [$diffs, $diffs ? hash('sha256', implode("\n", $sig)) : ''];
}

/** Can this quote go to the customer yet? (approval pending or rejected blocks it) */
function quote_cleared(array $q): bool {
    return !in_array($q['approval_status'] ?? 'none', ['pending', 'rejected'], true);
}

/** Can this person see the business with this id? */
function can_see_business_id(int $bid): bool {
    if ($bid <= 0) return false;
    $st = db()->prepare('SELECT id, assigned_to FROM businesses WHERE id = ?');
    $st->execute([$bid]);
    $b = $st->fetch();
    return $b ? can_see_business($b) : false;
}

/**
 * After a quote is saved: work out whether its pricing needs an admin, and
 * open (or close) the approval request. Admins and people with pricing.free skip it.
 */
function quote_after_save(int $qid): void {
    $q = db()->prepare('SELECT q.*, b.name AS biz FROM quotes q JOIN businesses b ON b.id = q.business_id WHERE q.id = ?');
    $q->execute([$qid]);
    $q = $q->fetch();
    $lines = db()->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY sort_order');
    $lines->execute([$qid]);
    $lines = $lines->fetchAll();
    $withdraw = function () use ($qid) {
        db()->prepare("UPDATE approvals SET status = 'withdrawn', decided_at = NOW(), decision_note = 'Quote changed' WHERE quote_id = ? AND status = 'pending'")->execute([$qid]);
    };
    if (can('pricing.free')) {
        $withdraw();
        db()->prepare("UPDATE quotes SET approval_status = 'none', approval_hash = NULL WHERE id = ?")->execute([$qid]);
        return;
    }
    [$diffs, $hash] = quote_pricing_changes($lines);
    if (!$diffs) {
        $withdraw();
        db()->prepare("UPDATE quotes SET approval_status = 'none', approval_hash = NULL WHERE id = ?")->execute([$qid]);
        return;
    }
    // Same lines as last time: keep the existing decision or request.
    if ($q['approval_hash'] === $hash && in_array($q['approval_status'], ['approved', 'pending'], true)) return;
    $withdraw();
    db()->prepare("UPDATE quotes SET approval_status = 'pending', approval_hash = ? WHERE id = ?")->execute([$hash, $qid]);
    $n = count($diffs);
    approval_request('quote_pricing',
        'Quote #' . $q['quote_number'] . ' for ' . $q['biz'] . ': ' . $n . ' line' . ($n === 1 ? '' : 's') . ' differ from the pricebook',
        ['hash' => $hash, 'diffs' => $diffs, 'quote_number' => $q['quote_number']], $qid);
}
