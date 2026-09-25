<?php
/**
 * The shoutbox's emoji picker (1.69.0):
 *   php tests/emoji_test.php
 *
 *   1. the data files (assets/emoji/emoji-en.json, emoji-pl.json): every emoji up to Emoji 16.0, in
 *      Unicode's groups, named and keyworded in both languages, the five skin tones valid, nothing newer
 *      than the cap — held to Unicode's own ages (ICU) and to the lists of what 16.0 and 17.0 added;
 *   2. Font Awesome's faces as this project describes them (assets/emoji/fa-faces.json);
 *   3. the :fa-NAME: token: its grammar, and that it can never be an emote code or a :shortcode:;
 *   4. what a token becomes where Font Awesome cannot draw it — the ordinary emoji, never nothing —
 *      through every renderer (descriptions, messages, shouts in each format, a mail), hostile input;
 *   5. the two settings in their four places, and their words;
 *   6. the picker's script and the widget: the data asked for when the picker opens, not on the page;
 *   7. api/shout_emoji.php as a request.
 * With a Font Awesome Pro package drawing the faces, the synthetic cases are tests/iconpack_test.php §12
 * and the browser half is scratchpad/shots/emoji_picker_check.js.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/shout.php';
require_once $root . '/includes/settings_catalog.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . mb_substr($info, 0, 600)) . "\n";
    if (!$ok) $fails++;
}
/** The code points of a string, as integers. */
function emtCps(string $s): array { return array_map(fn($c) => mb_ord($c, 'UTF-8'), mb_str_split($s, 1, 'UTF-8')); }

/* ══ 1. the data files ═════════════════════════════════════════════════════════ */
$data = [];
foreach (['en', 'pl'] as $lc) $data[$lc] = json_decode((string)@file_get_contents($root . '/assets/emoji/emoji-' . $lc . '.json'), true);
$wantGroups = ['smileys', 'people', 'animals', 'food', 'travel', 'activities', 'objects', 'symbols', 'flags'];
check('both files parse, format 1, capped at Emoji 16.0, in Unicode\'s nine groups and order',
      is_array($data['en']) && is_array($data['pl']) && $data['en']['format'] === 1 && $data['pl']['format'] === 1
      && $data['en']['cap'] === '16.0' && $data['pl']['cap'] === '16.0'
      && $data['en']['groups'] === $wantGroups && $data['pl']['groups'] === $wantGroups
      && $data['en']['lang'] === 'en' && $data['pl']['lang'] === 'pl');
$E = $data['en']['e'] ?? []; $P = $data['pl']['e'] ?? [];
check('… the same emoji in the same order in both (row N is row N: the search\'s English fallback relies on it), as many as each says',
      count($E) === count($P) && count($E) === (int)$data['en']['count'] && count($P) === (int)$data['pl']['count']
      && array_column($E, 1) === array_column($P, 1) && array_column($E, 0) === array_column($P, 0) && array_column($E, 4) === array_column($P, 4));
$per = array_fill_keys($wantGroups, 0);
foreach ($E as $r) $per[$wantGroups[$r[0]] ?? '?'] = ($per[$wantGroups[$r[0]] ?? '?'] ?? 0) + 1;
// Unicode's own counts at 16.0, the components and the regional letters left out (tools/emoji_data.php).
$wantPer = ['smileys' => 169, 'people' => 386, 'animals' => 159, 'food' => 131, 'travel' => 218, 'activities' => 85, 'objects' => 264, 'symbols' => 224, 'flags' => 270];
check('per group: ' . implode(', ', array_map(fn($g, $c) => "$g $c", array_keys($per), $per)), $per === $wantPer, json_encode($per));
check('the characters are unique: no emoji twice', count(array_unique(array_column($E, 1))) === count($E));
$emptyEn = array_filter($E, fn($r) => trim((string)$r[2]) === '' || trim((string)$r[3]) === '');
$emptyPl = array_filter($P, fn($r) => trim((string)$r[2]) === '' || trim((string)$r[3]) === '');
check('every emoji has a name and keywords in English', $emptyEn === [], json_encode(array_slice(array_values($emptyEn), 0, 3), JSON_UNESCAPED_UNICODE));
check('… and in Polish', $emptyPl === [], json_encode(array_slice(array_values($emptyPl), 0, 3), JSON_UNESCAPED_UNICODE));
$same = 0; $polish = 0;
foreach ($E as $i => $r) { if ($r[2] === $P[$i][2]) $same++; if (preg_match('/[ąćęłńóśźż]/u', (string)$P[$i][2] . (string)$P[$i][3])) $polish++; }
check("the Polish is Polish: $same of " . count($E) . ' names the same as English (flags, letters), ' . $polish . ' rows with Polish letters',
      $same < count($E) * 0.2 && $polish > count($E) * 0.4);
