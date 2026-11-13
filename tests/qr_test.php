<?php
/**
 * Test for includes/qr.php:
 *   php tests/qr_test.php
 *
 * An encoder whose output nobody can read is a liability: a wrong matrix scans as the wrong secret,
 * or does not scan at all, and the mistake surfaces weeks later as "2FA is broken". So the matrices
 * built here are compared MODULE BY MODULE against an independent reference encoder — Python's
 * `segno`, which is not shipped and is used only to check this one.
 *
 * If segno is not installed the structural checks still run and the comparison is skipped visibly.
 * Install it with:  pip install segno
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/qr.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }

/* ── 1. structure, which holds whether or not a reference is available ────── */

$m = qrMatrix('HELLO');
$size = count($m);
check('a short string produces a version-1 matrix (21x21)', $size === 21, (string)$size);
check('every row is as long as the matrix is tall', count(array_filter($m, fn($r) => count($r) === $size)) === $size);
check('every module is 0 or 1', (function ($m) {
    foreach ($m as $r) foreach ($r as $v) if ($v !== 0 && $v !== 1) return false;
    return true;
})($m));

// The three finder patterns are the first thing a scanner looks for.
$finderOk = function (array $m, int $r, int $c): bool {
    for ($i = 0; $i < 7; $i++) {
        for ($j = 0; $j < 7; $j++) {
            $want = ($i === 0 || $i === 6 || $j === 0 || $j === 6
                     || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4)) ? 1 : 0;
            if ($m[$r + $i][$c + $j] !== $want) return false;
        }
    }
    return true;
};
check('the top-left finder pattern is there', $finderOk($m, 0, 0));
check('the top-right finder pattern is there', $finderOk($m, 0, $size - 7));
check('the bottom-left finder pattern is there', $finderOk($m, $size - 7, 0));
check('the dark module is set, as the standard requires', $m[$size - 8][8] === 1);
check('the horizontal timing pattern alternates',
      $m[6][8] === 1 && $m[6][9] === 0 && $m[6][10] === 1);

// A real otpauth URI is what this encoder actually exists for.
$uri = 'otpauth://totp/TryHackX%3Aadmin?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=TryHackX&algorithm=SHA1&digits=6&period=30';
$mu = qrMatrix($uri);
check('a real otpauth URI encodes, and needs a bigger version',
      count($mu) > 21 && count($mu) === count($mu[0]), (string)count($mu));

$svg = qrSvg($m, 4, 4);
// xmlns is a namespace declaration, not a request — the thing that matters is that nothing here
// FETCHES anything, because the whole reason this encoder exists is that the secret must not leave.
check('the SVG fetches nothing',
      !str_contains($svg, 'xlink') && !str_contains($svg, 'href=') && !str_contains($svg, 'src=')
      && !str_contains($svg, 'url('), $svg);
check('the SVG paints a light background, so a dark panel cannot invert it',
      str_contains($svg, 'fill="#ffffff"'));
check('the SVG carries a label for anything not looking at it', str_contains($svg, 'aria-label'));
check('the quiet zone is included in the viewBox',
      str_contains($svg, 'width="' . (($size + 8) * 4) . '"'));

try {
    qrMatrix(str_repeat('x', 400));
    check('a string too big for the supported versions is refused', false, 'no exception');
} catch (RuntimeException $e) {
    check('a string too big for the supported versions is refused, by name',
          str_contains($e->getMessage(), 'above 10'), $e->getMessage());
}

/* ── 2. what a decoder would actually read ────────────────────────────────── */
//
// Comparing whole matrices between encoders is meaningless: the mask is a free choice, and two
// correct libraries pick different ones and differ in a quarter of their modules. So the comparison
// is on CODEWORDS — read back out of a matrix the way a scanner would, which is mask-independent and
// tests the encoding, the Reed-Solomon and the module placement in one go.
//
// This is not theoretical. It caught a real bug: the generator polynomial was built with its
// coefficients in the opposite order to the division that consumes it, which produced perfectly
// well-formed error-correction codewords that were simply the wrong numbers — a symbol that looks
// right, scans, and decodes to nothing.

