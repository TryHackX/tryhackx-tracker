<?php
/**
 * Font Awesome packages the operator installs (1.69.0): the import, the checks, the store, and what
 * the serving endpoint (iconpack.php) needs to answer a request.
 *
 * WHY THIS EXISTS. Font Awesome Free comes from jsDelivr, pinned and with Subresource Integrity
 * (includes/icons.php). Pro cannot: it is licensed to the site that bought it and has no public CDN.
 * So the operator brings their own copy — a zip uploaded in Settings → Site, a directory named on the
 * server, or `php tools/iconpack.php import …` — and this file turns whatever they brought into a
 * package the site can serve. ONE pipeline for the three doors, so the panel, the path and the shell
 * cannot disagree about what a package is.
 *
 * WHAT A PACKAGE IS, however it was packed. Font Awesome's download holds `css/` and `webfonts/`
 * (and, in a full download, `metadata/`, `svgs/`, `js/`, `sprites/`, `less/`, `scss/`…); the operator
 * may zip that folder, or its contents, or the folder inside two more. The ROOT is the one directory,
 * at any depth, whose `css/` holds a Font Awesome stylesheet and which has a `webfonts/` beside it.
 * Two such directories is two packages in one archive, and that is refused rather than guessed at.
 * From the root only `css/*.css`, `webfonts/*.{woff2,woff,ttf,otf}`, `metadata/*.{json,yml,yaml}`
 * and a licence text are kept; everything else is skipped and said so.
 *
 * NOTHING IN A PACKAGE IS TRUSTED because of where it came from. Every entry name is checked before
 * anything is written (no `..`, no absolute path, no drive letter, no backslash, no control
 * character, no symlink, no two names that differ only in case); sizes, counts and the compression
 * ratio are bounded before a byte is inflated, and the bytes actually read are bounded again because
 * an archive's own size fields can lie; a font must begin with its format's signature; a stylesheet
 * must say it is Font Awesome, with an edition and a version, and may reference nothing but font
 * files of the package's own `webfonts/` — no `@import`, no other URL, no `expression(`,
 * `behavior:` or `javascript:`, no escaped identifier that could spell one. A package that fails any
 * of it is refused whole: a tampered file is a reason not to trust its neighbours either.
 *
 * AND LOADING TWO FILES MUST NOT CHANGE WHAT A THIRD DRAWS. Every style file sets rules for its own
 * family, and a few also set one rule for everybody (`.fa-regular{--fa-style:400}` in the 7.x Jelly
 * file). Those are allowed only when they say exactly what the core already says; a style file may not
 * redefine another family's variables, nor declare a face another file already declares with other
 * font files — that is how the site's own icons are kept what they were when a new style is ticked.
 *
 * Extraction goes to a temporary directory beside the store, is checked there, gets its manifest,
 * and only then is renamed into place, so a package appears whole or not at all.
 *
 * The store is `config/iconpacks/` — writable by the web server, refused over HTTP (config/.htaccess),
 * outside every deploy, and in .gitignore: the owner's Pro packages are licensed copies and must never
 * reach the repository. Packages are re-installable from their source, so nothing here is backed up.
 *
 * This file needs nothing else of the application: iconpack.php (the endpoint) includes it alone,
 * with no session and no database, and so can the tests.
 */

/** Bumped when the manifest's shape changes; a package written by another format is re-imported. */
const ICONPACK_FORMAT = 1;
/**
 * Part of every asset URL (`v=<hash16>-<rev>`): the CSS is rewritten as it is served, and a change to
 * that rewrite must reach browsers that cached last year's output for a year, immutable.
 */
const ICONPACK_SERVE_REV = '1';

// Limits. What a real package needs, with room: the Pro 7.3.1 download is 41 stylesheets (all.css the
// biggest, 175 KB), 38 fonts (the biggest woff2 600 KB), 7.5 MB; Pro 6.7.2 is 23 stylesheets and 36
// fonts (a .ttf beside each .woff2, the biggest 2.2 MB), 31 MB; and a full download may carry metadata
// files of several megabytes (Free's icon-families.json alone is 5.4 MB). A full "web" download also
// carries ~100 000 SVGs and scripts, which are never extracted, only listed — hence a high entry
// ceiling and a low kept-file ceiling.
const ICONPACK_ZIP_MAX_ENTRIES   = 250000;       // entries in the archive / files under the directory
const ICONPACK_MAX_KEPT_FILES    = 600;          // what is actually kept
const ICONPACK_MAX_CSS_BYTES     = 4194304;      // 4 MiB per stylesheet
const ICONPACK_MAX_FONT_BYTES    = 8388608;      // 8 MiB per font
const ICONPACK_MAX_META_BYTES    = 16777216;     // 16 MiB per metadata file; bigger is skipped, not refused
const ICONPACK_MAX_LICENSE_BYTES = 262144;       // 256 KiB of licence text
const ICONPACK_MAX_TOTAL_BYTES   = 167772160;    // 160 MiB kept, uncompressed
// Uncompressed ÷ compressed, above 1 MiB. Font Awesome's CSS packs about 5:1 and its JSON about 10:1;
// a zip bomb is hundreds or thousands to one.
const ICONPACK_MAX_RATIO         = 100;
const ICONPACK_RATIO_FLOOR       = 1048576;
const ICONPACK_MAX_DEPTH         = 16;           // how deep the root may be
const ICONPACK_MAX_PACKAGES      = 20;           // installed at once
const ICONPACK_ID_RE             = '/^fa-(free|pro)-\d{1,3}\.\d{1,3}\.\d{1,4}-[0-9a-f]{8}$/';

/** The four weights Font Awesome names, and semibold (the 7.x Utility and Whiteboard families). */
function iconpackStyleWeights(): array {
    return ['solid' => 900, 'semibold' => 600, 'regular' => 400, 'light' => 300, 'thin' => 100];
}

/** Where installed packages live. */
function iconpackDir(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'iconpacks';
}

function iconpackValidId(string $id): bool {
    return (bool)preg_match(ICONPACK_ID_RE, $id);
}

/* ── names ────────────────────────────────────────────────────────────────────── */

/**
 * Why a path from an archive or a directory walk may not be written anywhere: null when it is fine.
 *
 * Asked of EVERY entry, the ones that would be skipped too — an archive that carries a `../` or an
 * absolute name was built to do something, and there is no reason to go on reading it.
 */
function iconpackUnsafeName(string $name): ?string {
    if ($name === '' || strlen($name) > 1024) return 'length';
    if (str_contains($name, "\0")) return 'nul';
    if (preg_match('/[\x00-\x1F\x7F]/', $name)) return 'control';
    // PowerShell 5.1's Compress-Archive writes backslashes; the refusal says how to re-pack.
    if (str_contains($name, '\\')) return 'backslash';
    if ($name[0] === '/') return 'absolute';
    if (preg_match('/^[A-Za-z]:/', $name)) return 'drive';
    $trimmed = rtrim($name, '/');
    foreach (explode('/', $trimmed) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') return 'traversal';
    }
    return null;
}

/** The kind a kept file is, by its place under the root and its name; null = not kept. */
function iconpackKeptKind(string $rel): ?string {
    if (preg_match('#^css/[A-Za-z0-9._-]+\.css$#', $rel)) return 'css';
    if (preg_match('#^webfonts/[A-Za-z0-9._-]+\.(woff2|woff|ttf|otf)$#i', $rel)) return 'font';
    if (preg_match('#^metadata/[A-Za-z0-9._-]+\.(json|ya?ml)$#i', $rel)) {
        // The sponsors of the icons and the v4 name shims: nothing here or in the picker reads them.
        if (preg_match('#^metadata/(sponsors|shims)\.#i', $rel)) return null;
        return 'metadata';
    }
    if (preg_match('#^(LICENSE|LICENSE\.txt|LICENSE\.md|license\.txt|License\.txt)$#', $rel)) return 'license';
    return null;
}

/* ── reading a source ─────────────────────────────────────────────────────────── */

/**
 * Every entry of a zip or a directory, checked for its name and kind, WITHOUT reading any content.
 *
 * @return array{ok:bool, error?:string, detail?:string, entries?:array<int,array>, kind?:string}
 *   entries: [path, size, comp, dir(bool), index(int|null for a directory walk), abs(string|null)]
 */
function iconpackListSource(string $source): array {
    if (is_dir($source)) return iconpackListDir($source);
    if (!is_file($source)) return ['ok' => false, 'error' => 'not_found', 'detail' => $source];
    $fh = @fopen($source, 'rb');
    $magic = $fh ? (string)fread($fh, 4) : '';
    if ($fh) fclose($fh);
    if ($magic !== "PK\x03\x04" && $magic !== "PK\x05\x06") return ['ok' => false, 'error' => 'not_zip'];
    if (!class_exists('ZipArchive')) return ['ok' => false, 'error' => 'no_zip_support'];
    // The names as the archive itself spells them: libzip hands PHP a NUL in a name as a space, so
    // that one check reads the central directory directly — and a count that disagrees with
    // ZipArchive's is an archive two readers see differently, which is reason enough.
    $raw = iconpackZipRawNames($source);
    if (is_array($raw)) {
        foreach ($raw as $rn) {
            $why = iconpackUnsafeName($rn);
            if ($why !== null) return ['ok' => false, 'error' => 'unsafe_name', 'detail' => $why . ': ' . iconpackPrintable($rn)];
        }
    }
    $zip = new ZipArchive();
    if ($zip->open($source, ZipArchive::RDONLY) !== true) return ['ok' => false, 'error' => 'zip_open'];
    $n = $zip->numFiles;
    if ($n > ICONPACK_ZIP_MAX_ENTRIES) { $zip->close(); return ['ok' => false, 'error' => 'too_many_entries', 'detail' => (string)$n]; }
    if (is_array($raw) && count($raw) !== $n) { $zip->close(); return ['ok' => false, 'error' => 'zip_open']; }
    $entries = [];
    for ($i = 0; $i < $n; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) { $zip->close(); return ['ok' => false, 'error' => 'zip_open']; }
        $name = (string)$st['name'];
        $why = iconpackUnsafeName($name);
        if ($why !== null) { $zip->close(); return ['ok' => false, 'error' => 'unsafe_name', 'detail' => $why . ': ' . iconpackPrintable($name)]; }
        // A symlink entry: the Unix mode in the high half of the external attributes says S_IFLNK.
        $opsys = 0; $attr = 0;
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX
            && ((($attr >> 16) & 0170000) === 0120000)) {
            $zip->close();
            return ['ok' => false, 'error' => 'symlink', 'detail' => iconpackPrintable($name)];
        }
        $isDir = str_ends_with($name, '/');
        $entries[] = ['path' => rtrim($name, '/'), 'size' => (int)$st['size'], 'comp' => (int)$st['comp_size'],
                      'dir' => $isDir, 'index' => $i, 'abs' => null,
                      'encrypted' => ((int)($st['encryption_method'] ?? 0)) !== 0];
    }
    $zip->close();
    return ['ok' => true, 'kind' => 'zip', 'entries' => $entries];
}