check('keywords are the searchable words, lower case, one separator, no repeats',
      !array_filter(array_merge($E, $P), fn($r) => $r[3] !== mb_strtolower($r[3], 'UTF-8') || str_contains($r[3], '||') || count(explode('|', $r[3])) !== count(array_unique(explode('|', $r[3])))));
// Skin tones: five, light to dark, each the base with that one modifier — nothing else changed.
$badTone = []; $toned = 0;
foreach ($E as $r) {
    if ($r[4] === 0) continue;
    $toned++;
    if (!is_array($r[4]) || count($r[4]) !== 5) { $badTone[] = $r[1] . ' shape'; continue; }
    $strip = fn(string $s) => array_values(array_filter(emtCps($s), fn($c) => $c !== 0xFE0F && ($c < 0x1F3FB || $c > 0x1F3FF)));
    foreach ($r[4] as $k => $v) {
        $mods = array_values(array_filter(emtCps($v), fn($c) => $c >= 0x1F3FB && $c <= 0x1F3FF));
        if (!$mods || array_unique($mods) !== [0x1F3FB + $k]) $badTone[] = $r[1] . ' tone ' . ($k + 1);
        elseif ($strip($v) !== $strip($r[1])) $badTone[] = $r[1] . ' tone ' . ($k + 1) . ' is another emoji';
    }
}
check("$toned emoji with the five skin tones, each the base with one tone's modifier (light to dark) and nothing else changed",
      $badTone === [] && $toned === 323, implode(', ', array_slice($badTone, 0, 6)));
// Nothing newer than the cap. Unicode's own age for every code point (ICU); where the ICU here is older
// than Unicode 16, the eight characters 16.0 added are named; and what 17.0 added must be absent.
$new16 = [0x1FAE9, 0x1FAC6, 0x1FABE, 0x1FADC, 0x1FA89, 0x1FA8F, 0x1FADF];
$new17 = [0x1FAEA, 0x1FAEF, 0x1FAC8, 0x1FACD, 0x1F6D8, 0x1FA8A, 0x1FA8E];
$all = [];
foreach ($E as $r) { $all[] = $r[1]; if ($r[4]) foreach ($r[4] as $v) $all[] = $v; }
$tooNew = []; $unknown = []; $icu = class_exists('IntlChar');
foreach ($all as $s) {
    foreach (emtCps($s) as $cp) {
        if (in_array($cp, $new17, true)) $tooNew[] = sprintf('%X', $cp);
        if (!$icu || in_array($cp, $new16, true)) continue;
        $age = IntlChar::charAge($cp);
        $v = (int)$age[0] * 100 + (int)$age[1];
        if ($v === 0) $unknown[] = sprintf('%X', $cp); elseif ($v > 1600) $tooNew[] = sprintf('%X', $cp);
    }
}
check('no character newer than Emoji 16.0' . ($icu ? ' (Unicode\'s ages, ICU ' . INTL_ICU_VERSION . ', and the eight 16.0 added)' : ' (no ICU here: 17.0\'s list only)'),
      $tooNew === [] && array_diff(array_unique($unknown), []) === [], json_encode(['new' => array_unique($tooNew), 'unknown to ICU' => array_unique($unknown)]));
