<?php
/**
 * Build the shoutbox picker's emoji data (1.69.0) — assets/emoji/emoji-en.json and emoji-pl.json.
 *
 *   npm pack emojibase-data@17.0.0            (in a temporary directory, never in this repository)
 *   tar -xzf emojibase-data-17.0.0.tgz
 *   php tools/emoji_data.php <that>/package [--cap=16.0]
 *
 * WHERE THE WORDS COME FROM. emojibase-data (MIT, Copyright (c) 2017-2019 Miles Johnson) packs Unicode's
 * emoji list — every character and sequence, its group, its version and its skin-tone variants — with
 * the CLDR annotations for each locale: the short name ("grinning face", "szeroko uśmiechnięta buźka")
 * and the keywords people search by (Unicode License v3, Copyright (c) Unicode, Inc.). Nothing of the
 * package is committed but what this script writes, and each file it writes says where it came from in
 * its first key; assets/emoji/LICENSE.txt carries both licences in full, as they ask.
 *
 * WHAT IS LEFT OUT, AND WHY:
 *   * anything newer than --cap (default 16.0, the newest set Windows 11, Android and iOS all draw in
 *     2026 — 17.0 is on Windows 11 only in a Release Preview), and every skin-tone variant newer than it:
 *     a character the reader's font does not have is an empty box in the grid;
 *   * the components (the five tone swatches, the hair styles on their own): parts, not emoji;
 *   * the regional indicator letters (no group): a flag is two of them, and the flags are listed whole;
 *   * skin tones that are not ONE tone for the whole emoji (two people, two tones): the picker offers
 *     five tones per emoji, the way Android does; an emoji without all five uniform variants at or below
 *     the cap offers none.
 *
 * THE SHAPE, one file per language, one emoji per line so a regeneration reads as a diff:
 *   {"_": attribution, "format": 1, "lang", "cap", "source", "groups": [9 ids], "count",
 *    "e": [[group, emoji, name, "keyword|keyword", tones]]}
 * `tones` is 0, or the five variants, light to dark. Both files list the same emoji in the same order,
 * so row N of one is row N of the other: the picker searches the reader's own language and, for English
 * as the fallback, the English file's row N — fetched only once somebody searches, not with the grid.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$args = array_slice($argv, 1);
$src = null; $cap = '16.0'; $out = dirname(__DIR__) . '/assets/emoji';
foreach ($args as $a) {
    if (preg_match('/^--cap=(\d{1,2}(?:\.\d)?)$/', $a, $m)) $cap = $m[1];
    elseif (preg_match('/^--out=(.+)$/', $a, $m)) $out = $m[1];
    elseif ($src === null) $src = rtrim($a, '/\\');
}
if ($src === null || !is_file($src . '/en/data.json') || !is_file($src . '/pl/data.json')) {
    fwrite(STDERR, "usage: php tools/emoji_data.php <emojibase-data package directory> [--cap=16.0] [--out=assets/emoji]\n");
    exit(2);
}
$pkg = json_decode((string)file_get_contents($src . '/package.json'), true);
if (!is_array($pkg) || ($pkg['name'] ?? '') !== 'emojibase-data') { fwrite(STDERR, "not an emojibase-data package: $src\n"); exit(2); }
$pkgVer = (string)($pkg['version'] ?? '?');
// The CLDR release the package was built from, as its own changelog says it ("Update to Emoji v17 and
// CLDR 48."): the newest mention is the first.
$cldr = preg_match('/CLDR (\d+(?:\.\d+)?)/', (string)@file_get_contents($src . '/CHANGELOG.md'), $cm) ? $cm[1] : '?';
$capF = (float)$cap;

$load = function (string $path): array {
    $j = json_decode((string)file_get_contents($path), true);
    if (!is_array($j)) { fwrite(STDERR, "unreadable: $path\n"); exit(2); }
    return $j;
};
$en = $load($src . '/en/data.json');
$pl = $load($src . '/pl/data.json');
$gh = is_file($src . '/en/shortcodes/github.json') ? $load($src . '/en/shortcodes/github.json') : [];
$plBy = [];
foreach ($pl as $e) if (isset($e['hexcode'])) $plBy[$e['hexcode']] = $e;

// emojibase's group numbers → the picker's pages, in Unicode's order; 2 is the components.
$groupIds = [0 => 'smileys', 1 => 'people', 3 => 'animals', 4 => 'food', 5 => 'travel', 6 => 'activities', 7 => 'objects', 8 => 'symbols', 9 => 'flags'];
$groupIdx = array_flip(array_values($groupIds));

/** Keywords as one string: trimmed, lower-cased, without a repeat, without the separator itself. */
$kw = function (array $words): string {
    $seen = [];
    foreach ($words as $w) {
        $w = trim(mb_strtolower(str_replace('|', ' ', (string)$w), 'UTF-8'));
        if ($w !== '' && !isset($seen[$w])) $seen[$w] = true;
    }
    return implode('|', array_keys($seen));
};