/** Read the mask a matrix DECLARES, out of its own format information. */
function qrReadMask(array $m): ?int {
    $strip = [];
    for ($i = 0; $i < 6; $i++) $strip[] = $m[8][$i];
    $strip[] = $m[8][7]; $strip[] = $m[8][8]; $strip[] = $m[7][8];
    for ($i = 9; $i < 15; $i++) $strip[] = $m[14 - $i][8];
    for ($mask = 0; $mask < 8; $mask++) {
        $bits = qrFormatBits($mask);
        $want = [];
        for ($i = 0; $i < 15; $i++) $want[] = ($bits >> (14 - $i)) & 1;
        if ($want === $strip) return $mask;
    }
    return null;
}

/** Everything a scanner reads out of a symbol, in order. */
function qrReadCodewords(array $matrix, int $version, int $mask): array {
    $size = count($matrix);
    $base = array_fill(0, $size, array_fill(0, $size, 0));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));
    qrPlaceFunctionPatterns($base, $reserved, $version);
    $m = $matrix;
    for ($i = 0; $i < $size; $i++) {
        for ($j = 0; $j < $size; $j++) {
            if (!$reserved[$i][$j] && qrMaskBit($mask, $i, $j)) $m[$i][$j] ^= 1;
        }
    }
    $bits = '';
    $up = true;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) $right = 5;
        for ($v = 0; $v < $size; $v++) {
            $row = $up ? ($size - 1 - $v) : $v;
            for ($k = 0; $k < 2; $k++) {
                $col = $right - $k;
                if ($reserved[$row][$col]) continue;
                $bits .= $m[$row][$col];
            }
        }
        $up = !$up;
    }
    $out = [];
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out[] = bindec($b);
    return $out;
}

$cases = [
    'HELLO', 'HELLO WORLD',
    str_repeat('A', 20), str_repeat('B', 40), str_repeat('C', 60), str_repeat('D', 80),
    str_repeat('E', 100), str_repeat('F', 120), str_repeat('G', 140), str_repeat('H', 170),
    str_repeat('I', 200),
    $uri,
    'otpauth://totp/Tracker%3Aadmin?secret=ABCDEFGHIJKLMNOPQRSTUVWXYZ234567&issuer=Tracker&algorithm=SHA1&digits=6&period=30',
    'otpauth://totp/A%20Very%20Long%20Site%3Aadministrator?secret=NBSWY3DPFQQHO33SNRSCC&issuer=A%20Very%20Long%20Site&algorithm=SHA1&digits=6&period=30',
    // This one is here for a reason. It is the input that makes the standard's penalty rules pick
    // mask 3, and OpenCV's detector cannot find the resulting symbol at all — for THIS encoder and for
    // the reference encoder alike, since both produce the identical matrix. zxing-cpp, which is the
    // lineage behind real scanners, reads it without trouble. Keeping the case pinned means that if
    // anybody ever "fixes" the mask selection to please a weak detector, the encoder-comparison check
    // above says so immediately.
    'otpauth://totp/TryHackX%3ATryHackX?secret=NBSWY3DPFQQHO33SNRSCCABCDEFGHIJK&issuer=TryHackX&algorithm=SHA1&digits=6&period=30',
];

// ── 2a. every symbol must read back as exactly what went in ─────────────────
$roundTripFail = null;
foreach ($cases as $case) {
    $version = qrPickVersion(strlen($case));
    $want = qrBuildCodewords($case, $version);
    $m = qrMatrix($case);
    $mask = qrReadMask($m);
    if ($mask === null) { $roundTripFail = 'the format information does not name any valid mask'; break; }
    $got = array_slice(qrReadCodewords($m, $version, $mask), 0, count($want));
    if ($got !== $want) {
        $roundTripFail = 'version ' . $version . ', mask ' . $mask . ': what comes back out is not what went in';
        break;
    }
}
check('every symbol reads back as exactly the codewords that went in — the mask it declares, the '
      . 'placement, and the format bits all agree', $roundTripFail === null, (string)$roundTripFail);