/**
 * Every entry name in a zip's central directory, byte for byte — or null when the archive is one this
 * small reader does not follow (ZIP64, a split archive), in which case ZipArchive's view stands alone.
 */
function iconpackZipRawNames(string $path): ?array {
    $size = (int)@filesize($path);
    if ($size < 22) return null;
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    $tail = min($size, 65557);
    fseek($fh, $size - $tail);
    $buf = (string)fread($fh, $tail);
    $p = strrpos($buf, "PK\x05\x06");
    if ($p === false || strlen($buf) - $p < 22) { fclose($fh); return null; }
    $e = unpack('vdisk/vcddisk/ventries/vtotal/Vcdsize/Vcdoff', substr($buf, $p + 4, 16));
    if ($e['disk'] !== 0 || $e['total'] === 0xFFFF || $e['cdoff'] === 0xFFFFFFFF || $e['cdoff'] + $e['cdsize'] > $size) { fclose($fh); return null; }
    fseek($fh, $e['cdoff']);
    $cd = (string)fread($fh, $e['cdsize']);
    fclose($fh);
    $names = []; $o = 0; $len = strlen($cd);
    while ($o + 46 <= $len && substr($cd, $o, 4) === "PK\x01\x02") {
        $h = unpack('vname/vextra/vcomment', substr($cd, $o + 28, 6));
        $names[] = substr($cd, $o + 46, $h['name']);
        $o += 46 + $h['name'] + $h['extra'] + $h['comment'];
    }
    return count($names) === $e['total'] ? $names : null;
}

/** The directory half: a walk that never follows a link and refuses to meet one. */
function iconpackListDir(string $dir): array {
    $real = realpath($dir);
    if ($real === false || !is_dir($real)) return ['ok' => false, 'error' => 'not_found', 'detail' => $dir];
    if (!is_readable($real)) return ['ok' => false, 'error' => 'not_readable', 'detail' => $dir];
    // Importing the store into itself is never what anybody meant.
    $store = realpath(iconpackDir());
    if ($store !== false && (str_starts_with($real . DIRECTORY_SEPARATOR, $store . DIRECTORY_SEPARATOR))) {
        return ['ok' => false, 'error' => 'inside_store'];
    }
    $entries = [];
    $base = strlen($real) + 1;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::SELF_FIRST);
        $it->setMaxDepth(ICONPACK_MAX_DEPTH + 4);
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $rel = str_replace('\\', '/', substr($f->getPathname(), $base));
            if ($f->isLink()) return ['ok' => false, 'error' => 'symlink', 'detail' => iconpackPrintable($rel)];
            $why = iconpackUnsafeName($rel);
            if ($why !== null) return ['ok' => false, 'error' => 'unsafe_name', 'detail' => $why . ': ' . iconpackPrintable($rel)];
            if (count($entries) >= ICONPACK_ZIP_MAX_ENTRIES) return ['ok' => false, 'error' => 'too_many_entries', 'detail' => '>' . ICONPACK_ZIP_MAX_ENTRIES];
            $isDir = $f->isDir();
            $size = $isDir ? 0 : (int)$f->getSize();
            $entries[] = ['path' => $rel, 'size' => $size, 'comp' => $size, 'dir' => $isDir, 'index' => null,
                          'abs' => $f->getPathname(), 'encrypted' => false];
        }
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'not_readable', 'detail' => $dir];
    }
    return ['ok' => true, 'kind' => 'dir', 'entries' => $entries];
}

/** A name as it may be shown in a report: printable ASCII, the rest as \xNN, at most 200 characters. */
function iconpackPrintable(string $s): string {
    $out = (string)preg_replace_callback('/[^\x20-\x7E]/', fn($m) => sprintf('\\x%02X', ord($m[0])), $s);
    return strlen($out) > 200 ? substr($out, 0, 197) . '...' : $out;
}

/**
 * The package root: the one directory at any depth whose css/ holds a Font Awesome stylesheet and
 * which has a webfonts/ beside it. The css files are named here and read by the caller — the header
 * test needs bytes, and this function only has names.
 *
 * @return array<string,array{css:string[], fonts:int}>  root ('' = top) => its candidates
 */
function iconpackRootCandidates(array $entries): array {
    $css = []; $fonts = [];
    foreach ($entries as $e) {
        if ($e['dir']) continue;
        $p = $e['path'];
        // macOS resource forks and dot-files are never a package: `__MACOSX/x/css/._all.css` has the
        // same shape as the real thing and none of its bytes.
        if (preg_match('#(^|/)(__MACOSX|\.[^/]*)(/|$)#', $p)) continue;
        if (preg_match('#^(?:(.*)/)?css/([A-Za-z0-9._-]+\.css)$#', $p, $m)) {
            $root = $m[1];
            if (substr_count($root, '/') + ($root === '' ? 0 : 1) <= ICONPACK_MAX_DEPTH) $css[$root][] = $p;
        } elseif (preg_match('#^(?:(.*)/)?webfonts/[A-Za-z0-9._-]+\.(woff2|woff|ttf|otf)$#i', $p, $m)) {
            $fonts[$m[1]] = ($fonts[$m[1]] ?? 0) + 1;
        }
    }
    $out = [];
    foreach ($css as $root => $files) {
        if (!empty($fonts[$root])) $out[$root] = ['css' => $files, 'fonts' => $fonts[$root]];
    }
    return $out;
}

/** The first few hundred bytes of an entry, for the header test, bounded like everything else. */
function iconpackPeek(string $source, array $entry, int $bytes = 512): string {
    if ($entry['abs'] !== null) {
        $fh = @fopen($entry['abs'], 'rb');
        if (!$fh) return '';
        $s = (string)fread($fh, $bytes);
        fclose($fh);
        return $s;
    }
    $zip = new ZipArchive();
    if ($zip->open($source, ZipArchive::RDONLY) !== true) return '';
    $fh = $zip->getStreamIndex((int)$entry['index']);
    $s = $fh ? (string)fread($fh, $bytes) : '';
    if ($fh) fclose($fh);
    $zip->close();
    return $s;
}

/** Edition and version from a stylesheet's first comment, or null when it is not Font Awesome's. */
function iconpackCssHeader(string $head): ?array {
    if (!preg_match('#\A\xEF?\xBB?\xBF?\s*/\*!?[\s*]*Font Awesome (Free|Pro) (\d{1,3})\.(\d{1,3})\.(\d{1,4}) by @fontawesome#', $head, $m)) return null;
    return ['edition' => strtolower($m[1]), 'version' => $m[2] . '.' . $m[3] . '.' . $m[4], 'major' => (int)$m[2]];
}

/* ── the import ───────────────────────────────────────────────────────────────── */

/**
 * Install a package from a zip or a directory.
 *
 * $opts: kind ('upload' | 'path' | 'cli'), name (what the operator called it: the uploaded file's
 * name or the path), by (who). Returns a report the panel prints and the CLI echoes:
 *   ok, error (a code, see iconpackErrorText()), detail, id, already (the same content was already
 *   installed), root, edition, version, styles, bytes, kept [{path,bytes}], skipped {group => count},
 *   skipped_notes [{path, why}], refused [{path, why}].
 */
