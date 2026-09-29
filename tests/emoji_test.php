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
// 1.70.0: any icon, so the longest names Font Awesome has (nine parts) — and still nothing looser.
$ok = [':fa-face-grin:', ':fa-face-grin-tears/duotone-light:', ':fa-face-smile/sharp-duotone-solid:', ':fa-0:',
       ':fa-arrow-up-right-and-arrow-down-left-from-center:', ':fa-rocket/slab-press-duo-regular:'];
$no = [':fa_face:', ':fa-:', ':fa-Face-grin:', ':fa-face--grin:', ':fa-face-grin/:', ':fa-face-grin/Solid:', ':fa face:', 'fa-face-grin:', ':face-grin:',
       ':fa-a-b-c-d-e-f-g-h-i-j-k:', ':fa-rocket/a-b-c-d-e-f:'];
check('the token is `:fa-NAME:` or `:fa-NAME/STYLE:` in Font Awesome\'s own grammar, and nothing looser',
      !array_filter($ok, fn($t) => preg_match(EMOJI_FA_TOKEN_RE, $t, $m) !== 1 || $m[0] !== $t) && !array_filter($no, fn($t) => preg_match(EMOJI_FA_TOKEN_RE, $t) === 1));
check('… no emote code can be one ([a-z0-9_], no hyphen) and none of the shortcodes starts with fa-',
      !array_filter(array_keys(richtextEmoji()), fn($k) => str_starts_with((string)$k, 'fa-') || preg_match(EMOJI_FA_TOKEN_RE, ':' . $k . ':'))
      && !preg_match(SHOUT_EMOTE_CODE_RE, 'fa-face-grin') && preg_match(EMOJI_FA_TOKEN_RE, ':fa_face_grin:') === 0);
check('… and the style form is outside the shortcodes\' characters: a shortcode pass never reads it',
      preg_match('/:([a-z0-9_+-]{1,24}):/', ':fa-face-grin/solid:') === 0);
// The picker is assets/js/emoji-picker.js from 1.70.0 — moved out of shoutbox.js, which mounts it, when every
// editor got it. Both are read: the picker's code in the one, the room's mount of it in the other.
$pjs = (string)file_get_contents($root . '/assets/js/emoji-picker.js');
$sjs = $pjs . "\n" . (string)file_get_contents($root . '/assets/js/shoutbox.js');
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

/* ══ 5. the three settings, in their four places ═════════════════════════════════ */
$defs = trackerSchemaDefaultSettings();
check('the schema is at 78 or later, and the three settings ship off / the site\'s style / the faces alone (1.70.0: the scope)', TRACKER_SCHEMA_VERSION >= 78
      && ($defs['shout_emoji_fa'] ?? null) === 'off' && ($defs['shout_emoji_fa_style'] ?? null) === '' && ($defs['shout_emoji_fa_scope'] ?? null) === 'faces'
      && EMOJI_FA_MODES === ['off', 'fa', 'mixed'] && EMOJI_FA_SCOPES === ['faces', 'search', 'all']);
check('… read the careful way: anything but the three words is off, and faces', emojiFaSetting([]) === 'off' && emojiFaSetting(['shout_emoji_fa' => 'FA']) === 'off'
      && emojiFaSetting(['shout_emoji_fa' => 'mixed']) === 'mixed' && emojiFaScope([]) === 'faces' && emojiFaScope(['shout_emoji_fa_scope' => 'every']) === 'faces'
      && emojiFaScope(['shout_emoji_fa_scope' => 'search']) === 'search');
$save = (string)file_get_contents($root . '/api/admin/save_settings.php');
check('… in the save allow-list, the mode and the scope coerced like the room\'s other closed sets, the style judged against what loads',
      str_contains($save, "'shout_emoji_fa', 'shout_emoji_fa_style', 'shout_emoji_fa_scope',") && str_contains($save, "!in_array(\$data['shout_emoji_fa'], EMOJI_FA_MODES, true)")
      && str_contains($save, "!in_array(\$data['shout_emoji_fa_scope'], EMOJI_FA_SCOPES, true)") && str_contains($save, "\$data['shout_emoji_fa_scope'] = 'faces';")
      && str_contains($save, "if (\$v === 'brands' || !isset(\$loads[\$v])) \$v = '';"));