$flat = implode(' ', $all);
check('… none of 17.0\'s sequences either (the ballet dancer, the toned bunny ears and wrestlers), while 16.0\'s are there (face with bags under eyes, splatter, the Sark flag)',
      !str_contains($flat, "\u{1F9D1}\u{200D}\u{1FA70}") && !preg_match('/\x{1F46F}[\x{1F3FB}-\x{1F3FF}]|\x{1F93C}[\x{1F3FB}-\x{1F3FF}]/u', $flat)
      && in_array("\u{1FAE9}", $all, true) && in_array("\u{1FADF}", $all, true) && in_array("\u{1F1E8}\u{1F1F6}", $all, true));
check('no part on its own: no tone swatch, no hair style, no lone regional letter',
      !array_filter($all, fn($s) => (bool)preg_match('/^[\x{1F3FB}-\x{1F3FF}\x{1F9B0}-\x{1F9B3}\x{1F1E6}-\x{1F1FF}]$/u', $s)));
check('an emoji that is one by default carries no presentation selector (U+1F44D alone); one that is text by default keeps it (U+263A U+FE0F)',
      in_array("\u{1F44D}", array_column($E, 1), true) && !in_array("\u{1F44D}\u{FE0F}", array_column($E, 1), true)
      && in_array("\u{263A}\u{FE0F}", array_column($E, 1), true));
$lic = (string)@file_get_contents($root . '/assets/emoji/LICENSE.txt');
check('each file says where it came from, first; assets/emoji/LICENSE.txt carries both licences in full',
      str_contains($data['en']['_'], 'emojibase-data') && str_contains($data['en']['_'], 'MIT') && str_contains($data['en']['_'], 'CLDR')
      && str_contains($data['en']['_'], 'Unicode License v3') && str_contains($data['pl']['_'], 'LICENSE.txt')
      && str_contains($lic, 'MIT License') && str_contains($lic, 'Copyright (c) 2017-2019 Miles Johnson') && str_contains($lic, 'UNICODE LICENSE V3')
      && str_contains($lic, 'Permission is hereby granted, free of charge') && str_contains($lic, 'Except as contained in this notice'));
$gen = (string)@file_get_contents($root . '/tools/emoji_data.php');
check('the generator is the one in tools/, runs from a package directory given to it, and refuses anything else',
      str_contains($gen, "PHP_SAPI !== 'cli'") && str_contains($gen, "'emojibase-data'") && str_contains($gen, '--cap=')
      && trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/emoji_data.php') . ' ' . escapeshellarg(sys_get_temp_dir()) . ' 2>&1')) !== ''
      && !is_file(sys_get_temp_dir() . '/emoji-en.json'));
check('one emoji per line, so a regeneration reads as a diff',
      substr_count((string)file_get_contents($root . '/assets/emoji/emoji-en.json'), "\n") === count($E) + 3);

/* ══ 2. Font Awesome's faces, as this project describes them ═════════════════════ */
$F = emojiFaFaces();
$byChar = array_flip($all);
$badF = [];
foreach ($F['faces'] as $name => $f) {
    if (!preg_match('/^face-[a-z0-9]+(-[a-z0-9]+)*$/', $name)) $badF[] = "$name: name";
    if (!in_array($f[0], $F['pages'], true)) $badF[] = "$name: page";
    if (!isset($byChar[$f[1]])) $badF[] = "$name: its emoji is not one of the picker's";
    if (trim($f[2]) === '' || trim($f[3]) === '' || trim($f[4]) === '') $badF[] = "$name: words";
    if (!preg_match('/[a-ząćęłńóśźż]/u', $f[2])) $badF[] = "$name: label";
}
check('113 faces (7.3.1\'s emoji category; 6.7.2 has 110 of them), each on one of four pages, each falling back to an emoji the picker has, each with Polish and English words',
      count($F['faces']) === 113 && $F['pages'] === ['fa-happy', 'fa-calm', 'fa-sad', 'fa-cross'] && $badF === [], implode('; ', array_slice($badF, 0, 6)));
check('… every page has faces and a tab face of its own', !array_diff($F['pages'], array_column($F['faces'], 0))
      && !array_filter($F['pages'], fn($p) => !isset($F['faces'][$F['tabs'][$p] ?? ''])));
