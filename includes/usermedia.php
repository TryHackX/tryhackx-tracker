<?php
/**
 * Pictures and profile covers (v69): the one door every image goes through, and the helpers that
 * turn a row into an address.
 *
 * ── the design, in five sentences ──────────────────────────────────────────────────────────────
 * An image is never kept as it arrived: it is measured from its header, decoded by GD, turned
 * upright from its EXIF orientation, bounded, and re-encoded to WebP — which drops every byte of
 * EXIF and GPS and neutralises a file that was also something else. A cover is stored ONCE and never
 * cropped: the owner picks a focal point (x, y as percentages, 0-100) and a zoom (0.5-4), and every
 * surface paints it with the same CSS (`background-position: X% Y%; transform: scale(Z)`). A picture
 * keeps its uncropped source and is cut into 64/128/256 px squares on the server with EXACTLY the
 * formula the editor's CSS draws (object-position semantics), so what the owner framed is what
 * everybody sees. Replacing or removing deletes the old rows in the same transaction, so nothing an
 * owner or a moderator took down is left answering at an old address. And the addresses carry no
 * account id and no file name: they are the first sixteen characters of a hash plus a size.
 *
 * ── the order of the questions (userMediaPrepare) ──────────────────────────────────────────────
 *   1. bytes: at least a real file's worth, at most `avatar_max_kb` (and what PHP will accept);
 *   2. the magic bytes: JPEG, PNG, WebP or GIF — never SVG (a document that can carry script) and
 *      never BMP (megabytes of nothing), and a HEIC or TIFF is told so by name;
 *   3. the header, via getimagesizefromstring(): its type has to agree with the magic bytes, and
 *      width × height has to fit `avatar_max_mp` — all of this BEFORE a single pixel is decoded,
 *      because a 50000×50000 PNG is sixty bytes on the wire and ten gigabytes in memory;
 *   4. decode, then orient from EXIF;
 *   5. bound (1600 px for a picture's source, 2400 px for a cover plus a 1000 px thumb) and encode.
 * The research this follows (the owner's Flarum extension, flarum-cover-studio) checked the size
 * AFTER decoding, kept WebP uploads byte for byte, cropped the first avatar from the raw upload and
 * every later one from the processed file, and never deleted a replaced file. None of that here.
 *
 * Nothing in this file reads a request or a session: the endpoints (api/user_avatar.php,
 * api/user_cover.php, api/user_media.php, api/admin/user_media.php) do that, so the whole pipeline
 * can be driven from tests/usermedia_test.php with bytes and numbers.
 */

// ── the numbers ──────────────────────────────────────────────────────────────────────────────
// The ceilings are here, and api/admin/save_settings.php and the Settings form read them: one set
// of numbers bounds the setting, the reader and the field.
const USER_MEDIA_MIN_BYTES   = 64;          // the smallest file worth decoding; a real picture is more
const USER_MEDIA_KB_MIN      = 256;
const USER_MEDIA_KB_MAX      = 20480;
const USER_MEDIA_KB_DEFAULT  = 8192;
const USER_MEDIA_MP_MIN      = 4;
const USER_MEDIA_MP_MAX      = 40;
const USER_MEDIA_MP_DEFAULT  = 24;
const USER_COVER_H_MIN       = 96;
const USER_COVER_H_MAX       = 600;
const USER_AVATAR_SIZES      = [64, 128, 256];
const USER_AVATAR_SRC_EDGE   = 1600;        // the stored source of a picture, long edge
const USER_COVER_EDGE        = 2400;        // a stored cover, long edge
const USER_COVER_THUMB_EDGE  = 1000;        // its copy for small surfaces
// The real shapes of the profile header, in CSS px: the 62em column less 0.5rem padding each side on
// a wide screen, and a 390 px phone less 1.5rem each side (assets/css/style.css, .container). The
// editor frames a cover in exactly these, so what survives on each is visible before saving.
const USER_COVER_DESKTOP_W   = 976;
const USER_COVER_PHONE_W     = 342;
const USER_MEDIA_WEBP_Q      = 82;
const USER_MEDIA_ZOOM_MIN    = 0.5;
const USER_MEDIA_ZOOM_MAX    = 4.0;
const USER_MEDIA_RATE_UPLOADS = 6;          // per minute, per account AND per address (the extension's number)
const USER_MEDIA_RATE_RECROPS = 20;         // per minute: each one is a decode and three encodes
const USER_MEDIA_KINDS_AVATAR = ['avatar_src', 'avatar'];
const USER_MEDIA_KINDS_COVER  = ['cover', 'cover_thumb'];
/**
 * The twelve colours a generated picture can be. Material's 800 shades: every one of them carries a
 * white letter at better than 3:1, which is what a letter this large needs to be read.
 */
const USER_AVATAR_COLOURS = ['#1565c0', '#2e7d32', '#c62828', '#6a1b9a', '#d84315', '#00695c',
                             '#283593', '#ad1457', '#4e342e', '#37474f', '#558b2f', '#00838f'];

// ── the switches ─────────────────────────────────────────────────────────────────────────────

/** Pictures at all. Without accounts there is nobody to have one. */
function userAvatarsEnabled(array $cfg): bool
{
    return usersEnabled($cfg) && (($cfg['avatars_enabled'] ?? '1') === '1');
}

/** Profile covers at all. */
function userCoversEnabled(array $cfg): bool
{
    return usersEnabled($cfg) && (($cfg['covers_enabled'] ?? '1') === '1');
}

function userMediaMaxKb(array $cfg): int
{
    $v = (int)($cfg['avatar_max_kb'] ?? USER_MEDIA_KB_DEFAULT);
    return max(USER_MEDIA_KB_MIN, min(USER_MEDIA_KB_MAX, $v ?: USER_MEDIA_KB_DEFAULT));
}

function userMediaMaxMp(array $cfg): int
{
    $v = (int)($cfg['avatar_max_mp'] ?? USER_MEDIA_MP_DEFAULT);
    return max(USER_MEDIA_MP_MIN, min(USER_MEDIA_MP_MAX, $v ?: USER_MEDIA_MP_DEFAULT));
}

/** "8M", "512K", "1G", "-1" -> bytes (-1 and 0 stay as they are: no limit). */
function userMediaIniBytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return -1;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;   // fall through
        case 'm': $n *= 1024;   // fall through
        case 'k': $n *= 1024;
    }
    return $n;
}

/**
 * The most an upload may be, in bytes, AS THIS SERVER WILL ACTUALLY TAKE IT.
 *
 * The setting is the owner's ceiling; PHP's upload_max_filesize and post_max_size are the machine's,
 * and the lower one wins whether anybody says so or not. A form that promises 8 MB on a PHP that
 * stops at 2 MB lets somebody pick a 5 MB photo, wait for it to upload, and be told something
 * about CSRF — because a body over post_max_size arrives with $_POST empty. So the page, the
 * client-side check and the refusal all quote THIS number.
 */
function userMediaMaxBytes(array $cfg): int
{
    $max = userMediaMaxKb($cfg) * 1024;
    $up = userMediaIniBytes((string)ini_get('upload_max_filesize'));
    if ($up > 0) $max = min($max, $up);
    // The body carries the token and the three numbers besides the file; 16 KB is room for them.
    $post = userMediaIniBytes((string)ini_get('post_max_size'));
    if ($post > 0) $max = min($max, max(USER_MEDIA_MIN_BYTES, $post - 16384));
    return $max;
}