$kw = settingsCatalogKeywords();
check('… with search words', !empty($kw['shout_emoji_fa']) && !empty($kw['shout_emoji_fa_style']) && str_contains((string)($kw['shout_emoji_fa_scope'] ?? ''), 'kategorie'));
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
// 1.71.0: the controls left Settings → Shoutbox for a group of their own, Emoji & emotes — its first section
// the picker's, the second the emotes' — and the room keeps none of them.
$secOf = function (string $id) use ($tpl): string {
    $sp = strpos($tpl, 'id="' . $id . '"');
    return $sp !== false ? substr($tpl, $sp, (int)strpos($tpl, 'class="settings-section"', $sp + 20) - $sp) : '';
};
$sec = $secOf('section-emoji');
$shoutSec = $secOf('section-shout');
check('… and in Settings → Emoji & emotes (1.71.0; Shoutbox until then): the three controls, offered only while a Pro package is the icon source, a note otherwise',
      str_contains($tpl, 'id="section-emoji" data-group="emoji"') && str_contains($tpl, 'id="section-emotes" data-group="emoji"')
      && str_contains($sec, 'name="shout_emoji_fa"') && str_contains($sec, 'name="shout_emoji_fa_style"') && str_contains($sec, "!empty(\$shoutFa['available'])")
      && str_contains($sec, "__('settings.shout_emoji_fa_hint'") && str_contains($sec, "_h('settings.shout_emoji_fa_unavailable')")
      && str_contains($sec, 'id="admin-shout-emoji"') && strpos($tpl, 'id="section-emoji"') < strpos($tpl, 'id="admin-emotes"')
      && !str_contains($shoutSec, 'name="shout_emoji_fa') && !str_contains($shoutSec, 'id="admin-shout-emoji"') && !str_contains($shoutSec, 'id="admin-emotes"'));
$adminShout = (string)file_get_contents($root . '/assets/js/admin-shout.js');
check('… the scope beside them, drawn hidden while the faces are off and shown by the mode select (a hidden field still saves), its hint counting the catalogue',
      str_contains($sec, 'name="shout_emoji_fa_scope"') && str_contains($sec, "data-setting=\"shout_emoji_fa_scope\"<?= \$shoutFaWant === 'off' ? ' hidden' : '' ?>")
      && str_contains($sec, 'foreach (EMOJI_FA_SCOPES as $fsc)') && str_contains($sec, "__('settings.shout_emoji_fa_scope_hint', ['n' =>")
      && str_contains($sec, "__('settings.shout_emoji_fa_scope_noindex')")
      && str_contains($adminShout, "cell.hidden = mode.value === 'off';") && str_contains((string)file_get_contents($root . '/assets/css/admin.css'), '#admin-shout-emoji [data-setting][hidden] { display: none !important; }'));
foreach (['en', 'pl'] as $lc) {
    $d = include $root . '/lang/' . $lc . '.php';
    $hint = (string)($d['settings.shout_emoji_fa_hint'] ?? '');
    check("the $lc hint names the token and says the choice is offered only with a Pro package",
          str_contains($hint, '<code>:fa-') && ($lc === 'en' ? str_contains($hint, 'Offered only while a Font Awesome Pro package') : str_contains($hint, 'Dostępne tylko wtedy, gdy źródłem ikon strony jest paczka Font Awesome Pro')), $hint);
    $miss = [];
    foreach (array_merge(['recent', 'emotes', 'stickers'], $wantGroups, array_map(fn($p) => str_replace('-', '_', $p), $F['pages'])) as $tab) {
        if (trim((string)($d['js.shout.tab_' . $tab] ?? '')) === '') $miss[] = $tab;
    }
    foreach (['search', 'search_clear', 'search_none', 'search_found', 'recent_empty', 'emoji_failed', 'variants', 'variants_hint', 'tone_0', 'tone_5',
              'tab_fa_all', 'search_icons', 'fa_cats', 'fa_cat_title', 'fa_cat_status', 'fa_group', 'search_found_fa', 'fa_loading', 'fa_failed'] as $k) {
        if (trim((string)($d['js.shout.' . $k] ?? '')) === '') $miss[] = $k;
    }
    check("… and the picker says every page and control in $lc", $miss === [], implode(', ', $miss));
    // 1.70.0: the three scopes, and the hint that says how the other icons are found (by their English names).
    $sh = (string)($d['settings.shout_emoji_fa_scope_hint'] ?? '');
    check("the $lc words for the scope: three choices, and a hint that says the icons beyond the faces are found by their English names and their category",
          trim((string)($d['settings.shout_emoji_fa_scope_faces'] ?? '')) !== '' && trim((string)($d['settings.shout_emoji_fa_scope_search'] ?? '')) !== ''
          && trim((string)($d['settings.shout_emoji_fa_scope_all'] ?? '')) !== '' && str_contains($sh, ':n') && str_contains($sh, ':c')
          && ($lc === 'en' ? str_contains($sh, 'by its English name') && str_contains($sh, 'category') : str_contains($sh, 'po angielskiej nazwie') && str_contains($sh, 'kategorii')), $sh);
}
// Font Awesome's categories named in both languages (1.70.0): every id of the owner's two indexes — the
// same 68 in 6.7.2 and 7.3.1 — and the catalogue's own two pages; this project's words, not Font Awesome's.
$ownCatIds = ['accessibility', 'alert', 'alphabet', 'animals', 'arrows', 'astronomy', 'automotive', 'buildings', 'business', 'camping', 'charity',
              'charts-diagrams', 'childhood', 'clothing-fashion', 'coding', 'communication', 'connectivity', 'construction', 'design', 'devices-hardware',
              'disaster', 'editing', 'education', 'emoji', 'energy', 'files', 'film-video', 'food-beverage', 'fruits-vegetables', 'gaming', 'gender',
              'halloween', 'hands', 'holidays', 'household', 'humanitarian', 'logistics', 'maps', 'maritime', 'marketing', 'mathematics', 'media-playback',
              'medical-health', 'money', 'moving', 'music-audio', 'nature', 'numbers', 'photos-images', 'political', 'punctuation-symbols', 'religion',
              'science', 'science-fiction', 'security', 'shapes', 'shopping', 'social', 'spinners', 'sports-fitness', 'text-formatting', 'time', 'toggle',
              'transportation', 'travel-hotel', 'users-people', 'weather', 'writing'];
