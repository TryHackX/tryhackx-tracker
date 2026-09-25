<?php
/**
 * Font Awesome packages (1.69.0):
 *   php tests/iconpack_test.php
 *
 * Everything here is SYNTHETIC unless TRACKER_FA_PRO_DIR points at the owner's licensed Pro folders
 * (it defaults to C:\Users\Dominik\Desktop\tracker\Fonts when that exists): Font-Awesome-shaped CSS
 * and tiny files with a real font signature, generated at run time into the system's temp directory.
 * Nothing is copied from a real package, and nothing a test installs outlives the run — every package
 * this run adds to config/iconpacks/ is deleted again in a shutdown function, the Font Awesome
 * settings go back to what they were, and the audit lines the run wrote are removed. A package that
 * was installed before the run (the owner's own) is never touched.
 *
 *   1. names and paths: what may be written, what is kept;
 *   2. every nesting shape: the folder's contents zipped, the folder zipped, the folder inside more
 *      folders (with a Mac's __MACOSX beside it), the directory itself — one package, one id; two in
 *      one archive refused; no package at all refused;
 *   3. hostile archives: traversal, an absolute path, a drive letter, a backslash (and the advice for
 *      PowerShell's zips), a NUL, a control character, a symlink, a name twice, two names one case
 *      apart, a zip bomb by ratio, too many files, an oversized file, a size that lies;
 *   4. hostile stylesheets and fonts: @import, an external or protocol-relative url(), a url() to a
 *      font that is not there, a sheet that is not Font Awesome's, an escape outside a string,
 *      expression( / javascript:, mixed versions, Font Awesome 5, a style file that changes other
 *      styles (a global rule, a borrowed face, another code point), no core; a font that is not one;
 *   5. what is detected and written: styles in and outside all.css, their weights, families, classes,
 *      fonts and layers, the core, the icon names, what the metadata describes, the manifest, verify;
 *   6. the serving endpoint: headers, the rewritten CSS, refusal of everything unlisted, validators,
 *      and one round trip through the running local site;
 *   7. the setup and the map: loading, the chosen style per role, Pro twins and their fallbacks;
 *   8. the settings, checked against what is installed;
 *   9. the store: list, broken, the active package refused, delete;
 *  10. the CLI and the panel endpoint, each as a real run (the database's settings put back after);
 *  11. the owner's Pro packages, zipped into the temp directory and installed, when they are here.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/audit.php';
require_once $root . '/includes/settings_catalog.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . mb_substr($info, 0, 600)) . "\n";
    if (!$ok) $fails++;
}

$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
$GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfg;

/* ── bookkeeping: what this run adds, and how it is taken away again ────────── */
$installedBefore = iconpackInstalledIds();
$ours = [];                        // ids this run installed
$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ipt_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0777, true);
$settingKeys = ['icon_library', 'fa_source', 'fa_pack', 'fa_pack_styles', 'fa_style'];
$settingsWas = [];
foreach ($settingKeys as $k) $settingsWas[$k] = array_key_exists($k, $cfg) ? (string)$cfg[$k] : null;
$auditFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$loginAttempts = $root . '/config/login_attempts.json';
$loginWas = is_file($loginAttempts) ? (string)file_get_contents($loginAttempts) : null;
register_shutdown_function(function () use (&$ours, $installedBefore, $tmpRoot, $db, $settingsWas, $auditFloor, $loginAttempts, $loginWas) {
    foreach (array_unique($ours) as $id) {
        if (in_array($id, $installedBefore, true)) continue;
        $dir = iconpackDir() . DIRECTORY_SEPARATOR . $id;
        if (is_dir($dir)) iconpackRemoveTree($dir);
    }
    foreach (array_merge(glob(iconpackDir() . DIRECTORY_SEPARATOR . '.tmp-*') ?: [], glob(iconpackDir() . DIRECTORY_SEPARATOR . '.trash-*') ?: []) as $d) iconpackRemoveTree($d);
    iconpackRemoveTree($tmpRoot);
    $set = $db->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $del = $db->prepare("DELETE FROM settings WHERE `key` = ?");
    foreach ($settingsWas as $k => $v) { if ($v === null) $del->execute([$k]); else $set->execute([$k, $v]); }
    $db->prepare("DELETE FROM audit_log WHERE id > ? AND action LIKE 'iconpack.%'")->execute([$auditFloor]);
    if ($loginWas === null) @unlink($loginAttempts); else @file_put_contents($loginAttempts, $loginWas);
    $left = array_values(array_diff(iconpackInstalledIds(), $installedBefore));
    echo "cleanup: config/iconpacks holds " . count(iconpackInstalledIds()) . " package(s) (" . count($installedBefore) . " before the run, "
        . count($left) . " left by it" . ($left ? ': ' . implode(', ', $left) : '') . ")\n";
});
$import = function (string $src, array $opts = []) use (&$ours): array {
    $r = iconpackImport($src, $opts + ['kind' => 'cli', 'by' => 'test']);
    if (!empty($r['ok']) && !empty($r['id']) && empty($r['already'])) $ours[] = $r['id'];
    return $r;
};

/* ── synthetic packages ─────────────────────────────────────────────────────── */
/** A font file with its format's signature and, for WOFF/WOFF2, the length its header claims. */
function iptFont(string $ext, int $bytes = 96): string {
    $sig = ['woff2' => 'wOF2', 'woff' => 'wOFF', 'ttf' => "\x00\x01\x00\x00", 'otf' => 'OTTO'][$ext];
    $body = $sig . "\x00\x01\x00\x00" . pack('N', $bytes) . str_repeat("\x00", $bytes - 12);
    return $body;
}
/** The first comment every Font Awesome stylesheet carries, for this synthetic edition and version. */
function iptHead(array $o): string {
    return "/*!\n * Font Awesome " . ($o['edition'] === 'pro' ? 'Pro' : 'Free') . ' ' . $o['version'] . " by @fontawesome - https://fontawesome.com\n"
         . " * License - a synthetic package generated by tests/iconpack_test.php: no Font Awesome code, font or glyph\n */\n";
}
function iptFamName(array $o, string $fam): string {
    if ($fam === 'classic') return 'Font Awesome ' . $o['major'] . ' ' . ($o['edition'] === 'pro' ? 'Pro' : 'Free');
    return 'Font Awesome ' . $o['major'] . ' ' . ucwords(str_replace('-', ' ', $fam));
}
/** Font Awesome's one-word alias for a style: jelly-duo-regular → fajdr, solid → fas. */
function iptShort(string $key): string {
    $parts = explode('-', $key === 'duotone' ? 'duotone-solid' : $key);
    if ($key === 'brands') return 'fab';
    $s = 'fa';
    foreach ($parts as $p) $s .= $p[0];
    return $s;
}
/** One style file's text, 7.x or 6.x shaped. */
function iptStyleCss(array $o, string $key): string {
    $info = iconpackStyleFromKey($key);
    $fam = $info['family']; $w = $info['weight']; $st = $info['style'];
    $name = $fam === 'brands' ? 'Font Awesome ' . $o['major'] . ' Brands' : iptFamName($o, $fam);
    $font = $key === 'brands' ? 'fa-brands-400' : 'fa-' . ($fam === 'classic' ? $st : $key) . '-' . $w;
    $src = 'src:url(../webfonts/' . $font . '.woff2)' . ($o['major'] === 6 ? ' format("woff2"),url(../webfonts/' . $font . '.ttf) format("truetype")' : '');
    $short = iptShort($key);
    $famVar = $fam === 'brands' ? 'brands' : $fam;
    if ($o['major'] === 6) {
        $css = ':host,:root{--fa-style-family-' . $famVar . ':"' . $name . '";--fa-font-' . ($fam === 'classic' ? $st : $key) . ':normal ' . $w . ' 1em/1 "' . $name . '"}'
             . '@font-face{font-family:"' . $name . '";font-style:normal;font-weight:' . $w . ';font-display:block;' . $src . '}';
        if ($fam === 'brands') $css .= '.fa-brands,.fab{font-weight:400}';
        elseif ($fam === 'classic') $css .= '.fa-' . $st . ',.' . $short . '{font-weight:' . $w . '}';
        else $css .= '.fa-' . $st . ',.' . $short . '{font-weight:' . $w . '}';
        return $css;
    }
    $css = ':host,:root{--fa-family-' . $famVar . ':"' . $name . '";--fa-font-' . ($fam === 'classic' ? $st : $key) . ':normal ' . $w . ' 1em/1 var(--fa-family-' . $famVar . ');--fa-style-family-' . $famVar . ':var(--fa-family-' . $famVar . ')}'
         . '@font-face{font-family:"' . $name . '";font-style:normal;font-weight:' . $w . ';font-display:block;' . $src . '}';
    if ($fam === 'brands') return $css . '.fa-brands,.fab{--fa-family:var(--fa-family-brands);--fa-style:400}';
    $famClass = $fam === 'classic' ? 'fa-classic' : 'fa-' . $fam;
    $css .= '.' . $short . '{--fa-style:' . $w . '}.' . $famClass . ',.' . $short . '{--fa-family:var(--fa-family-' . $famVar . ')}.fa-' . $st . '{--fa-style:' . $w . '}';
    if (str_contains($fam, 'duo')) {
        $css .= '.' . $famClass . ':before,.' . $short . ':before{position:absolute;color:var(--fa-primary-color,currentColor)}'
              . '.' . $famClass . ':after,.' . $short . ':after{content:var(--fa)/"";color:var(--fa-secondary-color,currentColor)}';
    }
    return $css;
}
/** Icon declarations: `.fa-NAME{--fa:"\fXXXX"}`, a few with an alias, as Font Awesome writes them. */
function iptNames(array $names): string {
    $css = ''; $i = 0;
    foreach ($names as $nm) {
        $cp = 0xf000 + ($i++);
        $alias = $nm === 'trash-can' ? '.fa-trash-alt,' : '';
        $css .= $alias . '.fa-' . $nm . '{--fa:"\\' . dechex($cp) . '"}';
    }
    return $css . '.fa-0{--fa:"\\30 "}';
}
/**
 * A whole package as [relative path => bytes]. Options: major, edition, version, core (all|fontawesome),
 * inAll (the styles all.css has), extra (style files outside it), names, metadata (free|pro|search|null:
 * Font Awesome's own files describing Free or Pro, the owner's compact index, none), license, junk (the
 * svgs/ and js/ a full download also carries).
 */
