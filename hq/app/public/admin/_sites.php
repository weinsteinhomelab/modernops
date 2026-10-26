<?php
/** Demo + live websites tied to a business. */

function sites_for(int $bid): array {
    try {
        $st = db()->prepare("SELECT * FROM sites WHERE business_id=? ORDER BY kind='live' DESC, created_at DESC");
        $st->execute([$bid]);
        return $st->fetchAll();
    } catch (Exception $ex) { return []; }
}

/** Accept "example.com" or a full URL; return a normalized http(s) URL or ''. */
function site_clean_url(string $u): string {
    $u = trim($u);
    if ($u === '') return '';
    if (!preg_match('#^https?://#i', $u)) $u = 'https://' . ltrim($u, '/');
    return filter_var($u, FILTER_VALIDATE_URL) ? $u : '';
}

/** Client previews currently listed on the public /demos/ hub (cached 10 min). */
function demo_suggestions(): array {
    $origin = rtrim(SITE_ORIGIN, '/');
    $hub = $origin . '/demos/';
    $cache = sys_get_temp_dir() . '/mohq-demos.json';
    if (is_file($cache) && filemtime($cache) > time() - 600) {
        $c = json_decode((string)file_get_contents($cache), true);
        if (is_array($c)) return $c;
    }
    $out = [];
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'header' => "User-Agent: ModernOpsHQ\r\n"]]);
    $html = @file_get_contents($hub, false, $ctx);
    if ($html && preg_match_all('#href="([a-z0-9][a-z0-9_-]*)/?"#i', $html, $m)) {
        foreach (array_unique($m[1]) as $slug) $out[] = $hub . $slug . '/';
    }
    @file_put_contents($cache, json_encode($out));
    return $out;
}

// ---------------- Pictures of a customer's websites (screenshots, mockups) ----------------
// Stored in the database so they ride along with the nightly backup and work on any host.
const SITE_PIC_MAX = 10 * 1024 * 1024;          // per picture (MariaDB's default packet limit is 16 MB)
const SITE_PIC_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

/** Pictures for one business, grouped by site id (0 = not tied to a site). */
function site_pics_for(int $bid): array {
    $out = [];
    try {
        $st = db()->prepare('SELECT id, site_id, name, mime, size, width, height, created_at FROM site_files WHERE business_id=? ORDER BY created_at, id');
        $st->execute([$bid]);
        foreach ($st->fetchAll() as $r) $out[(int)$r['site_id']][] = $r;
    } catch (Exception $ex) {}
    return $out;
}

/** Save uploaded pictures ($_FILES[$field], multiple) for a site. Returns [saved count, list of problems]. */
function site_pics_save(int $bid, int $siteId, string $field): array {
    $saved = 0; $errs = [];
    $f = $_FILES[$field] ?? null;
    if (!$f || !is_array($f['name'])) return [0, ['Pick one or more pictures first.']];
    foreach ($f['name'] as $i => $name) {
        $name = trim(preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '', (string)$name)) ?: 'picture';
        $err = (int)$f['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int)$f['size'][$i] > SITE_PIC_MAX) { $errs[] = "$name is bigger than 10 MB."; continue; }
        if ($err !== UPLOAD_ERR_OK) { $errs[] = "$name didn't upload. Try again."; continue; }
        $tmp = $f['tmp_name'][$i];
        $info = @getimagesize($tmp);
        $mime = $info['mime'] ?? '';
        if (!isset(SITE_PIC_TYPES[$mime])) { $errs[] = "$name isn't a JPG, PNG, WebP or GIF picture."; continue; }
        $data = (string) file_get_contents($tmp);
        try {
            $st = db()->prepare('INSERT INTO site_files (business_id, site_id, name, mime, size, width, height, data, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $st->bindValue(1, $bid, PDO::PARAM_INT);
            $st->bindValue(2, $siteId ?: null, $siteId ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->bindValue(3, mb_substr($name, 0, 250));
            $st->bindValue(4, $mime);
            $st->bindValue(5, strlen($data), PDO::PARAM_INT);
            $st->bindValue(6, (int)$info[0], PDO::PARAM_INT);
            $st->bindValue(7, (int)$info[1], PDO::PARAM_INT);
            $st->bindValue(8, $data, PDO::PARAM_LOB);
            $st->bindValue(9, me_id() ?: null, me_id() ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->execute();
            $saved++;
        } catch (Exception $ex) { $errs[] = "$name couldn't be saved."; }
    }
    return [$saved, $errs];
}

/** One stored picture with its bytes, if this person may see its customer. */
function site_pic_get(int $fileId): ?array {
    $st = db()->prepare('SELECT * FROM site_files WHERE id=?');
    $st->execute([$fileId]);
    $r = $st->fetch();
    if (!$r || !can_see_business_id((int)$r['business_id'])) return null;
    return $r;
}

function human_size(int $b): string {
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
}

/** A locally hosted preview (e.g. 10.0.0.232:5196 on the office network): plain http unless a scheme is given. */
function site_clean_local(string $u): string {
    $u = trim($u);
    if ($u === '') return '';
    if (!preg_match('#^https?://#i', $u)) $u = 'http://' . ltrim($u, '/');
    return filter_var($u, FILTER_VALIDATE_URL) ? $u : '';
}