$enD = include $root . '/lang/en.php'; $plD = include $root . '/lang/pl.php';
$noName = []; $samePl = 0;
foreach (array_merge($ownCatIds, ['brands', 'other']) as $cid) {
    $k = 'emoji.facat.' . str_replace('-', '_', $cid);
    if (trim((string)($enD[$k] ?? '')) === '' || trim((string)($plD[$k] ?? '')) === '') $noName[] = $cid;
    elseif ($enD[$k] === $plD[$k]) $samePl++;
}
check('all 68 of Font Awesome\'s categories and the two pages of the catalogue\'s own are named in English and in Polish (' . $samePl . ' the same word in both: Emoji, Halloween, Marketing, Transport)',
      count($ownCatIds) === 68 && $noName === [] && $samePl <= 4 && emojiFaCategoryLabel('animals', 'pl') === 'Zwierzęta' && emojiFaCategoryLabel('food-beverage', 'en') === 'Food & drink'
      && emojiFaCategoryLabel('a-new-one', 'pl') === 'A new one', implode(', ', $noName));
check('… sent with the faces\' answer in the reader\'s language, not carried by every page (no emoji.* in the public bundle)',
      !array_filter(LANG_JS_PUBLIC, fn($p) => str_starts_with($p, 'emoji.')) && str_contains((string)file_get_contents($root . '/includes/emoji.php'), "'cats' => array_map(fn(\$id) => [\$id, emojiFaCategoryLabel(\$id, \$lang), \$icons['cats'][\$id] ?? []], \$cat['ids'])"));

/* ══ 5b. the categories as icons (1.71.0) ══════════════════════════════════════════════════════
 * The owner: the chips of the picker's "every icon" page were written words, and Font Awesome has a
 * fitting icon for nearly every category — the alphabet even as A B C. Each chip is the icon (or a short
 * run of them) this project chose, committed as NAMES in assets/emoji/fa-categories.json; the name is its
 * tooltip and what a screen reader says. Every name is in both of the owner's packages (the browser half
 * installs each and sees every chip draw its own icon: scratchpad/shots/emoji_picker_check.js); one the
 * package in use lacks, or a category without an entry, falls back to a generic glyph. */
$catJson = (string)file_get_contents($root . '/assets/emoji/fa-categories.json');
$CI = json_decode($catJson, true);
$nameRe = '/^[a-z0-9]+(?:-[a-z0-9]+){0,9}$/';
$badCI = [];
foreach ((array)($CI['cats'] ?? []) as $cid => $run) {
    if (!is_array($run) || count($run) < 1 || count($run) > EMOJI_FA_CAT_RUN_MAX) { $badCI[] = "$cid: a run of " . (is_array($run) ? count($run) : '?'); continue; }
    foreach ($run as $nm) if (!is_string($nm) || !preg_match($nameRe, $nm)) $badCI[] = "$cid: " . json_encode($nm);
}
$wantCI = array_merge($ownCatIds, ['brands', 'other']);
check('fa-categories.json: format 1, an entry for every one of the 68 categories and the catalogue\'s two pages — no more — each one icon or a run of up to ' . EMOJI_FA_CAT_RUN_MAX . ', every name in the token\'s grammar, a generic fallback',
      is_array($CI) && ($CI['format'] ?? 0) === 1 && is_string($CI['fallback'] ?? null) && preg_match($nameRe, $CI['fallback'])
      && array_keys((array)$CI['cats']) === $wantCI && $badCI === [] && EMOJI_FA_CAT_RUN_MAX === 3,
      json_encode(['missing' => array_values(array_diff($wantCI, array_keys((array)($CI['cats'] ?? [])))), 'extra' => array_values(array_diff(array_keys((array)($CI['cats'] ?? [])), $wantCI)), 'bad' => $badCI]));
check('… the runs where one glyph says less: the alphabet A B C, the numbers 1 2 3, text formatting B I U, punctuation ? ! &, fruit and a vegetable',
      $CI['cats']['alphabet'] === ['a', 'b', 'c'] && $CI['cats']['numbers'] === ['1', '2', '3'] && $CI['cats']['text-formatting'] === ['bold', 'italic', 'underline']
      && $CI['cats']['punctuation-symbols'] === ['question', 'exclamation', 'ampersand'] && $CI['cats']['fruits-vegetables'] === ['apple-whole', 'carrot']
      && count(array_filter($CI['cats'], fn($r) => count($r) > 1)) === 5);
check('… and the file holds names only: no glyph, no code point, nothing of Font Awesome\'s files',
      !preg_match('/\\\\u[ef][0-9a-f]{3}|[\x{E000}-\x{F8FF}]|@font-face|url\(|woff/iu', $catJson));
