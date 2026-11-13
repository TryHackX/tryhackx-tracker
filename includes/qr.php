<?php
/**
 * A QR encoder, for exactly one job: showing the 2FA setup key without sending it anywhere.
 *
 * ── why this is in the repository at all ────────────────────────────────────
 *
 * Every convenient way to put a QR on a page — an image API, a CDN script — means handing the string
 * to somebody else, and this string is a TOTP secret: as good as the admin password. So the code is
 * here, it runs on the machine that already knows the secret, and the page loads nothing.
 *
 * ── how it is known to be correct ───────────────────────────────────────────
 *
 * An encoder nobody can read the output of is a liability: a wrong matrix scans as the wrong secret,
 * or does not scan, and the mistake surfaces as "2FA is broken" weeks later. tests/qr_test.php builds
 * matrices here and compares them MODULE BY MODULE against an independent reference encoder for a
 * spread of lengths, versions and real otpauth:// URIs. If a single module differs, the test fails.
 *
 * Byte mode, error correction level M, versions 1-10 — which covers an otpauth URI comfortably and
 * stops well short of the sizes that would need a bigger table for no purpose.
 */

/* ── GF(256), the arithmetic Reed-Solomon is built on ─────────────────────── */

function qrGfTables(): array {
    static $t = null;
    if ($t !== null) return $t;
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) $x ^= 0x11D;          // the primitive polynomial QR specifies
    }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return $t = ['exp' => $exp, 'log' => $log];
}

function qrGfMul(int $a, int $b): int {
    if ($a === 0 || $b === 0) return 0;
    $t = qrGfTables();
    return $t['exp'][$t['log'][$a] + $t['log'][$b]];
}

/** The generator polynomial for n error-correction codewords. */
function qrRsGenerator(int $n): array {
    $g = [1];
    $t = qrGfTables();
    for ($i = 0; $i < $n; $i++) {
        $next = array_fill(0, count($g) + 1, 0);
        foreach ($g as $k => $c) {
            $next[$k] ^= qrGfMul($c, $t['exp'][$i]);
            $next[$k + 1] ^= $c;
        }
        $g = $next;
    }
    // Built constant-first; the division below walks it leading-first. Getting this the wrong way
    // round produces perfectly well-formed error-correction codewords that are simply the wrong
    // numbers -- a symbol that looks right, scans, and decodes to nothing.
    return array_reverse($g);
}

function qrRsEncode(array $data, int $ecCount): array {
    $gen = qrRsGenerator($ecCount);
    $rem = array_fill(0, $ecCount, 0);
    foreach ($data as $b) {
        $factor = $b ^ $rem[0];
        array_shift($rem);
        $rem[] = 0;
        foreach ($gen as $i => $g) {
            if ($i === 0) continue;
            $rem[$i - 1] ^= qrGfMul($g, $factor);
        }
    }
    return $rem;
}

/* ── the tables the standard defines ──────────────────────────────────────── */

/** [ec codewords per block, [[block count, data codewords per block], …]] for level M. */
function qrBlocksM(int $version): array {
    static $t = [
        1  => [10, [[1, 16]]],
        2  => [16, [[1, 28]]],
        3  => [26, [[1, 44]]],
        4  => [18, [[2, 32]]],
        5  => [24, [[2, 43]]],
        6  => [16, [[4, 27]]],
        7  => [18, [[4, 31]]],
        8  => [22, [[2, 38], [2, 39]]],
        9  => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];
    return $t[$version];
}

function qrAlignmentCentres(int $version): array {
    static $t = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];
    return $t[$version];
}

/** Pre-computed 18-bit BCH version information, only needed from version 7. */
function qrVersionBits(int $version): ?int {
    static $t = [7 => 0x07C94, 8 => 0x085BC, 9 => 0x09A99, 10 => 0x0A4D3];
    return $t[$version] ?? null;
}

function qrDataCapacity(int $version): int {
    [$ec, $groups] = qrBlocksM($version);
    $n = 0;
    foreach ($groups as [$count, $dataCw]) $n += $count * $dataCw;
    return $n;
}

/* ── the bit stream ───────────────────────────────────────────────────────── */