$faJson = (string)file_get_contents($root . '/assets/emoji/fa-faces.json');
check('… and the file holds names and words only: no glyph, no code point, nothing of Font Awesome\'s files',
      !preg_match('/\\\\u[ef][0-9a-f]{3}|[\x{E000}-\x{F8FF}]|@font-face|url\(/iu', $faJson));

/* ══ 3. the token ═══════════════════════════════════════════════════════════════ */
$ok = [':fa-face-grin:', ':fa-face-grin-tears/duotone-light:', ':fa-face-smile/sharp-duotone-solid:', ':fa-0:'];
$no = [':fa_face:', ':fa-:', ':fa-Face-grin:', ':fa-face--grin:', ':fa-face-grin/:', ':fa-face-grin/Solid:', ':fa face:', 'fa-face-grin:', ':face-grin:'];
check('the token is `:fa-NAME:` or `:fa-NAME/STYLE:` in Font Awesome\'s own grammar, and nothing looser',
      !array_filter($ok, fn($t) => preg_match(EMOJI_FA_TOKEN_RE, $t, $m) !== 1 || $m[0] !== $t) && !array_filter($no, fn($t) => preg_match(EMOJI_FA_TOKEN_RE, $t) === 1));
check('… no emote code can be one ([a-z0-9_], no hyphen) and none of the shortcodes starts with fa-',
      !array_filter(array_keys(richtextEmoji()), fn($k) => str_starts_with((string)$k, 'fa-') || preg_match(EMOJI_FA_TOKEN_RE, ':' . $k . ':'))
      && !preg_match(SHOUT_EMOTE_CODE_RE, 'fa-face-grin') && preg_match(EMOJI_FA_TOKEN_RE, ':fa_face_grin:') === 0);
check('… and the style form is outside the shortcodes\' characters: a shortcode pass never reads it',
      preg_match('/:([a-z0-9_+-]{1,24}):/', ':fa-face-grin/solid:') === 0);
$sjs = (string)file_get_contents($root . '/assets/js/shoutbox.js');
check('the picker\'s twin of the grammar is the same expression, held to the whole token',
      preg_match('#var FA_TOKEN = /\^(.+)\$/;#', $sjs, $jm) === 1 && '/' . $jm[1] . '/' === EMOJI_FA_TOKEN_RE, $jm[1] ?? '');

/* ══ 4. where Font Awesome cannot draw it: the emoji ═══════════════════════════════ */
$offCfg = ['shout_emoji_fa' => 'fa', 'icon_library' => 'bootstrap'];
$grin = $F['faces']['face-grin'][1]; $tears = $F['faces']['face-grin-tears'][1];
check('Bootstrap Icons, or Font Awesome without a Pro package: a face token is the emoji it stands for',
      richtextRender(':fa-face-grin:', 'bbcode', $offCfg) === '<p>' . $grin . '</p>'
      && richtextRender(':fa-face-grin/duotone-light:', 'bbcode', ['icon_library' => 'fontawesome', 'fa_source' => 'cdn7', 'shout_emoji_fa' => 'mixed']) === '<p>' . $grin . '</p>');
check('… a name that is no face stays the text it was typed as, and so does a face in [code] or in inline code',
      richtextRender(':fa-rocket: [code]:fa-face-grin:[/code]', 'bbcode', $offCfg) === '<p>:fa-rocket:</p><pre class="rt-code"><code>:fa-face-grin:</code></pre>'
      && richtextRender('`:fa-face-grin:` :fa-face-grin-tears:', 'markdown', $offCfg) === '<p><code class="rt-inline">:fa-face-grin:</code> ' . $tears . '</p>');
$href = richtextRender('[url=https://example.org/:fa-face-grin:]:fa-face-grin:[/url] https://example.org/a:fa-face-grin:b', 'bbcode', $offCfg);
check('… an address keeps its bytes (the token inside an href is part of it), the words of the link are drawn',
      str_contains($href, 'href="https://example.org/:fa-face-grin:"') && str_contains($href, '>' . $grin . '</a>'), $href);