$CIr = emojiFaCategoryIcons();
check('emojiFaCategoryIcons() reads it as it is (70 categories, the fallback) — and keeps what it reads to names and short runs',
      count($CIr['cats']) === 70 && $CIr['cats'] === $CI['cats'] && $CIr['fallback'] === $CI['fallback']
      && str_contains($emSrc = (string)file_get_contents($root . '/includes/emoji.php'), "if (\$run && count(\$run) <= EMOJI_FA_CAT_RUN_MAX) \$d['cats'][\$id] = array_map('strval', \$run);")
      && str_contains($emSrc, "\$name = fn(\$n) => is_string(\$n) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+){0,9}\$/', \$n);"));
check('… sent with each category of the faces\' answer, with the fallback; a new file is a new address for that answer (emojiFaVersion())',
      str_contains($emSrc, "'fallback' => \$icons['fallback']]") && str_contains($emSrc, "(string)@filemtime(dirname(__DIR__) . '/assets/emoji/fa-categories.json')"));
check('the picker takes them (prepFa()) and draws each chip from the catalogue it holds: the named icons when the package has every one, else the fallback, else the category\'s own first icon, else its name — each icon in its own default style',
      str_contains($pjs, 'var CAT_RUN_MAX = 3;') && str_contains($pjs, 'out.catalog.icons[c[0]] = Array.isArray(c[2])')
      && str_contains($pjs, 'var names = want.length && want.every(function (n) { return !!cat.byName[n]; }) ? want')
      && str_contains($pjs, ': fb && cat.byName[fb] ? [fb]') && str_contains($pjs, ': cat.byCat[c.i][0] ? [cat.byCat[c.i][0].n] : [];')
      && str_contains($pjs, 'return faGlyph(fa, n, cat.byName[n].v[0], true);'));
check('… a chip is its icon, named for a screen reader (aria-label: the category) and explained in the site\'s tooltip (data-tip: the name and the count) — no browser title, no words beside the icon',
      str_contains($pjs, "'aria-pressed': on ? 'true' : 'false', 'aria-label': c.l, tabindex: on ? '0' : '-1',")
      && str_contains($pjs, "dataset: { c: c.id, tip: t('js.shout.fa_cat_title', { name: c.l, n: c.n }) } }, g.length ? g : c.l);")
      && !str_contains($pjs, "title: t('js.shout.fa_cat_title'"));
check('… the chosen chip filled with the accent, the others an edge; a run a longer pill; on a phone about a cell\'s height',
      str_contains($css0 = (string)file_get_contents($root . '/assets/css/style.css'), '.shout-picker-cat.active { color: var(--bg-card); background: var(--accent); border-color: var(--accent); }')
      && str_contains($css0, '.shout-picker .shout-picker-cat .fai { display: block; font-size: 0.95rem;')
      && str_contains($css0, '.shout-picker-cat { min-width: 2.6rem; height: 2.2rem; }'));

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
check('1.70.0: the catalogue of every icon is asked for only when the scope needs it — with `all` once the first page is drawn (in idle time), with `search` by the first search, at once only when Recent holds an icon — at the package\'s address',
      str_contains($sjs, "API + 'shout_emoji&part=catalog&v=' + encodeURIComponent(v)") && str_contains($sjs, "if (fa && !cat && (recentWaits || fa.scope === 'all')) {")
      && str_contains($sjs, 'window.requestIdleCallback(function () { if (!panel.hidden) catalogLoad(); }') && str_contains($sjs, 'if (cat) faList = faSearch(q, words, raw);')
      && str_contains($sjs, "if (!fa || !fa.catalog || fa.scope === 'faces') return Promise.resolve(null);"));