function userCoverHeight(array $cfg): int
{
    return max(USER_COVER_H_MIN, min(USER_COVER_H_MAX, (int)($cfg['cover_height'] ?? 220) ?: 220));
}

function userCoverHeightMobile(array $cfg): int
{
    return max(USER_COVER_H_MIN, min(USER_COVER_H_MAX, (int)($cfg['cover_height_mobile'] ?? 160) ?: 160));
}

/** gradient | darken | none; anything else reads as the one that keeps a name readable. */
function userCoverOverlay(array $cfg): string
{
    $v = (string)($cfg['cover_overlay'] ?? 'gradient');
    return in_array($v, ['gradient', 'darken', 'none'], true) ? $v : 'gradient';
}

/**
 * 'image' only when the owner chose it AND there is a default picture to show; otherwise the
 * generated letter. A choice that points at nothing must not draw a broken image beside every name.
 */
function userAvatarDefaultMode(array $cfg): string
{
    return (($cfg['avatar_default'] ?? 'generated') === 'image' && userMediaValidSha((string)($cfg['avatar_default_sha'] ?? '')))
        ? 'image' : 'generated';
}

function userMediaValidSha(string $sha): bool
{
    return (bool)preg_match('/^[0-9a-f]{40}$/', $sha);
}

// ── the numbers somebody sends ───────────────────────────────────────────────────────────────

/**
 * A focal coordinate: null/'' is "not given" (the fallback), anything numeric is CLAMPED to 0-100
 * and rounded to the column's two decimals, and anything else is null — which the endpoint answers
 * with a 422 rather than guessing what "left-ish" means.
 */
function userMediaFocusValue($v, float $fallback): ?float
{
    if ($v === null || $v === '') return $fallback;
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if (!is_finite($f)) return null;
    return round(max(0.0, min(100.0, $f)), 2);
}

/** A zoom factor: the same rules, clamped to 0.5-4. */
function userMediaZoomValue($v, float $fallback): ?float
{
    if ($v === null || $v === '') return $fallback;
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if (!is_finite($f)) return null;
    return round(max(USER_MEDIA_ZOOM_MIN, min(USER_MEDIA_ZOOM_MAX, $f)), 2);
}

/** Two decimals, dot, no thousands: how a focus or a zoom is written into a column, a hash or CSS. */
function userMediaFmt(float $v): string
{
    return number_format($v, 2, '.', '');
}

// ── what the bytes are ───────────────────────────────────────────────────────────────────────

/**
 * What these bytes are, from their magic bytes: 'jpeg' | 'png' | 'gif' | 'webp' (taken), 'svg' |
 * 'bmp' | 'heif' | 'avif' | 'tiff' | 'ico' (pictures this site refuses, named so the refusal can
 * say which), or '' (not a picture at all). The uploader's file name and declared type are never
 * asked: they are two more things somebody typed.
 */
function userMediaSniff(string $b): string
{
    if (strlen($b) < 12) return '';
    if (str_starts_with($b, "\xFF\xD8\xFF")) return 'jpeg';
    if (str_starts_with($b, "\x89PNG\r\n\x1a\n")) return 'png';
    if (str_starts_with($b, 'GIF87a') || str_starts_with($b, 'GIF89a')) return 'gif';
    if (str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP') return 'webp';
    // A BMP opens with "BM" and a DIB header of one of a handful of sizes at byte 14 — asked, so a
    // text file that happens to begin with those two letters is not told it is a bitmap.
    if (str_starts_with($b, 'BM') && strlen($b) > 18
        && in_array(unpack('V', substr($b, 14, 4))[1], [12, 40, 52, 56, 64, 108, 124], true)) return 'bmp';
    if (substr($b, 4, 4) === 'ftyp') {
        $brand = substr($b, 8, 4);
        if (in_array($brand, ['avif', 'avis'], true)) return 'avif';
        if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1', 'heim', 'heis'], true)) return 'heif';
        return '';
    }
    if (str_starts_with($b, "II*\x00") || str_starts_with($b, "MM\x00*")) return 'tiff';
    if (str_starts_with($b, "\x00\x00\x01\x00")) return 'ico';
    // An SVG is text: an <svg element, possibly after a BOM, an XML declaration, a doctype or a comment.
    $head = strtolower(ltrim(substr($b, 0, 1024), "\xEF\xBB\xBF \t\r\n"));
    if (str_starts_with($head, '<svg') || ((str_starts_with($head, '<?xml') || str_starts_with($head, '<!'))
                                           && str_contains($head, '<svg'))) return 'svg';
    return '';
}

/** Is there room in this process to decode a w×h picture? Asked before the decode, never after. */
function userMediaMemoryFits(int $w, int $h): bool
{
    $limit = userMediaIniBytes((string)ini_get('memory_limit'));
    if ($limit <= 0) return true;
    // GD keeps a truecolor pixel in four bytes, the decoders want row buffers on top, and the bounded
    // copy and its encode come after — five bytes a pixel plus a fixed 24 MB is the measured shape
    // of that with room to spare, not a guess at the minimum.
    $need = $w * $h * 5 + 24 * 1024 * 1024;
    return memory_get_usage(true) + $need <= $limit;
}

/** The EXIF orientation of a JPEG, 1-8 (1 = already upright, and the answer for anything unreadable). */
function userMediaExifOrientation(string $bytes): int
{
    if (!function_exists('exif_read_data')) return 1;
    $fp = @fopen('php://memory', 'r+b');
    if ($fp === false) return 1;
    fwrite($fp, $bytes);
    rewind($fp);
    $e = @exif_read_data($fp);
    fclose($fp);
    $o = is_array($e) ? (int)($e['Orientation'] ?? ($e['IFD0']['Orientation'] ?? 1)) : 1;
    return ($o >= 1 && $o <= 8) ? $o : 1;
}

/** A transparent truecolor canvas that keeps its alpha when it is written out. */
function userMediaCanvas(int $w, int $h): \GdImage
{
    $im = imagecreatetruecolor(max(1, $w), max(1, $h));
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    return $im;
}

/** A region of $src resampled onto a new canvas of $dw×$dh. */
function userMediaResample(\GdImage $src, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh): \GdImage
{
    $dst = userMediaCanvas($dw, $dh);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);
    return $dst;
}

/** The whole image, scaled so its long edge is at most $edge. Never enlarged. */
function userMediaBound(\GdImage $im, int $edge): \GdImage
{
    $w = imagesx($im);
    $h = imagesy($im);
    if (max($w, $h) <= $edge) return $im;
    $f = $edge / max($w, $h);
    return userMediaResample($im, 0, 0, $w, $h, max(1, (int)round($w * $f)), max(1, (int)round($h * $f)));
}

/**
 * The pixels turned the way the EXIF orientation says the camera was held. After this the image IS
 * upright, and the WebP it becomes carries no orientation tag at all — nobody downstream has to know.
 * (imagerotate() turns counter-clockwise for a positive angle.)
 */
