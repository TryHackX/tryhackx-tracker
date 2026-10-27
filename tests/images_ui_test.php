<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

function imgUiBalanceRows(int $count, int $maxPerRow = 4): array {
    if ($count <= 0) return [];
    $rowCount = (int)ceil($count / $maxPerRow);
    $base = intdiv($count, $rowCount);
    $remainder = $count % $rowCount;
    $rows = [];
    for ($r = 0; $r < $rowCount; $r++) $rows[] = $base + ($r < $remainder ? 1 : 0);
    return $rows;
}

function imgUiLabelsBlock(array $labels): string {
    $labels = array_values($labels);
    if (!$labels) return '';
    $rows = imgUiBalanceRows(count($labels));
    $out = '[thxlabels=' . $rows[0] . ']';
    $i = 0;
    foreach ($rows as $size) {
        $out .= '[thxrow]';
        for ($j = 0; $j < $size; $j++) $out .= '[img]' . $labels[$i++] . '[/img]';
        $out .= '[/thxrow]';
    }
    return $out . '[/thxlabels]';
}

function imgUiDesc(array $a): array {
    rsort($a);
    return $a;
}

$jsFile = $root . '/assets/js/images-editor.js';
$js = (string)@file_get_contents($jsFile);
check('the editor script is there', $js !== '');

$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'imgui_' . bin2hex(random_bytes(4)) . '.js';
file_put_contents($runner, "global.window = {};\nrequire(process.argv[2]);\nconst E = window.ImagesEditor;\n"
    . "const urls = (n) => Array.from({ length: n }, (_, i) => 'https://e.example/i/' + (i + 1) + '-640x960.webp');\n"
    . "const out = { rows: [], labels: [], markup: {}, offer: {}, api: Object.keys(E).sort() };\n"
    . "for (let k = 0; k <= 40; k++) out.rows.push(E.balanceRows(k));\n"
    . "for (let k = 0; k <= 13; k++) out.labels.push(E.labelsBlock(urls(k)));\n"
    . "['bbcode', 'flarum', 'markdown'].forEach((f) => { out.markup[f] = E.imageMarkup('https://e.example/i/7-640x480.webp', f); out.offer[f] = E.labelsAllowed(f); });\n"
    . "process.stdout.write(JSON.stringify(out));\n");
$raw = trim((string)@shell_exec('node ' . escapeshellarg($runner) . ' ' . escapeshellarg($jsFile) . ' 2>&1'));
@unlink($runner);
$out = json_decode($raw, true);
check('node loads the script on its own and it hands over its pure functions', is_array($out)
      && array_values(array_intersect(['attach', 'balanceRows', 'busy', 'imageMarkup', 'labelsAllowed', 'labelsBlock'], $out['api'] ?? [])) === ['attach', 'balanceRows', 'busy', 'imageMarkup', 'labelsAllowed', 'labelsBlock'],
      substr($raw, 0, 300));
$out = is_array($out) ? $out : ['rows' => [], 'labels' => [], 'markup' => [], 'offer' => []];

foreach ([1 => [1], 5 => [3, 2], 7 => [4, 3], 9 => [3, 3, 3], 10 => [4, 3, 3], 13 => [4, 3, 3, 3]] as $count => $want) {
    check("balanced rows for $count: " . implode('+', $want), ($out['rows'][$count] ?? null) === $want, json_encode($out['rows'][$count] ?? null));
}
check('no images, no rows', ($out['rows'][0] ?? null) === []);
$rowsBad = [];
for ($k = 0; $k <= 40; $k++) {
    $got = $out['rows'][$k] ?? null;
    $want = imgUiBalanceRows($k);
    if ($got !== $want) $rowsBad[] = "$k: " . json_encode($got) . ' want ' . json_encode($want);
    if ($k > 0 && is_array($got) && (array_sum($got) !== $k || max($got) > 4 || max($got) - min($got) > 1 || $got !== imgUiDesc($got))) $rowsBad[] = "$k: shape " . json_encode($got);
}
check('0-40 images: the rows are movie-generator\'s balanceRows() exactly, at most four, fuller rows first, never more than one apart', $rowsBad === [], implode(' | ', array_slice($rowsBad, 0, 4)));

$five = '[thxlabels=3][thxrow][img]https://e.example/i/1-640x960.webp[/img][img]https://e.example/i/2-640x960.webp[/img][img]https://e.example/i/3-640x960.webp[/img][/thxrow]'
      . '[thxrow][img]https://e.example/i/4-640x960.webp[/img][img]https://e.example/i/5-640x960.webp[/img][/thxrow][/thxlabels]';