function iptPackage(array $o = []): array {
    $o += ['major' => 7, 'edition' => 'pro', 'version' => '7.9.1', 'core' => 'all', 'inAll' => ['solid', 'regular', 'light', 'brands'],
           'extra' => ['sharp-solid', 'sharp-regular', 'jelly-regular', 'jelly-duo-regular'], 'names' => null, 'metadata' => 'free', 'license' => true, 'junk' => true,
           'faces' => []];
    $names = $o['names'] ?? array_values(array_diff(iconFaCandidateNames(), ['youtube']));
    $names = array_values(array_unique(array_merge($names, $o['faces'])));
    $h = iptHead($o);
    $f = [];
    $coreRule = $o['major'] === 7
        ? '.fa,.fa-brands,.fa-classic,.fa-regular,.fa-solid,.fa-light,.fa-thin,.fa-sharp,.fa-jelly,.fa-jelly-duo,.fab,.far,.fas{--_fa-family:var(--fa-family,var(--fa-style-family,"' . iptFamName($o, 'classic') . '"));display:var(--fa-display,inline-block);font-family:var(--_fa-family);font-weight:var(--fa-style,900);line-height:1;width:var(--fa-width,1.25em)}'
          . ':is(.fas,.far,.fab,.fa-solid,.fa-regular,.fa-light,.fa-brands,.fa-classic,.fa):before{content:var(--fa)/""}@supports not (content:""/""){:is(.fas,.far,.fa-solid,.fa):before{content:var(--fa)}}'
          . '@keyframes fa-spin{0%{transform:rotate(0deg)}to{transform:rotate(1turn)}}'
        : '.fa{font-family:var(--fa-style-family,"' . iptFamName($o, 'classic') . '");font-weight:var(--fa-style,900)}.fa,.fa-brands,.fa-regular,.fa-solid,.fa-light,.fa-sharp,.fab,.far,.fas,.fass{display:var(--fa-display,inline-block);line-height:1}'
          . '.fa-brands:before,.fa-regular:before,.fa-solid:before,.fa-light:before,.fa-sharp:before,.fa:before{content:var(--fa)}'
          . '.fa-classic,.fa-light,.fa-regular,.fa-solid,.fal,.far,.fas{font-family:"' . iptFamName($o, 'classic') . '"}.fa-brands,.fab{font-family:"Font Awesome 6 Brands"}.fa-sharp,.fass{font-family:"Font Awesome 6 Sharp"}';
    $all = $h . $coreRule;
    foreach ($o['inAll'] as $k) $all .= iptStyleCss($o, $k);
    $all .= iptNames($names) . '.fa-youtube{--fa:"\\f167"}';
    if ($o['core'] === 'fontawesome') $f['css/fontawesome.css'] = $h . $coreRule . iptNames($names);
    $f['css/all.css'] = $all;
    foreach (array_merge($o['inAll'], $o['extra']) as $k) {
        $f['css/' . $k . '.css'] = $h . iptStyleCss($o, $k) . ($k === 'brands' ? '.fa-youtube{--fa:"\\f167"}' : '');
        $info = iconpackStyleFromKey($k);
        $font = $k === 'brands' ? 'fa-brands-400' : 'fa-' . ($info['family'] === 'classic' ? $info['style'] : $k) . '-' . $info['weight'];
        $f['webfonts/' . $font . '.woff2'] = iptFont('woff2', 96 + strlen($k));
        if ($o['major'] === 6) $f['webfonts/' . $font . '.ttf'] = iptFont('ttf', 80);
    }
    if ($o['metadata'] === 'search') {
        // The owner's compact index (1.69.0), in its shape — ONE entry per icon, keyed by name — with this
        // test's own words: every icon in classic solid/regular/light, Duotone solid and Sharp solid, every
        // third one in Jelly too (a partial family), the face-* names in the emoji category.
        $idx = [];
        foreach ($names as $i => $nm) {
            $fam = ['classic' => ['solid', 'regular', 'light'], 'duotone' => ['solid'], 'sharp' => ['solid']];
            if ($i % 3 === 0) $fam['jelly'] = ['regular'];
            $idx[$nm] = ['label' => ucwords(str_replace('-', ' ', $nm)), 'unicode' => 'f000', 'is_free' => $i % 2 === 0,
                         'categories' => str_starts_with($nm, 'face-') ? ['emoji', 'synthetic'] : ['synthetic'],
                         'search_terms' => ['synthetic word ' . $i, str_replace('-', ' ', $nm)], 'families' => $fam];
        }
        $f['metadata/icons-search-v' . $o['version'] . '.json'] = json_encode($idx);
    } elseif ($o['metadata'] !== null) {
        $meta = [];
        foreach (array_slice($names, 0, 30) as $nm) {
            $meta[$nm] = ['label' => ucfirst($nm), 'unicode' => 'f000', 'aliases' => ['names' => $nm === 'trash-can' ? ['trash-alt'] : []],
                          'familyStylesByLicense' => ['free' => $o['metadata'] === 'pro' && $nm === 'badge-check' ? [] : [['family' => 'classic', 'style' => 'solid']],
                                                      'pro' => $o['metadata'] === 'pro' ? [['family' => 'sharp', 'style' => 'solid'], ['family' => 'classic', 'style' => 'light']] : [['family' => 'classic', 'style' => 'solid']]]];
        }
        // The faces a test package is given (option `faces`, 1.69.0), in Font Awesome's own format too:
        // named by categories.yml's `emoji`, described like any other icon.
        $faces = array_values(array_intersect($o['faces'], $names));
        foreach ($faces as $nm) {
            $meta[$nm] = ['label' => ucfirst(str_replace('-', ' ', $nm)), 'unicode' => 'f000', 'search' => ['terms' => ['synthetic ' . $nm]],
                          'familyStylesByLicense' => ['free' => [['family' => 'classic', 'style' => 'solid']],
                                                      'pro' => $o['metadata'] === 'pro' ? [['family' => 'duotone', 'style' => 'solid'], ['family' => 'classic', 'style' => 'light']] : []]];
        }
        $f['metadata/icon-families.json'] = json_encode($meta);
        $f['metadata/categories.yml'] = "accessibility:\n  icons:\n    - star\n  label: Accessibility\n"
            . ($faces ? "emoji:\n  icons:\n" . implode('', array_map(fn($nm) => "    - " . $nm . "\n", $faces)) . "  label: Emoji\n" : '');
        $f['metadata/sponsors.yml'] = "nobody: {}\n";
        $f['metadata/shims.json'] = '[]';
    }
    if ($o['license']) $f['LICENSE.txt'] = "Synthetic licence text for a test package.\n";
    if ($o['junk']) {
        $f['svgs/solid/star.svg'] = '<svg xmlns="http://www.w3.org/2000/svg"/>';
        $f['svgs/solid/gear.svg'] = '<svg xmlns="http://www.w3.org/2000/svg"/>';
        $f['js/all.js'] = '/* not kept */';
        $f['css/subdir/extra.css'] = '/* not kept: not directly in css/ */';
        $f['README.md'] = 'not kept';
    }
    return $f;
}
function iptWriteDir(string $dir, array $files): string {
    foreach ($files as $rel => $bytes) {
        $p = $dir . '/' . $rel;
        if (!is_dir(dirname($p))) mkdir(dirname($p), 0777, true);
        file_put_contents($p, $bytes);
    }
    return $dir;
}
/**
 * A zip written byte by byte, so a test can say exactly what an archive holds — the names a real
 * zipper would refuse to write, the sizes it would not lie about, the attributes it would not set.
 * entry: name, data, deflate (bool), unix (mode for a Unix "made by", e.g. 0120777 for a link),
 * size (the uncompressed size to CLAIM).
 */
function iptZip(string $path, array $entries): string {
    $local = ''; $central = ''; $offset = 0;
    foreach ($entries as $e) {
        $name = $e['name']; $data = $e['data'] ?? '';
        $deflate = !empty($e['deflate']);
        $comp = $deflate ? gzdeflate($data, 9) : $data;
        $method = $deflate ? 8 : 0;
        $usize = $e['size'] ?? strlen($data);
        $csize = strlen($comp);
        $crc = crc32($data);
        $mode = $e['unix'] ?? null;
        $madeBy = $mode !== null ? ((3 << 8) | 20) : 20;
        $ext = $mode !== null ? ($mode << 16) : 0;
        $lh = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, 0, 0x5921, $crc, $csize, $usize, strlen($name), 0) . $name;
        $local .= $lh . $comp;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, $madeBy, 20, 0x0800, $method, 0, 0x5921, $crc, $csize, $usize, strlen($name), 0, 0, 0, 0, $ext, $offset) . $name;
        $offset += strlen($lh) + $csize;
    }
    file_put_contents($path, $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), $offset, 0));
    return $path;
}
/** A package's files as zip entries under a prefix ("", "Font Awesome 7.9.1/", "a/b/…/"). */
function iptEntries(array $files, string $prefix = '', bool $deflate = true): array {
    $out = [];
    foreach ($files as $rel => $bytes) $out[] = ['name' => $prefix . $rel, 'data' => $bytes, 'deflate' => $deflate];
    return $out;
}
$n0 = 0;
$tmp = function (string $name) use ($tmpRoot, &$n0): string { return $tmpRoot . DIRECTORY_SEPARATOR . (++$n0) . '-' . $name; };

/* ══ 1. names and paths ═══════════════════════════════════════════════════════ */
$unsafe = ['../x.css' => 'traversal', 'css/../../x' => 'traversal', '/etc/passwd' => 'absolute', 'C:/x.css' => 'drive', 'c:x' => 'drive',
           "a\\b.css" => 'backslash', "a\0b" => 'nul', "a\x01b" => 'control', "a\x7Fb" => 'control', 'a//b' => 'traversal', './a' => 'traversal', '' => 'length'];
$bad = [];
foreach ($unsafe as $nm => $want) if (iconpackUnsafeName((string)$nm) !== $want) $bad[] = json_encode($nm) . '=>' . var_export(iconpackUnsafeName((string)$nm), true);
check('an unsafe name is named for what it is: traversal, absolute, drive letter, backslash, NUL, control character', $bad === [], implode(', ', $bad));
check('… and ordinary names are fine, a directory entry included', iconpackUnsafeName('Font Awesome 7.3.1/css/all.css') === null && iconpackUnsafeName('a/b/') === null
      && iconpackUnsafeName(str_repeat('a', 1025)) === 'length');
$kinds = ['css/all.css' => 'css', 'css/sharp-solid.min.css' => 'css', 'css/sub/x.css' => null, 'webfonts/fa-solid-900.woff2' => 'font', 'webfonts/X.TTF' => 'font',
          'webfonts/x.eot' => null, 'webfonts/x.svg' => null, 'metadata/icons.json' => 'metadata', 'metadata/categories.yml' => 'metadata',
          'metadata/sponsors.yml' => null, 'metadata/shims.json' => null, 'LICENSE.txt' => 'license', 'LICENSE' => 'license',
          'js/all.js' => null, 'svgs/solid/star.svg' => null, 'css/a b.css' => null, 'css/../all.css' => null];
$bad = [];
foreach ($kinds as $rel => $want) if (iconpackKeptKind($rel) !== $want) $bad[] = "$rel=>" . var_export(iconpackKeptKind($rel), true);
check('only css/*.css, webfonts/*.{woff2,woff,ttf,otf}, metadata/*.{json,yml,yaml} (not sponsors, not shims) and a licence are kept', $bad === [], implode(', ', $bad));

/* ══ 2. every nesting shape: one package, one id ══════════════════════════════ */
$pkg = iptPackage();
$srcDir = iptWriteDir($tmp('dir'), $pkg);
$r = $import($srcDir);
$idA = (string)($r['id'] ?? '');
check('a directory: installed, root at its top, a fa-pro-<version>-<hash> id', !empty($r['ok']) && empty($r['already']) && $r['root'] === ''
      && (bool)preg_match('/^fa-pro-7\.9\.1-[0-9a-f]{8}$/', $idA) && is_dir(iconpackDir() . '/' . $idA), json_encode($r));
$shapes = [
    'the folder\'s contents zipped' => ['', ''],
    'the folder zipped' => ['Font Awesome 7.9.1/', 'Font Awesome 7.9.1'],
    'the folder two wrappers deep' => ['downloads/fontawesome-pro-7.9.1-web/Font Awesome 7.9.1/', 'downloads/fontawesome-pro-7.9.1-web/Font Awesome 7.9.1'],
];
foreach ($shapes as $label => [$prefix, $wantRoot]) {
    $entries = iptEntries($pkg, $prefix);
    if ($prefix !== '') {
        // What a Mac adds beside the folder: resource forks in the same shape as the real thing.
        $entries[] = ['name' => '__MACOSX/' . $prefix . 'css/._all.css', 'data' => "\x00\x05\x16\x07junk"];
        $entries[] = ['name' => '__MACOSX/' . $prefix . 'webfonts/._fa-solid-900.woff2', 'data' => "\x00\x05\x16\x07junk"];
        $entries[] = ['name' => $prefix . '.DS_Store', 'data' => 'Bud1'];
    }
    $zip = iptZip($tmp('shape.zip'), $entries);
    $r = $import($zip);
    check("$label: the same package — same id, reported as already installed — with the root found at "
          . ($wantRoot === '' ? 'the top' : '"' . $wantRoot . '"'), !empty($r['ok']) && $r['id'] === $idA && !empty($r['already']) && $r['root'] === $wantRoot, json_encode($r));
}
$two = iptZip($tmp('two.zip'), array_merge(iptEntries($pkg, 'Font Awesome 7.9.1/'), iptEntries(iptPackage(['version' => '6.9.1', 'major' => 6]), 'Font Awesome 6.9.1/')));
$r = $import($two);
check('two packages in one archive: refused, both roots named', empty($r['ok']) && $r['error'] === 'two_roots'
      && str_contains($r['detail'], 'Font Awesome 7.9.1') && str_contains($r['detail'], 'Font Awesome 6.9.1'), json_encode($r));
$r = $import(iptZip($tmp('none.zip'), [['name' => 'readme.txt', 'data' => 'hello'], ['name' => 'css/site.css', 'data' => 'body{}'], ['name' => 'webfonts/x.woff2', 'data' => iptFont('woff2')]]));
check('an archive with no Font Awesome stylesheet: refused (no root)', empty($r['ok']) && $r['error'] === 'no_root', json_encode($r));
$r = $import($tmp('missing.zip'));
check('a path that is not there: refused', empty($r['ok']) && $r['error'] === 'not_found');
file_put_contents($notZip = $tmp('not.zip'), 'this is text, not a zip');
$r = $import($notZip);
check('a file that is not a zip: refused', empty($r['ok']) && $r['error'] === 'not_zip');

/* ══ 3. hostile archives ══════════════════════════════════════════════════════ */
$base = iptEntries($pkg, 'fa/');
$hostile = [
    'a traversal entry (../)'        => [['name' => 'fa/css/../../../evil.css', 'data' => 'x'], 'unsafe_name'],
    'an absolute path'               => [['name' => '/etc/cron.d/evil', 'data' => 'x'], 'unsafe_name'],
    'a drive letter'                 => [['name' => 'C:/Windows/evil.css', 'data' => 'x'], 'unsafe_name'],
    'a backslash'                    => [['name' => 'fa\\css\\all.css', 'data' => 'x'], 'unsafe_name'],
    'a NUL in a name'                => [['name' => "fa/css/a\0.css", 'data' => 'x'], 'unsafe_name'],
    'a control character in a name'  => [['name' => "fa/css/a\x07.css", 'data' => 'x'], 'unsafe_name'],
    'a symlink entry'                => [['name' => 'fa/webfonts/link.woff2', 'data' => '../../../etc/passwd', 'unix' => 0120777], 'symlink'],
];
foreach ($hostile as $label => [$entry, $code]) {
    $r = $import(iptZip($tmp('hostile.zip'), array_merge($base, [$entry])));
    check("$label: refused ($code), nothing written", empty($r['ok']) && $r['error'] === $code && empty($r['id']), json_encode(['error' => $r['error'], 'detail' => $r['detail']]));
}
check('the backslash refusal says how to pack it again (PowerShell\'s Compress-Archive, 7-Zip, tar)',
      str_contains(iconpackMessage('unsafe_name', 'backslash: fa\\css\\all.css'), 'Compress-Archive') && str_contains(iconpackMessage('unsafe_name', 'backslash: x'), 'tar -a'));