check('… its categories on chips over the grid, the search\'s group after the emoji, and every icon\'s variants by the server\'s own rule (faVariants() / emojiFaVariants())',
      str_contains($sjs, "className: 'shout-picker-cats'") && str_contains($sjs, 'function faSearch(q, words, raw) {') && str_contains($sjs, 'function faVariants(fa, has) {')
      && str_contains($sjs, "if (it.kind === 'h') return el('div', { className: 'shout-picker-group'")
      && str_contains($styleCss = (string)file_get_contents($root . '/assets/css/style.css'), '.shout-picker-cat.active {') && str_contains($styleCss, '.fai { font-size: 1.15em;')
      && str_contains((string)file_get_contents($root . '/includes/emoji.php'), 'function emojiFaVariants(array $has, array $ctx): array {'));
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
        // 1.70.0: the scope, and the catalogue of every icon behind `part=catalog`.
        $cfgAll = ['shout_emoji_fa_scope' => 'all'] + $cfgPro;
        $jAll = $get($cfgAll, $sess, ['lang' => 'pl']);
        $catFile = (string)@file_get_contents(iconpackCatalogPath($m['id']));
        $catJ = json_decode($catFile, true) ?: [];
        // 1.71.0: each category with its chip's icons (the committed names), and the fallback.
        $allCats = (array)($jAll['catalog']['cats'] ?? []);
        check('with the scope at `all` the answer says so, where the catalogue is and how many icons it holds, its categories in Polish — each with its chip\'s icons (1.71.0)',
              ($jAll['scope'] ?? '') === 'all' && ($jAll['catalog']['v'] ?? '') === iconpackCatalogVersion($m) && ($jAll['catalog']['n'] ?? 0) === ($catJ['count'] ?? -1)
              && in_array(['animals', 'Zwierzęta', ['paw']], $allCats, true) && in_array(['alphabet', 'Alfabet', ['a', 'b', 'c']], $allCats, true)
              && count($allCats) === count((array)($catJ['cats'] ?? [])) && !array_filter($allCats, fn($c) => ($c[2] ?? null) !== ($CI['cats'][$c[0]] ?? []))
              && ($jAll['catalog']['fallback'] ?? '') === $CI['fallback'] && isset($jAll['styles']['brands']),
              json_encode(['scope' => $jAll['scope'] ?? null, 'catalog' => array_diff_key((array)($jAll['catalog'] ?? []), ['cats' => 1]), 'first' => array_slice($allCats, 0, 3)]));
        $jc = $get($cfgAll, $sess, ['part' => 'catalog', 'v' => iconpackCatalogVersion($m)]);
        check('?part=catalog: the package\'s catalogue as stored (' . ($catJ['count'] ?? 0) . ' icons in ' . count((array)($catJ['cats'] ?? [])) . ' categories)',
              ($jc['count'] ?? null) === ($catJ['count'] ?? -1) && ($jc['icons'] ?? null) === ($catJ['icons'] ?? []) && ($jc['cats'] ?? null) === ($catJ['cats'] ?? []),
              substr(json_encode($jc), 0, 200));
        $jFaces = $get(['shout_emoji_fa_scope' => 'faces'] + $cfgPro, $sess, ['part' => 'catalog']);
        $jOff = $get(['shout_emoji_fa' => 'off'] + $cfgAll, $sess, ['part' => 'catalog']);
        $jSearch = $get(['shout_emoji_fa_scope' => 'search'] + $cfgPro, $sess, ['part' => 'catalog']);
        check('… with `search` too, and never with the scope at `faces` or the faces off (404)', ($jFaces['error'] ?? '') === 'off' && ($jOff['error'] ?? '') === 'off'
              && ($jSearch['count'] ?? null) === ($catJ['count'] ?? -1), json_encode([$jFaces, $jOff, array_keys($jSearch)]));
        check('… and for nobody who may not read the room', ($get(['shout_enabled' => '0'] + $cfgAll, $sess, ['part' => 'catalog'])['error'] ?? '') === 'disabled');
    } else {
        echo "SKIP the endpoint with a Pro package: none with an index is installed here (tests/iconpack_test.php §12 covers it synthetically)\n";
    }
} else {
    echo "SKIP the endpoint as a member: no smokeuser here (run deploy/local_bootstrap.php and the smokes)\n";
}
@unlink($runner);

/* ══ 8. the picker in every editor, and its data by context (1.70.0) ══════════════════════════════════
 * One module (assets/js/emoji-picker.js) for the room and every editor; its data asked for as the context
 * it serves (`for`), each gated by the permission that writes THAT text; the emotes beyond the room behind
 * `emotes_everywhere`. The rendering itself: tests/richtext_test.php (pure), tests/shout_emotes_test.php
 * (the store), tests/profile_bio_test.php (the profile). The browser: scratchpad/shots/picker_everywhere_check.js. */
$db = getDb();
$cfg = getSettings($db, true);
$defs = trackerSchemaDefaultSettings();
check('1.70.0 the schema is at 79 or later, and `emotes_everywhere` ships on', TRACKER_SCHEMA_VERSION >= 79 && ($defs['emotes_everywhere'] ?? null) === '1');
check('… in the save allow-list and among the switches coerced to 0/1',
      str_contains($save, "'shout_stickers_enabled', 'shout_emote_approval', 'emotes_everywhere',") && str_contains($save, "'shout_emotes_enabled', 'shout_stickers_enabled', 'shout_emote_approval', 'emotes_everywhere',"));
check('… with search words, in both languages', str_contains((string)(settingsCatalogKeywords()['emotes_everywhere'] ?? ''), 'messages') && str_contains((string)(settingsCatalogKeywords()['emotes_everywhere'] ?? ''), 'wiadomosci'));
$emSec = substr($tpl, (int)strpos($tpl, 'id="admin-emotes"'), 6000);
check('… and in Settings → Emoji & emotes → Emotes and stickers (Shoutbox\'s until 1.71.0), beside the switch it depends on (four switches to a row, the numbers on the next)',
      str_contains($emSec, 'data-setting="emotes_everywhere"') && str_contains($emSec, 'name="emotes_everywhere"')
      && strpos($emSec, 'name="shout_emotes_enabled"') < strpos($emSec, 'name="emotes_everywhere"') && strpos($emSec, 'name="emotes_everywhere"') < strpos($emSec, 'name="shout_stickers_enabled"')
      && substr_count(substr($emSec, 0, (int)strpos($emSec, 'data-setting="shout_emote_max_kb"')), 'class="col-md-3"') === 4);