check('five labels: [thxlabels=3], a row of three, a row of two, in the order they were picked', ($out['labels'][5] ?? null) === $five, (string)($out['labels'][5] ?? ''));
check('one label: [thxlabels=1] and one row of one', ($out['labels'][1] ?? null) === '[thxlabels=1][thxrow][img]https://e.example/i/1-640x960.webp[/img][/thxrow][/thxlabels]', (string)($out['labels'][1] ?? ''));
check('nothing picked, nothing written', ($out['labels'][0] ?? null) === '');
$labBad = [];
for ($k = 1; $k <= 13; $k++) {
    $got = (string)($out['labels'][$k] ?? '');
    $list = array_map(fn($i) => 'https://e.example/i/' . $i . '-640x960.webp', range(1, $k));
    if ($got !== imgUiLabelsBlock($list)) { $labBad[] = "$k: " . $got; continue; }
    $rows = imgUiBalanceRows($k);
    preg_match('/^\[thxlabels=(\d+)\]/', $got, $c);
    preg_match_all('/\[thxrow\](.*?)\[\/thxrow\]/', $got, $rm);
    $sizes = array_map(fn($r) => substr_count($r, '[img]'), $rm[1]);
    preg_match_all('/\[img\](.*?)\[\/img\]/', $got, $im);
    if ((int)($c[1] ?? 0) !== $rows[0] || $sizes !== $rows || $im[1] !== $list) $labBad[] = "$k: structure";
}
check('1-13 labels: the block is movie-generator\'s labelsBlock() byte for byte, C = the first row, every image once and in order', $labBad === [], implode(' | ', array_slice($labBad, 0, 3)));

check('BBCode inserts [img]URL[/img]', ($out['markup']['bbcode'] ?? null) === '[img]https://e.example/i/7-640x480.webp[/img]', (string)($out['markup']['bbcode'] ?? ''));
check('Flarum inserts [img]URL[/img]', ($out['markup']['flarum'] ?? null) === '[img]https://e.example/i/7-640x480.webp[/img]', (string)($out['markup']['flarum'] ?? ''));
check('Markdown inserts ![](URL)', ($out['markup']['markdown'] ?? null) === '![](https://e.example/i/7-640x480.webp)', (string)($out['markup']['markdown'] ?? ''));
check('"Insert as labels" is offered for BBCode and Flarum, not for Markdown',
      ($out['offer']['bbcode'] ?? null) === true && ($out['offer']['flarum'] ?? null) === true && ($out['offer']['markdown'] ?? null) === false,
      json_encode($out['offer'] ?? null));

$wl = (string)file_get_contents($root . '/templates/pages/whitelist.php');
$io = (string)file_get_contents($root . '/templates/partials/info_overlay.php');
$wlGate = "<?php if (function_exists('imagesMayUpload') && imagesMayUpload(\$db, \$cfg, \$wlMe)): ?>\n"
        . "            <?php \$imgFor = 'wl-desc'; include __DIR__ . '/../partials/images_bar.php'; ?>\n"
        . "            <?php endif; ?>";
$ioGate = "<?php if (function_exists('imagesMayUpload') && imagesMayUpload(\$db, \$cfg, \$ioViewer)): ?>\n"
        . "        <?php \$imgFor = 'info-desc'; include __DIR__ . '/images_bar.php'; ?>\n"
        . "        <?php endif; ?>";
check('the whitelist form draws the bar only for a reader imagesMayUpload() allows ($wlMe)', str_contains($wl, $wlGate));
check('the Info panel\'s editor draws the bar only for a reader imagesMayUpload() allows ($ioViewer)', str_contains($io, $ioGate));
check('each template draws the bar once, and only inside its gate',
      substr_count($wl, 'images_bar.php') === 1 && substr_count($io, 'images_bar.php') === 1
      && substr_count($wl, 'imagesMayUpload(') === 1 && substr_count($io, 'imagesMayUpload(') === 1);
$wlAt = strpos($wl, $wlGate);
check('… under the whitelist editor\'s card, above its formatting help',
      $wlAt !== false && strpos($wl, 'id="wl-desc-preview"') < $wlAt && $wlAt < strpos($wl, '<details class="rt-syntax-fold">', (int)strpos($wl, 'id="wl-desc-preview"')));