$r = $import(iptZip($tmp('dup.zip'), array_merge($base, [['name' => 'fa/css/all.css', 'data' => $pkg['css/all.css']]])));
check('the same kept file twice: refused (duplicate)', empty($r['ok']) && $r['error'] === 'duplicate', json_encode($r['error']));
$r = $import(iptZip($tmp('case.zip'), array_merge($base, [['name' => 'fa/css/All.css', 'data' => $pkg['css/all.css']]])));
check('two kept names one case apart: refused (case collision)', empty($r['ok']) && $r['error'] === 'case_collision', json_encode($r['error']));
// A bomb: 3 MB of one byte deflates to a few kilobytes — hundreds to one.
$r = $import(iptZip($tmp('bomb.zip'), array_merge($base, [['name' => 'fa/metadata/icons.json', 'data' => str_repeat('0', 3 * 1048576), 'deflate' => true]])));
check('a zip bomb by ratio (3 MB at ~1000:1): refused before a byte is inflated', empty($r['ok']) && $r['error'] === 'ratio', json_encode([$r['error'], $r['detail']]));
$many = $base;
for ($i = 0; $i <= ICONPACK_MAX_KEPT_FILES; $i++) $many[] = ['name' => 'fa/webfonts/f' . $i . '.woff2', 'data' => iptFont('woff2', 16)];
$r = $import(iptZip($tmp('many.zip'), $many));
check('more kept files than a package has: refused (' . ICONPACK_MAX_KEPT_FILES . ' at most)', empty($r['ok']) && $r['error'] === 'too_many_files', json_encode($r['error']));
$r = $import(iptZip($tmp('big.zip'), array_merge($base, [['name' => 'fa/css/huge.css', 'data' => 'x', 'deflate' => true, 'size' => ICONPACK_MAX_CSS_BYTES + 1]])));
check('a stylesheet over its size limit (by its declared size): refused', empty($r['ok']) && $r['error'] === 'file_too_large', json_encode($r['error']));
$r = $import(iptZip($tmp('lie.zip'), array_merge(array_values(array_filter($base, fn($e) => $e['name'] !== 'fa/css/all.css')),
                                           [['name' => 'fa/css/all.css', 'data' => $pkg['css/all.css'], 'deflate' => true, 'size' => 100]])));
check('a size field that lies (the bytes are not what the archive declares): refused, nothing installed', empty($r['ok']) && empty($r['id']), json_encode([$r['error'], $r['detail']]));
$r = $import(iptZip($tmp('bigmeta.zip'), array_merge($base, [['name' => 'fa/metadata/icons.json', 'data' => '{}', 'deflate' => true, 'size' => ICONPACK_MAX_META_BYTES + 5]])));
check('metadata too large to keep: left out with a note, never a reason to refuse the fonts', !empty($r['ok'])
      && in_array('metadata/icons.json', array_column($r['skipped_notes'], 'path'), true), json_encode([$r['error'], $r['skipped_notes']]));
check('… and the store holds no temporary or trash directory after all of the refusals',
      !glob(iconpackDir() . '/.tmp-*') && !glob(iconpackDir() . '/.trash-*'));
$link = $tmp('linkdir');
iptWriteDir($link, $pkg);
$made = @symlink($link . '/css/all.css', $link . '/css/linked.css');
if ($made) {
    $r = $import($link);
    check('a directory with a symlink in it: refused (a walk never follows a link)', empty($r['ok']) && $r['error'] === 'symlink', json_encode($r['error']));
} else {
    echo "SKIP a directory with a symlink: this system would not create one here (Windows without the privilege)\n";
}

/* ══ 4. hostile stylesheets and fonts ═════════════════════════════════════════ */
$h = iptHead(['edition' => 'pro', 'version' => '7.9.1']);
$badCss = [
    '@import'                              => [['css/jelly-regular.css' => $h . '@import url(../webfonts/fa-jelly-regular-400.woff2);' . iptStyleCss(['major' => 7, 'edition' => 'pro', 'version' => '7.9.1'], 'jelly-regular')], 'css_import'],
    'an external url()'                     => [['css/sharp-solid.css' => str_replace('url(../webfonts/fa-sharp-solid-900.woff2)', 'url(https://evil.example/f.woff2)', $pkg['css/sharp-solid.css'])], 'css_url_external'],
    'a protocol-relative url()'             => [['css/sharp-solid.css' => str_replace('url(../webfonts/fa-sharp-solid-900.woff2)', 'url(//evil.example/f.woff2)', $pkg['css/sharp-solid.css'])], 'css_url_external'],
    'a url() to a font the package lacks'   => [['css/sharp-solid.css' => str_replace('fa-sharp-solid-900.woff2', 'fa-sharp-solid-000.woff2', $pkg['css/sharp-solid.css'])], 'css_url_missing'],
    'a url() leaving webfonts/'             => [['css/sharp-solid.css' => str_replace('../webfonts/fa-sharp-solid-900.woff2', '../css/all.css', $pkg['css/sharp-solid.css'])], 'css_url_external'],
    'a sheet that is not Font Awesome\'s'   => [['css/site.css' => "/* my theme */\nbody{color:red}"], 'css_not_fa'],
    'an escape outside a string (@\\69mport)' => [['css/jelly-regular.css' => $h . '@\\69mport "x";' . iptStyleCss(['major' => 7, 'edition' => 'pro', 'version' => '7.9.1'], 'jelly-regular')], 'css_forbidden'],
    'expression('                           => [['css/jelly-regular.css' => $h . '.fa-jelly{width:expression(alert(1))}'], 'css_forbidden'],
    'javascript:'                           => [['css/jelly-regular.css' => $h . '.fa-jelly{--x:"javascript:alert(1)"}'], 'css_forbidden'],
    'behavior:'                             => [['css/jelly-regular.css' => $h . '.fa-jelly{behavior:url(x.htc)}'], 'css_forbidden'],
    'another version beside it'             => [['css/jelly-regular.css' => str_replace('Pro 7.9.1', 'Pro 7.9.2', $pkg['css/jelly-regular.css'])], 'css_mixed_versions'],
    'a style file setting a rule for every icon' => [['css/jelly-regular.css' => $pkg['css/jelly-regular.css'] . '.fa-solid{--fa-family:var(--fa-family-jelly)}'], 'css_global_rule'],
    'a style file giving a core name another code point' => [['css/brands.css' => $pkg['css/brands.css'] . '.fa-star{--fa:"\\e999"}'], 'css_global_rule'],
    'a style file redefining a classic variable' => [['css/jelly-regular.css' => $pkg['css/jelly-regular.css'] . ':root{--fa-family-classic:"Font Awesome 7 Jelly"}'], 'css_global_rule'],
    'a style file drawing under the classic family\'s name' => [['css/jelly-regular.css' => str_replace('font-family:"Font Awesome 7 Jelly"', 'font-family:"Font Awesome 7 Pro"', $pkg['css/jelly-regular.css'])], 'css_face_conflict'],
];
foreach ($badCss as $label => [$over, $code]) {
    $r = $import(iptWriteDir($tmp('badcss'), array_merge($pkg, $over)));
    check("CSS with $label: the package is refused ($code)", empty($r['ok']) && $r['error'] === $code && !empty($r['refused']), json_encode([$r['error'], $r['detail']]));
}
$fa5 = [];
foreach ($pkg as $rel => $bytes) $fa5[$rel] = str_ends_with($rel, '.css') ? str_replace('Pro 7.9.1', 'Pro 5.15.4', $bytes) : $bytes;
$r = $import(iptWriteDir($tmp('fa5'), $fa5));
check('Font Awesome 5: refused, the site supports 6 and 7', empty($r['ok']) && $r['error'] === 'major_unsupported', json_encode($r['error']));
$noCore = $pkg; unset($noCore['css/all.css']);
$r = $import(iptWriteDir($tmp('nocore'), $noCore));
check('no all.css and no fontawesome.css: refused (no core)', empty($r['ok']) && $r['error'] === 'no_core', json_encode($r['error']));
$r = $import(iptWriteDir($tmp('badfont'), array_merge($pkg, ['webfonts/fa-sharp-solid-900.woff2' => 'wOFF' . substr(iptFont('woff2'), 4)])));
check('a .woff2 that is not WOFF2 (its signature): refused', empty($r['ok']) && $r['error'] === 'font_magic', json_encode([$r['error'], $r['detail']]));
$lying = iptFont('woff2', 96); $lying = substr($lying, 0, 8) . pack('N', 5000) . substr($lying, 12);
$r = $import(iptWriteDir($tmp('badlen'), array_merge($pkg, ['webfonts/fa-sharp-solid-900.woff2' => $lying])));
check('a WOFF2 whose header claims another length: refused', empty($r['ok']) && $r['error'] === 'font_magic' && str_contains($r['detail'], '5000'), json_encode($r['detail']));
$r = $import(iptWriteDir($tmp('badttf'), array_merge(iptPackage(['major' => 6, 'version' => '6.9.1', 'extra' => ['sharp-solid']]), ['webfonts/fa-sharp-solid-900.ttf' => '<html>not a font</html>'])));
check('a .ttf that is not a font: refused', empty($r['ok']) && $r['error'] === 'font_magic', json_encode($r['error']));

/* ══ 5. what is detected and written ═══════════════════════════════════════════ */
$m = iconpackManifest($idA, true) ?? iconpackManifest($idA);
$styles = [];
foreach ($m['styles'] ?? [] as $s) $styles[$s['key']] = $s;
check('the manifest: vendor, edition, version, major, root, hash, source, installed at and by',
      ($m['vendor'] ?? '') === 'fontawesome' && $m['edition'] === 'pro' && $m['version'] === '7.9.1' && (int)$m['major'] === 7 && $m['root'] === ''
      && (bool)preg_match('/^[0-9a-f]{64}$/', $m['hash']) && str_starts_with($idA, 'fa-pro-7.9.1-' . substr($m['hash'], 0, 8))
      && $m['source']['kind'] === 'cli' && (bool)preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $m['installed_at']) && $m['installed_by'] === 'test', json_encode(array_intersect_key($m, array_flip(['vendor', 'edition', 'version', 'root', 'source', 'installed_at', 'installed_by']))));
check('the styles, classic first, then brands, then the rest by family: solid, regular, light and brands in all.css; jelly, jelly duo, sharp solid and sharp regular in files of their own',
      array_keys($styles) === ['solid', 'regular', 'light', 'brands', 'jelly-regular', 'jelly-duo-regular', 'sharp-solid', 'sharp-regular'],
      implode(',', array_keys($styles)));
check('… each marked in all.css or not, correctly', !empty($styles['solid']['in_all']) && !empty($styles['light']['in_all']) && !empty($styles['brands']['in_all'])
      && empty($styles['sharp-solid']['in_all']) && empty($styles['jelly-regular']['in_all']));
check('… with its label, family, weight, classes and font family',
      $styles['sharp-solid']['label'] === 'Sharp Solid' && $styles['sharp-solid']['family'] === 'sharp' && (int)$styles['sharp-solid']['weight'] === 900
      && $styles['sharp-solid']['classes'] === 'fa-sharp fa-solid' && $styles['sharp-solid']['font_family'] === 'Font Awesome 7 Sharp'
      && $styles['jelly-regular']['classes'] === 'fa-jelly fa-regular' && (int)$styles['jelly-regular']['weight'] === 400
      && $styles['light']['classes'] === 'fa-light' && $styles['brands']['classes'] === 'fa-brands', json_encode($styles['sharp-solid']));
check('… its file, its fonts and their bytes', $styles['sharp-solid']['file'] === 'css/sharp-solid.css' && $styles['sharp-solid']['fonts'] === ['webfonts/fa-sharp-solid-900.woff2']
      && $styles['sharp-solid']['css_bytes'] === strlen($pkg['css/sharp-solid.css']) && $styles['sharp-solid']['font_bytes'] === strlen($pkg['webfonts/fa-sharp-solid-900.woff2']));
check('… and whether it draws in two layers (the duo family) or one', (int)$styles['jelly-duo-regular']['layers'] === 2 && (int)$styles['jelly-regular']['layers'] === 1
      && (int)$styles['solid']['layers'] === 1);
check('the core is all.css; families by name', ($m['core'] ?? []) === ['kind' => 'all', 'file' => 'css/all.css']
      && ($m['families']['sharp'] ?? '') === 'Font Awesome 7 Sharp' && ($m['families']['classic'] ?? '') === 'Font Awesome 7 Pro');
$namesJ = json_decode((string)file_get_contents(iconpackDir() . '/' . $idA . '/names.json'), true);
$wantNames = count(iconFaCandidateNames()) + 2;   // + the trash-alt alias and .fa-0 (the digit, whose code point "0" is falsy)
check('the icon names, aliases included, with their code points (names.json), counted in the manifest',
      is_array($namesJ['names'] ?? null) && count($namesJ['names']) === $wantNames && $namesJ['names']['trash-alt'] === $namesJ['names']['trash-can']
      && (string)($namesJ['names']['0'] ?? '') === '30' && (int)$m['icons']['names'] === $wantNames && (int)$m['icons']['glyphs'] === $wantNames - 1,
      count($namesJ['names'] ?? []) . ' vs ' . $wantNames);
check('which of the map\'s names it has, worked out at import for the pages', ($m['present']['for'] ?? '') === hash('sha256', implode(',', iconFaCandidateNames()))
      && $m['present']['names'] === iconFaCandidateNames(), count($m['present']['names'] ?? []) . ' of ' . count(iconFaCandidateNames()));
$kept = array_keys($m['files']);
sort($kept);
check('kept: css/*.css, webfonts/*, metadata (not sponsors, not shims) and LICENSE.txt — nothing else',
      !array_filter($kept, fn($p) => !preg_match('#^(css/[^/]+\.css|webfonts/[^/]+|metadata/(icon-families\.json|categories\.yml)|LICENSE\.txt)$#', $p))
      && in_array('LICENSE.txt', $kept, true) && in_array('metadata/categories.yml', $kept, true) && !in_array('metadata/sponsors.yml', $kept, true), implode(', ', $kept));
check('… every one with its size and SHA-256', !array_filter($m['files'], fn($f) => !preg_match('/^[0-9a-f]{64}$/', (string)$f['sha256']) || (int)$f['bytes'] <= 0));
$first = iconpackImport(iptWriteDir($tmp('report'), array_merge($pkg, ['LICENSE.txt' => "a different licence, so a new id\n"])), ['kind' => 'cli', 'by' => 'test']);
if (!empty($first['id']) && empty($first['already'])) $ours[] = $first['id'];
check('the report lists what was skipped, by folder: svgs/, js/, the README, the sub-folder of css/, sponsors and shims',
      ($first['skipped']['svgs/'] ?? 0) === 2 && ($first['skipped']['js/'] ?? 0) === 1 && ($first['skipped']['README.md'] ?? 0) === 1
      && ($first['skipped']['css/'] ?? 0) === 1 && ($first['skipped']['metadata/'] ?? 0) === 2, json_encode($first['skipped']));