$rows = ['en' => [], 'pl' => []];
$perGroup = array_fill_keys(array_values($groupIds), 0);
$toned = 0; $tonesDropped = [];
usort($en, fn($a, $b) => ((int)($a['order'] ?? PHP_INT_MAX)) <=> ((int)($b['order'] ?? PHP_INT_MAX)));
foreach ($en as $e) {
    if (!isset($e['group'], $groupIds[$e['group']])) continue;              // components, regional letters
    if ((float)($e['version'] ?? 99) > $capF + 1e-9) continue;              // newer than the fonts
    $g = $groupIds[$e['group']];
    $emoji = (string)$e['emoji'];
    // emojibase writes the emoji-presentation selector after every character that ALSO has a text
    // form (👍 as U+1F44D U+FE0F); for one that is an emoji by default (type 1) the character alone is
    // Unicode's fully-qualified form, and the one a shout should carry. A text-default one (☺, type 0)
    // keeps its selector, or it is drawn as a black glyph.
    if ((int)($e['type'] ?? 0) === 1 && preg_match('/^(\X)$/u', $emoji) && preg_match('/^([^\x{FE0F}])\x{FE0F}$/u', $emoji, $one)) $emoji = $one[1];
    // Five uniform tones at or below the cap, light (1) to dark (5) — or none at all.
    $tones = 0;
    if (!empty($e['skins'])) {
        $byTone = [];
        foreach ($e['skins'] as $s) {
            if ((float)($s['version'] ?? 99) > $capF + 1e-9) continue;
            $t = $s['tone'] ?? null;
            if (is_array($t)) $t = count(array_unique($t)) === 1 ? (int)$t[0] : null;
            if (is_int($t) && $t >= 1 && $t <= 5) $byTone[$t] = (string)$s['emoji'];
        }
        ksort($byTone);
        if (count($byTone) === 5) { $tones = array_values($byTone); $toned++; }
        else $tonesDropped[] = $emoji;
    }
    $enName = (string)$e['label'];
    $enKw = array_merge((array)($e['tags'] ?? []));
    // GitHub's shortcodes are the words people coming from chat already type (":tada:", ":joy:").
    foreach ((array)($gh[$e['hexcode']] ?? []) as $sc) $enKw[] = str_replace('_', ' ', (string)$sc);
    $p = $plBy[$e['hexcode']] ?? [];
    $plName = trim((string)($p['label'] ?? '')) !== '' ? (string)$p['label'] : $enName;
    $plKw = (array)($p['tags'] ?? []);
    $gi = $groupIdx[$g];
    $rows['en'][] = [$gi, $emoji, $enName, $kw($enKw), $tones];
    $rows['pl'][] = [$gi, $emoji, $plName, $kw($plKw), $tones];
    $perGroup[$g]++;
}

if (!is_dir($out) && !@mkdir($out, 0775, true)) { fwrite(STDERR, "cannot create $out\n"); exit(1); }
$attribution = 'Emoji names, keywords and groups for the shoutbox picker (assets/js/shoutbox.js): Emoji ' . $cap
    . ' and older. Generated by tools/emoji_data.php from emojibase-data ' . $pkgVer
    . ' (MIT License, Copyright (c) 2017-2019 Miles Johnson), whose names and keywords are the Unicode CLDR ' . $cldr
    . ' annotations (Unicode License v3, Copyright (c) 1991-2026 Unicode, Inc.). Both licences are in full in'
    . ' assets/emoji/LICENSE.txt. Do not edit: regenerate.';
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
foreach (['en', 'pl'] as $lang) {
    $head = ['_' => $attribution, 'format' => 1, 'lang' => $lang, 'cap' => $cap,
             'source' => 'emojibase-data ' . $pkgVer . ', CLDR ' . $cldr,
             'groups' => array_values($groupIds), 'count' => count($rows[$lang])];
    $body = substr(json_encode($head, $flags), 0, -1) . ",\n\"e\":[\n";
    $lines = [];
    foreach ($rows[$lang] as $r) $lines[] = json_encode($r, $flags);
    $body .= implode(",\n", $lines) . "\n]}\n";
    if (json_decode($body, true) === null) { fwrite(STDERR, "generated JSON does not parse ($lang)\n"); exit(1); }
    file_put_contents($out . '/emoji-' . $lang . '.json', $body);
    printf("%s: %d emoji, %d bytes -> %s\n", $lang, count($rows[$lang]), strlen($body), $out . '/emoji-' . $lang . '.json');
}
printf("cap Emoji %s, emojibase-data %s, CLDR %s; per group: %s; %d with five tones%s\n", $cap, $pkgVer, $cldr,
       implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($perGroup), $perGroup)), $toned,
       $tonesDropped ? '; no uniform five tones (left without): ' . implode(' ', $tonesDropped) : '');
