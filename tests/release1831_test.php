<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/audit.php', 'includes/images.php', 'includes/imagerefs.php', 'includes/forumdescs.php',
          'includes/lang.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$GLOBALS['db'] = $db;
langInit([], 'en');
$cfgOn = array_merge($cfg, ['site_url' => 'https://r1831.test']);
$GLOBALS['cfg'] = $cfgOn;

$h = fn(int $i): string => 'c1831' . str_repeat('0', 34) . $i;
$wipe = function () use ($db, $h): void {
    foreach ([1, 2] as $i) {
        $db->prepare("DELETE FROM forum_descs WHERE info_hash = ?")->execute([$h($i)]);
        $db->prepare("DELETE FROM whitelist WHERE info_hash = ?")->execute([$h($i)]);
    }
};
$wipe();
register_shutdown_function($wipe);

$ins = $db->prepare("INSERT INTO whitelist (info_hash, name, total_size, files_count, source, created_at, banned, content_status, meta_status, description, description_format)
                     VALUES (?, ?, 1000, 1, 'admin', NOW(), 0, ?, 'done', ?, 'bbcode')");
$ins->execute([$h(1), 'Quxwerty Release One', 'rejected', 'some words']);
$ins->execute([$h(2), 'Quxwerty Release Two', 'approved', 'other words']);
$found = fn(array $q): array => array_values(array_filter(array_column(indexSearchCatalogue($db, $cfgOn,
    $q + ['search' => 'Quxwerty', 'include_whitelist' => true, 'catalog' => false, 'per_page' => 50, 'page' => 1])['rows'], 'info_hash'),
    fn($x) => str_starts_with((string)$x, 'c1831')));
$all = $found([]);
sort($all);
check('the search lists a registered torrent whatever became of its description', $all === [$h(1), $h(2)], json_encode($all));
check('… "hide rejected" is still there for whoever picks it', $found(['content' => 'not_rejected']) === [$h(2)]);
check('… and the endpoint and the page start from "any"', str_contains($src('api/index_search.php'), "(string)(\$_GET['content'] ?? 'any')")
      && str_contains($src('templates/pages/search.php'), '<option value="any">'));

$fd = $src('includes/forumdescs.php');
check('a forum text brought back after it was turned down is published when the key publishes, also when the text changed',
      str_contains($fd, "\$back = \$status === 'rejected' && !empty(\$topic['restore']);")
      && str_contains($fd, "|| (\$back && \$mode === 'publish')) ? 'approved' : 'pending';"));
check('the forum hears "turned down" as its own count, not as skipped', str_contains($src('api/v1/whitelist_describe.php'),
      "in_array(\$r['status'], ['declined', 'removed'], true) ? 'declined'"));

$db->prepare("INSERT INTO forum_descs (info_hash, client_id, discussion_id, post_id, state, import_sha) VALUES (?, 1, 99, 99, 'declined', 'x')")->execute([$h(1)]);
$wid = (int)$db->query("SELECT id FROM whitelist WHERE info_hash = '" . $h(1) . "'")->fetchColumn();
$ok = forumDescClearForResend($db, $cfgOn, 'wl', $wid);
$row = $db->query("SELECT description, content_status FROM whitelist WHERE id = " . $wid)->fetch(PDO::FETCH_ASSOC);
$fdLeft = (int)$db->query("SELECT COUNT(*) FROM forum_descs WHERE info_hash = '" . $h(1) . "'")->fetchColumn();
check('delete for a resend: the text goes and the forum\'s decision is forgotten, so its next send is new', $ok && $row && $row['description'] === null && $fdLeft === 0,
      json_encode([$ok, $row, $fdLeft]));
check('… from the Moderation page, for the selected, with the password', str_contains($src('api/admin/wl_content.php'), "['approve', 'reject', 'clear']")
      && str_contains($src('assets/js/admin-review.js'), "runBulk('clear', '', pw);") && str_contains($src('templates/admin/moderation.php'), 'id="rq-delete-sel"'));
check('… and the pictures copied for it that nothing else uses go at once', str_contains($fd, "if (\$row === null || (string)\$row['source'] !== 'copy') continue;")
      && str_contains($fd, 'imagesDelete($db, $img);'));

$wipe();
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