check('what the metadata describes is recorded — Free here, however many icons, and no Pro style',
      ($m['metadata']['describes'] ?? '') === 'free' && (int)$m['metadata']['icons'] === 30 && empty($m['metadata']['pro_styles'])
      && (int)$m['metadata']['css_names'] === $wantNames, json_encode($m['metadata']));
$proMeta = $import(iptWriteDir($tmp('prometa'), iptPackage(['metadata' => 'pro', 'version' => '7.9.3'])));
$pm = iconpackManifest((string)$proMeta['id']);
check('… and Pro metadata is told apart (a Pro-only icon, a Pro style)', !empty($proMeta['ok']) && ($pm['metadata']['describes'] ?? '') === 'pro'
      && !empty($pm['metadata']['pro_styles']) && (int)$pm['metadata']['pro_only_icons'] === 1, json_encode($pm['metadata'] ?? null));
$v = iconpackVerify($idA);
check('verify: every file as the manifest has it', !empty($v['ok']) && $v['hash_ok'] && !$v['missing'] && !$v['changed'], json_encode($v));
$victim = iconpackDir() . '/' . $idA . '/css/sharp-solid.css';
$saved = (string)file_get_contents($victim);
file_put_contents($victim, $saved . '/* changed */');
file_put_contents(iconpackDir() . '/' . $idA . '/css/stray.css', 'x');
$v = iconpackVerify($idA);
check('… and a changed file, and a file nobody installed, are reported', empty($v['ok']) && $v['changed'] === ['css/sharp-solid.css'] && $v['extra'] === ['css/stray.css'], json_encode($v));
file_put_contents($victim, $saved);
@unlink(iconpackDir() . '/' . $idA . '/css/stray.css');
// 6.x: families by font-weight rules and :root --fa-style-family-*, a .ttf beside each woff2.
$six = $import(iptWriteDir($tmp('six'), iptPackage(['major' => 6, 'version' => '6.9.1', 'extra' => ['sharp-solid'], 'names' => array_values(array_diff(iconFaCandidateNames(), ['youtube', 'thumbtack-angle', 'dot']))])));
$m6 = iconpackManifest((string)$six['id']);
$s6 = [];
foreach ($m6['styles'] ?? [] as $s) $s6[$s['key']] = $s;
check('a 6.x-shaped package: installed, sharp solid outside all.css with both its fonts', !empty($six['ok']) && (int)$m6['major'] === 6
      && isset($s6['sharp-solid']) && empty($s6['sharp-solid']['in_all']) && $s6['sharp-solid']['fonts'] === ['webfonts/fa-sharp-solid-900.woff2', 'webfonts/fa-sharp-solid-900.ttf']
      && $s6['sharp-solid']['font_family'] === 'Font Awesome 6 Sharp' && !empty($s6['solid']['in_all']), json_encode($six['error'] ?? null) . ' ' . json_encode(array_keys($s6)));
// A full download: fontawesome.css beside all.css, the compatibility sheets and the JS build's sheet,
// and no metadata/ at all.
$full = iptPackage(['core' => 'fontawesome', 'version' => '7.9.4', 'metadata' => null]);
$fh = iptHead(['edition' => 'pro', 'version' => '7.9.4']);
$full['css/v4-shims.css'] = $fh . '.fa.fa-glass{--fa:"\\f000"}';
$full['css/v5-font-face.css'] = $fh . '@font-face{font-family:"Font Awesome 5 Pro";font-display:block;font-weight:900;src:url(../webfonts/fa-solid-900.woff2) format("woff2")}';
$full['css/svg-with-js.css'] = $fh . ':host,:root{--fa-font-solid:normal 900 1em/1 "Font Awesome 7 Pro"}svg.svg-inline--fa{height:1em;overflow:visible}';
$fw = $import(iptWriteDir($tmp('fwcore'), $full));
$mfw = iconpackManifest((string)$fw['id']);
$fwNotes = array_column($fw['skipped_notes'] ?? [], 'why', 'path');
check('a full download: fontawesome.css is the core when it is there', !empty($fw['ok']) && ($mfw['core']['kind'] ?? '') === 'fontawesome', json_encode($fw['error'] ?? null));
check('… its compatibility sheets and the JS build\'s sheet are checked, then left out, each with its reason',
      ($fwNotes['css/v4-shims.css'] ?? '') === 'compat' && ($fwNotes['css/v5-font-face.css'] ?? '') === 'compat' && ($fwNotes['css/svg-with-js.css'] ?? '') === 'svg_build'
      && !isset($mfw['files']['css/v4-shims.css']) && !isset($mfw['files']['css/svg-with-js.css']) && isset($mfw['files']['css/fontawesome.css']), json_encode($fwNotes));
check('… and without metadata/ the manifest says it describes nothing', ($mfw['metadata']['describes'] ?? '') === 'none' && $mfw['metadata']['files'] === []);
$bad5 = $full;
$bad5['css/v4-shims.css'] = $fh . '.fa.fa-glass{--fa:"\\f000"}@import url(https://evil.example/x.css);';
$r = $import(iptWriteDir($tmp('fwbad'), $bad5));
check('… a tampered compatibility sheet still refuses the whole package', empty($r['ok']) && $r['error'] === 'css_import', json_encode($r['error']));
// The owner's complete downloads (1.69.0): Pro 7.3.1 also carries svg.css (the SVG build's sheet, beside
// svg-with-js.css) and v4-font-face.css; the owner's download script adds a root manifest.json (what it
// fetched, not a Font Awesome file) and a compact index, metadata/icons-search-vX.Y.Z.json, in a shape
// Font Awesome's metadata does not have. Installed: svg.css left out as the JS/SVG build's, the manifest
// skipped, the index kept — and described as unknown, for what reads it later.
$fh8 = iptHead(['edition' => 'pro', 'version' => '7.9.2']);
$own = iptPackage(['core' => 'fontawesome', 'version' => '7.9.2', 'metadata' => null]);
$own['css/svg.css'] = $fh8 . ':host,:root{--fa-font-solid:normal 900 1em/1 "Font Awesome 7 Pro"}svg.svg-inline--fa{height:1em;overflow:visible}';
$own['css/v4-font-face.css'] = $fh8 . '@font-face{font-family:"FontAwesome";font-display:block;src:url(../webfonts/fa-solid-900.woff2) format("woff2")}';
$own['manifest.json'] = '{"version":"7.9.2","complete":true,"files":{"css/all.css":{"etag":"x","size":1}}}';
$own['metadata/icons-search-v7.9.2.json'] = '{"gear":{"label":"Gear","unicode":"f013","is_free":true,"families":{"classic":["solid","regular"]}}}';
$ow = $import(iptWriteDir($tmp('owndl'), $own));
$mow = !empty($ow['ok']) ? iconpackManifest((string)$ow['id']) : [];
$owNotes = array_column($ow['skipped_notes'] ?? [], 'why', 'path');
check('the owner\'s complete download: installed; svg.css left out as the SVG build\'s sheet, v4-font-face.css as a compatibility sheet',
      !empty($ow['ok']) && ($owNotes['css/svg.css'] ?? '') === 'svg_build' && ($owNotes['css/v4-font-face.css'] ?? '') === 'compat'
      && !isset($mow['files']['css/svg.css']), json_encode([$ow['error'] ?? null, $owNotes]));
check('… the download script\'s root manifest.json skipped, the icons-search index kept and described as unknown',
      ($ow['skipped']['manifest.json'] ?? 0) === 1 && !isset($mow['files']['manifest.json']) && isset($mow['files']['metadata/icons-search-v7.9.2.json'])
      && ($mow['metadata']['describes'] ?? '') === 'unknown', json_encode([$ow['skipped'] ?? null, $mow['metadata'] ?? null]));
$sFw = iconSetup(['icon_library' => 'fontawesome', 'fa_source' => 'pack', 'fa_pack' => (string)$fw['id'], 'fa_pack_styles' => '["light"]', 'fa_style' => 'light']);
$fwHrefs = array_map(fn($c) => (string)preg_replace('/.*&f=/', '', $c['href']), $sFw['css']);
check('… it loads fontawesome.css with Solid, Regular and Brands always (the site\'s fills, outlines and brand), and exactly what is ticked',
      $fwHrefs === ['css/fontawesome.css', 'css/solid.css', 'css/regular.css', 'css/light.css', 'css/brands.css'] && $sFw['style'] === 'light', implode(', ', $fwHrefs));
check('… where all.css\'s styles are a choice like any other, the always-loaded three not', array_keys(iconFaStyleChoices(iconSetup(['icon_library' => 'fontawesome', 'fa_source' => 'pack',
      'fa_pack' => (string)$fw['id']]))) === ['solid', 'regular'] && iconSettingsNormalise(['fa_source' => 'pack', 'fa_pack' => (string)$fw['id'],
      'fa_pack_styles' => '["solid","light","brands"]', 'fa_style' => 'solid'], [])['data']['fa_pack_styles'] === '["light"]');

/* ══ 6. serving ═══════════════════════════════════════════════════════════════ */
$ver = iconpackAssetVersion($m);
$get = fn(array $q, array $srv = []) => iconpackServeRequest($q, $srv + ['REQUEST_METHOD' => 'GET']);
$hdr = function (array $r, string $name): ?string {
    foreach ($r['headers'] as $h) if (stripos($h, $name . ':') === 0) return trim(substr($h, strlen($name) + 1));
    return null;
};
$css = $get(['p' => $idA, 'v' => $ver, 'f' => 'css/sharp-solid.css']);
check('a listed stylesheet: 200, text/css, nosniff, a year immutable, same-origin, no script',
      $css['status'] === 200 && $hdr($css, 'Content-Type') === 'text/css; charset=utf-8' && $hdr($css, 'X-Content-Type-Options') === 'nosniff'
      && $hdr($css, 'Cache-Control') === 'public, max-age=31536000, immutable' && $hdr($css, 'Cross-Origin-Resource-Policy') === 'same-origin'
      && $hdr($css, 'Content-Security-Policy') === "default-src 'none'", json_encode($css['headers']));
check('… its url()s rewritten to this endpoint, relative to the stylesheet, and nothing else changed',
      str_contains($css['body'], 'url("iconpack.php?p=' . $idA . '&v=' . $ver . '&f=webfonts/fa-sharp-solid-900.woff2")') && !str_contains($css['body'], '../webfonts')
      && str_replace('url("iconpack.php?p=' . $idA . '&v=' . $ver . '&f=webfonts/fa-sharp-solid-900.woff2")', 'url(../webfonts/fa-sharp-solid-900.woff2)', $css['body']) === $pkg['css/sharp-solid.css']
      && (int)$hdr($css, 'Content-Length') === strlen($css['body']));
$font = $get(['p' => $idA, 'v' => $ver, 'f' => 'webfonts/fa-sharp-solid-900.woff2']);
check('a listed font: 200, font/woff2, served from the file as it is', $font['status'] === 200 && $hdr($font, 'Content-Type') === 'font/woff2'
      && isset($font['path']) && (string)file_get_contents($font['path']) === $pkg['webfonts/fa-sharp-solid-900.woff2'] && (int)$hdr($font, 'Content-Length') === strlen($pkg['webfonts/fa-sharp-solid-900.woff2']));
$refuse = [
    'the metadata (never served)' => ['p' => $idA, 'v' => $ver, 'f' => 'metadata/icon-families.json'],
    'the licence (never served)'  => ['p' => $idA, 'v' => $ver, 'f' => 'LICENSE.txt'],
    'the manifest'                => ['p' => $idA, 'v' => $ver, 'f' => 'manifest.json'],
    'a file the manifest does not list' => ['p' => $idA, 'v' => $ver, 'f' => 'css/nope.css'],
    'a traversal'                 => ['p' => $idA, 'v' => $ver, 'f' => 'css/../manifest.json'],
    'another package\'s version'  => ['p' => $idA, 'v' => str_repeat('0', 16) . '-1', 'f' => 'css/all.css'],
    'an unknown package'          => ['p' => 'fa-pro-1.0.0-00000000', 'v' => $ver, 'f' => 'css/all.css'],
    'an id that is not an id'     => ['p' => '../config', 'v' => $ver, 'f' => 'css/all.css'],
];
$bad = [];
foreach ($refuse as $label => $q) { $x = $get($q); if ($x['status'] !== 404 || $hdr($x, 'X-Content-Type-Options') !== 'nosniff') $bad[] = $label . '=' . $x['status']; }
check('everything else is a 404 (the metadata, the licence, the manifest, unlisted, traversal, another version, unknown ids)', $bad === [], implode(', ', $bad));
$etag = (string)$hdr($css, 'ETag');
$c304 = $get(['p' => $idA, 'v' => $ver, 'f' => 'css/sharp-solid.css'], ['HTTP_IF_NONE_MATCH' => $etag]);
$m304 = $get(['p' => $idA, 'v' => $ver, 'f' => 'css/sharp-solid.css'], ['HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', time() + 60) . ' GMT']);
check('validators: the ETag (package + file hash) and Last-Modified answer 304 without a body',
      (bool)preg_match('/^"[0-9a-f]{16}-1-[0-9a-f]{16}"$/', $etag) && $c304['status'] === 304 && !isset($c304['body']) && $m304['status'] === 304
      && $hdr($css, 'Last-Modified') !== null, $etag . ' ' . $c304['status'] . ' ' . $m304['status']);