$hostile = richtextRender(':fa-face-grin"><img src=x onerror=alert(1)>: :fa-face-grin/solid" onmouseover="x: <i class="fae">:fa-face-grin:</i>', 'bbcode', $offCfg);
check('… hostile input is text: nothing it carries becomes an element or an attribute',
      !str_contains($hostile, '<img') && !str_contains($hostile, '<i ') && !str_contains($hostile, '" on') && str_contains($hostile, '&lt;i class=&quot;fae&quot;&gt;'), $hostile);
check('a mail always gets the emoji, whatever the site draws', richtextRenderForEmail('hi :fa-face-grin:', 'bbcode', ['shout_emoji_fa' => 'fa']) !== ''
      && str_contains(richtextRenderForEmail('hi :fa-face-grin:', 'bbcode', ['shout_emoji_fa' => 'fa']), $grin));
$ctx0 = ['known' => [], 'base' => '', 'me_name' => '', 'emotes' => [], 'stickers' => false, 'img_title' => ''];
check('a shout draws tokens in every format the room offers — plain too, as it draws emotes there',
      shoutBodyHtml("x :fa-face-grin:\ny", 'plain', $offCfg, $ctx0) === 'x ' . $grin . "<br>\ny"
      && str_contains(shoutBodyHtml('x :fa-face-grin:', 'bbcode', $offCfg, $ctx0), $grin)
      && str_contains(shoutBodyHtml('x :fa-face-grin:', 'markdown', $offCfg, $ctx0), $grin));
check('… and an emote beside a token is still an emote: the two never read each other',
      str_contains(shoutBodyHtml(':fa-face-grin::wave:', 'bbcode', $offCfg, ['emotes' => ['wave' => ['id' => 1, 'code' => 'wave', 'name' => 'Wave', 'sha1' => 'aaaaaaaa', 'width' => 16, 'height' => 16, 'is_sticker' => 0]]] + $ctx0), 'alt=":wave:"'));
check('a face the package would lack, or a style that no longer loads, falls back the same way (the synthetic Pro cases: iconpack_test §12)',
      emojiFaTokenHtml(':fa-face-grin/solid:', 'face-grin', 'solid', ['mode' => 'fa', 'faces' => [], 'setup' => null], 'en') === htmlspecialchars($grin));
check('no text is lost when nothing can be drawn and no emoji is known: the face\'s label, in brackets',
      emojiFaTokenHtml(':fa-face-new:', 'face-new', null, ['mode' => 'off', 'faces' => ['face-new' => ['Face New', [], ['solid']]], 'setup' => null], 'en') === '[Face New]');

/* ══ 5. the two settings, in their four places ═══════════════════════════════════ */
$defs = trackerSchemaDefaultSettings();
check('the schema is at 77 or later, and the two settings ship off / the site\'s style', TRACKER_SCHEMA_VERSION >= 77
      && ($defs['shout_emoji_fa'] ?? null) === 'off' && ($defs['shout_emoji_fa_style'] ?? null) === '' && EMOJI_FA_MODES === ['off', 'fa', 'mixed']);
check('… read the careful way: anything but the three words is off', emojiFaSetting([]) === 'off' && emojiFaSetting(['shout_emoji_fa' => 'FA']) === 'off'
      && emojiFaSetting(['shout_emoji_fa' => 'mixed']) === 'mixed');
$save = (string)file_get_contents($root . '/api/admin/save_settings.php');
check('… in the save allow-list, the mode coerced like the room\'s other closed sets, the style judged against what loads',
      str_contains($save, "'shout_emoji_fa', 'shout_emoji_fa_style',") && str_contains($save, "!in_array(\$data['shout_emoji_fa'], EMOJI_FA_MODES, true)")
      && str_contains($save, "if (\$v === 'brands' || !isset(\$loads[\$v])) \$v = '';"));