foreach (['en', 'pl'] as $lc) {
    $d = include $root . '/lang/' . $lc . '.php';
    $hint = (string)($d['settings.emotes_everywhere_hint'] ?? '');
    check("the $lc words: the switch, a hint naming the places and the e-mail, the toolbar titles, the picker's sticker line",
          trim((string)($d['settings.emotes_everywhere'] ?? '')) !== '' && str_contains($hint, '<code>:code:</code>')
          && ($lc === 'en' ? str_contains($hint, 'private messages') && str_contains($hint, 'e-mail') : str_contains($hint, 'prywatnych wiadomościach') && str_contains($hint, 'e-mailu'))
          && trim((string)($d['rt.emoji'] ?? '')) !== '' && trim((string)($d['rt.emoji_emotes'] ?? '')) !== '' && trim((string)($d['rt.emoji_stickers'] ?? '')) !== ''
          && trim((string)($d['js.bio.emoji'] ?? '')) !== '' && trim((string)($d['js.bio.emoji_emotes'] ?? '')) !== '' && trim((string)($d['js.shout.sticker_insert_hint'] ?? '')) !== '', $hint);
}
// The module, and every editor wired to it.
$boxOnly = (string)file_get_contents($root . '/assets/js/shoutbox.js');
check('one picker: assets/js/emoji-picker.js is window.EmojiPicker (mount, attach, isToken, forgetEmotes), and the room mounts it with no copy of its own',
      str_contains($pjs, 'window.EmojiPicker = {') && str_contains($pjs, 'mount: mountPicker,') && str_contains($pjs, 'attach: attach,') && str_contains($pjs, 'isToken: isToken,')
      && str_contains($boxOnly, 'picker = Picker.mount({') && !str_contains($boxOnly, 'function mountPicker') && !str_contains($boxOnly, 'var FA_TOKEN')
      && str_contains($boxOnly, 'Picker.isToken(text)') && substr_count($boxOnly, 'Picker.forgetEmotes()') === 2);
check('… the room\'s requests are the ones it always made (no `for`), every other context says which it is',
      str_contains($pjs, "var forQ = ctx === 'shout' ? '' : '&for=' + ctx;") && str_contains($pjs, "get('shout_emotes' + (ctx === 'shout' ? '' : '&for=' + encodeURIComponent(ctx)))")
      && str_contains($pjs, "+ '&lang=' + encodeURIComponent(want) + forQ") && str_contains($pjs, "'shout_emoji&part=catalog&v=' + encodeURIComponent(v) + forQ"));
check('… an editor\'s panel floats (fixed, in the button\'s dialog or on the page) and follows what scrolls; one Esc is the picker\'s alone',
      str_contains($pjs, "var host = opts.host || (btn.closest && btn.closest('[role=\"dialog\"]')) || document.body;")
      && str_contains($pjs, "if (floating) window.addEventListener('scroll', onScroll, true);") && str_contains($pjs, 'e.stopPropagation();')
      && str_contains((string)file_get_contents($root . '/assets/css/style.css'), '.shout-picker.shout-picker-float { position: fixed; z-index: 1300; }'));
check('… a sticker in an editor goes in as its code (the room sends it), and a pick past the box\'s maxlength is refused, not cut',
      str_contains($pjs, "if (it.kind === 's' && typeof opts.sticker === 'function') { close(); opts.sticker(it.code); return; }")
      && str_contains($pjs, "if (max && before.length + add.length + after.length > max) {") && str_contains($pjs, "ta.dispatchEvent(new Event('input', { bubbles: true }));"));
$layoutSrc = (string)file_get_contents($root . '/templates/layout.php');
$appSrc = (string)file_get_contents($root . '/assets/js/app.js');
check('the page loads it on every public page, before app.js (which mounts the whitelist form\'s editor as it loads)',
      ($lp = strpos($layoutSrc, "assets/js/emoji-picker.js<?= assetVer('assets/js/emoji-picker.js') ?>")) !== false
      && $lp < strpos($layoutSrc, "assets/js/app.js<?= assetVer('assets/js/app.js') ?>") && $lp < strpos($layoutSrc, 'assets/js/shoutbox.js'));
check('window.RichText.mount() attaches it to the toolbar\'s `<id>-emoji`, puts it away on Preview, and keeps its group whatever the format',
      str_contains($appSrc, "const emojiBtn = document.getElementById(id + '-emoji');") && str_contains($appSrc, "window.EmojiPicker.attach({ textarea: ta, button: emojiBtn })")
      && str_contains($appSrc, "if (which !== 'write' && picker) picker.close();") && str_contains($appSrc, 'if (marks.length) g.hidden = !marks.some(b => !b.hidden);'));