function userMediaOrient(\GdImage $im, int $o): \GdImage
{
    $clear = imagecolorallocatealpha($im, 0, 0, 0, 127);
    // imagerotate() hands back a NEW image, or false; on false the picture stays as it was rather
    // than the pipeline carrying a false into every call after this one.
    $turn = function (\GdImage $img, int $deg) use ($clear): \GdImage {
        $r = imagerotate($img, $deg, $clear);
        return $r instanceof \GdImage ? $r : $img;
    };
    switch ($o) {
        case 2: imageflip($im, IMG_FLIP_HORIZONTAL); break;
        case 3: $im = $turn($im, 180); break;
        case 4: imageflip($im, IMG_FLIP_VERTICAL); break;
        case 5: imageflip($im, IMG_FLIP_HORIZONTAL); $im = $turn($im, 90); break;    // transpose
        case 6: $im = $turn($im, -90); break;                                         // 90° clockwise
        case 7: imageflip($im, IMG_FLIP_HORIZONTAL); $im = $turn($im, -90); break;   // transverse
        case 8: $im = $turn($im, 90); break;                                          // 90° counter-clockwise
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);
    return $im;
}

/** WebP bytes for an image, alpha kept. '' only if the encoder itself failed. */
function userMediaEncode(\GdImage $im): string
{
    imagesavealpha($im, true);
    ob_start();
    $ok = @imagewebp($im, null, USER_MEDIA_WEBP_Q);
    $out = (string)ob_get_clean();
    return $ok ? $out : '';
}

/**
 * THE door. Bytes in; an upright, bounded GD image out — or a refusal with a lang key.
 *
 * ['ok' => true, 'im' => GdImage, 'w' => …, 'h' => …, 'from' => 'jpeg', 'orientation' => 1..8]
 * ['ok' => false, 'status' => 4xx, 'error' => 'api.media.…', 'detail' => …]
 *
 * Steps 1-3 read nothing but the header. The megapixel ceiling and the memory check are both
 * answered there, so a picture that would not fit is refused having cost a few hundred bytes of
 * parsing rather than the decode that would have taken the process down.
 */
function userMediaPrepare(string $bytes, array $cfg, int $maxEdge): array
{
    // 1. how much
    $len = strlen($bytes);
    if ($len < USER_MEDIA_MIN_BYTES) return ['ok' => false, 'status' => 400, 'error' => 'api.media.too_small'];
    $max = userMediaMaxBytes($cfg);
    if ($len > $max) return ['ok' => false, 'status' => 413, 'error' => 'api.media.too_large', 'detail' => (string)$len];

    // 2. what, by the magic bytes
    $kind = userMediaSniff($bytes);
    if (in_array($kind, ['svg', 'bmp', 'heif', 'avif', 'tiff', 'ico'], true)) {
        return ['ok' => false, 'status' => 415, 'error' => 'api.media.unsupported', 'detail' => $kind];
    }
    if ($kind === '') return ['ok' => false, 'status' => 400, 'error' => 'api.media.not_image'];

    // 3. the header: its own idea of the type has to be ours, and the pixel count has to fit
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || (int)($info[0] ?? 0) <= 0 || (int)($info[1] ?? 0) <= 0) {
        return ['ok' => false, 'status' => 400, 'error' => 'api.media.not_image'];
    }
    $want = ['jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF, 'webp' => IMAGETYPE_WEBP][$kind];
    if ((int)($info[2] ?? 0) !== $want) {
        return ['ok' => false, 'status' => 400, 'error' => 'api.media.mismatch', 'detail' => $kind . '/' . (int)($info[2] ?? 0)];
    }
    $w = (int)$info[0];
    $h = (int)$info[1];
    if ($w * $h > userMediaMaxMp($cfg) * 1000000) {
        return ['ok' => false, 'status' => 413, 'error' => 'api.media.too_many_px', 'detail' => $w . '×' . $h];
    }
    if (!userMediaMemoryFits($w, $h)) {
        return ['ok' => false, 'status' => 413, 'error' => 'api.media.too_big_to_process', 'detail' => $w . '×' . $h];
    }

    // 4. decode — the first moment a pixel is touched — and read which way up it was taken
    $orientation = $kind === 'jpeg' ? userMediaExifOrientation($bytes) : 1;
    $im = @imagecreatefromstring($bytes);
    if (!($im instanceof \GdImage)) return ['ok' => false, 'status' => 400, 'error' => 'api.media.unreadable'];
    // A GIF (and an 8-bit PNG) decodes to a palette; everything after this works in truecolor, and an
    // animated GIF has become its first frame by now — the decoder reads no further.
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    imagealphablending($im, false);
    imagesavealpha($im, true);

    // 5. bounded FIRST and turned upright second: the long edge is the long edge whichever way up
    // the picture is, and rotating the bounded copy instead of the decoded original is the
    // difference between holding two full-size images in memory and holding one.
    $im = userMediaBound($im, $maxEdge);
    if ($orientation !== 1) $im = userMediaOrient($im, $orientation);

    return ['ok' => true, 'im' => $im, 'w' => imagesx($im), 'h' => imagesy($im), 'from' => $kind, 'orientation' => $orientation];
}

// ── the picture: its source and its squares ──────────────────────────────────────────────────

/**
 * The window a picture is cut from, in source pixels: [left, top, side].
 *
 * EXACTLY the research's formula (flarum-cover-studio, AvatarFocusService, section 3.4 step 7):
 *   side = round(min(w,h) / max(0.01, zoom)),  left = round(x/100 * (w - side)),  top = round(y/100 * (h - side))
 * which is what `object-fit: cover; object-position: X% Y%; transform: scale(Z); transform-origin:
 * X% Y%` shows in a square box — worked through in the docblock of userAvatarCut(). There is NO
 * minimum window: the extension's 100 px floor made a small picture at high zoom save less zoomed
 * than its own preview had shown.
 *
 * Below zoom 1 the window is larger than the short edge and `left`/`top` go negative: the picture
 * floats inside the window, which is exactly what the editor draws over its blurred backdrop.
 */
function userAvatarWindow(int $w, int $h, float $x, float $y, float $zoom): array
{
    $side = (int)round(min($w, $h) / max(0.01, $zoom));
    $side = max(1, $side);
    $left = (int)round($x / 100 * ($w - $side));
    $top  = (int)round($y / 100 * ($h - $side));
    return [$left, $top, $side];
}

/**
 * Which squares a window this big can give without enlarging anything: 64 always (every surface
 * needs something, and it is the one size there is no smaller alternative to), then 128 and 256 only
 * when the window has at least that many source pixels across. The stream serves the largest one at
 * or below what was asked, so a missing 256 is answered with the 128 in its place.
 */
function userAvatarSizesFor(int $side): array
{
    $out = [];
    foreach (USER_AVATAR_SIZES as $s) {
        if ($s === USER_AVATAR_SIZES[0] || $side >= $s) $out[] = $s;
    }
    return $out;
}

/**
 * The squares, as GD images keyed by edge.
 *
 * Why this is the CSS: in a square box of edge B, cover-fit scales the image by s = B/min(w,h), and
 * object-position X% puts its left edge at X/100·(B − w·s). scale(Z) about the point X/100·B then
 * shows the pre-transform strip of width B/Z starting at X/100·B·(1 − 1/Z). In image pixels that is
 * a window of min(w,h)/Z starting at X/100·(w − min(w,h)/Z) — the formula above, to the pixel.
 *
 * Zoom below 1 composes: the zoom-1 window (the picture cover-fitted at the same focus), shrunk to
 * 32 px, blurred and stretched back up — the cheap soft blur of downscale → blur → upscale — then
 * darkened by 15 % like the editor's `brightness(.85)`, and the picture laid over it where the window
 * says. The fill is never the only thing a transparent picture sits on by accident: it is what the
 * editor showed behind it too.
 */