check('… a POST is 405, a HEAD is answered like a GET', $get(['p' => $idA, 'v' => $ver, 'f' => 'css/all.css'], ['REQUEST_METHOD' => 'POST'])['status'] === 405
      && $get(['p' => $idA, 'v' => $ver, 'f' => 'css/all.css'], ['REQUEST_METHOD' => 'HEAD'])['status'] === 200);
check('the endpoint file sends exactly that decision and loads nothing but includes/iconpack.php',
      str_contains((string)file_get_contents($root . '/iconpack.php'), "require_once __DIR__ . '/includes/iconpack.php';\n\n\$r = iconpackServeRequest(\$_GET, \$_SERVER);")
      && substr_count((string)file_get_contents($root . '/iconpack.php'), 'require') === 1);
// One round trip through the running site (php -S here; Apache on production takes the same query string).
$site = 'http://127.0.0.1:8089/';
$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
$live = @file_get_contents($site . 'iconpack.php?p=' . $idA . '&v=' . $ver . '&f=css/sharp-solid.css', false, $ctx);
if ($live === false) {
    echo "SKIP the round trip through the local site: http://127.0.0.1:8089/ does not answer\n";
} else {
    $lh = implode("\n", $http_response_header ?? []);
    check('through the local site: the stylesheet, rewritten, with its headers', str_contains($lh, ' 200') && stripos($lh, 'Content-Type: text/css') !== false
          && stripos($lh, 'X-Content-Type-Options: nosniff') !== false && str_contains((string)$live, 'iconpack.php?p=' . $idA), $lh);
    @file_get_contents($site . 'iconpack.php?p=' . $idA . '&v=' . $ver . '&f=metadata/icon-families.json', false, $ctx);
    $lh2 = implode("\n", $http_response_header ?? []);
    $fontLive = @file_get_contents($site . 'iconpack.php?p=' . $idA . '&v=' . $ver . '&f=webfonts/fa-sharp-solid-900.woff2', false, $ctx);
    check('… a font byte for byte, and the metadata a 404', $fontLive === $pkg['webfonts/fa-sharp-solid-900.woff2'] && str_contains($lh2, ' 404'), $lh2);
}

/* ══ 7. the setup and the map ═════════════════════════════════════════════════ */
$cfgPack = fn(string $id, array $styles, string $style) => ['icon_library' => 'fontawesome', 'fa_source' => 'pack', 'fa_pack' => $id,
                                                             'fa_pack_styles' => json_encode($styles), 'fa_style' => $style];
$sA = iconSetup($cfgPack($idA, ['sharp-solid'], 'sharp-solid'));
check('a package loads its core and exactly the ticked files', $sA['source'] === 'pack' && count($sA['css']) === 2
      && str_contains($sA['css'][0]['href'], 'f=css/all.css') && str_contains($sA['css'][1]['href'], 'f=css/sharp-solid.css') && $sA['css'][0]['integrity'] === ''
      && array_keys($sA['styles']) === ['solid', 'regular', 'light', 'brands', 'sharp-solid'], json_encode(array_column($sA['css'], 'href')));
$tag = iconFontTag($cfgPack($idA, ['sharp-solid'], 'sharp-solid'));
check('… printed in the head as same-origin links, no SRI, &amp; escaped, the map and the observer after them',
      substr_count($tag, '<link ') === 2 && str_contains($tag, 'iconpack.php?p=' . $idA . '&amp;v=' . $ver . '&amp;f=css/all.css') && !str_contains($tag, 'integrity')
      && strpos($tag, 'id="icon-map"') > strrpos($tag, '<link ') && str_contains($tag, 'assets/js/icons.js'));
$mapA = iconFaMap($sA);
check('with sharp solid chosen: what follows the style is sharp, an outline is classic regular (sharp regular is not ticked), a brand is a brand',
      $mapA['gear'] === 'fa-sharp fa-solid fa-gear' && $mapA['bell'] === 'fa-regular fa-bell' && $mapA['star-fill'] === 'fa-sharp fa-solid fa-star'
      && $mapA['youtube'] === 'fa-brands fa-youtube', json_encode([$mapA['gear'], $mapA['bell'], $mapA['star-fill']]));
check('the Pro twins the package has are used (the badge, the octagons, the shields), with their outline role',
      $mapA['patch-check'] === 'fa-regular fa-badge-check' && $mapA['patch-check-fill'] === 'fa-sharp fa-solid fa-badge-check'
      && $mapA['x-octagon'] === 'fa-regular fa-octagon-xmark' && $mapA['shield-lock'] === 'fa-regular fa-shield-keyhole' && $mapA['pin-angle'] === 'fa-regular fa-thumbtack-angle',
      json_encode([$mapA['patch-check'], $mapA['pin-angle']]));
$covA = iconMapCoverage($sA);
check('… counted, and the outlines drawn in classic because sharp regular is not ticked are listed as fallbacks, with the reason',
      $covA['twins'] > 15 && $covA['approx'] + $covA['exact'] === $covA['total'] && $covA['fallbacks']
      && !array_filter($covA['fallbacks'], fn($f) => $f['why'] !== 'style_not_loaded') && $covA['fallbacks'][0]['style'] === 'regular', json_encode(array_slice($covA['fallbacks'], 0, 2)));
$sJ = iconMapCoverage(iconSetup($cfgPack($idA, ['jelly-regular'], 'jelly-regular')));
$mapJ = iconFaMap(iconSetup($cfgPack($idA, ['jelly-regular'], 'jelly-regular')));
check('with jelly regular (400): outlines and the rest in jelly, a filled half in solid, since Jelly Fill is not ticked',
      $mapJ['gear'] === 'fa-jelly fa-regular fa-gear' && $mapJ['bell'] === 'fa-jelly fa-regular fa-bell' && $mapJ['star-fill'] === 'fa-solid fa-star', json_encode([$mapJ['gear'], $mapJ['star-fill']]));
$noTwins = $import(iptWriteDir($tmp('notwins'), iptPackage(['version' => '7.9.5', 'names' => array_map(fn($e) => $e[0], array_values(iconFaEntries()))])));
$sN = iconSetup($cfgPack((string)$noTwins['id'], [], 'solid'));
$mapN = iconFaMap($sN);
$covN = iconMapCoverage($sN);
check('a package without the twins: each entry falls back to its Free name', !empty($noTwins['ok']) && $mapN['patch-check'] === 'fa-regular fa-circle-check'
      && $mapN['x-octagon'] === 'fa-solid fa-circle-xmark' && $mapN['exclamation-octagon-fill'] === 'fa-solid fa-circle-exclamation', json_encode([$mapN['patch-check'] ?? null]));
$twinFb = array_filter($covN['fallbacks'], fn($f) => $f['why'] === 'twin_missing');
// hdd-stack's twin is Free's own `server`, drawn in Pro's outline: the one twin such a package still has.
check('… and the panel lists each of those fallbacks, naming the twin it lacks', count($twinFb) >= 15
      && in_array('badge-check', array_column($twinFb, 'wanted'), true) && $covN['twins'] === 1 && $mapN['hdd-stack'] === 'fa-regular fa-server',
      count($twinFb) . ' twins=' . $covN['twins'] . ' ' . json_encode(array_slice($twinFb, 0, 2)));
$sGone = iconSetup($cfgPack('fa-pro-9.9.9-deadbeef', [], 'light'));
check('a stored package that is gone: the site draws Free 6.7.2 and says why', $sGone['source'] === 'cdn6' && $sGone['fallback'] === 'pack_missing'
      && $sGone['style'] === 'solid' && str_contains($sGone['css'][0]['href'], 'fontawesome-free@6.7.2'));
$s7 = iconSetup(['icon_library' => 'fontawesome', 'fa_source' => 'cdn7', 'fa_style' => 'regular']);
$map7 = iconFaMap($s7);
check('Free 7.3.1 regular: the forty names Free has in regular drawn so, the rest solid, the fills solid',
      $s7['css'][0]['href'] === ICON_FONTAWESOME7_CSS && $s7['css'][0]['integrity'] === ICON_FONTAWESOME7_SRI && $map7['bell'] === 'fa-regular fa-bell'
      && $map7['calendar-range'] === 'fa-regular fa-calendar-days' && $map7['gear'] === 'fa-solid fa-gear' && $map7['star-fill'] === 'fa-solid fa-star');
$bad = [];
foreach (iconFaMap(iconSetupDefault()) as $bi => $cls) { if (!preg_match('/^fa-(solid|regular|brands) fa-/', $cls)) $bad[] = $bi; }
check('with no setup at all the map is 1.68\'s: Free 6.7.2, solid, regular outlines, one brand', $bad === [] && iconFaMap() === iconFaMap(iconSetupDefault()));

/* ══ 8. the settings, against what is installed ═══════════════════════════════ */
$norm = fn(array $d, array $c = []) => iconSettingsNormalise($d, $c);
$x = $norm(['fa_source' => 'bogus', 'fa_pack' => '', 'fa_pack_styles' => '[]', 'fa_style' => 'solid']);
check('an unknown source is coerced to cdn6', $x['error'] === null && $x['data']['fa_source'] === 'cdn6');
$x = $norm(['fa_source' => 'pack', 'fa_pack' => 'fa-pro-9.9.9-deadbeef', 'fa_pack_styles' => '[]', 'fa_style' => 'solid']);
check('a package that is not installed is refused', $x['error'] === 'api.settings.fa_pack_missing' && $x['vars']['id'] === 'fa-pro-9.9.9-deadbeef');
$x = $norm(['fa_source' => 'pack', 'fa_pack' => '', 'fa_pack_styles' => '[]', 'fa_style' => 'solid']);
check('… so is the package source with no package', $x['error'] === 'api.settings.fa_pack_none');
$x = $norm(['fa_source' => 'pack', 'fa_pack' => 'fa-pro-9.9.9-deadbeef', 'fa_pack_styles' => '[]', 'fa_style' => 'light'],
           ['fa_source' => 'pack', 'fa_pack' => 'fa-pro-9.9.9-deadbeef']);
check('… but a stored choice whose package went missing does not stop the rest of the page from saving', $x['error'] === null);
$x = $norm(['fa_source' => 'pack', 'fa_pack' => $idA, 'fa_pack_styles' => json_encode(['light', 'sharp-solid', 'bogus', 'jelly-regular', '<b>', 'sharp-solid']), 'fa_style' => 'jelly-regular']);
check('the ticked styles: only this package\'s own files (all.css ones and unknown words dropped), once each, in the package\'s order',
      $x['error'] === null && json_decode($x['data']['fa_pack_styles'], true) === ['jelly-regular', 'sharp-solid'] && $x['data']['fa_style'] === 'jelly-regular', json_encode($x));
$x = $norm(['fa_source' => 'pack', 'fa_pack' => $idA, 'fa_pack_styles' => '[]', 'fa_style' => 'sharp-solid']);
check('a style that does not load with those files becomes solid', $x['data']['fa_style'] === 'solid');
$x = $norm(['fa_source' => 'cdn7', 'fa_pack' => $idA, 'fa_pack_styles' => '["sharp-solid"]', 'fa_style' => 'brands']);
check('brands is never the site\'s style; jsDelivr offers solid and regular', $x['data']['fa_style'] === 'solid'
      && array_keys(iconFaStyleChoices(iconSetup(['icon_library' => 'fontawesome', 'fa_source' => 'cdn7']))) === ['solid', 'regular']);
$save = (string)file_get_contents($root . '/api/admin/save_settings.php');
check('the settings save runs that check, and asks for the password when the site moves onto a package or to another one',
      str_contains($save, "'fa_source', 'fa_pack', 'fa_pack_styles', 'fa_style',") && str_contains($save, '$faCheck = iconSettingsNormalise($data, $cfg);')
      && str_contains($save, "if (\$faPackNow !== '' && \$faPackNow !== \$faPackWas) \$reauthChanged[] = 'fa_pack';"));
$defs = trackerSchemaDefaultSettings();
check('the four ship as 1.68 drew: cdn6, no package, no extra styles, solid; schema 76',
      ($defs['fa_source'] ?? null) === 'cdn6' && ($defs['fa_pack'] ?? null) === '' && ($defs['fa_pack_styles'] ?? null) === '[]' && ($defs['fa_style'] ?? null) === 'solid'
      && TRACKER_SCHEMA_VERSION >= 76);
$kw = settingsCatalogKeywords();
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
check('each has search words and a control in Settings → Site', !empty($kw['fa_source']) && !empty($kw['fa_pack']) && !empty($kw['fa_pack_styles']) && !empty($kw['fa_style'])
      && str_contains($tpl, 'name="fa_source"') && str_contains($tpl, 'name="fa_pack"') && str_contains($tpl, 'name="fa_pack_styles"') && str_contains($tpl, 'name="fa_style"'));

/* ══ 9. the store ══════════════════════════════════════════════════════════════ */
$list = iconpackList();
check('the list shows every package with its summary', in_array($idA, array_column($list, 'id'), true)
      && !array_filter($list, fn($p) => $p['id'] === $idA && ($p['styles'] !== 8 || $p['outside_all'] !== 4 || $p['edition'] !== 'pro')));
$brokenId = 'fa-pro-7.9.9-0badc0de';
@mkdir(iconpackDir() . '/' . $brokenId, 0775, true);
file_put_contents(iconpackDir() . '/' . $brokenId . '/manifest.json', '{not json');
$ours[] = $brokenId;
check('a directory without a readable manifest is listed as broken (so it can be deleted), and serves nothing',
      (bool)array_filter(iconpackList(), fn($p) => $p['id'] === $brokenId && !empty($p['broken']))
      && $get(['p' => $brokenId, 'v' => $ver, 'f' => 'css/all.css'])['status'] === 404);
