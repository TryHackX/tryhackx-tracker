<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/auth.php',
          'includes/api_auth.php', 'includes/audit.php', 'includes/lang.php', 'includes/forumdescs.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 600)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');
$cfgI = array_merge($cfg, ['site_url' => 'https://img.test/', 'images_max_mb' => '8', 'images_max_edge' => '2048',
    'images_max_mpx' => '24', 'images_webp_quality' => '82', 'images_remote_scan_at' => '0']);

$tidy = function () use ($db): void {
    $st = $db->query("SELECT id FROM images WHERE source_url LIKE 'https://pics.consent.test/%' OR source_url LIKE 'https://pics.noconsent.test/%'");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) imagesDelete($db, (int)$id);
    $db->exec("DELETE FROM image_remote WHERE host LIKE '%consent.test'");
    $db->exec("DELETE FROM api_clients WHERE label LIKE 'consenttest%'");
};
$tidy();

try {
    $cols = array_column($db->query("SHOW COLUMNS FROM api_clients")->fetchAll(PDO::FETCH_ASSOC), 'Default', 'Field');
    check('a key has image_consent, unset until the partner says', array_key_exists('image_consent', $cols) && $cols['image_consent'] === null
          && array_key_exists('image_consent_at', $cols), json_encode([$cols['image_consent'] ?? 'missing']));
    check('the schema is 98, and the columns are in it twice (fresh install and upgrade)', TRACKER_SCHEMA_VERSION >= 98
          && (int)($cfg['schema_version'] ?? 0) >= 98 && substr_count($src('includes/schema.php'), 'image_consent') >= 4);
    check('the key\'s pictures and consent come with the key at authentication',
          str_contains($src('includes/api_auth.php'), 'describe_mode, describe_accounts, image_domains, image_consent'));

    check('a consent is a yes or a no; anything else says nothing', imagesConsentFrom(true) === 1 && imagesConsentFrom('1') === 1
          && imagesConsentFrom(1) === 1 && imagesConsentFrom('true') === 1 && imagesConsentFrom(false) === 0 && imagesConsentFrom('0') === 0
          && imagesConsentFrom(0) === 0 && imagesConsentFrom('false') === 0 && imagesConsentFrom(null) === null && imagesConsentFrom('yes') === null
          && imagesConsentFrom(2) === null);

    $k = apiClientCreate($db, 'consenttest-a', 'whitelist');
    $kid = (int)$k['id'];
    $db->prepare("UPDATE api_clients SET image_domains = 'pics.consent.test' WHERE id = ?")->execute([$kid]);
    $row = function () use ($db, $kid): array {
        $st = $db->prepare("SELECT id, image_domains, image_consent, image_consent_at FROM api_clients WHERE id = ?");
        $st->execute([$kid]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    $c0 = $row();
    check('a key that wants pictures but was never told yes: no_consent, and nothing is copied from it',
          imagesClientState($c0) === ['images' => 'no_consent', 'image_domains' => ['pics.consent.test']]
          && !in_array('pics.consent.test', imagesRemoteHosts($db, true), true), json_encode(imagesClientState($c0)));
    $c1 = imagesConsentNote($db, $c0, true);
    $r1 = $row();
    check('the partner says yes: stored with its time, and the domain is copied from', (int)$r1['image_consent'] === 1 && $r1['image_consent_at'] !== null
          && imagesClientState($c1)['images'] === 'copy' && in_array('pics.consent.test', imagesRemoteHosts($db, true), true), json_encode($r1));
    $db->exec("UPDATE api_clients SET image_consent_at = '2001-01-01 00:00:00' WHERE id = " . $kid);
    imagesConsentNote($db, $row(), '1');
    check('saying the same again writes nothing', $row()['image_consent_at'] === '2001-01-01 00:00:00');
    imagesConsentNote($db, $row(), null);
    check('not saying anything changes nothing', (int)$row()['image_consent'] === 1);
    $c2 = imagesConsentNote($db, $row(), false);
    check('the partner withdraws it: no longer copied from', (int)$row()['image_consent'] === 0 && imagesClientState($c2)['images'] === 'no_consent'
          && !in_array('pics.consent.test', imagesRemoteHosts($db, true), true));
    check('a key with no image domains is off whatever the partner says',
          imagesClientState(['image_domains' => '', 'image_consent' => 1]) === ['images' => 'off', 'image_domains' => []]);
    $db->exec("UPDATE api_clients SET image_consent = 1, enabled = 0 WHERE id = " . $kid);
    check('a disabled key is not copied from, consent or not', !in_array('pics.consent.test', imagesRemoteHosts($db, true), true));
    $db->exec("UPDATE api_clients SET enabled = 1 WHERE id = " . $kid);

    $k2 = apiClientCreate($db, 'consenttest-b', 'whitelist');
    $db->prepare("UPDATE api_clients SET image_domains = 'pics.noconsent.test' WHERE id = ?")->execute([(int)$k2['id']]);
    $yes = 'https://pics.consent.test/a.png';
    $no = 'https://pics.noconsent.test/b.png';
    imagesRemoteEnqueue($db, [$yes, $no]);
    $pic = function (int $w, int $h): string {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 30, 120, 90));
        imagesetpixel($im, 0, 0, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    };
    $calls = [];
    $io = ['resolve' => fn(string $h): array => ['93.184.216.34'],
           'get' => function (string $url, string $host, int $port, string $ip, int $max) use (&$calls, $pic): array {
               $calls[] = $url;
               return ['ok' => true, 'bytes' => $pic(640, 480)];
           }];
    imagesRemoteTick($db, $cfgI, $io);
    $state = function (string $u) use ($db): string {
        $st = $db->prepare("SELECT status FROM image_remote WHERE url_sha = ?");
        $st->execute([hash('sha256', $u)]);
        return (string)$st->fetchColumn();
    };
    check('the janitor copies from the domain that agreed, and does not even ask the one that did not',
          $state($yes) === 'done' && $calls === [$yes], json_encode([$state($yes), $calls]));
    check('… whose picture waits in the queue rather than failing for good', $state($no) === 'pending');
    $db->prepare("UPDATE api_clients SET image_consent = 1 WHERE id = ?")->execute([(int)$k2['id']]);
    $calls = [];
    imagesRemoteTick($db, $cfgI, $io);
    check('… and is copied as soon as that partner agrees', $state($no) === 'done' && $calls === [$no], json_encode([$state($no), $calls]));

    foreach (['api/v1/whitelist_submit.php', 'api/v1/whitelist_describe.php'] as $f) {
        check("$f takes copy_images from the body and answers the picture state",
              str_contains($src($f), "imagesConsentNote(\$db, \$client, \$payload['copy_images'] ?? null)") && str_contains($src($f), '+ imagesClientState($client)'));
    }
    check('the ping takes copy_images from the query or the body and answers the picture state',
          str_contains($src('api/v1/whitelist_ping.php'), "\$_GET['copy_images']") && str_contains($src('api/v1/whitelist_ping.php'), '+ imagesClientState($client)'));
    check('describe records the consent even while descriptions are off',
          strpos($src('api/v1/whitelist_describe.php'), 'imagesConsentNote(') < strpos($src('api/v1/whitelist_describe.php'), "'describe_off'"));
    check('the key list says what the partner said', str_contains($src('api/admin/fetch_api_clients.php'), "\$c['image_consent'] = ")
          && str_contains($src('assets/js/admin-whitelist.js'), "'js.wl.cl_consent_yes'"));
} finally {
    $tidy();
}

echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