$kw = settingsCatalogKeywords();
check('… with search words', !empty($kw['shout_emoji_fa']) && !empty($kw['shout_emoji_fa_style']));
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
$sp = strpos($tpl, 'id="section-shout"');
$sec = $sp !== false ? substr($tpl, $sp, (int)strpos($tpl, 'class="settings-section"', $sp + 20) - $sp) : '';
check('… and in Settings → Shoutbox: both controls, offered only while a Pro package is the icon source, a note otherwise',
      str_contains($sec, 'name="shout_emoji_fa"') && str_contains($sec, 'name="shout_emoji_fa_style"') && str_contains($sec, "!empty(\$shoutFa['available'])")
      && str_contains($sec, "__('settings.shout_emoji_fa_hint'") && str_contains($sec, "_h('settings.shout_emoji_fa_unavailable')")
      && strpos($sec, 'id="admin-shout-emoji"') < strpos($sec, 'id="admin-emotes"'));
foreach (['en', 'pl'] as $lc) {
    $d = include $root . '/lang/' . $lc . '.php';
    $hint = (string)($d['settings.shout_emoji_fa_hint'] ?? '');
    check("the $lc hint names the token and says the choice is offered only with a Pro package",
          str_contains($hint, '<code>:fa-') && ($lc === 'en' ? str_contains($hint, 'Offered only while a Font Awesome Pro package') : str_contains($hint, 'Dostępne tylko wtedy, gdy źródłem ikon strony jest paczka Font Awesome Pro')), $hint);
    $miss = [];
    foreach (array_merge(['recent', 'emotes', 'stickers'], $wantGroups, array_map(fn($p) => str_replace('-', '_', $p), $F['pages'])) as $tab) {
        if (trim((string)($d['js.shout.tab_' . $tab] ?? '')) === '') $miss[] = $tab;
    }
    foreach (['search', 'search_clear', 'search_none', 'search_found', 'recent_empty', 'emoji_failed', 'variants', 'variants_hint', 'tone_0', 'tone_5'] as $k) {
        if (trim((string)($d['js.shout.' . $k] ?? '')) === '') $miss[] = $k;
    }
    check("… and the picker says every page and control in $lc", $miss === [], implode(', ', $miss));
}

/* ══ 6. the picker's script, and the widget ═══════════════════════════════════════ */
check('the script carries no emoji: the grid is data, asked for when the picker opens (ensureData(), from the widget\'s data-emoji-files)',
      !preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{1F3FB}-\x{1F3FF}]/u', $sjs)
      && str_contains($sjs, 'function ensureData()') && str_contains($sjs, "JSON.parse(box.dataset.emojiFiles || '{}')")
      && str_contains($sjs, "API + 'shout_emoji&v='") && substr_count($sjs, 'fetchJson(') >= 3);
check('… Recent and the tone are this browser\'s (localStorage, every access guarded); a hold is 450 ms; the grid is drawn in slices',
      str_contains($sjs, "RECENT_KEY = 'thx_emoji_recent', TONE_KEY = 'thx_emoji_tone'") && str_contains($sjs, 'var HOLD_MS = 450;')
      && str_contains($sjs, 'try { return window.localStorage.getItem(key); } catch (e)') && str_contains($sjs, 'requestAnimationFrame(function () { if (mine !== job) return; slice(MORE_CELLS); more(); });'));
check('… the variants open on a long press, a right click, the context-menu key and Shift+Enter; a plain click inserts at once',
      str_contains($sjs, "gridEl.addEventListener('pointerdown'") && str_contains($sjs, "gridEl.addEventListener('contextmenu'")
      && str_contains($sjs, "e.key === 'ContextMenu'") && str_contains($sjs, "(e.key === 'Enter' && e.shiftKey)") && str_contains($sjs, 'pick(itemOf(cell));'));
$wid = (string)file_get_contents($root . '/templates/partials/shoutbox_widget.php');
check('the widget says where the files are and whether the faces are on — and carries no emoji data of its own',
      str_contains($wid, 'data-emoji-files="') && str_contains($wid, 'data-emoji-fa="') && str_contains($wid, 'data-emoji-fa-v="') && !str_contains($wid, 'emoji-en.json'));
check('the script that marks the site\'s icons leaves Font Awesome\'s faces alone (an element without `bi` is not its)',
      str_contains((string)file_get_contents($root . '/assets/js/icons.js'), "if (!name && !cl.contains('bi')) return;"));