function iconpackImport(string $source, array $opts = []): array {
    $report = ['ok' => false, 'error' => null, 'detail' => '', 'id' => null, 'already' => false, 'root' => null,
               'edition' => null, 'version' => null, 'styles' => 0, 'bytes' => 0,
               'kept' => [], 'skipped' => [], 'skipped_notes' => [], 'refused' => []];
    $fail = function (string $code, string $detail = '', ?string $path = null) use (&$report): array {
        $report['ok'] = false;
        $report['error'] = $code;
        $report['detail'] = $detail;
        if ($path !== null) $report['refused'][] = ['path' => $path, 'why' => $code, 'detail' => $detail];
        return $report;
    };

    $store = iconpackDir();
    if (!is_dir($store) && !@mkdir($store, 0775, true) && !is_dir($store)) return $fail('store_not_writable', $store);
    if (!is_writable($store)) return $fail('store_not_writable', $store);
    iconpackSweepTemp();
    if (count(iconpackInstalledIds()) >= ICONPACK_MAX_PACKAGES) return $fail('too_many_packages', (string)ICONPACK_MAX_PACKAGES);

    $list = iconpackListSource($source);
    if (!$list['ok']) return $fail((string)$list['error'], (string)($list['detail'] ?? ''));
    $entries = $list['entries'];

    // ── 1. the root ──
    $cands = iconpackRootCandidates($entries);
    $byPath = [];
    foreach ($entries as $e) $byPath[$e['path']] = $e;
    $roots = [];
    foreach ($cands as $root => $c) {
        foreach ($c['css'] as $cssPath) {
            if (iconpackCssHeader(iconpackPeek($source, $byPath[$cssPath])) !== null) { $roots[] = $root; break; }
        }
    }
    if (!$roots) return $fail('no_root');
    if (count($roots) > 1) return $fail('two_roots', implode(' | ', array_map(fn($r) => $r === '' ? '/' : $r, $roots)));
    $root = $roots[0];
    $report['root'] = $root;
    $prefix = $root === '' ? '' : $root . '/';

    // ── 2. what is kept, and what is only listed ──
    $kept = [];   // rel => entry + kind
    $seen = [];   // exact names, lower-cased names
    $skippedCount = [];
    $total = 0;
    foreach ($entries as $e) {
        if ($e['dir']) continue;
        $p = $e['path'];
        if ($prefix !== '' && !str_starts_with($p, $prefix)) { iconpackCountSkip($skippedCount, $p, $root, 'outside'); continue; }
        $rel = substr($p, strlen($prefix));
        $kind = iconpackKeptKind($rel);
        if ($kind === null) { iconpackCountSkip($skippedCount, $rel, '', 'not_kept'); continue; }
        // Two entries with one name (a zip allows it) or two that differ only in case (one of them
        // would silently replace the other on a case-insensitive disk): refused, not picked between.
        if (isset($seen['x' . $rel])) return $fail('duplicate', $rel, $rel);
        if (isset($seen['i' . strtolower($rel)])) return $fail('case_collision', $rel, $rel);
        $seen['x' . $rel] = true; $seen['i' . strtolower($rel)] = true;
        if (!empty($e['encrypted'])) return $fail('encrypted', $rel, $rel);
        $cap = ['css' => ICONPACK_MAX_CSS_BYTES, 'font' => ICONPACK_MAX_FONT_BYTES,
                'metadata' => ICONPACK_MAX_META_BYTES, 'license' => ICONPACK_MAX_LICENSE_BYTES][$kind];
        if ($e['size'] > $cap) {
            // Metadata is a convenience for later (the emoji picker's names and categories): a file too
            // big to keep is left out, never a reason to refuse the fonts the site needs.
            if ($kind === 'metadata' || $kind === 'license') {
                $report['skipped_notes'][] = ['path' => $rel, 'why' => 'too_large', 'bytes' => $e['size']];
                iconpackCountSkip($skippedCount, $rel, '', 'too_large');
                continue;
            }
            return $fail('file_too_large', $rel . ' (' . $e['size'] . ' B)', $rel);
        }
        if ($e['size'] > ICONPACK_RATIO_FLOOR && $e['comp'] > 0 && $e['size'] / $e['comp'] > ICONPACK_MAX_RATIO) {
            return $fail('ratio', $rel . ' (' . round($e['size'] / $e['comp']) . ':1)', $rel);
        }
        $total += $e['size'];
        $kept[$rel] = $e + ['kind' => $kind, 'rel' => $rel];
    }
    // A licence may appear under several spellings; one is kept, as LICENSE.txt.
    $lic = array_values(array_filter(array_keys($kept), fn($r) => $kept[$r]['kind'] === 'license'));
    foreach (array_slice($lic, 1) as $extra) { unset($kept[$extra]); iconpackCountSkip($skippedCount, $extra, '', 'not_kept'); }
    if (count($kept) > ICONPACK_MAX_KEPT_FILES) return $fail('too_many_files', (string)count($kept));
    if ($total > ICONPACK_MAX_TOTAL_BYTES) return $fail('total_too_large', (string)$total);
    $free = @disk_free_space($store);
    if ($free !== false && $free < $total + 52428800) return $fail('disk_full', (string)(int)$free);
    ksort($skippedCount);
    $report['skipped'] = $skippedCount;

    // ── 3. extract what is kept, into a directory nobody reads yet ──
    $tmp = $store . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(6));
    if (!@mkdir($tmp, 0775) || !@mkdir($tmp . '/css', 0775) || !@mkdir($tmp . '/webfonts', 0775) || !@mkdir($tmp . '/metadata', 0775)) {
        iconpackRemoveTree($tmp);
        return $fail('store_not_writable', $store);
    }
    $zip = null;
    if ($list['kind'] === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($source, ZipArchive::RDONLY) !== true) { iconpackRemoveTree($tmp); return $fail('zip_open'); }
    }
    try {
        foreach ($kept as $rel => $e) {
            $dest = $tmp . '/' . ($e['kind'] === 'license' ? 'LICENSE.txt' : $rel);
            $in = $zip ? $zip->getStreamIndex((int)$e['index']) : @fopen((string)$e['abs'], 'rb');
            $out = @fopen($dest, 'xb');
            if (!$in || !$out) { if ($in) fclose($in); if ($out) fclose($out); throw new RuntimeException('write_failed|' . $rel); }
            // The declared size is a claim; what arrives is counted. One byte more than declared and
            // the file is refused, whatever the header said.
            $limit = (int)$e['size']; $got = 0;
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) break;
                $got += strlen($chunk);
                if ($got > $limit) { fclose($in); fclose($out); throw new RuntimeException('size_mismatch|' . $rel); }
                fwrite($out, $chunk);
            }
            fclose($in); fclose($out);
            if ($got !== $limit) throw new RuntimeException('size_mismatch|' . $rel);
            $kept[$rel]['dest'] = $dest;
        }
    } catch (RuntimeException $x) {
        if ($zip) $zip->close();
        iconpackRemoveTree($tmp);
        [$code, $path] = explode('|', $x->getMessage(), 2) + [1 => ''];
        return $fail($code, $path, $path);
    }
    if ($zip) $zip->close();

    // ── 4. check what was extracted, then describe it ──
    $a = iconpackAnalyse($tmp, $kept);
    if (!$a['ok']) {
        iconpackRemoveTree($tmp);
        return $fail((string)$a['error'], (string)($a['detail'] ?? ''), $a['path'] ?? null);
    }
    foreach ($a['notes'] as $nt) $report['skipped_notes'][] = $nt;

    // ── 5. the manifest, then the rename that makes it exist ──
    $files = [];
    $hashCtx = hash_init('sha256');
    $keptRels = array_keys($kept);
    sort($keptRels, SORT_STRING);
    foreach ($keptRels as $rel) {
        $e = $kept[$rel];
        if (!empty($a['drop'][$rel])) { @unlink($e['dest']); continue; }
        $stored = $e['kind'] === 'license' ? 'LICENSE.txt' : $rel;
        $sha = hash_file('sha256', $e['dest']);
        $files[$stored] = ['bytes' => (int)filesize($e['dest']), 'sha256' => $sha, 'kind' => $e['kind']];
        hash_update($hashCtx, $stored . "\0" . $sha . "\n");
    }
    $hash = hash_final($hashCtx);
    $id = 'fa-' . $a['edition'] . '-' . $a['version'] . '-' . substr($hash, 0, 8);
    $final = $store . DIRECTORY_SEPARATOR . $id;
    $report['edition'] = $a['edition'];
    $report['version'] = $a['version'];
    $report['styles'] = count($a['styles']);
    $report['id'] = $id;
    foreach ($files as $rel => $f) { $report['kept'][] = ['path' => $rel, 'bytes' => $f['bytes']]; $report['bytes'] += $f['bytes']; }
    if (is_dir($final)) {
        iconpackRemoveTree($tmp);
        $report['ok'] = true;
        $report['already'] = true;
        return $report;
    }
    $names = $a['names'];
    $manifest = [
        'format'       => ICONPACK_FORMAT,
        'id'           => $id,
        'vendor'       => 'fontawesome',
        'edition'      => $a['edition'],
        'version'      => $a['version'],
        'major'        => $a['major'],
        'hash'         => $hash,
        'root'         => $root,
        'source'       => ['kind' => (string)($opts['kind'] ?? 'cli'), 'name' => mb_substr((string)($opts['name'] ?? basename($source)), 0, 200)],
        'installed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'installed_by' => mb_substr((string)($opts['by'] ?? 'cli'), 0, 64),
        'core'         => $a['core'],
        'families'     => $a['families'],
        'styles'       => $a['styles'],
        'aux'          => $a['aux'],
        'files'        => $files,
        'bytes'        => $report['bytes'],
        'icons'        => ['names' => count($names), 'glyphs' => count(array_unique($names)), 'file' => 'names.json'],
        // Styles by knowledge only from metadata that describes THIS edition: Free metadata on a Pro
        // package (the owner's first folders) knows nothing of Pro's styles, and would make every icon
        // look solid-only.
        'present'      => iconpackPresence($names, iconpackIndexTrusted($a['edition'], $a['metadata']) ? $a['index'] : null),
        'metadata'     => $a['metadata'],
        'license'      => isset($files['LICENSE.txt']) ? 'LICENSE.txt' : null,
        'report'       => ['skipped' => $skippedCount, 'notes' => $report['skipped_notes']],
    ];
    $report['metadata'] = $a['metadata'];
    $okNames = @file_put_contents($tmp . '/names.json', json_encode(['format' => ICONPACK_FORMAT, 'names' => $names], JSON_UNESCAPED_SLASHES)) !== false;
    // The index, read once from the metadata (iconpackIndexBuild()): the styles of every icon, and the
    // emoji category — what a page reads instead of a metadata file of several megabytes.
    $flagsJ = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $okIdx = $a['index'] === null || @file_put_contents($tmp . '/index.json', json_encode($a['index'], $flagsJ)) !== false;
    $okEmo = $a['emoji'] === null || @file_put_contents($tmp . '/emoji.json', json_encode($a['emoji'], $flagsJ)) !== false;
    $okMan = @file_put_contents($tmp . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    if (!$okNames || !$okIdx || !$okEmo || !$okMan || !@rename($tmp, $final)) {
        iconpackRemoveTree($tmp);
        if (is_dir($final)) { $report['ok'] = true; $report['already'] = true; return $report; }   // a twin import won the race
        return $fail('write_failed', $id);
    }
    iconpackManifest($id, true);
    $report['ok'] = true;
    return $report;
}

/** One skipped file, counted under the first folder of its path ("svgs/", "js/") — never listed one by one. */
function iconpackCountSkip(array &$count, string $rel, string $root, string $why): void {
    $first = str_contains($rel, '/') ? substr($rel, 0, (int)strpos($rel, '/') + 1) : $rel;
    $key = ($why === 'outside' ? 'outside:' : ($why === 'too_large' ? 'too_large:' : '')) . $first;
    $count[$key] = ($count[$key] ?? 0) + 1;
}

/**
 * The checks and the description of an extracted package: fonts, stylesheets, the core, the styles,
 * the icon names, the metadata. Everything that reads CONTENT is here.
 */