function userAvatarCut(\GdImage $src, float $x, float $y, float $zoom): array
{
    $w = imagesx($src);
    $h = imagesy($src);
    [$left, $top, $side] = userAvatarWindow($w, $h, $x, $y, $zoom);
    $sizes = userAvatarSizesFor($side);
    $out = [];
    if ($side <= $w && $side <= $h) {
        foreach ($sizes as $s) $out[$s] = userMediaResample($src, $left, $top, $side, $side, $s, $s);
        return $out;
    }
    // The backdrop: the zoom-1 window, blurred small.
    $m = min($w, $h);
    $bl = (int)round($x / 100 * ($w - $m));
    $bt = (int)round($y / 100 * ($h - $m));
    $blur = userMediaResample($src, $bl, $bt, $m, $m, 32, 32);
    for ($i = 0; $i < 3; $i++) imagefilter($blur, IMG_FILTER_GAUSSIAN_BLUR);
    // The part of the picture the window actually contains, in source pixels.
    $ix0 = max(0, $left);
    $iy0 = max(0, $top);
    $ix1 = min($w, $left + $side);
    $iy1 = min($h, $top + $side);
    foreach ($sizes as $s) {
        $dst = userMediaCanvas($s, $s);
        imagecopyresampled($dst, $blur, 0, 0, 0, 0, $s, $s, 32, 32);
        imagealphablending($dst, true);
        imagefilledrectangle($dst, 0, 0, $s - 1, $s - 1, imagecolorallocatealpha($dst, 0, 0, 0, (int)round(127 * 0.85)));
        if ($ix1 > $ix0 && $iy1 > $iy0) {
            $f = $s / $side;
            imagecopyresampled($dst, $src,
                (int)round(($ix0 - $left) * $f), (int)round(($iy0 - $top) * $f), $ix0, $iy0,
                max(1, (int)round(($ix1 - $ix0) * $f)), max(1, (int)round(($iy1 - $iy0) * $f)),
                $ix1 - $ix0, $iy1 - $iy0);
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $out[$s] = $dst;
    }
    return $out;
}

/**
 * The id every square of ONE crop shares: the source and the framing, hashed. The same source cut
 * the same way is the same id and the same bytes, so the address cannot go stale; any change to any
 * of the four is a new id and therefore a new address, which is what lets that address be cached for
 * a year.
 */
function userAvatarCropId(string $srcSha, float $x, float $y, float $zoom): string
{
    return sha1('avatar-v1|' . $srcSha . '|' . userMediaFmt($x) . '|' . userMediaFmt($y) . '|' . userMediaFmt($zoom));
}

// ── writing ──────────────────────────────────────────────────────────────────────────────────

/** One row in. Returns its id. */
function userMediaInsert(PDO $db, ?int $userId, string $kind, ?int $size, string $sha, string $bytes, int $w, int $h): int
{
    $st = $db->prepare("INSERT INTO user_media (user_id, kind, size, sha1, mime, bytes, width, height, data)
                        VALUES (?, ?, ?, ?, 'image/webp', ?, ?, ?, ?)");
    $st->bindValue(1, $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(2, $kind);
    $st->bindValue(3, $size, $size === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(4, $sha);
    $st->bindValue(5, strlen($bytes), PDO::PARAM_INT);
    $st->bindValue(6, $w, PDO::PARAM_INT);
    $st->bindValue(7, $h, PDO::PARAM_INT);
    $st->bindValue(8, $bytes, PDO::PARAM_LOB);
    $st->execute();
    return (int)$db->lastInsertId();
}

/**
 * Delete this owner's rows of these kinds, except the ones just written. NULL-safe (`<=>`), so the
 * site's own rows (user_id NULL) are an owner like any other. The kinds are literals from this file.
 */
function userMediaDeleteExcept(PDO $db, ?int $userId, array $kinds, array $keepIds): int
{
    $kinds = array_values(array_intersect($kinds, array_merge(USER_MEDIA_KINDS_AVATAR, USER_MEDIA_KINDS_COVER)));
    if (!$kinds) return 0;
    $in = implode(',', array_fill(0, count($kinds), '?'));
    $args = array_merge([$userId], $kinds);
    $sql = "DELETE FROM user_media WHERE user_id <=> ? AND kind IN ($in)";
    if ($keepIds) {
        $ph = implode(',', array_fill(0, count($keepIds), '?'));
        $sql .= " AND id NOT IN ($ph)";
        $args = array_merge($args, array_map('intval', $keepIds));
    }
    $st = $db->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

/** Where a picture's framing lives: the account's row, or the site's settings when $userId is NULL. */
function userMediaRecordAvatar(PDO $db, ?int $userId, ?string $cropId, float $x, float $y, float $zoom): void
{
    if ($userId === null) {
        setSettings($db, ['avatar_default_sha' => (string)$cropId, 'avatar_default_x' => userMediaFmt($x),
                          'avatar_default_y' => userMediaFmt($y), 'avatar_default_zoom' => userMediaFmt($zoom)]);
        return;
    }
    $db->prepare("UPDATE users SET avatar_sha = ?, avatar_x = ?, avatar_y = ?, avatar_zoom = ? WHERE id = ?")
       ->execute([$cropId, userMediaFmt($x), userMediaFmt($y), userMediaFmt($zoom), $userId]);
}

/** The same for a cover. */
function userMediaRecordCover(PDO $db, ?int $userId, ?string $sha, float $x, float $y, float $zoom): void
{
    if ($userId === null) {
        setSettings($db, ['cover_default_sha' => (string)$sha, 'cover_default_x' => userMediaFmt($x),
                          'cover_default_y' => userMediaFmt($y), 'cover_default_zoom' => userMediaFmt($zoom)]);
        return;
    }
    $db->prepare("UPDATE users SET cover_sha = ?, cover_x = ?, cover_y = ?, cover_zoom = ? WHERE id = ?")
       ->execute([$sha, userMediaFmt($x), userMediaFmt($y), userMediaFmt($zoom), $userId]);
}

/**
 * Cut the squares from a decoded source and write them — plus the source itself when it is new —
 * then delete every row they replace, all in ONE transaction. Afterwards there is exactly one crop
 * of this owner's picture in the table, and nothing an earlier upload left is reachable at any
 * address, public or not (the research's 6b.2: every replaced cover stayed on the disk for ever).
 */
function userAvatarWrite(PDO $db, ?int $userId, \GdImage $src, string $srcSha, ?string $srcBytes, float $x, float $y, float $zoom): array
{
    $cropId = userAvatarCropId($srcSha, $x, $y, $zoom);
    $encoded = [];
    foreach (userAvatarCut($src, $x, $y, $zoom) as $s => $im) {
        $b = userMediaEncode($im);
        if ($b === '') return ['ok' => false, 'status' => 500, 'error' => 'api.media.store_failed', 'detail' => 'encode'];
        $encoded[$s] = $b;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    try {
        $db->beginTransaction();
        $keep = [];
        if ($srcBytes !== null) $keep[] = userMediaInsert($db, $userId, 'avatar_src', null, $srcSha, $srcBytes, $w, $h);
        foreach ($encoded as $s => $b) $keep[] = userMediaInsert($db, $userId, 'avatar', (int)$s, $cropId, $b, (int)$s, (int)$s);
        userMediaDeleteExcept($db, $userId, $srcBytes !== null ? USER_MEDIA_KINDS_AVATAR : ['avatar'], $keep);
        userMediaRecordAvatar($db, $userId, $cropId, $x, $y, $zoom);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[usermedia] avatar write: ' . $e->getMessage());
        return ['ok' => false, 'status' => 500, 'error' => 'api.media.store_failed'];
    }
    return ['ok' => true, 'status' => 200, 'crop' => $cropId, 'src_sha' => $srcSha, 'sizes' => array_keys($encoded),
            'x' => $x, 'y' => $y, 'zoom' => $zoom, 'w' => $w, 'h' => $h];
}

/**
 * A new picture: through the pipeline, stored as its source, and cut from THAT source — the very
 * bytes every later re-crop will decode — so the first crop and the tenth agree to the pixel. (The
 * extension cut the first one from the raw upload and the rest from the processed file.)
 * `$userId` NULL is the site's default picture.
 */
function userAvatarStore(PDO $db, array $cfg, ?int $userId, string $bytes, float $x, float $y, float $zoom): array
{
    $p = userMediaPrepare($bytes, $cfg, USER_AVATAR_SRC_EDGE);
    if (empty($p['ok'])) return $p;
    $srcBytes = userMediaEncode($p['im']);
    unset($p['im']);
    if ($srcBytes === '') return ['ok' => false, 'status' => 500, 'error' => 'api.media.store_failed', 'detail' => 'encode'];
    $src = @imagecreatefromstring($srcBytes);
    if (!($src instanceof \GdImage)) return ['ok' => false, 'status' => 500, 'error' => 'api.media.store_failed', 'detail' => 'reread'];
    $r = userAvatarWrite($db, $userId, $src, sha1($srcBytes), $srcBytes, $x, $y, $zoom);
    if (!empty($r['ok'])) $r['from'] = $p['from'];
    return $r;
}

/** The stored source of a picture, with or without its bytes, or null. */
function userAvatarSourceRow(PDO $db, ?int $userId, bool $withData = false): ?array
{
    $cols = $withData ? 'id, user_id, sha1, width, height, bytes, data' : 'id, user_id, sha1, width, height, bytes';
    try {
        $st = $db->prepare("SELECT $cols FROM user_media WHERE user_id <=> ? AND kind = 'avatar_src' ORDER BY id DESC LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null;   // the table arrives with schema 69
    }
    return $row ?: null;
}

/** New framing for the picture already there: decode the stored source, cut again, replace the squares. */
function userAvatarReposition(PDO $db, array $cfg, ?int $userId, float $x, float $y, float $zoom): array
{
    $row = userAvatarSourceRow($db, $userId, true);
    if ($row === null) return ['ok' => false, 'status' => 404, 'error' => 'api.media.no_picture'];
    $src = @imagecreatefromstring((string)$row['data']);
    if (!($src instanceof \GdImage)) return ['ok' => false, 'status' => 500, 'error' => 'api.media.unreadable'];
    return userAvatarWrite($db, $userId, $src, (string)$row['sha1'], null, $x, $y, $zoom);
}

/**
 * A new cover: through the pipeline, bounded to 2400 px, plus a 1000 px copy for small surfaces when
 * the cover is larger than that. Never cropped — the framing is data the page paints with.
 */
function userCoverStore(PDO $db, array $cfg, ?int $userId, string $bytes, float $x, float $y, float $zoom): array
{
    $p = userMediaPrepare($bytes, $cfg, USER_COVER_EDGE);
    if (empty($p['ok'])) return $p;
    $im = $p['im'];
    unset($p['im']);
    $coverBytes = userMediaEncode($im);
    if ($coverBytes === '') return ['ok' => false, 'status' => 500, 'error' => 'api.media.store_failed', 'detail' => 'encode'];
    $w = imagesx($im);
    $h = imagesy($im);
    $sha = sha1($coverBytes);
    $thumb = null;
    if (max($w, $h) > USER_COVER_THUMB_EDGE) {
        $t = userMediaBound($im, USER_COVER_THUMB_EDGE);
        $thumb = ['bytes' => userMediaEncode($t), 'w' => imagesx($t), 'h' => imagesy($t)];
        if ($thumb['bytes'] === '') $thumb = null;   // the cover itself serves small surfaces then
    }
    try {
        $db->beginTransaction();
        $keep = [userMediaInsert($db, $userId, 'cover', null, $sha, $coverBytes, $w, $h)];
        if ($thumb !== null) $keep[] = userMediaInsert($db, $userId, 'cover_thumb', null, $sha, $thumb['bytes'], $thumb['w'], $thumb['h']);
        userMediaDeleteExcept($db, $userId, USER_MEDIA_KINDS_COVER, $keep);
        userMediaRecordCover($db, $userId, $sha, $x, $y, $zoom);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[usermedia] cover write: ' . $e->getMessage());
        return ['ok' => false, 'status' => 500, 'error' => 'api.media.store_failed'];
    }
    return ['ok' => true, 'status' => 200, 'sha' => $sha, 'thumb' => $thumb !== null, 'w' => $w, 'h' => $h,
            'x' => $x, 'y' => $y, 'zoom' => $zoom, 'from' => $p['from']];
}

/** New framing for the cover already there. No image work at all: the page paints the numbers. */
function userCoverReposition(PDO $db, array $cfg, ?int $userId, float $x, float $y, float $zoom): array
{
    try {
        $st = $db->prepare("SELECT sha1 FROM user_media WHERE user_id <=> ? AND kind = 'cover' ORDER BY id DESC LIMIT 1");
        $st->execute([$userId]);
        $sha = $st->fetchColumn();
    } catch (\Throwable $e) {
        $sha = false;
    }
    if (!$sha) return ['ok' => false, 'status' => 404, 'error' => 'api.media.no_cover'];
    userMediaRecordCover($db, $userId, (string)$sha, $x, $y, $zoom);
    return ['ok' => true, 'status' => 200, 'sha' => (string)$sha, 'x' => $x, 'y' => $y, 'zoom' => $zoom];
}

/**
 * Take a picture or a cover away: every row of it deleted and the columns (or the site's settings)
 * back to "none", in one transaction. Nothing is kept anywhere — the account page says so before it
 * asks, because the extension this follows kept every file and said so.
 */
function userMediaRemove(PDO $db, ?int $userId, string $what): array
{
    $kinds = $what === 'cover' ? USER_MEDIA_KINDS_COVER : USER_MEDIA_KINDS_AVATAR;
    try {
        $db->beginTransaction();
        $n = userMediaDeleteExcept($db, $userId, $kinds, []);
        if ($what === 'cover') userMediaRecordCover($db, $userId, null, 50.0, 50.0, 1.0);
        else                   userMediaRecordAvatar($db, $userId, null, 50.0, 50.0, 1.0);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[usermedia] remove: ' . $e->getMessage());
        return ['ok' => false, 'status' => 500, 'error' => 'api.media.store_failed'];
    }
    return ['ok' => true, 'status' => 200, 'removed' => $n];
}

// ── the rate limits ──────────────────────────────────────────────────────────────────────────

/**
 * May this account, from this address, do one more of these? 'upload' is a decode and up to four
 * encodes; 'recrop' is a decode and three. Per account AND per address, so neither a script behind
 * one login nor one behind many logins gets further than the other (the research's 6b.8: the
 * extension counted only the owner's files, charged a moderator's uploads to the member, and never
 * limited a re-crop at all).
 */
function userMediaRateAllow(string $what, int $userId, string $ip): bool
{
    $max = $what === 'upload' ? USER_MEDIA_RATE_UPLOADS : USER_MEDIA_RATE_RECROPS;
    $action = 'media_' . ($what === 'upload' ? 'up' : 'crop');
    $bucket = function_exists('ipBucket') ? ipBucket($ip) : $ip;
    return rateLimitAllow($action . '_u', 'u' . $userId, $max, 60)
        && rateLimitAllow($action . '_ip', $bucket, $max, 60);
}

// ── reading it back: the stream ──────────────────────────────────────────────────────────────

/**
 * The row an address means, WITH its bytes, or null for "not found" — which is also the answer for
 * "not yours" and "switched off", so the stream never tells a stranger that something exists.
 *
 * `h` is the first sixteen hex characters of a sha1 (a prefix, so the index still does the work).
 * `s`: 64/128/256 asks for a picture's square — the largest one at or below it is served, because a
 * small source may never have had the larger sizes; 0 asks for a cover; 1000 its thumb (the cover
 * itself when it was already small); an owner's source answers to 0 as well, to its owner and to a
 * panel session only.
 */
function userMediaFind(PDO $db, array $cfg, string $h, int $s, ?int $viewerId, bool $panel): ?array
{
    if (!preg_match('/^[0-9a-f]{16}$/', $h)) return null;
    try {
        // Without the bytes: several rows can answer to one prefix (three sizes of a crop, a cover
        // and its thumb, the same picture uploaded by two people) and only the chosen one is read.
        // The bound is generous for the one honest way to reach it — many people framing the same
        // picture the same way share a crop id — and each of them contributes all three sizes.
        // NO ORDER BY: which row wins is decided below, and `ORDER BY id … LIMIT` is exactly the
        // shape that walks the primary key instead of the (sha1, size) range this is indexed for.
        $st = $db->prepare("SELECT id, user_id, kind, size, sha1, mime, bytes, width, height FROM user_media
                             WHERE sha1 LIKE ? LIMIT 60");
        $st->execute([$h . '%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return null;
    }
    if (!$rows) return null;
    $pick = null;
    $squares = array_values(array_filter($rows, fn($r) => $r['kind'] === 'avatar'));
    if ($squares) {
        if (!$panel && !userAvatarsEnabled($cfg)) return null;
        $want = max(USER_AVATAR_SIZES[0], $s);
        usort($squares, fn($a, $b) => (int)$b['size'] <=> (int)$a['size']);
        foreach ($squares as $r) { if ((int)$r['size'] <= $want) { $pick = $r; break; } }
        if ($pick === null) $pick = end($squares);
    } else {
        $covers = array_values(array_filter($rows, fn($r) => $r['kind'] === 'cover' || $r['kind'] === 'cover_thumb'));
        if ($covers) {
            if (!$panel && !userCoversEnabled($cfg)) return null;
            $wantThumb = $s > 0;
            foreach ($covers as $r) { if (($r['kind'] === 'cover_thumb') === $wantThumb) { $pick = $r; break; } }
            if ($pick === null) {
                foreach ($covers as $r) { if ($r['kind'] === 'cover') { $pick = $r; break; } }
            }
        } else {
            // A source. Its owner's own, or the panel's — the uncropped original may show what
            // somebody deliberately cropped out, so it is never a public address. The same bytes
            // uploaded by two people are two rows, and the owner is looked for among them.
            foreach ($rows as $r) {
                if ($r['kind'] !== 'avatar_src') continue;
                $owner = $r['user_id'] === null ? null : (int)$r['user_id'];
                if ($panel || ($viewerId !== null && $owner === $viewerId)) { $pick = $r; break; }
            }
        }
    }
    if ($pick === null) return null;
    $st = $db->prepare("SELECT data FROM user_media WHERE id = ?");
    $st->execute([(int)$pick['id']]);
    $data = $st->fetchColumn();
    if ($data === false) return null;
    $pick['data'] = (string)$data;
    return $pick;
}

// ── addresses: what phase B draws beside every name ──────────────────────────────────────────

/** The stream address for a stored row: the first sixteen of its sha1 and a size. No id, no name. */
function userMediaUrl(string $sha, int $s, string $baseUrl): string
{
    return $baseUrl . 'api.php?endpoint=user_media&h=' . substr($sha, 0, 16) . '&s=' . $s;
}

/** The square for a picture drawn at $cssPx: twice the CSS size (sharp on a high-density screen), capped at 256. */
function userAvatarVariantFor(int $cssPx): int
{
    foreach (USER_AVATAR_SIZES as $s) {
        if ($s >= $cssPx * 2) return $s;
    }
    return USER_AVATAR_SIZES[count(USER_AVATAR_SIZES) - 1];
}

/**
 * The letter a generated picture shows: the first letter or digit of the name, upper-cased, or 'U'
 * for a name made of nothing but dots, dashes and underscores. Always one of 36 characters.
 */
function userAvatarLetter(string $username): string
{
    return preg_match('/[A-Za-z0-9]/', $username, $m) ? strtoupper($m[0]) : 'U';
}

/**
 * Which of the twelve colours: FNV-1a over the lower-cased name, modulo twelve. A hash of the NAME,
 * so a person keeps their colour on every page and every visit; FNV-1a because it is four lines in
 * PHP and four in JavaScript and both give the same number (assets/js/avatar.js has the twin).
 */
function userAvatarColourIndex(string $username): int
{
    $h = 2166136261;
    $s = strtolower($username);
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $h ^= ord($s[$i]);
        $h = ($h * 16777619) & 0xFFFFFFFF;
    }
    return $h % count(USER_AVATAR_COLOURS);
}

/** The generated picture's address. 36 letters × 12 colours is the whole space, so it caches perfectly. */
function userAvatarGeneratedUrl(string $username, string $baseUrl): string
{
    return $baseUrl . 'api.php?endpoint=user_avatar_default&l=' . userAvatarLetter($username) . '&c=' . userAvatarColourIndex($username);
}

/**
 * THE helper phase B calls beside every name: the right address for somebody's picture.
 *
 * `$userish` is anything carrying `username` and `avatar_sha` (a full users row, or the subset a
 * JOIN picked; `id` is accepted and not needed). The account's own square when it has one, else the
 * site's default picture when the owner set one, else the generated letter. No query inside: the
 * default's id is a setting, read from `$cfg` or, when the caller has none, the request's global.
 *
 * With pictures switched off it answers the letter — a caller that insists on an address gets a
 * harmless one — while userAvatarHtml() below draws nothing at all.
 */
function userAvatarUrl(array $userish, int $size, string $baseUrl, ?array $cfg = null): string
{
    $cfg = $cfg ?? (is_array($GLOBALS['cfg'] ?? null) ? $GLOBALS['cfg'] : []);
    $name = (string)($userish['username'] ?? '');
    if (!userAvatarsEnabled($cfg)) return userAvatarGeneratedUrl($name, $baseUrl);
    $v = userAvatarVariantFor($size);
    $own = strtolower((string)($userish['avatar_sha'] ?? ''));
    if (userMediaValidSha($own)) return userMediaUrl($own, $v, $baseUrl);
    if (userAvatarDefaultMode($cfg) === 'image') return userMediaUrl(strtolower((string)$cfg['avatar_default_sha']), $v, $baseUrl);
    return userAvatarGeneratedUrl($name, $baseUrl);
}

/**
 * The same, as the one element every renderer draws: square, sized, decorative (the name is always
 * beside it), lazy. `data-fallback` is the generated letter, which assets/js/avatar.js swaps in if
 * the image ever fails to load — a missing row must not leave a broken-image icon beside a name.
 * '' when pictures are switched off: nothing is drawn rather than a letter nobody asked for.
 *
 * `$userish` is a users row (or the part of one a JOIN picked) — or, from 1.63.0 phase B, a row an
 * endpoint shaped for the browser, which carries the picture's ADDRESS as `avatar` (built by
 * userAvatarField() on the server) instead of the account behind it. The shoutbox draws its first
 * page from exactly those rows, so the server and assets/js/shoutbox.js hand this element the same
 * input and cannot draw two different pictures. `avatar` = '' is the server saying "nothing here".
 *
 * `srcset` names the square each screen density should take (userAvatarSrcset); `src` stays the
 * square userAvatarUrl() picks, twice the CSS size, for anything that does not read srcset.
 */
function userAvatarHtml(array $userish, int $size, string $baseUrl, string $class = 'avatar', ?array $cfg = null): string
{
    $cfg = $cfg ?? (is_array($GLOBALS['cfg'] ?? null) ? $GLOBALS['cfg'] : []);
    if (!userAvatarsEnabled($cfg)) return '';
    $size = max(8, min(512, $size));
    $fallback = userAvatarGeneratedUrl((string)($userish['username'] ?? ''), $baseUrl);
    if (array_key_exists('avatar', $userish) && is_string($userish['avatar'])) {
        if ($userish['avatar'] === '') return '';
        // An address this file did not write is not drawn: the letter stands in for it.
        $url = userAvatarSized($userish['avatar'], $size, $baseUrl) ?? $fallback;
    } else {
        $url = userAvatarUrl($userish, $size, $baseUrl, $cfg);
    }
    $srcset = userAvatarSrcset($url, $size, $baseUrl);
    return '<img class="' . htmlspecialchars(trim($class . ' js-avatar'), ENT_QUOTES, 'UTF-8') . '"'
         . ' src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
         . ($srcset !== '' ? ' srcset="' . htmlspecialchars($srcset, ENT_QUOTES, 'UTF-8') . '"' : '')
         . ($fallback !== $url ? ' data-fallback="' . htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8') . '"' : '')
         . ' width="' . $size . '" height="' . $size . '" alt="" loading="lazy" decoding="async">';
}

// ── beside every name (1.63.0 phase B) ───────────────────────────────────────────────────────────
//
// Most endpoints that list people send a NAME and deliberately not the account id behind it, even
// where their SQL has it. So what travels in their JSON is the picture's ADDRESS, built here from the
// columns the endpoint's own JOIN already picked — never an id, and never a second query per row. The
// renderers read that address back through userAvatarHtml() above and window.userAvatarImg()
// (assets/js/avatar.js), which recognise only the three shapes this file writes.

/**
 * The site's own mark: what a line the SITE said is signed with, where a person's picture would go
 * (the shoutbox's system lines, and a line whose author's account has since gone). The favicon,
 * because it is the one picture that already means "this tracker" on every page.
 */
function userAvatarSiteUrl(string $baseUrl): string
{
    return $baseUrl . 'assets/img/favicon.svg';
}

/**
 * Which of this file's addresses $url is, or null for anything else: 'media' (an account's square or
 * the site's default picture, with `prefix` = everything up to the size), 'letter' (the generated
 * picture) or 'site' (the site's mark). assets/js/avatar.js asks the same three questions.
 */
function userAvatarUrlKind(string $url, string $baseUrl): ?array
{
    $b = preg_quote($baseUrl, '~');
    if (preg_match('~^(' . $b . 'api\.php\?endpoint=user_media&h=[0-9a-f]{16}&s=)[0-9]{1,3}$~', $url, $m)) {
        return ['kind' => 'media', 'prefix' => $m[1]];
    }
    if (preg_match('~^' . $b . 'api\.php\?endpoint=user_avatar_default&l=[A-Z0-9]&c=(?:[0-9]|1[01])$~', $url)) {
        return ['kind' => 'letter'];
    }
    return $url === userAvatarSiteUrl($baseUrl) ? ['kind' => 'site'] : null;
}

/** The smallest stored square with at least $px device pixels, or the largest there is. */
function userAvatarSquareAtLeast(int $px): int
{
    foreach (USER_AVATAR_SIZES as $s) {
        if ($s >= $px) return $s;
    }
    return USER_AVATAR_SIZES[count(USER_AVATAR_SIZES) - 1];
}

/**
 * That address for a picture drawn at $size CSS px. An account's square becomes the same crop at the
 * size this surface needs (twice the CSS size, as userAvatarUrl() picks); a letter and the site's mark
 * are drawings with one address at every size. Null for an address this file did not write.
 */
function userAvatarSized(string $url, int $size, string $baseUrl): ?string
{
    $k = userAvatarUrlKind($url, $baseUrl);
    if ($k === null) return null;
    return $k['kind'] === 'media' ? $k['prefix'] . userAvatarVariantFor($size) : $url;
}

/**
 * The `srcset` of a picture drawn at $size CSS px: for a 1x, a 2x and a 3x screen, the smallest
 * stored square still sharp there, each listed at the HIGHEST density it serves — so a 2x screen is
 * never sent to the 3x file for want of an entry of its own. '' when one square suits every screen
 * (anything up to 21 px is the 64 everywhere) and for a drawing, which is sharp at any size.
 */
function userAvatarSrcset(string $url, int $size, string $baseUrl): string
{
    $k = userAvatarUrlKind($url, $baseUrl);
    if ($k === null || $k['kind'] !== 'media') return '';
    $pairs = [];
    foreach ([1, 2, 3] as $d) {
        $v = userAvatarSquareAtLeast($size * $d);
        $last = count($pairs) - 1;
        if ($last >= 0 && $pairs[$last][0] === $v) $pairs[$last][1] = $d;
        else $pairs[] = [$v, $d];
    }
    if (count($pairs) < 2) return '';
    return implode(', ', array_map(static fn(array $p): string => $k['prefix'] . $p[0] . ' ' . $p[1] . 'x', $pairs));
}

/**
 * For a JSON row: the address to draw beside a name, or '' while pictures are switched off — which
 * every renderer reads as "draw nothing". Built from `username` and `avatar_sha`, the two columns the
 * endpoint's own JOIN adds; an endpoint that withholds the account id sends this instead of it, and
 * it names a picture, not a person.
 */
function userAvatarField(array $userish, int $size, string $baseUrl, ?array $cfg = null): string
{
    $cfg = $cfg ?? (is_array($GLOBALS['cfg'] ?? null) ? $GLOBALS['cfg'] : []);
    return userAvatarsEnabled($cfg) ? userAvatarUrl($userish, $size, $baseUrl, $cfg) : '';
}

/** The same for a line the site itself said: its mark, or '' while pictures are switched off. */
function userAvatarSiteField(string $baseUrl, ?array $cfg = null): string
{
    $cfg = $cfg ?? (is_array($GLOBALS['cfg'] ?? null) ? $GLOBALS['cfg'] : []);
    return userAvatarsEnabled($cfg) ? userAvatarSiteUrl($baseUrl) : '';
}

/**
 * assets/js/avatar.js for a PANEL page, carrying on its own tag the two facts the public layout hands
 * it as APP_MEDIA and APP_BASE — the panel has neither global and does not need to grow them.
 */
function userAvatarScriptTag(string $baseUrl, array $cfg): string
{
    $def = userAvatarDefaultMode($cfg) === 'image' ? substr(strtolower((string)$cfg['avatar_default_sha']), 0, 16) : '';
    $ver = function_exists('assetVer') ? assetVer('assets/js/avatar.js') : '';
    return '<script src="' . htmlspecialchars($baseUrl . 'assets/js/avatar.js' . $ver, ENT_QUOTES, 'UTF-8') . '"'
         . ' data-base="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . '"'
         . ' data-avatars="' . (userAvatarsEnabled($cfg) ? '1' : '0') . '" data-def="' . $def . '"></script>';
}

/**
 * Somebody's cover as the page paints it: ['url', 'thumb', 'x', 'y', 'zoom', 'default' => bool], or
 * null for "no band at all". Their own first, then the site's default cover, then nothing — which
 * the profile page draws as the plain head it always had.
 */
function userCoverFor(array $userish, string $baseUrl, ?array $cfg = null): ?array
{
    $cfg = $cfg ?? (is_array($GLOBALS['cfg'] ?? null) ? $GLOBALS['cfg'] : []);
    if (!userCoversEnabled($cfg)) return null;
    $own = strtolower((string)($userish['cover_sha'] ?? ''));
    if (userMediaValidSha($own)) {
        return ['url' => userMediaUrl($own, 0, $baseUrl), 'thumb' => userMediaUrl($own, USER_COVER_THUMB_EDGE, $baseUrl),
                'x' => (float)($userish['cover_x'] ?? 50), 'y' => (float)($userish['cover_y'] ?? 50),
                'zoom' => (float)($userish['cover_zoom'] ?? 1), 'default' => false];
    }
    $def = strtolower((string)($cfg['cover_default_sha'] ?? ''));
    if (userMediaValidSha($def)) {
        return ['url' => userMediaUrl($def, 0, $baseUrl), 'thumb' => userMediaUrl($def, USER_COVER_THUMB_EDGE, $baseUrl),
                'x' => (float)($cfg['cover_default_x'] ?? 50), 'y' => (float)($cfg['cover_default_y'] ?? 50),
                'zoom' => (float)($cfg['cover_default_zoom'] ?? 1), 'default' => true];
    }
    return null;
}

/**
 * The CSS custom properties that paint one cover, as a declaration list. Numbers this code formats
 * and an address built from hex: nothing anybody typed reaches a stylesheet through here.
 */
function userCoverCssVars(array $cover): string
{
    $url = str_replace(['\\', '"'], ['%5C', '%22'], (string)$cover['url']);
    return '--cv-img:url("' . $url . '");--cv-x:' . userMediaFmt((float)$cover['x']) . '%;--cv-y:'
         . userMediaFmt((float)$cover['y']) . '%;--cv-z:' . userMediaFmt((float)$cover['zoom']) . ';';
}

/**
 * A nonce'd <style> that paints one element's cover, for a page rendered on the server.
 *
 * Not a style="" attribute: includes/csp.php keeps 'unsafe-inline' in style-src only until the
 * attributes it lists are gone, and the way it will drop it is by moving style-src onto this same
 * per-request nonce — at which point an attribute is refused and this block is not. It paints on the
 * first frame, before any script has run, and JavaScript updates the same properties afterwards
 * through the CSSOM (element.style.setProperty), which no policy governs.
 */
function userCoverStyleBlock(string $selector, ?array $cover, array $cfg): string
{
    if (!preg_match('/^[#.][A-Za-z0-9_-]+$/', $selector)) return '';
    // The heights, and the desktop header's shape as a ratio for a miniature of it — present even
    // with no cover to paint, so an empty preview is already the shape the first cover will have.
    return '<style' . nonceAttr() . '>' . $selector . '{' . ($cover !== null ? userCoverCssVars($cover) : '')
         . '--cv-h:' . userCoverHeight($cfg) . 'px;--cv-hm:' . userCoverHeightMobile($cfg) . 'px;'
         . '--cv-ar:' . USER_COVER_DESKTOP_W . '/' . userCoverHeight($cfg) . '}</style>';
}

/**
 * Everything the account page's editor needs about one account, and nothing a stranger may have:
 * the source address is here because this is only ever drawn for its owner.
 */
function userMediaEditorState(PDO $db, array $cfg, array $user, string $baseUrl): array
{
    $sha = strtolower((string)($user['avatar_sha'] ?? ''));
    $src = userMediaValidSha($sha) ? userAvatarSourceRow($db, (int)$user['id']) : null;
    $cover = strtolower((string)($user['cover_sha'] ?? ''));
    $hasCover = userMediaValidSha($cover);
    return [
        'avatar' => [
            'has' => $src !== null,
            'src' => $src !== null ? userMediaUrl((string)$src['sha1'], 0, $baseUrl) : '',
            'x' => (float)($user['avatar_x'] ?? 50), 'y' => (float)($user['avatar_y'] ?? 50), 'zoom' => (float)($user['avatar_zoom'] ?? 1),
        ],
        // The editor frames the FULL cover, not the thumb the extension used: the frame is nearly the
        // width of the real header, and at zoom 4 a thousand-pixel copy would be a smear to aim with.
        'cover' => [
            'has' => $hasCover,
            'src' => $hasCover ? userMediaUrl($cover, 0, $baseUrl) : '',
            'thumb' => $hasCover ? userMediaUrl($cover, USER_COVER_THUMB_EDGE, $baseUrl) : '',
            'x' => (float)($user['cover_x'] ?? 50), 'y' => (float)($user['cover_y'] ?? 50), 'zoom' => (float)($user['cover_zoom'] ?? 1),
        ],
    ];
}

/** The generated picture itself: a letter on its colour, as a small SVG with nothing in it but that. */
function userAvatarDefaultSvg(string $letter, int $colour): string
{
    $letter = preg_match('/^[A-Z0-9]$/', $letter) ? $letter : 'U';
    $bg = USER_AVATAR_COLOURS[max(0, min(count(USER_AVATAR_COLOURS) - 1, $colour))];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">'
         . '<rect width="128" height="128" fill="' . $bg . '"/>'
         . '<text x="64" y="64" dy="0.35em" text-anchor="middle" fill="#ffffff" '
         . 'font-family="Segoe UI, Helvetica Neue, Arial, sans-serif" font-size="62" font-weight="600">'
         . $letter . '</text></svg>';
}