$css = (string)file_get_contents($root . '/assets/css/style.css');
check('the picker\'s pieces are styled in the site\'s own colours; a face is an emoji\'s yellow, in shouts and in the panel',
      str_contains($css, '.shout-picker-search {') && str_contains($css, '.shout-picker-var {') && str_contains($css, '.shout-picker-cell.has-var::after {')
      && str_contains($css, '.fae {') && str_contains((string)file_get_contents($root . '/assets/css/admin.css'), '.fae {'));

/* ══ 7. api/shout_emoji.php, as a request ═══════════════════════════════════════════ */
$db = getDb();
$apiSrc = (string)file_get_contents($root . '/api.php');
check('the endpoint is routed', str_contains($apiSrc, "'shout_emoji'                => 'api/shout_emoji.php',"));
$uid = (int)$db->query("SELECT id FROM users WHERE username = 'smokeuser'")->fetchColumn();
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'emt_runner_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_GET = $a["get"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/richtext.php", "includes/users.php", "includes/shout.php", "includes/audit.php", "includes/auth.php", "includes/lang.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, null);
$GLOBALS["__audit_endpoint"] = "shout_emoji";
require "api/shout_emoji.php";
');
$get = function (array $cfgX, array $session, array $q = []) use ($root, $runner): array {
    $arg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'emt_args_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($arg, json_encode(['root' => $root, 'get' => $q, 'cfg' => $cfgX, 'session' => $session, 'sid' => 'emtest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($runner) . ' ' . escapeshellarg($arg) . ' 2>&1');
    @unlink($arg);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 300)];
};
$roomOn = ['users_enabled' => '1', 'shout_enabled' => '1'];
$sess = $uid > 0 ? ['user_id' => $uid, 'user_login_time' => time(), 'csrf_token' => 'emt'] : [];
check('with the room off: refused, as its emotes are', ($get(['shout_enabled' => '0'], $sess)['error'] ?? '') === 'disabled');
if ($uid > 0) {
    $j = $get($roomOn + ['shout_emoji_fa' => 'mixed', 'icon_library' => 'bootstrap'], $sess);
    check('a member, Bootstrap Icons: no faces — the mode is off whatever the setting says', ($j['success'] ?? false) === true && ($j['mode'] ?? '') === 'off' && ($j['faces'] ?? null) === [], json_encode($j));
    $pro = array_values(array_filter(iconpackInstalledIds(), fn($id) => ($m = iconpackManifest($id)) && $m['edition'] === 'pro' && iconpackEmojiOf($id) !== null));
    if ($pro) {
        $m = iconpackManifest($pro[count($pro) - 1]);
        $cfgPro = $roomOn + ['icon_library' => 'fontawesome', 'fa_source' => 'pack', 'fa_pack' => $m['id'], 'fa_pack_styles' => '[]', 'fa_style' => 'solid', 'shout_emoji_fa' => 'fa'];
        $j = $get($cfgPro, $sess, ['lang' => 'pl', 'v' => 'x']);
        $jEn = $get($cfgPro, $sess, ['lang' => 'en']);
        $grinPl = array_values(array_filter((array)($j['faces'] ?? []), fn($f) => ($f['n'] ?? '') === 'face-grin'))[0] ?? [];
        check('a member, Pro ' . $m['version'] . ' drawing the icons (installed here): the faces, labelled in the language asked for',
              ($j['mode'] ?? '') === 'fa' && count($j['faces'] ?? []) === count(iconpackEmojiOf($m['id'])['faces'])
              && ($grinPl['l'] ?? '') === $F['faces']['face-grin'][2] && (array_values(array_filter($jEn['faces'] ?? [], fn($f) => $f['n'] === 'face-grin'))[0]['l'] ?? '') !== $grinPl['l'],
              json_encode(['mode' => $j['mode'] ?? null, 'n' => count($j['faces'] ?? []), 'grin' => $grinPl]));
    } else {
        echo "SKIP the endpoint with a Pro package: none with an index is installed here (tests/iconpack_test.php §12 covers it synthetically)\n";
    }
} else {
    echo "SKIP the endpoint as a member: no smokeuser here (run deploy/local_bootstrap.php and the smokes)\n";
}
@unlink($runner);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