function qrPickVersion(int $byteLen): int {
    for ($v = 1; $v <= 10; $v++) {
        $header = 4 + ($v <= 9 ? 8 : 16);                       // mode + character count
        if ((qrDataCapacity($v) * 8) >= $header + $byteLen * 8) return $v;
    }
    throw new RuntimeException('QR: ' . $byteLen . ' bytes needs a version above 10');
}

function qrBuildCodewords(string $text, int $version): array {
    $bits = '';
    $bits .= '0100';                                             // byte mode
    $countBits = $version <= 9 ? 8 : 16;
    $bits .= str_pad(decbin(strlen($text)), $countBits, '0', STR_PAD_LEFT);
    for ($i = 0, $n = strlen($text); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
    }
    $capacityBits = qrDataCapacity($version) * 8;
    $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));   // terminator
    if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
    $pad = [0xEC, 0x11];
    $i = 0;
    while (strlen($bits) < $capacityBits) {
        $bits .= str_pad(decbin($pad[$i % 2]), 8, '0', STR_PAD_LEFT);
        $i++;
    }
    $data = [];
    foreach (str_split($bits, 8) as $b) $data[] = bindec($b);

    // Split into blocks, compute the error correction for each, then interleave both — data
    // codeword 0 of every block, then codeword 1 of every block, and the same again for the EC.
    [$ecCount, $groups] = qrBlocksM($version);
    $blocks = [];
    $pos = 0;
    foreach ($groups as [$count, $dataCw]) {
        for ($b = 0; $b < $count; $b++) {
            $chunk = array_slice($data, $pos, $dataCw);
            $pos += $dataCw;
            $blocks[] = ['data' => $chunk, 'ec' => qrRsEncode($chunk, $ecCount)];
        }
    }
    $out = [];
    $maxData = max(array_map(fn($b) => count($b['data']), $blocks));
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($blocks as $b) if (isset($b['data'][$i])) $out[] = $b['data'][$i];
    }
    for ($i = 0; $i < $ecCount; $i++) {
        foreach ($blocks as $b) $out[] = $b['ec'][$i];
    }
    return $out;
}

/* ── the matrix ───────────────────────────────────────────────────────────── */

function qrPlaceFunctionPatterns(array &$m, array &$reserved, int $version): void {
    $size = count($m);
    $finder = function (int $r, int $c) use (&$m, &$reserved, $size) {
        for ($i = -1; $i <= 7; $i++) {
            for ($j = -1; $j <= 7; $j++) {
                $rr = $r + $i; $cc = $c + $j;
                if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) continue;
                $on = ($i >= 0 && $i <= 6 && ($j === 0 || $j === 6))
                   || ($j >= 0 && $j <= 6 && ($i === 0 || $i === 6))
                   || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4);
                $m[$rr][$cc] = $on ? 1 : 0;
                $reserved[$rr][$cc] = true;
            }
        }
    };
    $finder(0, 0);
    $finder(0, $size - 7);
    $finder($size - 7, 0);

    for ($i = 8; $i < $size - 8; $i++) {                     // timing patterns
        $bit = ($i % 2 === 0) ? 1 : 0;
        $m[6][$i] = $bit; $reserved[6][$i] = true;
        $m[$i][6] = $bit; $reserved[$i][6] = true;
    }

    $centres = qrAlignmentCentres($version);
    foreach ($centres as $r) {
        foreach ($centres as $c) {
            // Not where a finder already is.
            if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $size - 9) || ($r >= $size - 9 && $c <= 8)) continue;
            for ($i = -2; $i <= 2; $i++) {
                for ($j = -2; $j <= 2; $j++) {
                    $on = (max(abs($i), abs($j)) !== 1) ? 1 : 0;
                    $m[$r + $i][$c + $j] = $on;
                    $reserved[$r + $i][$c + $j] = true;
                }
            }
        }
    }

    $m[$size - 8][8] = 1;                                    // the dark module
    $reserved[$size - 8][8] = true;

    for ($i = 0; $i <= 8; $i++) {                            // format information areas
        if (!$reserved[8][$i]) { $reserved[8][$i] = true; $m[8][$i] = 0; }
        if (!$reserved[$i][8]) { $reserved[$i][8] = true; $m[$i][8] = 0; }
    }
    // The second copy of the format information is EIGHT modules wide and only SEVEN tall: the
    // eighth position down the column is the dark module set just above. Reserving eight in both
    // directions blanks it -- which the old, reversed format writer then happened to write over, so
    // the two bugs hid each other and the symbol still scanned.
    for ($i = 0; $i < 8; $i++) { $reserved[8][$size - 1 - $i] = true; $m[8][$size - 1 - $i] = 0; }
    for ($i = 0; $i < 7; $i++) { $reserved[$size - 1 - $i][8] = true; $m[$size - 1 - $i][8] = 0; }
    if (qrVersionBits($version) !== null) {                   // version information areas
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $reserved[$i][$size - 11 + $j] = true; $m[$i][$size - 11 + $j] = 0;
                $reserved[$size - 11 + $j][$i] = true; $m[$size - 11 + $j][$i] = 0;
            }
        }
    }
}