function iconpackAnalyse(string $dir, array $kept): array {
    $bad = fn(string $code, string $detail, ?string $path = null) => ['ok' => false, 'error' => $code, 'detail' => $detail, 'path' => $path];
    $fonts = []; $cssFiles = []; $meta = []; $notes = []; $drop = [];
    foreach ($kept as $rel => $e) {
        if ($e['kind'] === 'font') $fonts[basename($rel)] = $rel;
        elseif ($e['kind'] === 'css') $cssFiles[$rel] = $e['dest'];
        elseif ($e['kind'] === 'metadata') $meta[$rel] = $e['dest'];
    }
    // ── fonts: their signature, and for WOFF/WOFF2 the length their own header claims ──
    foreach ($fonts as $base => $rel) {
        $why = iconpackFontProblem($kept[$rel]['dest'], strtolower(pathinfo($base, PATHINFO_EXTENSION)));
        if ($why !== null) return $bad('font_magic', $rel . ': ' . $why, $rel);
    }
    // ── the licence: text, not a program ──
    foreach ($kept as $rel => $e) {
        if ($e['kind'] !== 'license') continue;
        $t = (string)@file_get_contents($e['dest']);
        if ($t === '' || str_contains($t, "\0") || !mb_check_encoding($t, 'UTF-8')) {
            $drop[$rel] = true;
            $notes[] = ['path' => $rel, 'why' => 'not_text'];
        }
    }
    // ── every stylesheet: Font Awesome's, one edition and version, nothing but its own fonts ──
    $headers = []; $parsed = [];
    foreach ($cssFiles as $rel => $path) {
        $css = (string)@file_get_contents($path);
        if (!mb_check_encoding($css, 'UTF-8')) return $bad('css_encoding', $rel, $rel);
        $h = iconpackCssHeader(substr($css, 0, 600));
        if ($h === null) return $bad('css_not_fa', $rel, $rel);
        $headers[$rel] = $h;
        $why = iconpackCssProblem($css, $fonts);
        if ($why !== null) return $bad($why[0], $rel . ': ' . $why[1], $rel);
        $rules = iconpackCssRules($css);
        if ($rules === null) return $bad('css_unparsable', $rel, $rel);
        $parsed[$rel] = ['rules' => $rules, 'bytes' => strlen($css)];
    }
    if (!$headers) return $bad('no_root', '');
    $editions = array_unique(array_map(fn($h) => $h['edition'] . ' ' . $h['version'], $headers));
    if (count($editions) > 1) {
        $firstEd = reset($editions);
        $odd = (string)array_key_first(array_filter($headers, fn($h) => $h['edition'] . ' ' . $h['version'] !== $firstEd));
        return $bad('css_mixed_versions', implode(', ', $editions), $odd);
    }
    $h0 = reset($headers);
    if (!in_array($h0['major'], [6, 7], true)) return $bad('major_unsupported', $h0['version']);

    // ── the core, and the files that are not styles ──
    $byKey = [];   // style key => [rel, rel.min]
    $aux = [];
    $core = null;
    foreach (array_keys($parsed) as $rel) {
        $key = (string)preg_replace('/(\.min)?\.css$/', '', basename($rel));
        if ($key === 'fontawesome' || $key === 'all') { $byKey['@' . $key][] = $rel; continue; }
        // The sheets a download carries that are not styles: the old names of Font Awesome 4 and the
        // old font families of 4 and 5 (v4-shims, v4-/v5-font-face), and the JS/SVG build's own sheet
        // (svg-with-js), which this site never loads (includes/icons.php says why). Checked like the
        // rest — a tampered one still refuses the package — then left out, and the report says so.
        if (preg_match('/^(v4-shims|v4-font-face|v5-font-face|svg|svg-with-js|conflict-detection|custom-icons)$/', $key)) {
            $aux[] = $rel;
            $drop[$rel] = true;
            $notes[] = ['path' => $rel, 'why' => str_starts_with($key, 'svg') ? 'svg_build' : 'compat'];
            continue;
        }
        $byKey[$key][] = $rel;
    }
    // A package with the core file (a full download) loads it plus the classic files it wants; one
    // without it (the owner's copies) loads all.css. The .min spelling is served when both exist.
    $pick = fn(array $rels) => array_values(array_filter($rels, fn($r) => str_ends_with($r, '.min.css')))[0] ?? $rels[0];
    if (isset($byKey['@fontawesome'])) $core = ['kind' => 'fontawesome', 'file' => $pick($byKey['@fontawesome'])];
    elseif (isset($byKey['@all'])) $core = ['kind' => 'all', 'file' => $pick($byKey['@all'])];
    else return $bad('no_core', '');
    $allRel = isset($byKey['@all']) ? $pick($byKey['@all']) : null;
    $coreRules = $parsed[$core['file']]['rules'];
    $allRules = $allRel !== null ? $parsed[$allRel]['rules'] : [];

    // The family names the core (and all.css) declare: `--fa-family-X` (7.x) / `--fa-style-family-X` (6.x).
    $families = iconpackFamiliesFrom(array_merge($coreRules, $allRules));
    $coreVars = iconpackRootVars(array_merge($coreRules, $allRules));
    $coreFaces = iconpackFaces(array_merge($coreRules, $allRules));
    $coreClasses = iconpackClassSet(array_merge($coreRules, $allRules));
    $coreNames = iconpackNames(array_merge($coreRules, $allRules));

    // ── the style files ──
    $styles = [];
    $facesSeen = [];   // "family|weight" => src set, across the core and every style file
    foreach ($coreFaces as $f) $facesSeen[$f['family'] . '|' . $f['weight']] = $f['srcs'];
    // Every family name any file claims, so one file cannot put its font under another's name.
    $allFamilies = $families;
    foreach ($byKey as $key => $rels) {
        if ($key[0] !== '@') foreach ($rels as $oneRel) $allFamilies += iconpackFamiliesFrom($parsed[$oneRel]['rules']);
    }
    // A sheet that is Font Awesome's and clean but not a style this site can place is left out too.
    $leaveOut = function (array $rels, string $why) use (&$aux, &$drop, &$notes): void {
        foreach ($rels as $r) { $aux[] = $r; $drop[$r] = true; $notes[] = ['path' => $r, 'why' => $why]; }
    };
    foreach ($byKey as $key => $rels) {
        if ($key[0] === '@') continue;
        $rel = $pick($rels);
        $info = iconpackStyleFromKey((string)$key);
        if ($info === null) { $leaveOut($rels, 'not_a_style'); continue; }
        foreach ($rels as $oneRel) {
            $problem = iconpackStyleRulesProblem($parsed[$oneRel]['rules'], $info, $coreVars, $coreNames);
            if ($problem !== null) return $bad('css_global_rule', $oneRel . ': ' . $problem, $oneRel);
        }
        $rules = $parsed[$rel]['rules'];
        $faces = iconpackFaces($rules);
        if (count($faces) !== 1) { $leaveOut($rels, 'not_a_style'); continue; }
        $face = $faces[0];
        if ($face['weight'] !== $info['weight']) return $bad('css_face_conflict', $rel . ': weight ' . $face['weight'] . ' for ' . $info['style'], $rel);
        // The face must be drawn under this family's own name: a Jelly file declaring "Font Awesome 7
        // Pro" 400 would take over every classic regular icon on the page.
        $claimed = $allFamilies[$face['family']] ?? null;
        if ($claimed !== null && $claimed !== $info['family']) {
            return $bad('css_face_conflict', $rel . ': "' . $face['family'] . '" is the ' . $claimed . ' family', $rel);
        }
        $fk = $face['family'] . '|' . $face['weight'];
        if (isset($facesSeen[$fk]) && $facesSeen[$fk] !== $face['srcs']) {
            return $bad('css_face_conflict', $rel . ': "' . $face['family'] . '" ' . $face['weight'] . ' is already drawn by other font files', $rel);
        }
        $facesSeen[$fk] = $face['srcs'];
        $own = iconpackClassSet($rules);
        $classesAll = $own + $coreClasses;
        if (($info['family'] !== 'classic' && $info['family'] !== 'brands' && !isset($classesAll['fa-' . $info['family']]))
            || ($info['family'] !== 'brands' && !isset($classesAll['fa-' . $info['style']]))) {
            $leaveOut($rels, 'no_family_class'); continue;
        }
        $fontBytes = 0; $fontList = [];
        foreach ($face['srcs'] as $src) {
            $fr = 'webfonts/' . $src;
            $fontList[] = $fr;
            if ($fontBytes === 0 && isset($kept[$fr])) $fontBytes = (int)filesize($kept[$fr]['dest']);   // the first src is the one a browser fetches
        }
        $inAll = false;
        foreach (iconpackFaces($allRules) as $af) {
            if ($af['family'] === $face['family'] && $af['weight'] === $face['weight'] && $af['srcs'] === $face['srcs']) { $inAll = true; break; }
        }
        $styles[$key] = [
            'key' => $key, 'label' => $info['label'], 'family' => $info['family'], 'style' => $info['style'],
            'weight' => $info['weight'], 'font_family' => $face['family'], 'classes' => $info['classes'],
            'file' => $rel, 'files' => array_values($rels), 'fonts' => $fontList,
            'css_bytes' => $parsed[$rel]['bytes'], 'font_bytes' => $fontBytes,
            'in_all' => $inAll, 'layers' => iconpackLayers(array_merge($rules, $coreRules), $info, $own) ? 2 : 1,
        ];
    }
    // all.css draws styles a trimmed package may carry no file for; they still exist.
    foreach (iconpackFaces($allRules) as $af) {
        $fam = $families[$af['family']] ?? null;
        if ($fam === null) continue;   // the v4/v5 compatibility faces
        $style = array_search($af['weight'], iconpackStyleWeights(), true);
        if ($style === false) continue;
        $key = iconpackStyleKey($fam, $fam === 'brands' ? 'regular' : (string)$style);
        if (isset($styles[$key])) continue;
        $info = iconpackStyleFromKey($key);
        if ($info === null) continue;
        $styles[$key] = ['key' => $key, 'label' => $info['label'], 'family' => $info['family'], 'style' => $info['style'],
                         'weight' => $info['weight'], 'font_family' => $af['family'], 'classes' => $info['classes'],
                         'file' => null, 'files' => [], 'fonts' => array_map(fn($s) => 'webfonts/' . $s, $af['srcs']),
                         'css_bytes' => 0, 'font_bytes' => isset($kept['webfonts/' . ($af['srcs'][0] ?? '')]) ? (int)filesize($kept['webfonts/' . $af['srcs'][0]]['dest']) : 0,
                         'in_all' => true, 'layers' => iconpackLayers($allRules, $info, []) ? 2 : 1];
    }
    if ($core['kind'] === 'all' && !isset($styles['solid'])) return $bad('no_core', 'solid');
    if ($core['kind'] === 'fontawesome' && (!isset($styles['solid']) || $styles['solid']['file'] === null)) return $bad('no_core', 'solid.css');
    uasort($styles, 'iconpackStyleOrder');

    // ── icon names, from the core and the brands file (brand icons are declared there) ──
    $names = iconpackNames($coreRules);
    if (isset($styles['brands']['file'])) $names += iconpackNames($parsed[$styles['brands']['file']]['rules']);
    if ($allRel !== null && $allRel !== $core['file']) $names += iconpackNames($allRules);
    if (count($names) < 10) return $bad('css_no_icons', $core['file'], $core['file']);
    ksort($names, SORT_STRING);

    $famOut = [];
    foreach ($styles as $s) $famOut[$s['family']] = $s['font_family'];
    ksort($famOut);
    $index = iconpackIndexBuild($meta, $names);
    return ['ok' => true, 'edition' => $h0['edition'], 'version' => $h0['version'], 'major' => $h0['major'],
            'core' => $core, 'families' => $famOut, 'styles' => array_values($styles),
            'aux' => array_values(array_unique($aux)), 'names' => $names,
            'metadata' => $index['summary'], 'index' => $index['index'], 'emoji' => $index['emoji'],
            'notes' => $notes, 'drop' => $drop];
}

