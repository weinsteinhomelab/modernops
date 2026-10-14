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