function qrPlaceData(array &$m, array $reserved, array $codewords): void {
    $size = count($m);
    $bits = '';
    foreach ($codewords as $c) $bits .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
    $idx = 0;
    $upward = true;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) $right = 5;                        // the vertical timing column is skipped
        for ($v = 0; $v < $size; $v++) {
            $row = $upward ? ($size - 1 - $v) : $v;
            for ($k = 0; $k < 2; $k++) {
                $col = $right - $k;
                if ($reserved[$row][$col]) continue;
                $m[$row][$col] = ($idx < strlen($bits)) ? (int)$bits[$idx] : 0;
                $idx++;
            }
        }
        $upward = !$upward;
    }
}

function qrMaskBit(int $mask, int $i, int $j): bool {
    switch ($mask) {
        case 0: return ($i + $j) % 2 === 0;
        case 1: return $i % 2 === 0;
        case 2: return $j % 3 === 0;
        case 3: return ($i + $j) % 3 === 0;
        case 4: return (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0;
        case 5: return (($i * $j) % 2) + (($i * $j) % 3) === 0;
        case 6: return (((($i * $j) % 2) + (($i * $j) % 3)) % 2) === 0;
        default: return (((($i + $j) % 2) + (($i * $j) % 3)) % 2) === 0;
    }
}

function qrPenalty(array $m): int {
    $size = count($m);
    $score = 0;
    // Rule 1: runs of five or more of the same colour, in both directions.
    for ($pass = 0; $pass < 2; $pass++) {
        for ($a = 0; $a < $size; $a++) {
            $run = 1;
            for ($b = 1; $b < $size; $b++) {
                $cur  = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                $prev = $pass === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];
                if ($cur === $prev) { $run++; continue; }
                if ($run >= 5) $score += 3 + ($run - 5);
                $run = 1;
            }
            if ($run >= 5) $score += 3 + ($run - 5);
        }
    }
    // Rule 2: every 2x2 block of one colour.
    for ($i = 0; $i < $size - 1; $i++) {
        for ($j = 0; $j < $size - 1; $j++) {
            $v = $m[$i][$j];
            if ($v === $m[$i][$j + 1] && $v === $m[$i + 1][$j] && $v === $m[$i + 1][$j + 1]) $score += 3;
        }
    }
    // Rule 3: the finder-like pattern 1011101 with four light modules on either side.
    $pat1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
    $pat2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
    for ($i = 0; $i < $size; $i++) {
        for ($j = 0; $j <= $size - 11; $j++) {
            $rowOk1 = true; $rowOk2 = true; $colOk1 = true; $colOk2 = true;
            for ($k = 0; $k < 11; $k++) {
                if ($m[$i][$j + $k] !== $pat1[$k]) $rowOk1 = false;
                if ($m[$i][$j + $k] !== $pat2[$k]) $rowOk2 = false;
                if ($m[$j + $k][$i] !== $pat1[$k]) $colOk1 = false;
                if ($m[$j + $k][$i] !== $pat2[$k]) $colOk2 = false;
            }
            if ($rowOk1) $score += 40;
            if ($rowOk2) $score += 40;
            if ($colOk1) $score += 40;
            if ($colOk2) $score += 40;
        }
    }
    // Rule 4: how far the proportion of dark modules is from half. The standard is specific about
    // the arithmetic -- take the multiple of five below the percentage and the one above, measure each
    // one's distance from 50, and use the SMALLER. A single floor() instead is a common shortcut and
    // it picks a different mask on some symbols, which is exactly how this was caught.
    $dark = 0;
    foreach ($m as $row) foreach ($row as $v) $dark += $v;
    $percent = ($dark * 100) / ($size * $size);
    $low = (int)(floor($percent / 5) * 5);
    $high = $low + 5;
    $score += min((int)(abs($low - 50) / 5), (int)(abs($high - 50) / 5)) * 10;
    return $score;
}