/** classic first, then brands, then everything else by family and weight — the order they are listed and loaded. */
function iconpackStyleOrder(array $a, array $b): int {
    $rank = fn(array $s) => $s['family'] === 'classic' ? 0 : ($s['family'] === 'brands' ? 1 : 2);
    return [$rank($a), $rank($a) === 2 ? $a['family'] : '', -$a['weight']] <=> [$rank($b), $rank($b) === 2 ? $b['family'] : '', -$b['weight']];
}

/** A font file's problem, or null: its format's signature, and the length a WOFF/WOFF2 header claims. */
function iconpackFontProblem(string $path, string $ext): ?string {
    $size = (int)@filesize($path);
    $fh = @fopen($path, 'rb');
    if (!$fh) return 'unreadable';
    $head = (string)fread($fh, 12);
    fclose($fh);
    if (strlen($head) < 12) return 'too short';
    $sig = substr($head, 0, 4);
    $want = ['woff2' => ['wOF2'], 'woff' => ['wOFF'], 'ttf' => ["\x00\x01\x00\x00", 'true'], 'otf' => ['OTTO']][$ext] ?? [];
    if (!in_array($sig, $want, true)) return 'not a ' . strtoupper($ext) . ' file';
    if ($ext === 'woff2' || $ext === 'woff') {
        $claimed = unpack('N', substr($head, 8, 4))[1];
        if ($claimed !== $size) return 'its header says ' . $claimed . ' bytes, the file has ' . $size;
    }
    return null;
}

/**
 * What in a stylesheet's TEXT makes it untrustworthy, as [code, detail], or null.
 *
 * Read on the raw text, comments included, before any parsing: a check that trusts the parser
 * inherits the parser's blind spots. Escapes are allowed only inside quoted strings (Font Awesome
 * writes its code points as "\f005"); outside one, `@\69 mport` would be an import no search for the
 * word finds.
 */
function iconpackCssProblem(string $css, array $fonts): ?array {
    $low = strtolower($css);
    foreach (['@import' => 'css_import', 'expression(' => 'css_forbidden', 'javascript:' => 'css_forbidden',
              'vbscript:' => 'css_forbidden', '-moz-binding' => 'css_forbidden', 'behavior' => 'css_forbidden',
              'behaviour' => 'css_forbidden', '@namespace' => 'css_forbidden', 'image-set(' => 'css_url_external'] as $needle => $code) {
        if (str_contains($low, $needle)) return [$code, $needle];
    }
    $noStrings = (string)preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/s', '""', $css);
    if (str_contains($noStrings, '\\')) return ['css_forbidden', 'an escape outside a string'];
    // Every url( must be one of ours: ../webfonts/<a font file that is in the package>.
    $n = substr_count($low, 'url(');
    preg_match_all('/url\(\s*(["\']?)([^"\')\s]*)\1\s*\)/i', $css, $m);
    if (count($m[2]) !== $n) return ['css_url_external', 'a url() this check cannot read'];
    foreach ($m[2] as $u) {
        if (!preg_match('#^\.\./webfonts/([A-Za-z0-9._-]+)$#', $u, $um)) return ['css_url_external', $u];
        if (!isset($fonts[$um[1]])) return ['css_url_missing', $u];
    }
    return null;
}

/* ── a small CSS reader: enough for Font Awesome's own sheets, and for refusing what is not ── */

/**
 * The leaf rules of a stylesheet: [['prelude' => …, 'body' => …|null, 'at' => [enclosing at-rules]]].
 * Comments are removed; a brace or a semicolon inside a string is text. Null when the sheet does not
 * balance, or nests a rule inside a rule — neither is anything Font Awesome writes.
 */
