<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
foreach (['includes/settings_catalog.php', 'includes/users.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 600)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);
$en = require $root . '/lang/en.php';
$pl = require $root . '/lang/pl.php';

$schema = $src('includes/schema.php');
$save = $src('api/admin/save_settings.php');
$tpl = $src('templates/admin/settings.php');
$overlay = $src('templates/partials/info_overlay.php');
$app = $src('assets/js/app.js');
$kw = settingsCatalogKeywords();

check('the two Info panel settings have defaults: resizing on, descriptions folded past 640 pixels',
      str_contains($schema, "'info_resize'                 => '1',") && str_contains($schema, "'info_desc_fold'              => '640',"));
check('… are saved, the fold within 0 to 5000, the switch as a yes or no', str_contains($save, "'info_desc_fold' => [0, 5000, 640],")
      && substr_count($save, "'info_resize'") >= 2 && substr_count($save, "'info_desc_fold'") >= 2);
check('… are on the settings page and found by the search', str_contains($tpl, 'data-setting="info_resize"') && str_contains($tpl, 'data-setting="info_desc_fold"')
      && isset($kw['info_resize'], $kw['info_desc_fold']));
check('… and are written in both languages', isset($en['settings.info_resize'], $pl['settings.info_resize'], $en['settings.info_desc_fold'], $pl['settings.info_desc_fold'],
      $en['js.app.info_size_reset'], $pl['js.app.info_size_reset']));
check('resizing is a right of its own, given to members once, and the panel offers it only with the switch on and the right held',
      str_contains($src('includes/users.php'), "'info.resize'    =>") && str_contains($schema, "schemaGrantOnce(\$db, 'v99_info_resize', ['member' => ['info.resize']]);")
      && str_contains($overlay, "data-resize=\"<?= (\$cfg['info_resize'] ?? '1') === '1' && userCan(\$db, \$cfg, 'info.resize') ? '1' : '0' ?>\""));
check('the fold the page is given is the setting, kept within bounds', str_contains($overlay, "data-fold=\"<?= max(0, min(5000, (int)(\$cfg['info_desc_fold'] ?? 640))) ?>\""));
check('the size is remembered in the browser only, read and written inside try, and can be reset',
      str_contains($app, "const key = store || 'thx-info-size';") && substr_count(substr($app, (int)strpos($app, 'function initInfoResize'), 2500), 'try {') >= 2
      && str_contains($app, "'info-size-reset'"));
check('a long description is folded only past the setting, with room to spare', str_contains($app, 'd.scrollHeight > infoDescFold + 96')
      && str_contains($app, 'body.appendChild(infoDescFold > 0 ? foldDescription(d) : d);'));
check('YouTube plays inside the panel: its no-cookie player is allowed as a frame, and no link sends the reader to YouTube',
      str_contains($src('.htaccess'), 'https://www.youtube-nocookie.com') && !str_contains($src('assets/js/ytplayer.js'), 'bi-box-arrow-up-right')
      && !isset($en['js.video.open']));

echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