$ioAt = strpos($io, $ioGate);
$tplAt = strpos($io, '<template id="info-desc-tpl">');
check('… and inside the Info panel\'s editor template, under its card, above its formatting help',
      $ioAt !== false && $tplAt !== false && $tplAt < $ioAt && $ioAt < strpos($io, '</template>', $tplAt)
      && strpos($io, 'id="info-desc-preview"') < $ioAt && $ioAt < strpos($io, '<details class="rt-syntax-fold">', (int)strpos($io, 'id="info-desc-preview"')));

$layout = (string)file_get_contents($root . '/templates/layout.php');
$tag = "<?php if ((function_exists('imagesMayUpload') && imagesMayUpload(\$db, \$cfg, \$navUser))\n"
     . "              || (function_exists('commentImagesMayUpload') && commentImagesMayUpload(\$db, \$cfg, \$navUser))): ?>\n"
     . "    <script src=\"<?= \$baseUrl ?>assets/js/images-editor.js<?= assetVer('assets/js/images-editor.js') ?>\"></script>\n"
     . "    <?php endif; ?>";
check('the layout loads the script only for a reader who may upload, and before app.js mounts the editors',
      str_contains($layout, $tag) && strpos($layout, $tag) < strpos($layout, "assets/js/app.js<?= assetVer('assets/js/app.js') ?>"));

$app = (string)file_get_contents($root . '/assets/js/app.js');
$mountFrom = strpos($app, '    function mountRichtext(id, opts) {');
$mountTo = strpos($app, "    mountRichtext('wl-desc');");
$mountSrc = ($mountFrom !== false && $mountTo !== false) ? substr($app, $mountFrom, $mountTo - $mountFrom) : '';
check('the shared editor attaches the bar itself, handing over its own format',
      str_contains($mountSrc, "window.ImagesEditor.attach({ id, textarea: ta, format: fmt })")
      && strpos($mountSrc, 'window.ImagesEditor.attach(') > strpos($mountSrc, 'const fmt = '));
check('neither the whitelist form nor the Info panel sends while an image is still uploading',
      str_contains($app, "if (descBusy && window.ImagesEditor && window.ImagesEditor.busy(descBusy)) {")
      && str_contains($app, "if (ta && window.ImagesEditor && window.ImagesEditor.busy(ta)) { msg.textContent = t.key('js.images.wait_uploads'); return; }"));

$imgFor = 'wl-desc';
ob_start();
include $root . '/templates/partials/images_bar.php';
$bar = (string)ob_get_clean();
$doc = new DOMDocument();
@$doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $bar . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
$byId = fn(string $id) => $doc->getElementById($id);
$missing = [];
foreach (['wl-desc-images', 'wl-desc-images-add', 'wl-desc-images-file', 'wl-desc-images-mine', 'wl-desc-images-ups',
          'wl-desc-images-msg', 'wl-desc-images-say', 'wl-desc-images-panel', 'wl-desc-images-hint', 'wl-desc-images-head'] as $id) {
    if (!$byId($id)) $missing[] = $id;
}
check('the bar carries every part the script finds round the editor\'s id', $missing === [], implode(', ', $missing));
$file = $byId('wl-desc-images-file');
check('"Choose files" takes several images at once', $file && $file->getAttribute('type') === 'file' && $file->hasAttribute('multiple')
      && $file->getAttribute('accept') === 'image/*');
$mine = $byId('wl-desc-images-mine');
$panel = $byId('wl-desc-images-panel');
check('"My images" is a disclosure: aria-expanded, aria-controls naming the panel, the panel hidden and named by it',
      $mine && $panel && $mine->getAttribute('aria-expanded') === 'false' && $mine->getAttribute('aria-controls') === 'wl-desc-images-panel'
      && $panel->hasAttribute('hidden') && $panel->getAttribute('aria-labelledby') === 'wl-desc-images-mine');
check('the panel is a layer of its own inside a window, so Esc closes it first', $panel && $panel->hasAttribute('data-esc-layer'));
$msgEl = $byId('wl-desc-images-msg');
$sayEl = $byId('wl-desc-images-say');
check('errors are an alert, progress words a polite live region', $msgEl && $msgEl->getAttribute('role') === 'alert' && $sayEl && $sayEl->getAttribute('aria-live') === 'polite');
$handlers = [];
foreach ((new DOMXPath($doc))->query('//*') as $node) {
    foreach ($node->attributes as $at) if (str_starts_with(strtolower($at->name), 'on')) $handlers[] = $node->nodeName . '@' . $at->name;
}
check('no inline handler and no inline script in the bar', $handlers === [] && stripos($bar, '<script') === false, implode(', ', $handlers));
$en = include $root . '/lang/en.php';
$pl = include $root . '/lang/pl.php';
check('the bar\'s words are the dictionary\'s, in both languages',
      str_contains($bar, htmlspecialchars($en['rt.images_drop'], ENT_QUOTES, 'UTF-8')) && ($pl['rt.images'] ?? '') === 'Obrazy'
      && ($pl['rt.images_mine'] ?? '') === 'Moje obrazy' && ($pl['js.images.labels'] ?? '') === 'Wstaw jako etykiety');