function iconpackCssRules(string $css): ?array {
    $css = (string)preg_replace('#/\*.*?\*/#s', '', $css);
    if (str_contains($css, '/*')) return null;
    $rules = []; $stack = []; $buf = '';
    $len = strlen($css);
    for ($i = 0; $i < $len; $i++) {
        // Jump to the next character that means anything here: all.css is 200 KB of mostly names.
        $run = strcspn($css, "\"'{};", $i);
        if ($run > 0) { $buf .= substr($css, $i, $run); $i += $run; if ($i >= $len) break; }
        $c = $css[$i];
        if ($c === '"' || $c === "'") {
            $j = $i + 1;
            while ($j < $len && $css[$j] !== $c) { if ($css[$j] === '\\') $j++; $j++; }
            if ($j >= $len) return null;
            $buf .= substr($css, $i, $j - $i + 1);
            $i = $j;
            continue;
        }
        if ($c === '{') {
            if ($stack && end($stack)[0] === 'rule') return null;
            $prelude = trim($buf); $buf = '';
            $container = (bool)preg_match('/^@(media|supports|layer|container|-?[a-z-]*keyframes|document)\b/i', $prelude);
            $stack[] = [$container ? 'container' : 'rule', $prelude];
            continue;
        }
        if ($c === '}') {
            if (!$stack) return null;
            [$kind, $prelude] = array_pop($stack);
            if ($kind === 'rule') {
                $at = [];
                foreach ($stack as $s) $at[] = $s[1];
                $rules[] = ['prelude' => $prelude, 'body' => trim($buf), 'at' => $at];
            } elseif (trim($buf) !== '') {
                return null;
            }
            $buf = '';
            continue;
        }
        if ($c === ';' && (!$stack || end($stack)[0] === 'container')) {
            $at = [];
            foreach ($stack as $s) $at[] = $s[1];
            if (trim($buf) !== '') $rules[] = ['prelude' => trim($buf), 'body' => null, 'at' => $at];
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if ($stack || trim($buf) !== '') return null;
    return $rules;
}

/** Split on a separator that is not inside a string, parentheses or brackets. */
function iconpackCssSplit(string $s, string $sep): array {
    $out = []; $buf = ''; $depth = 0; $q = null;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($q !== null) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) { $buf .= $s[++$i]; continue; }
            if ($c === $q) $q = null;
            continue;
        }
        if ($c === '"' || $c === "'") { $q = $c; $buf .= $c; continue; }
        if ($c === '(' || $c === '[') $depth++;
        if ($c === ')' || $c === ']') $depth = max(0, $depth - 1);
        if ($c === $sep && $depth === 0) { $out[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

/** A rule's declarations as [[property (lower case), value], …]. */
function iconpackCssDecls(?string $body): array {
    $out = [];
    foreach (iconpackCssSplit((string)$body, ';') as $d) {
        $p = strpos($d, ':');
        if ($p === false) continue;
        $out[] = [strtolower(trim(substr($d, 0, $p))), trim(substr($d, $p + 1))];
    }
    return $out;
}

/** The text of a CSS string literal: quotes off, escapes decoded. Null when it is not one. */
function iconpackCssString(string $v): ?string {
    $v = trim($v);
    if (strlen($v) < 2 || ($v[0] !== '"' && $v[0] !== "'") || substr($v, -1) !== $v[0]) return null;
    $inner = substr($v, 1, -1);
    return (string)preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?|\\\\(.)/s', function ($m) {
        if (($m[1] ?? '') !== '') {
            // Not `?: ''`: the digit zero is a real answer (`.fa-0{--fa:"\30 "}`) and "0" is falsy.
            $ch = mb_chr((int)hexdec($m[1]), 'UTF-8');
            return $ch === false ? '' : $ch;
        }
        return $m[2];
    }, $inner);
}

/** Custom properties set on :root / :host: name => raw value (the last one wins, as in CSS). */
function iconpackRootVars(array $rules): array {
    $out = [];
    foreach ($rules as $r) {
        if ($r['body'] === null || $r['at']) continue;
        $sels = iconpackCssSplit($r['prelude'], ',');
        if (!$sels || array_diff($sels, [':root', ':host'])) continue;
        foreach (iconpackCssDecls($r['body']) as [$p, $v]) if (str_starts_with($p, '--')) $out[$p] = $v;
    }
    return $out;
}

/** Family name ("Font Awesome 7 Sharp") => family key ("sharp"), from the root variables. */
function iconpackFamiliesFrom(array $rules): array {
    $vars = iconpackRootVars($rules);
    $out = [];
    foreach ($vars as $name => $value) {
        if (!preg_match('/^--fa-(?:style-)?family-([a-z0-9-]+)$/', $name, $m)) continue;
        $str = iconpackCssString($value);
        if ($str === null && preg_match('/^var\(\s*(--[a-z0-9-]+)\s*\)$/', $value, $vm) && isset($vars[$vm[1]])) $str = iconpackCssString($vars[$vm[1]]);
        if ($str !== null && $str !== '') $out[$str] = $m[1];
    }
    return $out;
}

/** The @font-face rules: [['family' => …, 'weight' => int, 'srcs' => [font file names, in order]]]. */
function iconpackFaces(array $rules): array {
    $out = [];
    foreach ($rules as $r) {
        if ($r['body'] === null || strtolower($r['prelude']) !== '@font-face') continue;
        $family = ''; $weight = 400; $srcs = [];
        foreach (iconpackCssDecls($r['body']) as [$p, $v]) {
            if ($p === 'font-family') $family = (string)(iconpackCssString($v) ?? trim($v));
            elseif ($p === 'font-weight') $weight = (int)(is_numeric($v) ? $v : ($v === 'bold' ? 700 : 400));
            elseif ($p === 'src') {
                preg_match_all('/url\(\s*(["\']?)\.\.\/webfonts\/([A-Za-z0-9._-]+)\1\s*\)/i', $v, $m);
                $srcs = $m[2];
            }
        }
        $out[] = ['family' => $family, 'weight' => $weight, 'srcs' => $srcs];
    }
    return $out;
}

/** Every class a sheet's selectors name, as a set. */
function iconpackClassSet(array $rules): array {
    $out = [];
    foreach ($rules as $r) {
        if ($r['body'] === null || str_starts_with($r['prelude'], '@')) continue;
        if (preg_match_all('/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $r['prelude'], $m)) foreach ($m[1] as $c) $out[$c] = true;
    }
    return $out;
}

/** Icon name => code point (hex), from rules shaped `.fa-a,.fa-b{--fa:"\f005"}`. */
function iconpackNames(array $rules): array {
    $out = [];
    foreach ($rules as $r) {
        if ($r['body'] === null || $r['at'] || !str_contains($r['body'], '--fa')) continue;
        $cp = null;
        foreach (iconpackCssDecls($r['body']) as [$p, $v]) {
            if ($p !== '--fa') continue;
            $s = iconpackCssString($v);
            if ($s !== null && $s !== '' && mb_strlen($s, 'UTF-8') === 1) $cp = dechex(mb_ord($s, 'UTF-8'));
        }
        if ($cp === null) continue;
        $names = [];
        foreach (iconpackCssSplit($r['prelude'], ',') as $sel) {
            if (!preg_match('/^\.fa-([a-z0-9]+(?:-[a-z0-9]+)*)$/', $sel, $m)) { $names = []; break; }
            $names[] = $m[1];
        }
        foreach ($names as $n) $out[$n] = $cp;
    }
    return $out;
}

/** "sharp-duotone-light" + (family, style) → the key Font Awesome names the file by. */
function iconpackStyleKey(string $family, string $style): string {
    if ($family === 'classic') return $style;
    if ($family === 'brands') return 'brands';
    if ($family === 'duotone' && $style === 'solid') return 'duotone';
    return $family . '-' . $style;
}

/**
 * A style file's key read as family + style: "solid" is classic solid, "duotone" is duotone solid,
 * "brands" is brands, "jelly-fill-regular" is the jelly-fill family in regular. Null when the last
 * word is not one of the five weights — a file named otherwise is not a style this code can place.
 */
function iconpackStyleFromKey(string $key): ?array {
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key)) return null;
    $weights = iconpackStyleWeights();
    if ($key === 'brands') {
        return ['family' => 'brands', 'style' => 'regular', 'weight' => 400, 'label' => 'Brands', 'classes' => 'fa-brands'];
    }
    if ($key === 'duotone') { $family = 'duotone'; $style = 'solid'; }
    elseif (isset($weights[$key])) { $family = 'classic'; $style = $key; }
    else {
        $p = strrpos($key, '-');
        if ($p === false) return null;
        $family = substr($key, 0, $p);
        $style = substr($key, $p + 1);
        if (!isset($weights[$style])) return null;
    }
    $label = ucwords(str_replace('-', ' ', $family === 'classic' ? $style : ($key === 'duotone' ? 'duotone' : $key)));
    $classes = $family === 'classic' ? 'fa-' . $style : 'fa-' . $family . ' fa-' . $style;
    return ['family' => $family, 'style' => $style, 'weight' => $weights[$style], 'label' => $label, 'classes' => $classes];
}

/**
 * Why a style file's rules would change icons that are not its own, or null.
 *
 * A selector is the file's own when it names the family class (`.fa-jelly`) or a short class
 * (`.fajr`, `.fass` — Font Awesome's one-word aliases); anything else reaches every icon on the page,
 * and is allowed only as the weight rule the core already has (`.fa-regular{--fa-style:400}`).
 * :root variables are allowed when they are this family's own, or say what the core says.
 */
function iconpackStyleRulesProblem(array $rules, array $info, array $coreVars, array $coreNames): ?string {
    $weights = iconpackStyleWeights();
    $own = $info['family'] === 'classic' ? 'fa-classic' : 'fa-' . $info['family'];
    $famToken = $info['family'] === 'classic' ? '(classic|' . implode('|', array_keys($weights)) . ')' : preg_quote($info['family'], '/');
    // Icon declarations (`.fa-monero{--fa:"\f3d0"}` — brands.css carries its icons' code points) may
    // add names; one the core already has must keep the core's code point, or a star would draw a heart.
    foreach (iconpackNames($rules) as $name => $cp) {
        if (isset($coreNames[$name]) && $coreNames[$name] !== $cp) return '.fa-' . $name . ' is given another code point';
    }
    foreach ($rules as $r) {
        if ($r['body'] === null) return 'a statement (' . iconpackPrintable(mb_substr($r['prelude'], 0, 40)) . ')';
        $pre = $r['prelude'];
        if (strtolower($pre) === '@font-face') continue;
        $decls = iconpackCssDecls($r['body']);
        if ($decls && !$r['at'] && !array_filter($decls, fn($d) => !in_array($d[0], ['--fa', '--fa--fa'], true))
            && !array_filter(iconpackCssSplit($pre, ','), fn($s) => !preg_match('/^\.fa-[a-z0-9]+(-[a-z0-9]+)*$/', $s))) {
            continue;   // checked above
        }
        if ($r['at'] && !preg_match('/^@supports\b/i', $r['at'][0])) return 'a rule inside ' . iconpackPrintable(mb_substr($r['at'][0], 0, 40));
        $sels = iconpackCssSplit($pre, ',');
        if ($sels && !array_diff($sels, [':root', ':host'])) {
            foreach (iconpackCssDecls($r['body']) as [$p, $v]) {
                if (!str_starts_with($p, '--')) return ':root sets ' . $p;
                if (isset($coreVars[$p])) {
                    if (trim($coreVars[$p]) !== trim($v)) return ':root changes ' . $p;
                } elseif (!preg_match('/^--fa-(?:style-)?(?:family|font)-' . $famToken . '(?:-|$)/', $p)) {
                    return ':root sets ' . $p;
                }
            }
            continue;
        }
        foreach ($sels as $sel) {
            preg_match_all('/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $sel, $cm);
            $mine = false;
            foreach ($cm[1] as $c) {
                if ($c === $own || ($c !== 'fa' && preg_match('/^fa[a-z]+$/', $c))) { $mine = true; break; }
            }
            if ($mine) continue;
            // Not its own: only `.fa-<style>` alone, setting that style's weight and nothing else.
            if (!preg_match('/^\.fa-(solid|semibold|regular|light|thin)$/', $sel, $sm)) return 'sets ' . iconpackPrintable(mb_substr($sel, 0, 60)) . ' for every icon';
            foreach (iconpackCssDecls($r['body']) as [$p, $v]) {
                if (!in_array($p, ['--fa-style', 'font-weight'], true) || (int)$v !== $weights[$sm[1]] || !ctype_digit(trim($v))) {
                    return 'sets ' . $sel . '{' . $p . ':' . iconpackPrintable(mb_substr($v, 0, 30)) . '} for every icon';
                }
            }
        }
    }
    return null;
}

/** Does this style draw in two layers (duotone and the "duo" families), ::before AND ::after? */
function iconpackLayers(array $rules, array $info, array $ownClasses): bool {
    $own = 'fa-' . $info['family'];
    foreach ($rules as $r) {
        if ($r['body'] === null || !preg_match('/:{1,2}after/', $r['prelude'])) continue;
        foreach (iconpackCssSplit($r['prelude'], ',') as $sel) {
            if (!preg_match('/:{1,2}after/', $sel)) continue;
            if (preg_match('/\.' . preg_quote($own, '/') . '(?![a-z0-9-])/', $sel)) return true;
            if ($info['family'] !== 'classic' && $info['family'] !== 'brands') {
                foreach (array_keys($ownClasses) as $c) {
                    if (preg_match('/^fa[a-z]+$/', $c) && $c !== 'fa' && preg_match('/\.' . $c . '(?![a-z0-9-])/', $sel)) return true;
                }
            }
        }
    }
    return false;
}

/**
 * THE PACKAGE'S INDEX (1.69.0): which icons it has, in which families and styles, under which words —
 * read ONCE, at import, from whichever metadata the download carries, and kept beside the manifest in
 * the one shape the site reads:
 *
 *   * Font Awesome's own `icon-families.json` (a genuine download's metadata/, with `categories.yml`
 *     beside it naming the emoji) — preferred whenever it is there: it is the publisher's;
 *   * the owner's compact index, `icons-search-vX.Y.Z.json`, written by his own download script: ONE
 *     entry per icon keyed by its name — label, unicode, is_free, categories, search_terms and
 *     families {family: [styles]} (recognised by that shape, whatever the file is called);
 *   * Font Awesome's older `icons.json` (the classic styles only).
 *
 * Normalised to name => {l: label, t: [terms], free, c: [categories], f: {family: [styles]}} and kept as
 * two small files beside the manifest (iconpackImport()): `index.json`, every icon's style keys as a
 * dictionary of the few distinct sets (the site-icon map's question: does the loaded Jelly draw a
 * gear?), and `emoji.json`, the `emoji` category with its labels and words (what the shoutbox picker
 * offers and the :fa-NAME: token draws). Both hold only names the package's own CSS declares.
 *
 * The summary in the manifest says what the metadata describes, so nobody takes Free metadata for Pro
 * coverage: Font Awesome Free's own files describe no Pro icon and no Pro style (the owner's first two
 * folders carried exactly those); a folder whose metadata is none of the three is 'unknown'.
 *
 * @return array{summary: array, index: ?array, emoji: ?array}
 */
function iconpackIndexBuild(array $meta, array $cssNames): array {
    $files = array_keys($meta);
    sort($files);
    $summary = ['files' => $files, 'describes' => $meta ? 'unknown' : 'none', 'icons' => 0, 'css_names_covered' => 0,
                'css_names' => count($cssNames), 'pro_styles' => false, 'pro_only_icons' => 0,
                'kind' => null, 'index' => null, 'families' => 0, 'emoji' => 0];
    $none = ['summary' => $summary, 'index' => null, 'emoji' => null];
    $src = iconpackIndexSource($meta);
    if ($src === null) return $none;
    $icons = iconpackIndexRead($src);
    if (!$icons) return $none;

    $covered = 0; $proOnly = 0; $proStyles = false; $families = [];
    $sets = []; $setOf = [];                  // "k1,k2" => index into $sets
    $styles = []; $emoji = [];
    $setIdx = function (array $fam) use (&$sets, &$setOf): int {
        $keys = [];
        foreach ($fam as $family => $sts) foreach ((array)$sts as $st) {
            if (is_string($family) && is_string($st) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $family . '-' . $st)) $keys[] = iconpackStyleKey($family, $st);
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        $k = implode(',', $keys);
        if (!isset($setOf[$k])) { $setOf[$k] = count($sets); $sets[] = $keys; }
        return $setOf[$k];
    };
    foreach ($icons as $name => $e) {
        if (!$e['free']) $proOnly++;
        foreach ($e['f'] as $family => $sts) {
            $families[$family] = true;
            foreach ($sts as $st) if (!in_array($family . '/' . $st, ['classic/solid', 'classic/regular', 'classic/brands'], true)) $proStyles = true;
        }
        if (!isset($cssNames[$name])) continue;
        $covered++;
        $i = $setIdx($e['f']);
        $styles[$name] = $i;
        if (in_array('emoji', $e['c'], true)) $emoji[$name] = [$e['l'], $e['t'], $i];
    }
    ksort($styles, SORT_STRING);
    ksort($emoji, SORT_STRING);
    $summary['icons'] = count($icons);
    $summary['css_names_covered'] = $covered;
    $summary['pro_only_icons'] = $proOnly;
    $summary['pro_styles'] = $proStyles;
    $summary['describes'] = ($proOnly > 0 || $proStyles) ? 'pro' : 'free';
    $summary['kind'] = $src['kind'];
    $summary['index'] = $src['rel'];
    $summary['families'] = count($families);
    $summary['emoji'] = count($emoji);
    return ['summary' => $summary,
            'index' => ['format' => 1, 'kind' => $src['kind'], 'file' => $src['rel'], 'sets' => $sets, 'styles' => $styles],
            'emoji' => $emoji ? ['format' => 1, 'kind' => $src['kind'], 'sets' => $sets, 'faces' => $emoji] : null];
}

/**
 * Which metadata file the index is read from: Font Awesome's icon-families.json, else a file shaped like
 * the owner's index (sniffed, not trusted by name), else icons.json. Null when there is none of them.
 */
function iconpackIndexSource(array $meta): ?array {
    if (isset($meta['metadata/icon-families.json'])) {
        return ['kind' => 'icon-families', 'rel' => 'metadata/icon-families.json', 'path' => $meta['metadata/icon-families.json'],
                'categories' => $meta['metadata/categories.yml'] ?? ($meta['metadata/categories.yaml'] ?? null)];
    }
    $rels = array_keys($meta);
    sort($rels, SORT_STRING);
    // The owner's file by its shape: its name says icons-search, but a renamed copy is the same index.
    usort($rels, fn($a, $b) => (int)!str_contains($a, 'icons-search') <=> (int)!str_contains($b, 'icons-search') ?: strcmp($a, $b));
    foreach ($rels as $rel) {
        if (!str_ends_with(strtolower($rel), '.json') || $rel === 'metadata/icons.json') continue;
        $head = (string)@file_get_contents($meta[$rel], false, null, 0, 4096);
        if (preg_match('/^\s*\{\s*"[a-z0-9-]+"\s*:\s*\{/', $head) && str_contains($head, '"search_terms"') && str_contains($head, '"families"')) {
            return ['kind' => 'icons-search', 'rel' => $rel, 'path' => $meta[$rel], 'categories' => null];
        }
    }
    if (isset($meta['metadata/icons.json'])) return ['kind' => 'icons', 'rel' => 'metadata/icons.json', 'path' => $meta['metadata/icons.json'], 'categories' => null];
    return null;
}

/**
 * One metadata file, normalised: name => {l, t, free, c, f}. Anything that is not the shape expected is
 * skipped entry by entry — metadata is a convenience, never a reason to refuse the fonts.
 */
function iconpackIndexRead(array $src): array {
    $j = json_decode((string)@file_get_contents((string)$src['path']), true);
    if (!is_array($j)) return [];
    $cats = $src['categories'] !== null ? iconpackCategoriesYml((string)@file_get_contents((string)$src['categories'])) : [];
    $clean = fn($v) => array_values(array_filter(array_map(fn($x) => is_string($x) ? mb_substr(trim($x), 0, 80) : '', (array)$v), fn($x) => $x !== ''));
    $out = [];
    foreach ($j as $name => $e) {
        // A PHP array turns the keys "0" … "9" (Font Awesome's digit icons) into integers.
        $name = (string)$name;
        if (!is_array($e) || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) continue;
        $fam = [];
        if ($src['kind'] === 'icons-search') {
            foreach ((array)($e['families'] ?? []) as $f => $sts) if (is_string($f)) $fam[$f] = $clean($sts);
            $terms = $clean($e['search_terms'] ?? []);
            $free = !empty($e['is_free']);
            $c = $clean($e['categories'] ?? []);
        } elseif ($src['kind'] === 'icon-families') {
            foreach (['free', 'pro'] as $lic) foreach ((array)($e['familyStylesByLicense'][$lic] ?? []) as $fs) {
                if (is_array($fs) && is_string($fs['family'] ?? null) && is_string($fs['style'] ?? null)) $fam[$fs['family']][] = $fs['style'];
            }
            $terms = $clean($e['search']['terms'] ?? []);
            $free = !empty($e['familyStylesByLicense']['free']);
            $c = $cats[$name] ?? [];
        } else {
            foreach ($clean($e['styles'] ?? []) as $st) $fam['classic'][] = $st;
            $terms = $clean($e['search']['terms'] ?? []);
            $free = !empty($e['free']);
            $c = $cats[$name] ?? [];
        }
        foreach ($fam as $f => $sts) $fam[$f] = array_values(array_unique($sts));
        $out[$name] = ['l' => mb_substr(is_string($e['label'] ?? null) ? trim($e['label']) : $name, 0, 80),
                       't' => array_slice($terms, 0, 40), 'free' => $free, 'c' => $c, 'f' => $fam];
    }
    return $out;
}

/**
 * Font Awesome's categories.yml, read for the one thing asked of it: which icons each category holds.
 * Its shape is fixed and shallow (a category, `icons:`, a list of names, `label:`), so a few lines of
 * reading rather than a YAML library the server may not have. Returns icon => [categories].
 */
function iconpackCategoriesYml(string $yml): array {
    $out = []; $cat = null; $inIcons = false;
    foreach (preg_split('/\R/', $yml) ?: [] as $line) {
        if (preg_match('/^([a-z0-9-]+):\s*$/', $line, $m)) { $cat = $m[1]; $inIcons = false; continue; }
        if ($cat === null) continue;
        if (preg_match('/^\s+icons:\s*$/', $line)) { $inIcons = true; continue; }
        if ($inIcons && preg_match('/^\s+-\s+([a-z0-9]+(?:-[a-z0-9]+)*)\s*$/', $line, $m)) { $out[$m[1]][] = $cat; continue; }
        if (preg_match('/^\s+[a-z_]+:/', $line)) $inIcons = false;
    }
    return $out;
}

/**
 * Which of the names the site's map can ask for this package declares — worked out once, at import,
 * so a page does not read five thousand names to learn about thirty. `for` is a hash of the question:
 * when a later release's map asks about other names, the answer is recomputed from names.json.
 * With an index (1.69.0) it also says in which styles each of them is drawn (`sets`, `styles`), so the
 * map knows that a loaded family lacks a glyph instead of leaving it to the font chain.
 */
function iconpackPresence(array $names, ?array $index = null): array {
    $cands = function_exists('iconFaCandidateNames') ? iconFaCandidateNames() : [];
    $have = array_values(array_filter($cands, fn($n) => isset($names[$n])));
    $out = ['for' => hash('sha256', implode(',', $cands)), 'names' => $have];
    if ($index !== null) {
        $sets = []; $styles = []; $remap = [];
        foreach ($have as $n) {
            if (!isset($index['styles'][$n])) continue;
            $i = (int)$index['styles'][$n];
            if (!isset($remap[$i])) { $remap[$i] = count($sets); $sets[] = $index['sets'][$i]; }
            $styles[$n] = $remap[$i];
        }
        $out['sets'] = $sets;
        $out['styles'] = $styles;
    }
    return $out;
}

/**
 * May the index speak for the package's styles? When it describes the package's own edition: a Free
 * package by anything, a Pro one only by metadata that knows Pro (its families, its Pro-only icons).
 */
function iconpackIndexTrusted(string $edition, ?array $summary): bool {
    $d = (string)($summary['describes'] ?? 'none');
    return $edition === 'free' ? in_array($d, ['free', 'pro'], true) : $d === 'pro';
}

/** The package's index.json (every icon's styles), or null when its metadata had none. */
function iconpackIndexOf(string $id): ?array {
    static $cache = [];
    if (array_key_exists($id, $cache)) return $cache[$id];
    if (!iconpackValidId($id)) return $cache[$id] = null;
    $j = json_decode((string)@file_get_contents(iconpackDir() . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'index.json'), true);
    return $cache[$id] = (is_array($j) && is_array($j['sets'] ?? null) && is_array($j['styles'] ?? null)) ? $j : null;
}

/** The package's emoji.json (the `emoji` category: label, words, styles), or null. */
function iconpackEmojiOf(string $id): ?array {
    static $cache = [];
    if (array_key_exists($id, $cache)) return $cache[$id];
    if (!iconpackValidId($id)) return $cache[$id] = null;
    $j = json_decode((string)@file_get_contents(iconpackDir() . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'emoji.json'), true);
    return $cache[$id] = (is_array($j) && is_array($j['sets'] ?? null) && is_array($j['faces'] ?? null)) ? $j : null;
}

/* ── the store ────────────────────────────────────────────────────────────────── */

/** The ids of the installed packages (directories of the right shape; a broken one is still listed). */
function iconpackInstalledIds(): array {
    $dir = iconpackDir();
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (scandir($dir) ?: [] as $e) {
        if ($e !== '.' && $e !== '..' && iconpackValidId($e) && is_dir($dir . DIRECTORY_SEPARATOR . $e)) $out[] = $e;
    }
    sort($out, SORT_STRING);
    return $out;
}

/** A package's manifest, or null. Cached for the request; $refresh drops the cached copy. */
function iconpackManifest(string $id, bool $refresh = false): ?array {
    static $cache = [];
    if ($refresh) { unset($cache[$id]); return null; }
    if (array_key_exists($id, $cache)) return $cache[$id];
    if (!iconpackValidId($id)) return $cache[$id] = null;
    $raw = @file_get_contents(iconpackDir() . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'manifest.json');
    $m = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($m) || ($m['format'] ?? 0) !== ICONPACK_FORMAT || ($m['id'] ?? '') !== $id || !is_array($m['styles'] ?? null) || !is_array($m['files'] ?? null)) {
        return $cache[$id] = null;
    }
    return $cache[$id] = $m;
}

/** Every package as the panel's table and the CLI's list show it: the manifest's summary, or why it is broken. */
function iconpackList(): array {
    $out = [];
    foreach (iconpackInstalledIds() as $id) {
        $m = iconpackManifest($id);
        if ($m === null) { $out[] = ['id' => $id, 'broken' => true]; continue; }
        $outside = count(array_filter($m['styles'], fn($s) => empty($s['in_all']) && $s['file'] !== null));
        $out[] = ['id' => $id, 'broken' => false, 'edition' => $m['edition'], 'version' => $m['version'], 'major' => (int)$m['major'],
                  'styles' => count($m['styles']), 'outside_all' => $outside, 'core' => $m['core']['kind'] ?? 'all',
                  'bytes' => (int)$m['bytes'], 'files' => count($m['files']), 'installed_at' => $m['installed_at'],
                  'installed_by' => $m['installed_by'], 'source' => $m['source'], 'root' => $m['root'],
                  'icons' => (int)($m['icons']['names'] ?? 0), 'metadata' => $m['metadata']['describes'] ?? 'none'];
    }
    // Newest version first; a broken directory last.
    usort($out, fn($a, $b) => (empty($a['broken']) <=> empty($b['broken'])) * -1
        ?: version_compare((string)($b['version'] ?? '0'), (string)($a['version'] ?? '0'))
        ?: strcmp($a['id'], $b['id']));
    return $out;
}

/**
 * A package as an audit summary names it: "Font Awesome Pro 7.3.1". The id is the line's target and is
 * shown under the summary already; the audit's cells are one line each, cut with "…", so a summary
 * that repeats it is one that is cut.
 */
function iconpackTitle(?array $m, string $fallback = ''): string {
    return $m ? 'Font Awesome ' . ucfirst((string)$m['edition']) . ' ' . $m['version'] : $fallback;
}

/**
 * The styles that load with a package whatever is ticked, besides its core. With all.css as the core
 * every style in it is there already; with fontawesome.css (a full download) nothing is — so Solid
 * (every style's last resort), Regular (the site's outlines: an empty star beside the filled one) and
 * Brands (the one brand icon's only font) are always linked, and the rest when ticked.
 */
function iconpackForcedStyles(array $m): array {
    return (($m['core']['kind'] ?? 'all') === 'fontawesome') ? ['solid', 'regular', 'brands'] : [];
}

/** Does this style load with the package's core whatever is ticked (in all.css, or forced)? */
function iconpackStyleFixed(array $m, array $style): bool {
    if (($m['core']['kind'] ?? 'all') === 'all') return !empty($style['in_all']);
    return in_array((string)$style['key'], iconpackForcedStyles($m), true) && $style['file'] !== null;
}

/** Is this the package the site draws with now? It may not be deleted. */
function iconpackIsActive(string $id, array $cfg): bool {
    return (string)($cfg['fa_source'] ?? '') === 'pack' && (string)($cfg['fa_pack'] ?? '') === $id;
}

/** Remove a package: renamed out of the way first, so a page never loads a half-deleted one. */
function iconpackDelete(string $id, array $cfg): array {
    if (!iconpackValidId($id) || !is_dir(iconpackDir() . DIRECTORY_SEPARATOR . $id)) return ['ok' => false, 'error' => 'unknown_package'];
    if (iconpackIsActive($id, $cfg)) return ['ok' => false, 'error' => 'active_package'];
    $from = iconpackDir() . DIRECTORY_SEPARATOR . $id;
    $trash = iconpackDir() . DIRECTORY_SEPARATOR . '.trash-' . bin2hex(random_bytes(6));
    if (!@rename($from, $trash)) return ['ok' => false, 'error' => 'write_failed'];
    iconpackRemoveTree($trash);
    iconpackManifest($id, true);
    return ['ok' => true];
}

/**
 * Check an installed package against its manifest: every file there with its size and hash, nothing
 * listed missing. Extra files are reported (nothing serves them: the endpoint serves the list).
 */
function iconpackVerify(string $id): array {
    $m = iconpackManifest($id);
    if ($m === null) return ['ok' => false, 'error' => is_dir(iconpackDir() . DIRECTORY_SEPARATOR . $id) ? 'broken_manifest' : 'unknown_package'];
    $dir = iconpackDir() . DIRECTORY_SEPARATOR . $id;
    $missing = []; $changed = []; $extra = [];
    $h = hash_init('sha256');
    $rels = array_keys($m['files']);
    sort($rels, SORT_STRING);
    foreach ($rels as $rel) {
        $f = $m['files'][$rel];
        $p = $dir . '/' . $rel;
        if (!is_file($p)) { $missing[] = $rel; continue; }
        $sha = hash_file('sha256', $p);
        if ((int)filesize($p) !== (int)$f['bytes'] || $sha !== $f['sha256']) $changed[] = $rel;
        hash_update($h, $rel . "\0" . $f['sha256'] . "\n");
    }
    foreach (['css', 'webfonts', 'metadata'] as $sub) {
        foreach (glob($dir . '/' . $sub . '/*') ?: [] as $p) {
            $rel = $sub . '/' . basename($p);
            if (!isset($m['files'][$rel])) $extra[] = $rel;
        }
    }
    $hashOk = hash_final($h) === $m['hash'];
    return ['ok' => !$missing && !$changed && $hashOk, 'missing' => $missing, 'changed' => $changed, 'extra' => $extra,
            'files' => count($m['files']), 'hash_ok' => $hashOk];
}

/** Names the package declares (names.json), for the rare question the manifest's presence list cannot answer. */
function iconpackNamesOf(string $id): array {
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    $raw = @file_get_contents(iconpackDir() . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'names.json');
    $j = $raw !== false ? json_decode($raw, true) : null;
    return $cache[$id] = (is_array($j) && is_array($j['names'] ?? null)) ? $j['names'] : [];
}

function iconpackRemoveTree(string $dir): void {
    if (!is_dir($dir) || is_link($dir)) { if (is_file($dir) || is_link($dir)) @unlink($dir); return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { if ($f->isDir() && !$f->isLink()) @rmdir($f->getPathname()); else @unlink($f->getPathname()); }
    @rmdir($dir);
}

/** Temporary and trash directories an interrupted import or delete left behind, after an hour. */
function iconpackSweepTemp(): void {
    // Two patterns rather than GLOB_BRACE, which musl-based systems do not have.
    foreach (['.tmp-*', '.trash-*'] as $pat) {
        foreach (glob(iconpackDir() . DIRECTORY_SEPARATOR . $pat) ?: [] as $d) {
            if (is_dir($d) && @filemtime($d) < time() - 3600) iconpackRemoveTree($d);
        }
    }
}

/* ── serving ──────────────────────────────────────────────────────────────────── */

/** The version part of every asset URL: the package's content hash, and the rewrite's revision. */
function iconpackAssetVersion(array $m): string {
    return substr((string)$m['hash'], 0, 16) . '-' . ICONPACK_SERVE_REV;
}

/** The URL a page loads one of the package's files from — relative to the site's base. */
function iconpackAssetUrl(string $base, array $m, string $rel): string {
    return $base . 'iconpack.php?p=' . rawurlencode((string)$m['id']) . '&v=' . iconpackAssetVersion($m) . '&f=' . str_replace('%2F', '/', rawurlencode($rel));
}

/**
 * The answer to one request of iconpack.php, without sending it (the tests read this directly).
 *
 * Only a file the manifest lists, and only a stylesheet or a font: metadata and the licence stay on
 * the server. The version in the URL must be the package's own, so an old page asking for a file of
 * a package that was replaced gets a 404 rather than bytes it did not ask for.
 *
 * The CSS is rewritten as it is served, not stored rewritten: every `url(../webfonts/NAME)` — already
 * proved at import to be one of the package's fonts — becomes `url("iconpack.php?p=…&f=webfonts/NAME")`,
 * relative to the stylesheet's own URL, so neither the base path nor PATH_INFO is involved. That is
 * the choice between the two ways the brief allowed: PATH_INFO works on php -S and on Apache with the
 * PHP handler, but it is a web-server setting (AcceptPathInfo, nginx's fastcgi_split_path_info) and
 * this project has paid more than once for a behaviour that differed between the local server and
 * production; a query string needs nothing from any server.
 *
 * @return array{status:int, headers:string[], path?:string, body?:string}
 */
function iconpackServeRequest(array $get, array $server): array {
    $nf = ['status' => 404, 'headers' => ['Content-Type: text/plain; charset=utf-8', 'X-Content-Type-Options: nosniff',
           'Cache-Control: no-store', "Content-Security-Policy: default-src 'none'"], 'body' => "Not found\n"];
    $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return ['status' => 405, 'headers' => array_merge($nf['headers'], ['Allow: GET, HEAD']), 'body' => "Method not allowed\n"];
    }
    $id = (string)($get['p'] ?? '');
    $v = (string)($get['v'] ?? '');
    $f = (string)($get['f'] ?? '');
    if (!iconpackValidId($id) || !preg_match('#^(css|webfonts)/[A-Za-z0-9._-]+$#', $f) || !preg_match('/^[0-9a-f]{16}-[0-9a-z]+$/', $v)) return $nf;
    $m = iconpackManifest($id);
    if ($m === null || $v !== iconpackAssetVersion($m)) return $nf;
    $entry = $m['files'][$f] ?? null;
    if (!is_array($entry) || !in_array($entry['kind'] ?? '', ['css', 'font'], true)) return $nf;
    $path = iconpackDir() . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $f);
    if (!is_file($path)) return $nf;

    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $type = ['css' => 'text/css; charset=utf-8', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf'][$ext] ?? null;
    if ($type === null) return $nf;
    $etag = '"' . $v . '-' . substr((string)$entry['sha256'], 0, 16) . '"';
    $lastMod = strtotime((string)$m['installed_at']) ?: 0;
    $headers = [
        'Content-Type: ' . $type,
        'X-Content-Type-Options: nosniff',
        // A year, never revalidated: the URL carries the package's hash, so another package or
        // another rewrite is another URL.
        'Cache-Control: public, max-age=31536000, immutable',
        'ETag: ' . $etag,
        'Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastMod) . ' GMT',
        // The licensed fonts are this site's: another origin may not embed them.
        'Cross-Origin-Resource-Policy: same-origin',
        "Content-Security-Policy: default-src 'none'",
    ];
    $inm = (string)($server['HTTP_IF_NONE_MATCH'] ?? '');
    $ims = (string)($server['HTTP_IF_MODIFIED_SINCE'] ?? '');
    if (($inm !== '' && in_array($etag, array_map('trim', explode(',', $inm)), true))
        || ($inm === '' && $ims !== '' && ($t = strtotime($ims)) !== false && $t >= $lastMod)) {
        return ['status' => 304, 'headers' => array_values(array_filter($headers, fn($h) => !str_starts_with($h, 'Content-Type')))];
    }
    if ($ext === 'css') {
        $css = (string)@file_get_contents($path);
        $prefix = 'iconpack.php?p=' . rawurlencode($id) . '&v=' . $v . '&f=webfonts/';
        $body = (string)preg_replace_callback('/url\(\s*(["\']?)\.\.\/webfonts\/([A-Za-z0-9._-]+)\1\s*\)/i',
            fn($mm) => 'url("' . $prefix . $mm[2] . '")', $css);
        $headers[] = 'Content-Length: ' . strlen($body);
        return ['status' => 200, 'headers' => $headers, 'body' => $body];
    }
    $headers[] = 'Content-Length: ' . (int)filesize($path);
    return ['status' => 200, 'headers' => $headers, 'path' => $path];
}