$tplW = (string)file_get_contents($root . '/templates/pages/whitelist.php');
$tplI = (string)file_get_contents($root . '/templates/partials/info_overlay.php');
$tplA = (string)file_get_contents($root . '/templates/pages/account.php');
check('every editor\'s toolbar has the button, as its own context: the whitelist form and the Info panel (a description, first or proposed), the message composer',
      str_contains($tplW, "emojiPickerButton(\$db, \$cfg, \$baseUrl, 'description', 'wl-desc-emoji')") && str_contains($tplI, "emojiPickerButton(\$db, \$cfg, \$baseUrl, 'description', 'info-desc-emoji')")
      && str_contains($tplA, "emojiPickerButton(\$db, \$cfg, \$baseUrl, 'message', 'pm-body-emoji')")
      && strpos($tplW, 'wl-desc-emoji') > strpos($tplW, 'id="wl-desc-tools"') && strpos($tplW, 'wl-desc-emoji') < strpos($tplW, '<textarea id="wl-desc"'));
// 1.71.0: and `comment`, a comment under a torrent (includes/comments.php) — tests/comments_test.php has the rest.
check('`for`: absent or empty is the room, a context is itself, anything else nothing (refused, never read as another)',
      emojiPickerFor(null) === 'shout' && emojiPickerFor('') === 'shout' && emojiPickerFor('message') === 'message' && emojiPickerFor('list') === 'list'
      && emojiPickerFor('comment') === 'comment'
      && emojiPickerFor('MESSAGE') === null && emojiPickerFor('panel') === null && emojiPickerFor(['message']) === null && EMOJI_PICKER_CONTEXTS === ['shout', 'message', 'description', 'bio', 'list', 'comment']);
$attrs = emojiPickerAttrs(['for' => 'message', 'files' => ['en' => '/a"b.json'], 'fa' => 'mixed"><x', 'fa_v' => 'v1', 'emotes' => true, 'stickers' => false, 'emotes_page' => true]);
check('the button\'s data, escaped: the context, the files, Font Awesome\'s mode and fingerprint, what is offered',
      $attrs === ' data-emoji-for="message" data-emoji-files="{&quot;en&quot;:&quot;/a\&quot;b.json&quot;}" data-emoji-fa="mixed&quot;&gt;&lt;x" data-emoji-fa-v="v1" data-emotes="1" data-stickers="0" data-emotes-page="1"', $attrs);
$btnHtml = emojiPickerButton($db, $cfg, '/', 'message', 'pm-body-emoji');
check('the toolbar button: a real button of its own group, the site\'s face icon, a title that says what it offers, the data above',
      str_starts_with($btnHtml, '<span class="rt-tool-group rt-tool-emoji"><button type="button" class="rt-emoji-btn" id="pm-body-emoji" title="')
      && str_contains($btnHtml, 'aria-haspopup="dialog" aria-expanded="false" data-emoji-for="message"') && str_contains($btnHtml, '<i class="bi bi-emoji-smile" aria-hidden="true"></i></button></span>'), $btnHtml);

// The endpoints, as requests, by context.
$runner2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'emt_runner2_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($runner2, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_GET = $a["get"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php", "includes/richtext.php",
          "includes/users.php", "includes/favourites.php", "includes/lists.php", "includes/people.php", "includes/profilebio.php", "includes/shout.php",
          "includes/audit.php", "includes/auth.php", "includes/lang.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, null);