$iconsBad = [];
foreach (['bi-collection'] as $ic) if (!str_contains($bar, 'class="bi ' . $ic . '"')) $iconsBad[] = $ic;
if (!str_contains($bar, 'class="bi bi-cloud-arrow-up rt-images-drop-icon"')) $iconsBad[] = 'bi-cloud-arrow-up';
foreach (["icon('bi-ui-checks-grid')", "iconBtn('bi-chevron-left'", "iconBtn('bi-chevron-right'", "iconBtn('bi-link-45deg'", "iconBtn('bi-code-slash'", "iconBtn('bi-trash'"] as $ic) {
    if (!str_contains($js, $ic)) $iconsBad[] = $ic;
}
$map = iconFaMap();
foreach (['image', 'cloud-arrow-up', 'collection', 'ui-checks-grid', 'chevron-left', 'chevron-right', 'link-45deg', 'code-slash', 'trash', 'check-lg'] as $nm) {
    if (!isset($map[$nm])) $iconsBad[] = 'no Font Awesome twin for ' . $nm;
}
check('every icon the bar and the panel draw is one the Font Awesome map knows', $iconsBad === [], implode(', ', $iconsBad));

preg_match_all("/'(js\\.images\\.[a-z0-9_]+)'/", $js . $app, $km);
$keys = array_values(array_unique($km[1]));
$keyBad = [];
foreach ($keys as $k) {
    $plural = isset($en[$k . '_one'], $en[$k . '_many'], $pl[$k . '_one'], $pl[$k . '_many'], $pl[$k . '_few']);
    if (!$plural && (!isset($en[$k], $pl[$k]) || $en[$k] === '' || $pl[$k] === '')) $keyBad[] = $k;
}
check('every word the script writes is in both dictionaries (' . count($keys) . ' keys)', count($keys) > 30 && $keyBad === [], implode(', ', $keyBad));
check('… and reaches every public page (js.images. in LANG_JS_PUBLIC)', in_array('js.images.', LANG_JS_PUBLIC, true));
$untranslated = [];
foreach ($en as $k => $v) if (str_starts_with($k, 'js.images.') && $k !== 'js.images.percent' && $k !== 'js.images.thumb_tip' && $k !== 'js.images.failed_named' && ($pl[$k] ?? $v) === $v) $untranslated[] = $k;
check('… with Polish of its own, not the English copied', $untranslated === [], implode(', ', $untranslated));

$jsBad = [];
if (preg_match('/\son[a-z]+\s*=/i', $js)) $jsBad[] = 'on*= in markup';
if (preg_match('/\.on[a-z]+\s*=/', $js)) $jsBad[] = 'on* property';
if (str_contains($js, 'innerHTML')) $jsBad[] = 'innerHTML';
if (preg_match("/textContent\\s*=\\s*'[^']/", $js)) $jsBad[] = 'a literal word';
check('the script writes no handler, no markup and no word outside the dictionary', $jsBad === [], implode(', ', $jsBad));
check('uploads go one file per request, at most two at a time, with the page\'s token',
      str_contains($js, 'const PARALLEL = 2;') && str_contains($js, "fd.append('file', file, file.name || 'image.png');")
      && str_contains($js, "fd.append('csrf_token', csrfToken(node));") && substr_count($js, "fd.append(") === 2);
check('a file over the server\'s max_bytes is refused before it is sent', str_contains($js, 'Number(lim.max_bytes)')
      && str_contains($js, "t.key('js.images.too_big', { name, max: t.bytes(max) })"));
check('a drop lands at the text position under the pointer, a thumbnail\'s drag carries the markup as text',
      str_contains($js, 'offsetFromPoint(ta, e.clientX, e.clientY)') && str_contains($js, "e.dataTransfer.setData('text/plain', imageMarkup(url, fmt()));"));
check('a delete is asked in place and the server\'s refusal is shown as it said it',
      str_contains($js, "window.askInPlace(btn, t.key('js.images.delete_q')") && str_contains($js, "note(serverSays(j) || t.key('js.images.delete_failed'));"));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