function qrFormatBits(int $mask): int {
    $data = (0 << 3) | $mask;                 // 00 = error correction level M
    $rem = $data << 10;
    for ($i = 14; $i >= 10; $i--) {
        if ($rem & (1 << $i)) $rem ^= 0x537 << ($i - 10);
    }
    return (($data << 10) | $rem) ^ 0x5412;
}

function qrApplyFormat(array &$m, array $reserved, int $mask): void {
    $size = count($m);
    $bits = qrFormatBits($mask);
    // $i walks the POSITIONS the standard defines, and each position takes bit (14 - $i): the most
    // significant bit goes first. Writing them the other way round is self-consistent -- this file's
    // own reader would agree with itself and a round-trip would pass -- and no scanner on earth would
    // read the symbol. It took a second, independent encoder to see it.
    for ($i = 0; $i < 15; $i++) {
        $bit = ($bits >> (14 - $i)) & 1;
        // The copy beside the top-left finder.
        if ($i < 6)       { $m[8][$i] = $bit; }
        elseif ($i === 6) { $m[8][7] = $bit; }
        elseif ($i === 7) { $m[8][8] = $bit; }
        elseif ($i === 8) { $m[7][8] = $bit; }
        else              { $m[14 - $i][8] = $bit; }
        // …and the copy split between the other two finders. The break is after seven, not eight.
        if ($i < 7) $m[$size - 1 - $i][8] = $bit;
        else        $m[8][$size - 15 + $i] = $bit;
    }
}

function qrApplyVersion(array &$m, int $version): void {
    $bits = qrVersionBits($version);
    if ($bits === null) return;
    $size = count($m);
    for ($i = 0; $i < 18; $i++) {
        $bit = ($bits >> $i) & 1;
        $r = intdiv($i, 3);
        $c = $i % 3;
        $m[$r][$size - 11 + $c] = $bit;
        $m[$size - 11 + $c][$r] = $bit;
    }
}

/** The finished matrix: rows of 0/1, no quiet zone (the renderer adds that). */
function qrMatrix(string $text): array {
    $version = qrPickVersion(strlen($text));
    $size = 17 + 4 * $version;
    $codewords = qrBuildCodewords($text, $version);

    $base = array_fill(0, $size, array_fill(0, $size, 0));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));
    qrPlaceFunctionPatterns($base, $reserved, $version);
    qrPlaceData($base, $reserved, $codewords);

    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $m = $base;
        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                if (!$reserved[$i][$j] && qrMaskBit($mask, $i, $j)) $m[$i][$j] ^= 1;
            }
        }
        qrApplyFormat($m, $reserved, $mask);
        qrApplyVersion($m, $version);
        $score = qrPenalty($m);
        if ($score < $bestScore) { $bestScore = $score; $best = $m; }
    }
    return $best;
}

/**
 * The matrix as an inline SVG.
 *
 * One <rect> per dark run rather than per module: a version-8 code is 2 209 modules, and a thousand
 * separate rectangles is a page a browser has to think about. Colours are explicit because the panel
 * is dark and a scanner needs light-on-dark to be the wrong way round.
 */
function qrSvg(array $m, int $scale = 4, int $quiet = 4): string {
    $size = count($m);
    $dim = ($size + $quiet * 2) * $scale;
    $out = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '" '
         . 'viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img" '
         . 'aria-label="QR code with the two-factor setup key">'
         . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/><g fill="#000000">';
    for ($i = 0; $i < $size; $i++) {
        $j = 0;
        while ($j < $size) {
            if (!$m[$i][$j]) { $j++; continue; }
            $run = 0;
            while ($j + $run < $size && $m[$i][$j + $run]) $run++;
            $out .= '<rect x="' . (($j + $quiet) * $scale) . '" y="' . (($i + $quiet) * $scale)
                  . '" width="' . ($run * $scale) . '" height="' . $scale . '"/>';
            $j += $run;
        }
    }
    return $out . '</g></svg>';
}