$GLOBALS["__audit_endpoint"] = $a["endpoint"];
require "api/" . $a["endpoint"] . ".php";
');
$ask = function (string $endpoint, array $cfgX, array $session, array $q = []) use ($root, $runner2): array {
    $arg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'emt_args2_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($arg, json_encode(['root' => $root, 'endpoint' => $endpoint, 'get' => $q, 'cfg' => $cfgX, 'session' => $session, 'sid' => 'emtest2' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($runner2) . ' ' . escapeshellarg($arg) . ' 2>&1');
    @unlink($arg);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 300)];
};
if ($uid > 0) {
    $sessU = ['user_id' => $uid, 'user_login_time' => time(), 'csrf_token' => 'emt'];
    $open = ['users_enabled' => '1', 'shout_enabled' => '0', 'shout_emotes_enabled' => '1', 'shout_stickers_enabled' => '1', 'emotes_everywhere' => '1',
             'pm_enabled' => '1', 'wl_allow_description' => '1', 'profiles_enabled' => '1', 'profile_bio_enabled' => '1', 'lists_enabled' => '1',
             'icon_library' => 'bootstrap', 'shout_emoji_fa' => 'off'];
    $roomOn2 = ['shout_enabled' => '1'] + $open;
    // A sticker of the site's for the run, and back out again.
    $db->exec("DELETE FROM shout_emotes WHERE code = 'emtctx_big'");
    $stk = shoutEmoteStore($db, $roomOn2, 'emtctx_big', 'Emt ctx big', '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" fill="#468"/><title>emtctx</title></svg>', null, true);
    shoutEmotesInvalidate();
    try {
        check('the endpoints refuse a `for` that is no context (400), both of them',
              ($ask('shout_emoji', $roomOn2, $sessU, ['for' => 'panel'])['error'] ?? '') === 'bad_for' && ($ask('shout_emotes', $roomOn2, $sessU, ['for' => 'x'])['error'] ?? '') === 'bad_for');
        $jm = $ask('shout_emoji', $open, $sessU, ['for' => 'message', 'lang' => 'en']);
        check('a MESSAGE\'s picker is answered with the room switched off — its gate is pm.send, not the room\'s view',
              ($jm['success'] ?? false) === true && ($jm['mode'] ?? '') === 'off' && ($ask('shout_emoji', $open, $sessU, [])['error'] ?? '') === 'disabled', json_encode($jm));
        check('… refused where messages are off, and to somebody signed out',
              ($ask('shout_emoji', ['pm_enabled' => '0'] + $open, $sessU, ['for' => 'message'])['error'] ?? '') === 'disabled'
              && ($ask('shout_emoji', $open, [], ['for' => 'message'])['error'] ?? '') === 'login_required');
        // The emotes are the ROOM's (their store, their manager, their page): beyond it only while its emotes
        // are on — the room switched off takes them everywhere, the faces and the emoji stay.
        check('… but the emotes are the room\'s: with the room off a message\'s picker has none (403), its faces and emoji still do',
              ($ask('shout_emotes', $open, $sessU, ['for' => 'message'])['error'] ?? '') === 'disabled');
        $em = $ask('shout_emotes', $roomOn2, $sessU, ['for' => 'message']);
        $emCodes = array_column((array)($em['emotes'] ?? []), 'code');
        $emBig = array_values(array_filter((array)($em['emotes'] ?? []), fn($r) => ($r['code'] ?? '') === 'emtctx_big'))[0] ?? [];
        check('a message\'s emotes: the store\'s, the sticker among them marked as one — and no upload limits, which are the room page\'s',
              ($em['success'] ?? false) === true && in_array('emtctx_big', $emCodes, true) && ($emBig['sticker'] ?? false) === true && ($em['stickers'] ?? false) === true
              && !array_key_exists('may_upload', $em) && !isset($emBig['draw']) && str_contains((string)($emBig['url'] ?? ''), 'endpoint=shout_emote&id='), json_encode(array_slice($em, 0, 3)));
        $eb = $ask('shout_emotes', $roomOn2, $sessU, ['for' => 'bio']);
        check('a profile\'s: no stickers at all', ($eb['success'] ?? false) === true && !in_array('emtctx_big', array_column((array)($eb['emotes'] ?? []), 'code'), true)
              && ($eb['stickers'] ?? true) === false && in_array('flame', array_column((array)($eb['emotes'] ?? []), 'code'), true), json_encode(array_column((array)($eb['emotes'] ?? []), 'code')));
        check('… nothing where `emotes_everywhere` is off (403) — the room\'s own answer unchanged by it',
              ($ask('shout_emotes', ['emotes_everywhere' => '0'] + $roomOn2, $sessU, ['for' => 'message'])['error'] ?? '') === 'disabled'
              && ($ask('shout_emotes', ['emotes_everywhere' => '0'] + $roomOn2, $sessU, [])['success'] ?? false) === true
              && array_key_exists('may_upload', $ask('shout_emotes', $roomOn2, $sessU, [])));
        $desc = $ask('shout_emotes', $roomOn2, $sessU, ['for' => 'description']);
        check('a DESCRIPTION\'s (content.submit or content.propose), a LIST\'s (lists.use), a PROFILE\'s (profile.bio) — each its own gate, each switched off refused',
              ($desc['success'] ?? false) === true && ($ask('shout_emoji', $open, $sessU, ['for' => 'list'])['success'] ?? false) === true
              && ($ask('shout_emoji', $open, $sessU, ['for' => 'bio'])['success'] ?? false) === true
              && ($ask('shout_emoji', ['wl_allow_description' => '0'] + $open, $sessU, ['for' => 'description'])['error'] ?? '') === 'disabled'
              && ($ask('shout_emoji', ['lists_enabled' => '0'] + $open, $sessU, ['for' => 'list'])['error'] ?? '') === 'disabled'
              && ($ask('shout_emoji', ['profile_bio_enabled' => '0'] + $open, $sessU, ['for' => 'bio'])['error'] ?? '') === 'disabled', json_encode(array_slice($desc, 0, 2)));
        $guestMay = !empty(userEffectivePermissions($db, null, array_merge($cfg, $open))['content.submit']) || !empty(userEffectivePermissions($db, null, array_merge($cfg, $open))['content.propose']);
        $jg = $ask('shout_emoji', $open, [], ['for' => 'description']);
        check('… a guest by the guest group\'s own grant (the whitelist form is public): ' . ($guestMay ? 'this one holds it' : 'this one holds neither, so it is asked to sign in'),
              $guestMay ? (($jg['success'] ?? false) === true) : (($jg['error'] ?? '') === 'login_required'), json_encode($jg));
    } finally {
        $db->exec("DELETE FROM shout_emotes WHERE code = 'emtctx_big'");
        shoutEmotesInvalidate();
    }
} else {
    echo "SKIP the endpoints by context: no smokeuser here (run deploy/local_bootstrap.php and the smokes)\n";
}
@unlink($runner2);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