$activeCfg = ['fa_source' => 'pack', 'fa_pack' => $idA];
$r = iconpackDelete($idA, $activeCfg);
check('the active package cannot be deleted', empty($r['ok']) && $r['error'] === 'active_package' && is_dir(iconpackDir() . '/' . $idA));
$r = iconpackDelete($brokenId, $activeCfg);
check('another one can, and is gone at once', !empty($r['ok']) && !is_dir(iconpackDir() . '/' . $brokenId));
check('an id that is not an id deletes nothing', iconpackDelete('../iconpacks', [])['error'] === 'unknown_package');
check('config/iconpacks/ is in .gitignore, on a line of its own', (bool)preg_match('#^config/iconpacks/$#m', (string)file_get_contents($root . '/.gitignore')));
$gi = @shell_exec('git -C ' . escapeshellarg($root) . ' check-ignore --no-index -q config/iconpacks/' . $idA . '/css/all.css && echo IGNORED');
if ($gi === null || $gi === false) echo "SKIP git check-ignore: no git here\n";
else check('… and git agrees: a package file cannot be committed', trim((string)$gi) === 'IGNORED', (string)$gi);

/* ══ 10. the CLI and the panel endpoint, run for real ═════════════════════════ */
$cli = function (string $args) use ($root): array {
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=off ' . escapeshellarg($root . '/tools/iconpack.php') . ' ' . $args . ' 2>&1', $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
};
$c = $cli('list');
check('CLI list: every package, the one in use starred, and what the site draws', $c['code'] === 0 && str_contains($c['out'], $idA) && str_contains($c['out'], 'icon library:'), $c['out']);
$cliSrc = iptWriteDir($tmp('clisrc'), iptPackage(['version' => '7.9.6']));
$c = $cli('import ' . escapeshellarg($cliSrc));
preg_match('/(fa-pro-7\.9\.6-[0-9a-f]{8})/', $c['out'], $cm);
$cliId = $cm[1] ?? '';
if ($cliId !== '') $ours[] = $cliId;
check('CLI import: installed, with the report the panel shows', $c['code'] === 0 && $cliId !== '' && str_contains($c['out'], 'package found in: /'), $c['out']);
$c = $cli('import ' . escapeshellarg($two));
check('CLI import of two packages: refused, exit 1', $c['code'] === 1 && str_contains($c['out'], 'Font Awesome 6.9.1'), $c['out']);
$c = $cli('activate ' . $cliId . ' --styles=sharp-solid.css,jelly-regular --style=sharp-solid');
$after = getSettings($db, true);
check('CLI activate: the four settings written through the same checks (file names or keys), the library left alone',
      $c['code'] === 0 && $after['fa_source'] === 'pack' && $after['fa_pack'] === $cliId && json_decode($after['fa_pack_styles'], true) === ['jelly-regular', 'sharp-solid']
      && $after['fa_style'] === 'sharp-solid' && $after['icon_library'] === ($settingsWas['icon_library'] ?? 'bootstrap'), $c['out'] . ' ' . json_encode(array_intersect_key($after, array_flip($settingKeys))));
$c = $cli('styles ' . $cliId . ' sharp-solid --style=jelly-regular');
$after = getSettings($db, true);
check('CLI styles: the style files of the active package, and a style that no longer loads becomes solid, said so',
      $c['code'] === 0 && json_decode($after['fa_pack_styles'], true) === ['sharp-solid'] && $after['fa_style'] === 'solid' && str_contains($c['out'], 'does not load'), $c['out']);