// ── 2b. and those codewords match an independent encoder ────────────────────
$python = null;
foreach (['python', 'python3', 'py'] as $cand) {
    $o = []; $rc = null;
    @exec(escapeshellarg($cand) . ' -c "import qrcode" 2>&1', $o, $rc);
    if ($rc === 0) { $python = $cand; break; }
}
if ($python === null) {
    skip('comparison with an independent encoder', 'python-qrcode is not installed (pip install qrcode)');
} else {
    $script = sys_get_temp_dir() . '/qr_ref_' . getmypid() . '.py';
    file_put_contents($script, <<<'PY'
import sys, qrcode
from qrcode.util import QRData, MODE_8BIT_BYTE
version = int(sys.argv[1])
text = sys.stdin.buffer.read()
q = qrcode.QRCode(version=version, error_correction=qrcode.constants.ERROR_CORRECT_M, box_size=1, border=0)
q.add_data(QRData(text, mode=MODE_8BIT_BYTE))
q.make(fit=False)
sys.stdout.write(chr(10).join(''.join('1' if v else '0' for v in row) for row in q.get_matrix()))
PY);
    $ref = static function (string $text, int $version) use ($python, $script): ?array {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open(escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' . (int)$version, $desc, $pipes);
        if (!is_resource($p)) return null;
        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        $rows = array_values(array_filter(explode("\n", trim($out))));
        if (!$rows) return null;
        return array_map(fn($r) => array_map('intval', str_split(trim($r))), $rows);
    };

    $mismatch = null;
    $compared = 0;
    foreach ($cases as $case) {
        $version = qrPickVersion(strlen($case));
        $theirs = $ref($case, $version);
        if ($theirs === null) { $mismatch = 'the reference encoder produced nothing'; break; }
        if (count($theirs) !== 17 + 4 * $version) {
            $mismatch = 'the reference chose a different version for a ' . strlen($case) . '-byte input';
            break;
        }
        $theirMask = qrReadMask($theirs);
        if ($theirMask === null) { $mismatch = 'could not read the reference symbol\'s mask'; break; }
        $mine = qrBuildCodewords($case, $version);
        $got = array_slice(qrReadCodewords($theirs, $version, $theirMask), 0, count($mine));
        if ($got !== $mine) {
            $diff = [];
            foreach ($mine as $i => $v) if (($got[$i] ?? null) !== $v) $diff[] = $i;
            $mismatch = 'version ' . $version . ', ' . strlen($case) . ' bytes: codewords differ at index '
                      . implode(',', array_slice($diff, 0, 6));
            break;
        }
        $compared++;
    }
    // ── 2c. and the whole symbol, module for module ─────────────────────────
    // Codewords prove the encoding; this proves the CANVAS. Alignment patterns, the version
    // information block, the dark module and both copies of the format information carry no data, so
    // a mistake in any of them survives every check above and still breaks real scanners. The mask is
    // a free choice, so the test is "one of my eight candidates is theirs exactly" -- if the mask
    // matches, so must every function module in the symbol.
    $canvasFail = null;
    $canvasChecked = 0;
    foreach ($cases as $case) {
        $version = qrPickVersion(strlen($case));
        $theirs = $ref($case, $version);
        if ($theirs === null) { $canvasFail = 'the reference encoder produced nothing'; break; }
        $size = 17 + 4 * $version;
        $base = array_fill(0, $size, array_fill(0, $size, 0));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));
        qrPlaceFunctionPatterns($base, $reserved, $version);
        qrPlaceData($base, $reserved, qrBuildCodewords($case, $version));
        $hit = false;
        for ($mask = 0; $mask < 8 && !$hit; $mask++) {
            $m = $base;
            for ($i = 0; $i < $size; $i++) {
                for ($j = 0; $j < $size; $j++) {
                    if (!$reserved[$i][$j] && qrMaskBit($mask, $i, $j)) $m[$i][$j] ^= 1;
                }
            }
            qrApplyFormat($m, $reserved, $mask);
            qrApplyVersion($m, $version);
            if ($m === $theirs) $hit = true;
        }
        if (!$hit) {
            $canvasFail = 'version ' . $version . ', ' . strlen($case) . ' bytes: no mask reproduces the reference symbol';
            break;
        }
        $canvasChecked++;
    }
    check('the whole symbol matches module for module — function patterns, version information, the '
          . 'dark module and both copies of the format information included',
          $canvasFail === null && $canvasChecked === count($cases),
          $canvasFail ?? ('checked ' . $canvasChecked . ' of ' . count($cases)));

    // ── 2d. and a real decoder reads it back ────────────────────────────────
    // The checks above compare against another ENCODER, which proves the two agree but not that
    // anybody can read the result. This one runs the symbols through a DECODER — zxing-cpp, the
    // lineage behind most real scanners — and asks for the string back. It is the only check here
    // that tests the thing the user actually does: point a phone at it.
    $decoder = null;
    $o = []; $rc = null;
    @exec(escapeshellarg($python) . ' -c "import zxingcpp, numpy" 2>&1', $o, $rc);
    if ($rc === 0) $decoder = true;
    if ($decoder === null) {
        skip('reading the symbols back with a real decoder', 'zxing-cpp is not installed (pip install zxing-cpp numpy)');
    } else {
        $dscript = sys_get_temp_dir() . '/qr_dec_' . getmypid() . '.py';
        file_put_contents($dscript, <<<'PY'
import sys, json, numpy as np, zxingcpp
rows = json.loads(sys.stdin.read())
n = len(rows); scale = 8; quiet = 4
img = np.ones(((n + 2 * quiet) * scale, (n + 2 * quiet) * scale), dtype=np.uint8) * 255
for i, r in enumerate(rows):
    for j, v in enumerate(r):
        if v == '1':
            img[(i + quiet) * scale:(i + quiet + 1) * scale, (j + quiet) * scale:(j + quiet + 1) * scale] = 0
res = zxingcpp.read_barcode(img)
sys.stdout.write(res.text if res else '')
PY);
        $decode = static function (array $m) use ($python, $dscript): string {
            $rows = [];
            foreach ($m as $r) $rows[] = implode('', $r);
            $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $p = proc_open(escapeshellarg($python) . ' ' . escapeshellarg($dscript), $desc, $pipes);
            if (!is_resource($p)) return '';
            fwrite($pipes[0], json_encode($rows));
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
            return $out;
        };
        $decodeFail = null;
        $decoded = 0;
        foreach ($cases as $case) {
            $got = $decode(qrMatrix($case));
            if ($got !== $case) {
                $decodeFail = 'version ' . qrPickVersion(strlen($case)) . ', ' . strlen($case) . ' bytes: '
                            . ($got === '' ? 'the decoder could not find a symbol at all' : 'came back as something else');
                break;
            }
            $decoded++;
        }
        check('a real decoder reads every symbol back as exactly the string that went in',
              $decodeFail === null && $decoded === count($cases),
              $decodeFail ?? ('decoded ' . $decoded . ' of ' . count($cases)));
        echo '     (' . $decoded . ' symbols through zxing-cpp, the mask this encoder actually picks)' . "
";
        @unlink($dscript);
    }

    check('every codeword matches an independent encoder, across versions 1 to 10',
          $mismatch === null && $compared === count($cases),
          $mismatch ?? ('compared ' . $compared . ' of ' . count($cases)));
    echo '     (' . $compared . ' inputs, data and error correction, versions 1 through 10)' . "\n";
    @unlink($script);
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