$c = $cli('styles ' . $idA . ' sharp-solid');
check('CLI styles of a package that is not active: refused', $c['code'] === 1 && str_contains($c['out'], 'activate'), $c['out']);
$c = $cli('verify ' . $cliId);
check('CLI verify: ok, exit 0', $c['code'] === 0 && str_starts_with($c['out'], 'ok'), $c['out']);
$c = $cli('delete ' . $cliId);
check('CLI delete of the active package: refused, exit 1', $c['code'] === 1 && str_contains($c['out'], 'active package') && is_dir(iconpackDir() . '/' . $cliId), $c['out']);
$c = $cli('bogus');
check('CLI without a known command: the usage, exit 2', $c['code'] === 2 && str_contains($c['out'], 'usage'));
$audit = $db->query("SELECT action, ok, target_id, actor_name, action_group FROM audit_log WHERE id > " . (int)$auditFloor . " AND action LIKE 'iconpack.%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$acts = array_map(fn($a) => $a['action'] . ($a['ok'] ? '' : '!'), $audit);
check('the CLI wrote audit lines in the settings group, as cli:<user>: install, a refused install, activate, styles, a refused delete',
      $acts === ['iconpack.install', 'iconpack.install!', 'iconpack.activate', 'iconpack.styles', 'iconpack.delete!']
      && !array_filter($audit, fn($a) => $a['action_group'] !== 'settings' || !str_starts_with($a['actor_name'], 'cli:')), implode(', ', $acts));

// The panel endpoint FILE, run as a request in a child process with the includes api.php loads.
$runner = $tmp('runner.php');
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["SCRIPT_NAME"] = "/api.php";
$_GET = $a["get"];
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/usermedia.php", "includes/audit.php", "includes/auth.php", "includes/lang.php"] as $f) require_once $f;
$db = getDb();
$cfg = getSettings($db);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
$_SESSION["loggedin"] = true; $_SESSION["login_time"] = time(); $_SESSION["last_activity"] = time();
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, null);
$GLOBALS["__audit_endpoint"] = "admin/iconpacks";
require $a["file"];
');
$panel = function (string $method, array $get, array $post) use ($root, $runner, $tmp): array {
    $argFile = $tmp('args.json');
    file_put_contents($argFile, json_encode(['root' => $root, 'file' => $root . '/api/admin/iconpacks.php', 'method' => $method, 'get' => $get, 'post' => $post,
                                             'sid' => 'iptest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($runner) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => mb_substr($out, 0, 400)];
};
$j = $panel('GET', [], []);
check('panel GET: the packages, the setup the site draws with and its coverage, the limits', !empty($j['success']) && in_array($cliId, array_column($j['packages'] ?? [], 'id'), true)
      && isset($j['setup']['coverage']['total'], $j['limits']['upload'], $j['limits']['total']) && ($j['setup']['id'] ?? '') === $cliId, json_encode(array_keys($j)));
$j = $panel('GET', ['op' => 'preview', 'source' => 'pack', 'pack' => $idA, 'styles' => '["jelly-regular"]', 'style' => 'jelly-regular'], []);
check('panel preview: an unsaved setup judged like a save — its stylesheets, its map, its style choices',
      !empty($j['success']) && count($j['setup']['css'] ?? []) === 2 && ($j['map']['gear'] ?? '') === 'fa-jelly fa-regular fa-gear'
      && isset($j['setup']['choices']['jelly-regular']) && ($j['applied']['fa_style'] ?? '') === 'jelly-regular', json_encode($j['setup']['css'] ?? $j));
$j = $panel('GET', ['op' => 'preview', 'source' => 'pack', 'pack' => 'fa-pro-9.9.9-deadbeef'], []);
check('… and a package that is not there is the save\'s refusal', empty($j['success']) && ($j['code'] ?? '') === 'api.settings.fa_pack_missing', json_encode($j));
$pathSrc = iptWriteDir($tmp('pathsrc'), iptPackage(['version' => '7.9.7']));
$j = $panel('POST', [], ['op' => 'install_path', 'path' => $pathSrc, 'confirm_password' => 'not-the-password']);
check('panel install from a server path: a wrong password is refused and nothing is installed', empty($j['success']) && !array_filter(iconpackInstalledIds(), fn($i) => str_starts_with($i, 'fa-pro-7.9.7-')), json_encode($j));
$j = $panel('POST', [], ['op' => 'install_path', 'path' => 'relative/path', 'confirm_password' => 'admin123']);
check('… a relative path is refused before anything is read', empty($j['success']) && str_contains((string)($j['error'] ?? ''), '/'), json_encode($j));
$j = $panel('POST', [], ['op' => 'install_path', 'path' => $pathSrc, 'confirm_password' => 'admin123']);
$pathId = (string)($j['report']['id'] ?? '');
if ($pathId !== '') $ours[] = $pathId;
check('… with the password: installed, the report and the fresh list in the answer', !empty($j['success']) && str_starts_with($pathId, 'fa-pro-7.9.7-')
      && in_array($pathId, array_column($j['packages'] ?? [], 'id'), true) && !empty($j['lines']), json_encode($j['error'] ?? null));
$j = $panel('POST', [], ['op' => 'install_path', 'path' => $two, 'confirm_password' => 'admin123']);
check('… a refused import answers 422 with the reason and the report', empty($j['success']) && ($j['report']['error'] ?? '') === 'two_roots' && !empty($j['lines']), json_encode($j['report']['error'] ?? $j));
$j = $panel('POST', [], ['op' => 'activate', 'id' => $pathId, 'confirm_password' => 'admin123']);
$after = getSettings($db, true);
check('panel activate: the site moves onto the package, its ticks reset, the page told to reload', !empty($j['success']) && !empty($j['reload'])
      && $after['fa_source'] === 'pack' && $after['fa_pack'] === $pathId && $after['fa_pack_styles'] === '[]', json_encode($j['error'] ?? null));
$j = $panel('POST', [], ['op' => 'delete', 'id' => $pathId, 'confirm_password' => 'admin123']);
check('panel delete of the active package: 409, still there', empty($j['success']) && is_dir(iconpackDir() . '/' . $pathId), json_encode($j));
$j = $panel('POST', [], ['op' => 'delete', 'id' => $cliId, 'confirm_password' => 'admin123']);
check('panel delete of another: gone, and the list says so', !empty($j['success']) && !is_dir(iconpackDir() . '/' . $cliId) && !in_array($cliId, array_column($j['packages'] ?? [], 'id'), true), json_encode($j['error'] ?? null));
$j = $panel('GET', ['op' => 'verify', 'id' => $pathId], []);
check('panel verify', !empty($j['success']) && !empty($j['verify']['ok']));
$audit = $db->query("SELECT action, ok FROM audit_log WHERE id > " . (int)$auditFloor . " AND action LIKE 'iconpack.%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$acts = array_map(fn($a) => $a['action'] . ($a['ok'] ? '' : '!'), $audit);
$tail = array_slice($acts, 5);
check('the panel wrote one line per write: a wrong password, the install, the refused install, the activation, the refused delete, the delete',
      $tail === ['iconpack.manage!', 'iconpack.install', 'iconpack.install!', 'iconpack.activate', 'iconpack.delete!', 'iconpack.delete'], implode(', ', $tail));
$api = (string)file_get_contents($root . '/api.php');
check('admin/iconpacks is routed and, like every Settings endpoint, in no panel permission (the owner\'s own)',
      str_contains($api, "'admin/iconpacks'            => 'api/admin/iconpacks.php'") && adminEndpointPermissionOf('admin/iconpacks', $api) === null);

/* ══ 11. the owner's Pro packages, when they are here ═════════════════════════ */
// Found by looking, not by name: every folder under TRACKER_FA_PRO_DIR with a css/ and a webfonts/
// is a package, and what the import should make of it is read off the folder itself — its
// stylesheets, which of them are cores, compatibility sheets or styles (a style declares a font face).
$proDir = getenv('TRACKER_FA_PRO_DIR') ?: 'C:\\Users\\Dominik\\Desktop\\tracker\\Fonts';
$proFolders = [];
foreach (is_dir($proDir) ? (glob($proDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: []) : [] as $d) {
    if (is_dir($d . '/css') && is_dir($d . '/webfonts')) {
        $hdr = iconpackCssHeader(substr((string)@file_get_contents(is_file($d . '/css/fontawesome.css') ? $d . '/css/fontawesome.css' : $d . '/css/all.css'), 0, 600));
        if ($hdr !== null && $hdr['edition'] === 'pro') $proFolders[$hdr['version']] = $d;
    }
}
ksort($proFolders);
if (!$proFolders) {
    echo "SKIP the owner's Pro packages: TRACKER_FA_PRO_DIR is not set and $proDir holds no Font Awesome Pro folder\n";
} else {
    echo 'NOTE the owner\'s Pro packages found under ' . $proDir . ': ' . implode(', ', array_map(fn($v, $d) => "$v (" . basename($d) . ')', array_keys($proFolders), $proFolders)) . "\n";
    foreach ($proFolders as $v => $folder) {
        $base = basename($folder);
        // Zipped into the temp directory, the folder inside — the shape a download is uploaded in.
        $zipPath = $tmp('pro-' . $v . '.zip');
        $z = new ZipArchive();
        $z->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) $z->addFile($f->getPathname(), $base . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($folder) + 1)));
        $z->close();
        // What the folder holds, read directly.
        $sheets = array_map('basename', glob($folder . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . '*.css') ?: []);
        $extras = array_values(array_filter($sheets, fn($s) => (bool)preg_match('/^(v4-shims|v4-font-face|v5-font-face|svg|svg-with-js|conflict-detection|custom-icons)(\.min)?\.css$/', $s)));
        $cores = array_values(array_filter($sheets, fn($s) => (bool)preg_match('/^(all|fontawesome)(\.min)?\.css$/', $s)));
        $styleSheets = array_values(array_filter(array_diff($sheets, $extras, $cores),
            fn($s) => str_contains((string)file_get_contents($folder . '/css/' . $s), '@font-face')));
        $t0 = microtime(true);
        $r = $import($zipPath, ['kind' => 'upload', 'name' => $base . '.zip']);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        @unlink($zipPath);
        $pm = !empty($r['ok']) ? iconpackManifest((string)$r['id']) : null;
        $pStyles = $pm ? array_column($pm['styles'], null, 'key') : [];
        $keptCss = array_values(array_filter(array_keys($pm['files'] ?? []), fn($p) => str_starts_with($p, 'css/')));
        $notesP = array_column($r['skipped_notes'] ?? [], 'why', 'path');
        check("Pro $v ($base), zipped: installed as Font Awesome Pro $v, found inside its folder ($ms ms)", !empty($r['ok']) && ($pm['edition'] ?? '') === 'pro'
              && ($pm['version'] ?? '') === $v && ($r['root'] ?? '') === $base, json_encode([$r['error'] ?? null, $r['detail'] ?? null]));
        check("Pro $v: " . count($sheets) . ' stylesheets on disk — ' . implode(' + ', $cores) . ', ' . count($styleSheets) . ' style files, ' . count($extras)
              . ' compatibility / JS-build sheets (' . implode(', ', $extras) . '): every style file is a style, the cores kept, the rest left out with a reason',
              count($keptCss) === count($sheets) - count($extras)
              && !array_diff(array_map(fn($s) => (string)preg_replace('/(\.min)?\.css$/', '', $s), $styleSheets), array_keys($pStyles))
              && !array_filter($extras, fn($s) => !in_array($notesP['css/' . $s] ?? '', ['compat', 'svg_build'], true)),
              count($keptCss) . ' kept, styles ' . count($pStyles) . ' ' . json_encode($notesP));
        check("Pro $v: the core is " . (in_array('fontawesome.css', $cores, true) ? 'fontawesome.css' : 'all.css') . ', and every Sharp style is outside all.css',
              ($pm['core']['kind'] ?? '') === (in_array('fontawesome.css', $cores, true) ? 'fontawesome' : 'all')
              && !array_filter($pStyles, fn($s) => str_starts_with((string)$s['key'], 'sharp') && !empty($s['in_all']))
              && (bool)array_filter($pStyles, fn($s) => str_starts_with((string)$s['key'], 'sharp')));
        // Which styles all.css covers, read a second way: a style file is in all.css when every face it
        // declares (family + weight) is declared there too. The families 7.x added (Jelly, Slab, Notdog,
        // Thumbprint, …) are never in it.
        $facesOf = function (string $css): array {
            preg_match_all('/@font-face\s*\{([^}]*)\}/', $css, $fm);
            $out = [];
            foreach ($fm[1] as $body) {
                if (preg_match('/font-family:\s*["\']?([^;"\']+)/', $body, $a) && preg_match('/font-weight:\s*(\d+)/', $body, $b)) $out[] = trim($a[1]) . '|' . $b[1];
            }
            return $out;
        };
        $allFaces = $facesOf((string)@file_get_contents($folder . '/css/all.css'));
        $inAllWrong = []; $outside = [];
        foreach ($styleSheets as $s) {
            $k = (string)preg_replace('/(\.min)?\.css$/', '', $s);
            $f = $facesOf((string)file_get_contents($folder . '/css/' . $s));
            $derived = $f && !array_diff($f, $allFaces);
            if (!$derived) $outside[] = $k;
            if (isset($pStyles[$k]) && (bool)!empty($pStyles[$k]['in_all']) !== $derived) $inAllWrong[] = $k;
        }
        $newFamiliesInAll = array_keys(array_filter($pStyles, fn($s) => !in_array((string)$s['family'], ['classic', 'duotone', 'sharp', 'sharp-duotone', 'brands'], true) && !empty($s['in_all'])));
        check("Pro $v: " . count($outside) . ' of its ' . count($styleSheets) . ' style files are outside all.css, read off the faces'
              . ((int)$v[0] === 7 ? ', every family 7.x added among them' : '') . ', as the manifest says',
              $inAllWrong === [] && $newFamiliesInAll === [], json_encode(['wrong' => $inAllWrong, 'new_in_all' => $newFamiliesInAll, 'outside' => $outside]));
        $names = $pm ? iconpackNamesOf((string)$pm['id']) : [];
        // The names counted a second way, straight off the core and brands sheets, both quote styles.
        $raw = (string)@file_get_contents($folder . '/css/' . (in_array('fontawesome.css', $cores, true) ? 'fontawesome.css' : 'all.css'))
             . (string)@file_get_contents($folder . '/css/brands.css');
        preg_match_all('/((?:\.fa-[a-z0-9-]+,?)+)\{--fa:["\']/', $raw, $rm);
        $rawNames = [];
        foreach ($rm[1] as $list) foreach (explode(',', $list) as $s) if ($s !== '') $rawNames[substr($s, 4)] = true;
        $missing = [];
        foreach (iconFaEntries() as $bi => $e) {
            foreach ([$e[0], $e['v7'] ?? null, $e['pro'] ?? null, (int)$v[0] === 6 ? ($e['pro6'] ?? null) : ($e['pro7'] ?? null)] as $nm) {
                if ($nm !== null && !isset($names[$nm])) $missing[] = "$bi:$nm";
            }
        }
        check("Pro $v: " . count($names) . ' names with their aliases (counted a second way: ' . count($rawNames) . '), and every name the map asks of this version is there',
              count($names) === count($rawNames) && count($names) > 4000 && $missing === [], implode(', ', $missing));
        // Font Awesome's own metadata (icon-families.json / icons.json) is described as the edition it is,
        // and so (1.69.0) is the owner's own index, icons-search-vX.Y.Z.json — one entry per icon with its
        // families, recognised by that shape: a Pro package's index describes Pro. Anything else in
        // metadata/ is kept and described as unknown; a root manifest.json (the download script's list of
        // what it fetched) is not part of the package and is skipped.
        $metaFiles = array_map(fn($p) => 'metadata/' . basename($p), glob($folder . '/metadata/*.{json,yml,yaml}', GLOB_BRACE) ?: []);
        $faMeta = is_file($folder . '/metadata/icon-families.json') || is_file($folder . '/metadata/icons.json');
        $ownIdxFile = (glob($folder . '/metadata/icons-search*.json') ?: [])[0] ?? null;
        check("Pro $v: what its metadata describes is recorded (" . ($pm['metadata']['describes'] ?? '?') . '), every metadata file kept'
              . (is_file($folder . '/manifest.json') ? ', the root manifest.json skipped' : ''),
              (is_dir($folder . '/metadata') ? (($faMeta || $ownIdxFile !== null) ? in_array($pm['metadata']['describes'] ?? '', ['free', 'pro'], true) : ($pm['metadata']['describes'] ?? '') === 'unknown')
                                              : ($pm['metadata']['describes'] ?? '') === 'none')
              && !array_filter($metaFiles, fn($p) => !preg_match('#^metadata/(sponsors|shims)\.#i', $p) && !isset($pm['files'][$p]))
              && (!is_file($folder . '/manifest.json') || (($r['skipped']['manifest.json'] ?? 0) === 1 && !isset($pm['files']['manifest.json']))),
              json_encode(['describes' => $pm['metadata']['describes'] ?? null, 'meta' => $metaFiles, 'skipped' => $r['skipped'] ?? null]));
        // The owner's index, read here straight from his file (1.69.0) and held against what the import
        // made of it: every icon it describes, the `emoji` category the shoutbox draws its faces from, and
        // — where the package has a partial family (7.x's Jelly draws a few hundred icons) — which of the
        // site-icon map's icons that family lacks, which the map now falls back from by knowledge.
        if ($ownIdxFile !== null && !$faMeta && $pm) {
            $own = json_decode((string)file_get_contents($ownIdxFile), true) ?: [];
            $ownNames = array_map('strval', array_keys($own));
            $cssN = iconpackNamesOf((string)$pm['id']);
            $ownEmoji = array_values(array_filter($ownNames, fn($nm) => in_array('emoji', (array)($own[$nm]['categories'] ?? []), true) && isset($cssN[$nm])));
            sort($ownEmoji, SORT_STRING);
            $emo = iconpackEmojiOf((string)$pm['id']);
            $emoNames = $emo ? array_map('strval', array_keys($emo['faces'])) : [];
            sort($emoNames, SORT_STRING);
            $ix = iconpackIndexOf((string)$pm['id']);
            check("Pro $v: the owner's index is the package's own — " . count($own) . ' icons, ' . count($ownEmoji) . ' of them in its emoji category — kept as index.json and emoji.json',
                  ($pm['metadata']['kind'] ?? '') === 'icons-search' && ($pm['metadata']['describes'] ?? '') === 'pro' && (int)$pm['metadata']['icons'] === count($own)
                  && (int)$pm['metadata']['emoji'] === count($ownEmoji) && $emoNames === $ownEmoji
                  && $ix !== null && count($ix['styles']) === count(array_filter($ownNames, fn($nm) => isset($cssN[$nm]))),
                  json_encode(['kind' => $pm['metadata']['kind'] ?? null, 'icons' => $pm['metadata']['icons'] ?? null, 'emoji' => $pm['metadata']['emoji'] ?? null, 'want' => count($ownEmoji)]));
            $ownFace = $ownEmoji[0] ?? '';
            $famsOf = function (string $nm) use ($own): array {
                $k = [];
                foreach ((array)($own[$nm]['families'] ?? []) as $fam => $sts) foreach ((array)$sts as $st) $k[] = iconpackStyleKey((string)$fam, (string)$st);
                sort($k);
                return $k;
            };
            $emoSet = $emo && $ownFace !== '' ? (array)$emo['sets'][(int)$emo['faces'][$ownFace][2]] : [];
            sort($emoSet);
            check("Pro $v: a face's styles in emoji.json are the ones the owner's index gives it ($ownFace: " . count($famsOf($ownFace)) . ')',
                  $ownFace !== '' && $emoSet === $famsOf($ownFace), json_encode([$emoSet, $famsOf($ownFace)]));
            if (isset($pStyles['jelly-regular'])) {
                $sJ = iconSetup($cfgPack((string)$pm['id'], ['jelly-regular'], 'jelly-regular'));
                $xJ = iconFaMap($sJ, true);
                $wrongJ = []; $lackJ = 0;
                foreach ($xJ['why'] as $bi => $w) {
                    if ($w['role'] !== 's' || !isset($own[$w['name']])) continue;
                    $has = in_array('jelly-regular', $famsOf($w['name']), true);
                    if (!$has) $lackJ++;
                    $want = $has ? 'jelly-regular' : 'regular';
                    if ($w['style'] !== $want) $wrongJ[] = $bi . ':' . $w['name'] . ' drawn ' . $w['style'] . ', the index says ' . $want;
                }
                check("Pro $v, Jelly Regular: every icon that follows the style is drawn in Jelly where the owner's index says Jelly has it, and in classic regular where it does not ($lackJ of them) — by knowledge, not the font chain",
                      $wrongJ === [] && $lackJ > 0, implode(' | ', array_slice($wrongJ, 0, 6)));
            }
        }
        // The Free-regular list and the twins, held to the edition's own metadata, when the folder has it.
        $famj = json_decode((string)@file_get_contents($folder . '/metadata/icon-families.json'), true);
        if (is_array($famj)) {
            $freeReg = []; $freeAll = [];
            foreach ($famj as $nm => $e2) {
                $st = array_map(fn($fs) => $fs['family'] . '/' . $fs['style'], (array)($e2['familyStylesByLicense']['free'] ?? []));
                foreach (array_merge([(string)$nm], (array)($e2['aliases']['names'] ?? [])) as $al) { $freeAll[$al] = true; if (in_array('classic/regular', $st, true)) $freeReg[$al] = true; }
            }
            $wrongReg = [];
            foreach (iconFaEntries() as $e2) if (isset($freeReg[$e2[0]]) !== in_array($e2[0], iconFreeRegular(), true)) $wrongReg[] = $e2[0];
            $twinsFree = array_values(array_filter(array_map(fn($e2) => $e2['pro'] ?? ($e2['pro7'] ?? null), iconFaEntries()), fn($t) => $t !== null && $t !== 'server' && isset($freeAll[$t])));
            check("Pro $v's metadata: iconFreeRegular() is exactly the map's names Free has in regular, and no Pro twin is a Free icon",
                  array_values(array_unique($wrongReg)) === [] && $twinsFree === [], implode(',', $wrongReg) . ' | ' . implode(',', $twinsFree));
        }
        $sP = iconSetup($cfgPack((string)$pm['id'], [], 'solid'));
        $covP = iconMapCoverage($sP);
        // What a page links with nothing ticked, read off the folder: a full download's fontawesome.css
        // with the three styles that always come with it, or all.css alone.
        $wantCss = in_array('fontawesome.css', $cores, true)
            ? array_values(array_filter(['css/fontawesome.css', 'css/solid.css', 'css/regular.css', 'css/brands.css'], fn($p) => is_file($folder . '/' . $p)))
            : ['css/all.css'];
        $gotCss = array_map(fn($c) => (string)preg_replace('/.*&f=/', '', $c['href']), $sP['css'] ?? []);
        check("Pro $v: with solid a page links " . implode(' + ', array_map('basename', $wantCss)) . '; no fallback, ' . $covP['twins'] . ' Pro twins, ' . $covP['approx'] . ' approximations left',
              $gotCss === $wantCss && $covP['fallbacks'] === [] && $covP['twins'] >= 17, json_encode([$gotCss, $covP['fallbacks']]));
        if (empty($r['already'])) {
            $d = iconpackDelete((string)$pm['id'], getSettings($db, true));
            check("Pro $v: deleted again", !empty($d['ok']) && !is_dir(iconpackDir() . '/' . $pm['id']));
        } else {
            echo "NOTE Pro $v was already installed before this run ($r[id]) and is left as it was\n";
        }
    }
}

/* ══ 12. the index, and Font Awesome's faces drawn from it (1.69.0) ═══════════ */
// A synthetic Pro package in the shape of the owner's index (metadata/icons-search-vX.Y.Z.json: one entry
// per icon — label, categories, search terms, families), with six faces the shoutbox knows and a partial
// family (Jelly has every third icon). What the import keeps, what the site-icon map does with a family
// that lacks a glyph, and what a :fa-NAME: token becomes — face, style, fallback — with Font Awesome on,
// off, and gone. The same faces in Font Awesome's own format (icon-families.json + categories.yml), and
// under Free metadata, which a Pro package does not let speak for its styles.
require_once $root . '/includes/richtext.php';
$faceNames = ['face-grin', 'face-smile', 'face-grin-tears', 'face-meh', 'face-angry', 'face-melting'];
$synNames = array_values(array_unique(array_merge(array_values(array_diff(iconFaCandidateNames(), ['youtube'])), $faceNames)));
$sp = $import(iptWriteDir($tmp('search'), iptPackage(['version' => '7.9.6', 'metadata' => 'search', 'faces' => $faceNames,
                                                            'extra' => ['sharp-solid', 'jelly-regular', 'duotone']])));
$sid = (string)($sp['id'] ?? '');
$sm = $sid !== '' ? iconpackManifest($sid) : null;
check('the owner\'s index shape is recognised whatever the file is called: Pro, every icon, four families, six emoji',
      !empty($sp['ok']) && ($sm['metadata']['kind'] ?? '') === 'icons-search' && ($sm['metadata']['describes'] ?? '') === 'pro'
      && ($sm['metadata']['index'] ?? '') === 'metadata/icons-search-v7.9.6.json' && (int)$sm['metadata']['icons'] === count($synNames)
      && (int)$sm['metadata']['families'] === 4 && (int)$sm['metadata']['emoji'] === 6 && !empty($sm['metadata']['pro_styles']),
      json_encode($sm['metadata'] ?? $sp));
$sDir = iconpackDir() . DIRECTORY_SEPARATOR . $sid;
check('… kept (the file itself, never served) and read into index.json and emoji.json beside the manifest; the manifest knows the map\'s names\' styles',
      isset($sm['files']['metadata/icons-search-v7.9.6.json']) && is_file($sDir . '/index.json') && is_file($sDir . '/emoji.json')
      && !isset($sm['files']['index.json']) && isset($sm['present']['sets'], $sm['present']['styles'])
      && count($sm['present']['styles']) === count(array_intersect($sm['present']['names'], $synNames)),
      json_encode(['files' => array_keys($sm['files'] ?? []), 'present' => array_map(fn($x) => is_array($x) ? count($x) : $x, $sm['present'] ?? [])]));
check('… the package id is its files\', not its index\'s: the sidecars are not in the hash', iconpackVerify($sid)['ok'] ?? false);
$cfgS = fn(array $extra = []) => $extra + ['icon_library' => 'fontawesome', 'fa_source' => 'pack', 'fa_pack' => $sid,
                                         'fa_pack_styles' => '["duotone","jelly-regular","sharp-solid"]', 'fa_style' => 'solid',
                                         'shout_emoji_fa' => 'mixed', 'shout_emoji_fa_style' => 'duotone'];
// The map: Jelly draws what the index gives it, and the rest in classic at the same weight — by knowledge.
$xJ = iconFaMap(iconSetup($cfgS(['fa_style' => 'jelly-regular'])), true);
$wrongJ = []; $inJ = 0; $outJ = 0;
foreach ($xJ['why'] as $bi => $w) {
    if ($w['role'] !== 's') continue;
    $pos = array_search($w['name'], $synNames, true);
    if ($pos === false) continue;
    $want = $pos % 3 === 0 ? 'jelly-regular' : 'regular';
    $want === 'jelly-regular' ? $inJ++ : $outJ++;
    if ($w['style'] !== $want || ($want === 'regular' && ($w['note']['why'] ?? '') !== 'style_lacks')) $wrongJ[] = "$bi ({$w['name']}): {$w['style']} " . json_encode($w['note']);
}
check("with Jelly Regular chosen, an icon Jelly has is drawn in Jelly ($inJ) and one it lacks in classic regular, noted as a fallback ($outJ)",
      $wrongJ === [] && $inJ > 0 && $outJ > 0, implode(' | ', array_slice($wrongJ, 0, 4)));
// The faces.
$ctx = emojiFaContext($cfgS());
$grinPos = (int)array_search('face-grin', $synNames, true);
$wantV = array_values(array_unique(array_merge(['duotone'], array_values(array_intersect(array_keys(iconSetup($cfgS())['styles']),
         array_merge(['solid', 'regular', 'light', 'duotone', 'sharp-solid'], $grinPos % 3 === 0 ? ['jelly-regular'] : []))))));
check('with the package the site\'s icon source and the faces on (mixed): the six faces, the default style first, then every loaded style the index gives the face',
      $ctx['available'] && $ctx['mode'] === 'mixed' && $ctx['style'] === 'duotone' && $ctx['known'] && count($ctx['faces']) === 6
      && ($ctx['faces']['face-grin'][2] ?? null) === $wantV && ($ctx['faces']['face-grin'][0] ?? '') === 'Face Grin',
      json_encode(['style' => $ctx['style'], 'faces' => array_keys($ctx['faces']), 'grin' => $ctx['faces']['face-grin'] ?? null, 'want' => $wantV]));
$r = fn(string $text, array $extra = [], string $lang = 'en') => emojiFaRenderHtml(htmlspecialchars($text), $cfgS($extra), $lang);
check('a token is the face, in the default style, as an <i> with role="img" and its label',
      $r(':fa-face-grin:') === '<i class="fae fa-duotone fa-solid fa-face-grin" role="img" aria-label="Face Grin" title="Face Grin"></i>', $r(':fa-face-grin:'));
check('… in the style it names, when that style loads and the index gives it the face',
      $r(':fa-face-grin/light:') === '<i class="fae fa-light fa-face-grin" role="img" aria-label="Face Grin" title="Face Grin"></i>'
      && $r(':fa-face-grin/sharp-solid:') === '<i class="fae fa-sharp fa-solid fa-face-grin" role="img" aria-label="Face Grin" title="Face Grin"></i>', $r(':fa-face-grin/light:'));
$faFaces = emojiFaFaces()['faces'];
check('… and its label in the reader\'s language: Polish from this project\'s words',
      $r(':fa-face-grin:', [], 'pl') === '<i class="fae fa-duotone fa-solid fa-face-grin" role="img" aria-label="' . $faFaces['face-grin'][2] . '" title="' . $faFaces['face-grin'][2] . '"></i>', $r(':fa-face-grin:', [], 'pl'));
check('a style that does not load, or that the face is not drawn in, is the ordinary emoji the face stands for',
      $r(':fa-face-grin/thin:') === $faFaces['face-grin'][1] && $r(':fa-face-grin/brands:') === $faFaces['face-grin'][1]
      && $r(':fa-face-grin/nonsense-style:') === $faFaces['face-grin'][1], $r(':fa-face-grin/thin:'));
check('a face this package does not have is its emoji; a name that is no face is the text as typed',
      $r(':fa-face-zany:') === $faFaces['face-zany'][1] && $r(':fa-rocket:') === ':fa-rocket:' && $r(':fa-gear/solid:') === ':fa-gear/solid:');
check('Font Awesome\'s faces switched off, the icon library Bootstrap, the package gone: the emoji, never nothing',
      $r(':fa-face-grin:', ['shout_emoji_fa' => 'off']) === $faFaces['face-grin'][1]
      && $r(':fa-face-grin:', ['icon_library' => 'bootstrap']) === $faFaces['face-grin'][1]
      && $r(':fa-face-grin:', ['fa_pack' => 'fa-pro-9.9.9-deadbeef']) === $faFaces['face-grin'][1]
      && $r(':fa-face-grin:', ['fa_source' => 'cdn7']) === $faFaces['face-grin'][1]);
$hostile = [':fa-face-grin"><script>x</script>:', ':fa-face-grin/solid" onmouseover="x:', ':FA-face-grin:', ':fa-face--grin:', ':fa-face_grin:'];
$hostOut = array_map(fn($h) => $r($h), $hostile);
check('hostile or malformed tokens are text: no attribute, no tag, nothing drawn',
      !array_filter($hostOut, fn($o) => str_contains($o, '<i') || str_contains($o, '<script') || str_contains($o, '" on')), json_encode($hostOut));
check('the renderer walks text only: a token inside an attribute or a code element is left as it is',
      $r('') === '' && emojiFaRenderHtml('<a href="https://x.org/:fa-face-grin:">:fa-face-grin:</a><code>:fa-face-grin:</code>', $cfgS(), 'en')
         === '<a href="https://x.org/:fa-face-grin:"><i class="fae fa-duotone fa-solid fa-face-grin" role="img" aria-label="Face Grin" title="Face Grin"></i></a><code>:fa-face-grin:</code>');
// A line that is nothing but faces is a paragraph like one that is nothing but pictures: the paragraph
// pass dropped every chunk with no text in it but an <img>, and a shout of one face came out empty.
check('a line of nothing but faces is drawn (the paragraph pass once dropped it as empty)',
      richtextRender(':fa-face-grin: :fa-face-meh:', 'bbcode', $cfgS()) === '<p><i class="fae fa-duotone fa-solid fa-face-grin" role="img" aria-label="Face Grin" title="Face Grin"></i> '
                                                                       . '<i class="fae fa-duotone fa-solid fa-face-meh" role="img" aria-label="Face Meh" title="Face Meh"></i></p>',
      richtextRender(':fa-face-grin: :fa-face-meh:', 'bbcode', $cfgS()));
check('… through the shared renderer too: a description, a message and a shout draw it; a mail gets the emoji',
      str_contains(richtextRender('hi :fa-face-grin: [code]:fa-face-grin:[/code]', 'bbcode', $cfgS()), '<i class="fae fa-duotone fa-solid fa-face-grin"')
      && str_contains(richtextRender('hi :fa-face-grin: [code]:fa-face-grin:[/code]', 'bbcode', $cfgS()), '<code>:fa-face-grin:</code>')
      && str_contains(richtextRenderForEmail('hi :fa-face-grin:', 'bbcode', $cfgS()), $faFaces['face-grin'][1])
      && !str_contains(richtextRenderForEmail('hi :fa-face-grin:', 'bbcode', $cfgS()), '<i'));
$cd = emojiFaClientData($cfgS(), 'pl');
$cdGrin = array_values(array_filter($cd['faces'], fn($f) => $f['n'] === 'face-grin'))[0] ?? [];
check('what the picker is told: the mode, the default style, each loaded style with its classes, the pages that have faces, each face with its variants, fallback and words (Polish, then English)',
      $cd['mode'] === 'mixed' && $cd['style'] === 'duotone' && ($cd['styles']['duotone']['classes'] ?? '') === 'fa-duotone fa-solid' && !isset($cd['styles']['brands'])
      && array_column($cd['pages'], 'id') === ['fa-happy', 'fa-calm', 'fa-cross'] && count($cd['faces']) === 6
      && ($cdGrin['v'] ?? null) === $wantV && ($cdGrin['e'] ?? '') === $faFaces['face-grin'][1] && ($cdGrin['l'] ?? '') === $faFaces['face-grin'][2]
      && str_contains((string)($cdGrin['k'] ?? ''), 'radość') && str_contains((string)($cdGrin['k'] ?? ''), 'face grin'),
      json_encode(['pages' => $cd['pages'], 'grin' => $cdGrin]));
check('… and with the faces off it is told nothing', emojiFaClientData($cfgS(['shout_emoji_fa' => 'off']), 'en') === ['mode' => 'off', 'style' => 'duotone', 'known' => true, 'styles' => [], 'pages' => [], 'faces' => []]);
// Font Awesome's own format, and Free metadata that may not speak for a Pro package's styles.
$fp = $import(iptWriteDir($tmp('famfaces'), iptPackage(['version' => '7.9.7', 'metadata' => 'pro', 'faces' => $faceNames, 'extra' => ['duotone', 'sharp-solid']])));
$fid = (string)($fp['id'] ?? '');
$fctx = emojiFaContext(['icon_library' => 'fontawesome', 'fa_source' => 'pack', 'fa_pack' => $fid, 'fa_pack_styles' => '["duotone","sharp-solid"]', 'fa_style' => 'solid', 'shout_emoji_fa' => 'fa']);
check('Font Awesome\'s own metadata: the faces are categories.yml\'s emoji, their styles icon-families.json\'s (free and Pro)',
      !empty($fp['ok']) && (iconpackManifest($fid)['metadata']['kind'] ?? '') === 'icon-families' && $fctx['known'] && count($fctx['faces']) === 6
      && ($fctx['faces']['face-grin'][2] ?? null) === ['solid', 'light', 'duotone'], json_encode([$fctx['faces']['face-grin'] ?? null, iconpackManifest($fid)['metadata'] ?? null]));
$ff = $import(iptWriteDir($tmp('freefaces'), iptPackage(['version' => '7.9.8', 'metadata' => 'free', 'faces' => $faceNames, 'extra' => ['duotone', 'sharp-solid', 'jelly-regular']])));
$ffid = (string)($ff['id'] ?? '');
$ffm = iconpackManifest($ffid);
$ffctx = emojiFaContext(['icon_library' => 'fontawesome', 'fa_source' => 'pack', 'fa_pack' => $ffid, 'fa_pack_styles' => '["duotone","sharp-solid","jelly-regular"]', 'fa_style' => 'solid', 'shout_emoji_fa' => 'fa']);
check('Free metadata on a Pro package does not speak for its styles: no knowledge for the map, the faces this project knows in the full families',
      !empty($ff['ok']) && ($ffm['metadata']['describes'] ?? '') === 'free' && iconPackStyleKnowledge($ffm) === null && !isset($ffm['present']['sets'])
      && !$ffctx['known'] && count($ffctx['faces']) === 6 && ($ffctx['faces']['face-grin'][2] ?? null) === ['solid', 'regular', 'light', 'duotone', 'sharp-solid'],
      json_encode([$ffctx['faces']['face-grin'] ?? null, $ffm['metadata'] ?? null]));

/** The permission api.php's map gives an admin endpoint, read from the source (the map is a function-local static). */
function adminEndpointPermissionOf(string $endpoint, string $apiSrc): ?string {
    return preg_match("#'" . preg_quote($endpoint, '#') . "'\s*=>\s*'(panel\.[a-z._]+)'#", $apiSrc, $m) ? $m[1] : null;
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
