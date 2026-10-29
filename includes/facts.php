<?php

const FACTS_VERSION = 2;
const FACTS_NAME_VERSION = 2;
const FACTS_TEXT_MAX = 400000;
const FACTS_FILTER_CAP = 5000;
const FACTS_TICK_BATCH = 40;
const FACTS_TAGS_MAX = 6;
const FACTS_GROUPS = ['q', 'v', 'h', 'a', 's', 'l', 't', 'g'];
const FACTS_ONGOING = 9999;

const FACTS_LABELS = [
    'q:2160p' => '2160p', 'q:1440p' => '1440p', 'q:1080p' => '1080p', 'q:720p' => '720p', 'q:sd' => 'SD',
    'v:h264' => 'AVC', 'v:h265' => 'HEVC', 'v:av1' => 'AV1', 'v:vp9' => 'VP9', 'v:mpeg2' => 'MPEG-2', 'v:xvid' => 'XviD', 'v:vc1' => 'VC-1',
    'h:hdr10' => 'HDR10', 'h:hdr10p' => 'HDR10+', 'h:dv' => 'Dolby Vision', 'h:hlg' => 'HLG', 'h:hdr' => 'HDR',
    'a:aac' => 'AAC', 'a:ac3' => 'AC-3', 'a:eac3' => 'E-AC-3', 'a:atmos' => 'Atmos', 'a:dts' => 'DTS', 'a:dtshdma' => 'DTS-HD MA',
    'a:truehd' => 'TrueHD', 'a:flac' => 'FLAC', 'a:opus' => 'Opus', 'a:mp3' => 'MP3', 'a:pcm' => 'PCM',
    's:webdl' => 'WEB-DL', 's:webrip' => 'WEBRip', 's:bluray' => 'BluRay', 's:remux' => 'REMUX', 's:hdtv' => 'HDTV', 's:dvd' => 'DVD', 's:cam' => 'CAM/TS',
    'l:multi' => 'MULTi',
];
const FACTS_WORDS = ['l:lektor_pl', 'l:dub_pl', 'l:sub_pl', 'l:audio_pl', 'l:audio_en', 'l:sub_en', 't:movie', 't:series'];
const FACTS_NAME_RES = ['q:sd' => 1, 'q:720p' => 2, 'q:1080p' => 3, 'q:1440p' => 4, 'q:2160p' => 5];
const FACTS_NAME_BITS = ['v:h264', 'v:h265', 'v:av1', 'v:vp9', 'v:mpeg2', 'v:xvid', 'v:vc1', 'h:hdr10', 'h:hdr10p', 'h:dv', 'h:hlg', 'h:hdr',
    'a:aac', 'a:ac3', 'a:eac3', 'a:atmos', 'a:dts', 'a:dtshdma', 'a:truehd', 'a:flac', 'a:opus', 'a:mp3', 'a:pcm',
    's:webdl', 's:webrip', 's:bluray', 's:remux', 's:hdtv', 's:dvd', 's:cam',
    'l:multi', 'l:lektor_pl', 'l:dub_pl', 'l:sub_pl', 'l:audio_pl', 'l:audio_en', 'l:sub_en'];
const FACTS_NAME_LIST_CAP = 10000;
const FACTS_ALIASES = [
    'q:2160p' => '4k uhd 2160 ultra hd', 'q:1440p' => '2k qhd 1440', 'q:1080p' => 'full hd fhd 1080 1080i', 'q:720p' => 'hd 720', 'q:sd' => 'sd 480p 576p',
    'v:h264' => 'h264 h.264 x264 avc', 'v:h265' => 'h265 h.265 x265 hevc', 'v:xvid' => 'xvid divx', 'v:mpeg2' => 'mpeg2 mpeg-2', 'v:vc1' => 'vc1 vc-1',
    'h:hdr10' => 'hdr hdr10', 'h:hdr10p' => 'hdr10+ hdr10plus', 'h:dv' => 'dv dovi dolby vision', 'h:hdr' => 'hdr',
    'a:ac3' => 'ac3 ac-3 dd dolby digital', 'a:eac3' => 'eac3 e-ac-3 ddp dd+ dolby digital plus', 'a:dtshdma' => 'dts-hd ma dtshd master audio',
    'a:truehd' => 'truehd true hd', 'a:pcm' => 'pcm lpcm', 'a:atmos' => 'atmos dolby atmos', 'a:dts' => 'dts', 'a:aac' => 'aac',
    'a:flac' => 'flac lossless bezstratny', 'a:opus' => 'opus', 'a:mp3' => 'mp3', 'h:hlg' => 'hlg', 'v:av1' => 'av1', 'v:vp9' => 'vp9',
    's:webdl' => 'web-dl webdl web', 's:webrip' => 'webrip web-rip', 's:bluray' => 'bluray blu-ray bdrip brrip bd', 's:hdtv' => 'hdtv tvrip pdtv',
    's:dvd' => 'dvd dvdrip dvd5 dvd9', 's:cam' => 'cam ts telesync hdcam telecine', 's:remux' => 'remux bdremux',
    'l:multi' => 'multi dual audio wielojęzyczny', 'l:lektor_pl' => 'lektor polski pl voice-over voiceover', 'l:dub_pl' => 'dubbing dub polski pl pldub',
    'l:sub_pl' => 'napisy polskie pl subtitles plsub plsubs', 'l:audio_pl' => 'polski pl audio', 'l:audio_en' => 'english eng angielski audio',
    'l:sub_en' => 'napisy angielskie en english subtitles engsub',
];

function factsTagLabel(string $id, array $genres = []): string
{
    if (isset(FACTS_LABELS[$id])) return FACTS_LABELS[$id];
    if (in_array($id, FACTS_WORDS, true)) return __('facts.tag.' . str_replace(':', '_', $id));
    if (str_starts_with($id, 'g:')) return (string)($genres[substr($id, 2)] ?? substr($id, 2));
    return $id;
}

function factsTagValid(string $id): bool
{
    if (isset(FACTS_LABELS[$id]) || in_array($id, FACTS_WORDS, true)) return true;
    if (preg_match('/^x:[a-z0-9-]{1,64}$/', $id)) return true;
    return preg_match('/^g:[a-z0-9-]{2,40}$/', $id) === 1;
}

function factsPlain(string $text): string
{
    $text = mb_substr(str_replace(["\r\n", "\r"], "\n", $text), 0, FACTS_TEXT_MAX);
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_scrub($text, 'UTF-8');
    $text = preg_replace('~\[hide\b[^\]]*\].*?\[/hide\]~isu', "\n", $text) ?? $text;
    $text = preg_replace('~\[magnet\b[^\]]*\].*?\[/magnet\]~isu', ' ', $text) ?? $text;
    $text = preg_replace('~\[/?[a-z*][a-z0-9*]{0,15}(?:=[^\]\n]{0,200})?\]~iu', '', $text) ?? $text;
    $text = preg_replace('~(\*\*|__)~', '', $text) ?? $text;
    $text = str_replace(['\\~', '\\*', '\\_', '\\#', '\\-'], ['~', '*', '_', '#', '-'], $text);
    return preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', $text) ?? $text;
}

function factsFields(string $line): array
{
    if (!preg_match_all('/(?:^|,\s*)([\p{Lu}][\p{L}\p{N} ()\/._\'-]{0,40}?)\s*:(?!\/\/)[ \t\x{00A0}]*/u', $line, $m, PREG_OFFSET_CAPTURE)) return [];
    $out = [];
    $n = count($m[0]);
    for ($i = 0; $i < $n; $i++) {
        $start = $m[0][$i][1] + strlen($m[0][$i][0]);
        $end = $i + 1 < $n ? $m[0][$i + 1][1] : strlen($line);
        $value = trim(substr($line, $start, max(0, $end - $start)), " \t,;\u{00A0}");
        $out[] = [trim($m[1][$i][0]), $value];
    }
    return $out;
}

function factsSections(string $plain): array
{
    $sections = [['type' => 'general', 'f' => []]];
    $cur = 0;
    foreach (explode("\n", $plain) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^[_\-=*]{3,}$/u', $line)) {
            $sections[] = ['type' => 'general', 'f' => []];
            $cur = count($sections) - 1;
            continue;
        }
        if (preg_match('/^(general|video|audio|text|menu)(?:\s*#\s*\d+)?$/iu', $line, $hm)) {
            $sections[] = ['type' => strtolower($hm[1]), 'f' => []];
            $cur = count($sections) - 1;
            continue;
        }
        $fields = factsFields($line);
        if (!$fields) continue;
        $head = strtolower($fields[0][0]);
        if (in_array($head, ['video', 'audio', 'text'], true) && ($fields[0][1] === '' || preg_match('/^#?\d+/', $fields[0][1]))) {
            $sections[] = ['type' => $head, 'f' => []];
            $cur = count($sections) - 1;
            array_shift($fields);
        }
        foreach ($fields as [$k, $v]) $sections[$cur]['f'][] = [mb_strtolower($k), $v];
    }
    return $sections;
}

function factsField(array $section, array $labels): ?string
{
    foreach ($section['f'] as [$k, $v]) if (in_array($k, $labels, true) && $v !== '') return $v;
    return null;
}

function factsNumber(string $raw): ?float
{
    $s = preg_replace('/[ \x{00A0}\x{202F}\']/u', '', trim($raw)) ?? '';
    if ($s === '' || !preg_match('/^\d[\d.,]*$/', $s)) return null;
    $dot = strrpos($s, '.');
    $comma = strrpos($s, ',');
    if ($dot !== false && $comma !== false) {
        $dec = $dot > $comma ? '.' : ',';
        $s = str_replace($dec === '.' ? ',' : '.', '', $s);
        $s = str_replace($dec, '.', $s);
    } elseif ($comma !== false) {
        $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    } elseif ($dot !== false && substr_count($s, '.') > 1) {
        $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}

function factsKbps(string $value): ?int
{
    $value = explode(' / ', $value)[0];
    if (!preg_match('/(\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)?)\s*(kb\/s|kbps|mb\/s|mbps|b\/s)/iu', $value, $m)) return null;
    $n = factsNumber($m[1]);
    if ($n === null || $n <= 0) return null;
    $unit = strtolower($m[2]);
    $kbps = str_starts_with($unit, 'm') ? $n * 1000 : ($unit === 'b/s' ? $n / 1000 : $n);
    $kbps = (int)round($kbps);
    return $kbps >= 1 && $kbps <= 1000000 ? $kbps : null;
}

function factsPixels(?string $value): ?int
{
    if ($value === null || !preg_match('/(\d{1,2}(?:[ \x{00A0}\x{202F}]\d{3})|\d{2,5})\s*(?:pixels?|px|pikseli)?/iu', $value, $m)) return null;
    $n = (int)preg_replace('/\D/', '', $m[1]);
    return $n >= 16 && $n <= 20000 ? $n : null;
}

function factsResClass(int $w, int $h): int
{
    if ($w >= 3200 || $h >= 1800) return 2160;
    if ($w >= 2200 || $h >= 1300) return 1440;
    if ($w >= 1600 || $h >= 900) return 1080;
    if ($w >= 1100 || $h >= 650) return 720;
    return 480;
}

function factsResTag(int $res): string
{
    return $res >= 2160 ? 'q:2160p' : ($res >= 1440 ? 'q:1440p' : ($res >= 1080 ? 'q:1080p' : ($res >= 720 ? 'q:720p' : 'q:sd')));
}

function factsVideoCodec(string $format, string $more = ''): ?string
{
    $f = strtolower($format . ' ' . $more);
    if (preg_match('/\b(hevc|h\.?265|x265)\b/', $f)) return 'v:h265';
    if (preg_match('/\b(avc|h\.?264|x264)\b/', $f)) return 'v:h264';
    if (preg_match('/\bav1\b/', $f)) return 'v:av1';
    if (preg_match('/\bvp9\b/', $f)) return 'v:vp9';
    if (preg_match('/\bvc-?1\b|\bwvc1\b/', $f)) return 'v:vc1';
    if (preg_match('/mpeg-4 visual|\bxvid\b|\bdivx\b/', $f)) return 'v:xvid';
    if (preg_match('/mpeg video|mpeg-2|\bmpeg2\b/', $f)) return 'v:mpeg2';
    return null;
}

function factsHdr(string $value): array
{
    $v = strtolower($value);
    $out = [];
    if (str_contains($v, 'dolby vision') || preg_match('/\bdvhe\b|\bdovi\b/', $v)) $out[] = 'h:dv';
    if (str_contains($v, 'hdr10+') || str_contains($v, 'st 2094 app 4') || str_contains($v, 'hdr10 plus')) $out[] = 'h:hdr10p';
    if (preg_match('/hdr10(?!\+)/', $v) || str_contains($v, 'st 2086') || str_contains($v, 'pq')) $out[] = 'h:hdr10';
    if (str_contains($v, 'hlg') || str_contains($v, 'arib std-b67')) $out[] = 'h:hlg';
    return $out;
}

function factsAudio(string $format, string $commercial, string $title = ''): array
{
    $f = strtolower(trim($format));
    $c = strtolower($commercial . ' ' . $title);
    $out = [];
    if (str_contains($c, 'atmos') || preg_match('/\bjoc\b|16-ch/', $f)) $out[] = 'a:atmos';
    if (str_contains($c, 'truehd') || str_starts_with($f, 'mlp')) $out[] = 'a:truehd';
    elseif (str_contains($c, 'master audio') || str_contains($f, 'xll') || str_contains($c, 'dts:x') || str_contains($c, 'dts-hd ma')) $out[] = 'a:dtshdma';
    elseif (str_starts_with($f, 'dts') || str_contains($c, 'dts')) $out[] = 'a:dts';
    elseif (str_starts_with($f, 'e-ac-3') || str_contains($c, 'digital plus') || str_contains($c, 'dd+')) $out[] = 'a:eac3';
    elseif (str_starts_with($f, 'ac-3') || str_contains($c, 'dolby digital')) $out[] = 'a:ac3';
    elseif (str_starts_with($f, 'aac') || str_contains($c, 'aac')) $out[] = 'a:aac';
    elseif (str_starts_with($f, 'flac')) $out[] = 'a:flac';
    elseif (str_starts_with($f, 'opus')) $out[] = 'a:opus';
    elseif (str_starts_with($f, 'mpeg audio') || str_starts_with($f, 'mp3')) $out[] = 'a:mp3';
    elseif (str_starts_with($f, 'pcm') || str_starts_with($f, 'lpcm')) $out[] = 'a:pcm';
    return $out;
}

function factsLang(string $value): ?string
{
    $v = mb_strtolower(trim($value));
    if ($v === '') return null;
    if (preg_match('/^(polish|polski|pl|pol)\b/u', $v)) return 'pl';
    if (preg_match('/^(english|angielski|en|eng)\b/u', $v)) return 'en';
    return 'other';
}

function factsSource(string $value): ?string
{
    $v = strtolower($value);
    if (str_contains($v, 'remux')) return 's:remux';
    if (preg_match('/web-?dl|web dl|\bweb\b(?!-?rip)/', $v)) return 's:webdl';
    if (str_contains($v, 'webrip') || str_contains($v, 'web-rip')) return 's:webrip';
    if (preg_match('/blu-?ray|\bbd(rip|25|50)?\b|\bbrrip\b|uhd bd/', $v)) return 's:bluray';
    if (str_contains($v, 'hdtv') || str_contains($v, 'tvrip')) return 's:hdtv';
    if (preg_match('/\bdvd|\bvob\b/', $v)) return 's:dvd';
    if (preg_match('/\b(cam|hdcam|ts|telesync|hdts|tc|telecine)\b/', $v)) return 's:cam';
    return null;
}

function factsGenreSlug(string $genre): string
{
    $s = mb_strtolower(trim($genre));
    $s = strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function factsLabelValue(string $plain, array $labels): ?string
{
    $alt = implode('|', array_map(fn($l) => preg_quote($l, '/'), $labels));
    if (!preg_match('/(?:^|[,;|\x{2022}\x{00B7}\x{25AA}\x{25BA}*]|\s-\s)[ \t\x{00A0}]*(?:' . $alt . ')[ \t\x{00A0}]*(?::|：|\s[-–]\s)[ \t\x{00A0}]*([^\n]{0,300})/miu', $plain, $m)) return null;
    $value = $m[1];
    if (preg_match('/,\s*[\p{L}][\p{L}\p{N} ()\/._\'-]{0,40}?\s*[:：](?!\/\/)/u', $value, $next, PREG_OFFSET_CAPTURE)) $value = substr($value, 0, $next[0][1]);
    $value = preg_replace('/^[\s\x{00A0},.;]+|[\s\x{00A0},.;]+$/u', '', $value) ?? '';
    return $value === '' ? null : $value;
}

function factsYears(string $value): array
{
    if (!preg_match_all('/(?<!\d)(\d{4})(?!\d)/', $value, $m)) return [null, null];
    $max = (int)date('Y') + 2;
    $years = array_values(array_filter(array_map('intval', $m[1]), fn($y) => $y >= 1870 && $y <= $max));
    if (!$years) return [null, null];
    $end = max($years);
    if (count($years) === 1 && preg_match('/\d{4}\s*[-–—]\s*(?:$|\)|…|\.\.\.|obecnie|present|now)/iu', $value)) $end = FACTS_ONGOING;
    return [min($years), $end];
}

function factsCount(string $value): ?int
{
    if (!preg_match('/(\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+|\d+)/u', $value, $m)) return null;
    $n = (int)preg_replace('/\D/', '', $m[1]);
    return $n >= 1 && $n <= 100000 ? $n : null;
}

function factsSeasons(string $value): ?int
{
    $value = preg_replace('/\([^)]*\)/u', ' ', $value) ?? $value;
    $count = 0;
    $rest = preg_replace_callback('/(?<![\p{L}\d])S?(\d{1,3})\s*[-–—]\s*S?(\d{1,3})(?!\d)/iu', function (array $m) use (&$count): string {
        if ((int)$m[2] >= (int)$m[1]) $count += (int)$m[2] - (int)$m[1] + 1;
        return ' ';
    }, $value) ?? $value;
    preg_match_all('/(?<!\d)\d{1,3}(?!\d)/', $rest, $m);
    $singles = array_map('intval', $m[0] ?? []);
    $n = ($count === 0 && count($singles) === 1) ? $singles[0] : $count + count($singles);
    return $n >= 1 && $n <= 500 ? $n : null;
}

function factsMinutes(string $value): ?int
{
    $value = str_replace(['\\', '~', '≈', '±'], ' ', $value);
    $sum = 0.0;
    $count = 0;
    foreach (array_slice(preg_split('/\s+[-–—\/]\s+|\s*\|\s*/u', $value) ?: [], 0, 20) as $part) {
        $part = mb_strtolower(trim($part));
        if ($part === '') continue;
        $total = 0.0;
        $found = false;
        if (preg_match('/(?<!\d)(\d{1,3}):(\d{2})(?::(\d{2}))?(?!\d)/', $part, $mm)) {
            $total = (int)$mm[1] * 60 + (int)$mm[2];
            $found = true;
        } else {
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:h|hr|hrs|hours?|godz\.?|godzin[ay]?|godzina)(?![\p{L}])/u', $part, $mm)) { $total += (float)str_replace(',', '.', $mm[1]) * 60; $found = true; }
            if (preg_match('/(\d+)\s*(?:m|min|mins|minut[ay]?|minutes?)(?![\p{L}])/u', $part, $mm)) { $total += (int)$mm[1]; $found = true; }
            if (preg_match('/(\d+)\s*(?:s|sec|secs|sek|seconds?|sekund[ay]?)(?![\p{L}])/u', $part, $mm)) { $total += (int)$mm[1] / 60; $found = true; }
            if (!$found && preg_match('/^(\d{1,4})$/', $part, $mm)) { $total = (int)$mm[1]; $found = true; }
        }
        if ($found && $total > 0) { $sum += $total; $count++; }
    }
    if (!$count) return null;
    $n = (int)round($sum / $count);
    return $n >= 1 && $n <= 6000 ? $n : null;
}

function factsRating(string $plain, string $site): ?float
{
    if (!preg_match('/(?<![\p{L}])' . preg_quote($site, '/') . '(?![\p{L}])[^\d\n]{0,20}(\d{1,2}(?:[.,]\d{1,2})?)\s*\/\s*10(?!\d)/iu', $plain, $m)) return null;
    $v = (float)str_replace(',', '.', $m[1]);
    return $v >= 0 && $v <= 10 ? round($v, 1) : null;
}

function factsRateMode(string $value): ?string
{
    $value = explode(' / ', $value)[0];
    if (preg_match('/variable|zmienn/iu', $value)) return 'V';
    if (preg_match('/constant|sta[łl]/iu', $value)) return 'C';
    return null;
}

function factsChannels(string $value): ?string
{
    if (!preg_match('/(\d{1,2})\s*(?:channels?|kana)/iu', $value, $m)) return null;
    $n = (int)$m[1];
    return [1 => '1.0', 2 => '2.0', 3 => '2.1', 6 => '5.1', 7 => '6.1', 8 => '7.1'][$n] ?? ($n > 0 && $n <= 24 ? $n . 'ch' : null);
}

function factsAudioLabel(array $trackTags): ?string
{
    foreach (['a:truehd', 'a:dtshdma', 'a:dts', 'a:eac3', 'a:ac3', 'a:flac', 'a:pcm', 'a:opus', 'a:aac', 'a:mp3'] as $id) {
        if (in_array($id, $trackTags, true)) return FACTS_LABELS[$id] . (in_array('a:atmos', $trackTags, true) ? ' Atmos' : '');
    }
    return null;
}

function factsFromDescription(string $text, string $title = ''): array
{
    $plain = factsPlain($text);
    $sections = factsSections($plain);
    $tags = [];
    $res = null;
    $rates = [];
    $vmax = [];
    $vmode = null;
    $orates = [];
    $omax = [];
    $omode = null;
    $tracks = [];
    $audioLangs = [];
    $subLangs = [];
    $fallbackCodec = null;
    foreach ($sections as $sec) {
        if ($sec['type'] === 'video') {
            $codec = factsVideoCodec((string)factsField($sec, ['format']), (string)factsField($sec, ['codec id', 'writing library', 'format profile']));
            if ($codec !== null) $tags[$codec] = true;
            $w = factsPixels(factsField($sec, ['width']));
            $h = factsPixels(factsField($sec, ['height']));
            if ($w !== null && $h !== null) $res = max($res ?? 0, factsResClass($w, $h));
            $rate = factsField($sec, ['bit rate', 'nominal bit rate']);
            if ($rate !== null && ($k = factsKbps($rate)) !== null) $rates[] = $k;
            $mx = factsField($sec, ['maximum bit rate']);
            if ($mx !== null && ($k = factsKbps($mx)) !== null) $vmax[] = $k;
            $vmode ??= factsRateMode((string)factsField($sec, ['bit rate mode']));
            foreach (factsHdr((string)factsField($sec, ['hdr format', 'transfer characteristics'])) as $x) $tags[$x] = true;
        } elseif ($sec['type'] === 'audio') {
            $trackTags = factsAudio((string)factsField($sec, ['format']), (string)factsField($sec, ['commercial name']), (string)factsField($sec, ['title']));
            foreach ($trackTags as $x) $tags[$x] = true;
            $ar = factsField($sec, ['bit rate', 'nominal bit rate']);
            $am = factsField($sec, ['maximum bit rate']);
            $tracks[] = ['rate' => $ar !== null ? factsKbps($ar) : null, 'max' => $am !== null ? factsKbps($am) : null,
                         'mode' => factsRateMode((string)factsField($sec, ['bit rate mode'])), 'codec' => factsAudioLabel($trackTags),
                         'ch' => factsChannels((string)factsField($sec, ['channel(s)', 'channels']))];
            $lang = factsLang((string)factsField($sec, ['language']));
            if ($lang !== null) $audioLangs[$lang] = true;
            $trackTitle = mb_strtolower((string)factsField($sec, ['title']));
            if ($lang === 'pl' && str_contains($trackTitle, 'lektor')) $tags['l:lektor_pl'] = true;
            if ($lang === 'pl' && str_contains($trackTitle, 'dubbing')) $tags['l:dub_pl'] = true;
        } elseif ($sec['type'] === 'text') {
            $lang = factsLang((string)factsField($sec, ['language']));
            if ($lang !== null) $subLangs[$lang] = true;
        } else {
            $fallbackCodec ??= factsVideoCodec('', (string)factsField($sec, ['writing library']));
            $o = factsField($sec, ['overall bit rate']);
            if ($o !== null && ($k = factsKbps($o)) !== null) $orates[] = $k;
            $om = factsField($sec, ['maximum overall bit rate']);
            if ($om !== null && ($k = factsKbps($om)) !== null) $omax[] = $k;
            $omode ??= factsRateMode((string)factsField($sec, ['overall bit rate mode']));
        }
    }
    usort($tracks, fn($a, $b) => [$b['rate'] ?? $b['max'] ?? 0, count(array_filter($b, fn($v) => $v !== null))] <=> [$a['rate'] ?? $a['max'] ?? 0, count(array_filter($a, fn($v) => $v !== null))]);
    $best = $tracks && ($tracks[0]['rate'] !== null || $tracks[0]['max'] !== null || $tracks[0]['ch'] !== null) ? $tracks[0] : null;
    if ($fallbackCodec !== null && !array_filter(array_keys($tags), fn($t) => str_starts_with($t, 'v:'))) $tags[$fallbackCodec] = true;

    $kindSays = null;
    $kind = factsLabelValue($plain, ['Serial', 'Typ', 'Type', 'Rodzaj']);
    if ($kind !== null) $kindSays = (bool)preg_match('/(?<![\p{L}])(series|serial|miniserial|miniseries|sitcom)(?![\p{L}])/iu', $kind);

    $release = factsLabelValue($plain, ['Typ wydania', 'Release type', 'Źródło', 'Source', 'Format / Jakość']);
    if ($release !== null) {
        $src = factsSource($release);
        if ($src !== null) $tags[$src] = true;
        if (preg_match('/\bhdr\b/i', $release) && !array_filter(array_keys($tags), fn($t) => str_starts_with($t, 'h:'))) $tags['h:hdr'] = true;
        if ($res === null && preg_match('/\b(2160|1440|1080|720|576|480)[pi]\b/i', $release, $rm)) $res = (int)$rm[1] >= 576 ? (int)$rm[1] : 480;
    }

    $langInfo = mb_strtolower((string)factsLabelValue($plain, ['Informacje językowe', 'Wersja językowa', 'Język', 'Language info']));
    if ($langInfo !== '') {
        if (preg_match('/lektor\s*(pl|polski)|polski\s*lektor/u', $langInfo)) $tags['l:lektor_pl'] = true;
        if (preg_match('/dubbing\s*(pl|polski)|polski\s*dubbing/u', $langInfo)) $tags['l:dub_pl'] = true;
        if (preg_match('/napisy\s*(pl|polskie)|polskie\s*napisy/u', $langInfo)) $tags['l:sub_pl'] = true;
        if (preg_match('/napisy\s*(en|eng|angielskie)|angielskie\s*napisy/u', $langInfo)) $tags['l:sub_en'] = true;
        if (preg_match('/(dubbing|lektor|audio)\s*(en|eng|angielski)|oryginaln|angielski\s*(dubbing|audio)/u', $langInfo)) $tags['l:audio_en'] = true;
        if (preg_match('/\bmulti/u', $langInfo)) $tags['l:multi'] = true;
    }
    if (isset($audioLangs['pl']) && !isset($tags['l:lektor_pl']) && !isset($tags['l:dub_pl'])) $tags['l:audio_pl'] = true;
    if (isset($audioLangs['en'])) $tags['l:audio_en'] = true;
    if (isset($subLangs['pl'])) $tags['l:sub_pl'] = true;
    if (isset($subLangs['en'])) $tags['l:sub_en'] = true;
    if (count($audioLangs) >= 3) $tags['l:multi'] = true;

    [$yFrom, $yTo] = [null, null];
    $yearValue = factsLabelValue($plain, ['Rok premiery', 'Rok produkcji', 'Rok wydania', 'Year', 'Released', 'Zakres czasowy']);
    $firstLine = (string)strtok(ltrim($plain), "\n");
    $spans = [];
    if ($yearValue !== null) $spans[] = factsYears($yearValue);
    foreach ([$firstLine, $title] as $t) if (preg_match('/\(\s*(\d{4})\s*(?:([-–—])\s*(\d{4})?\s*)?\)/u', $t, $ym)) $spans[] = factsYears($ym[0]);
    $spans = array_filter($spans, fn($s) => $s[0] !== null);
    if ($spans) { $yFrom = min(array_column($spans, 0)); $yTo = max(array_column($spans, 1)); }

    $seasonsValue = factsLabelValue($plain, ['Sezony', 'Seasons', 'Liczba sezonów', 'Sezon']);
    $seasons = $seasonsValue !== null ? factsSeasons($seasonsValue) : null;
    $episodes = ($v = factsLabelValue($plain, ['Epizody', 'Odcinki', 'Episodes', 'Ilość odcinków', 'Liczba odcinków'])) !== null ? factsCount($v) : null;
    $duration = ($v = factsLabelValue($plain, ['Czas trwania', 'Duration', 'Runtime', 'Długość'])) !== null ? factsMinutes($v) : null;
    $pattern = preg_match('/(?<![\p{L}\p{N}])S(\d{1,2})[ ._-]?E\d{1,4}(?![\p{N}])/iu', $title . "\n" . $plain);

    if ($kindSays === true || ($seasonsValue !== null && preg_match('/[1-9]/', $seasonsValue)) || ($episodes !== null && $episodes >= 2) || $pattern) $tags['t:series'] = true;
    elseif ($kindSays === false || $yFrom !== null || $duration !== null || $res !== null) $tags['t:movie'] = true;

    $genres = [];
    $g = factsLabelValue($plain, ['Gatunek', 'Gatunki', 'Genre', 'Genres']);
    if ($g !== null) {
        foreach (preg_split('/\s*[,\/|]\s*/u', $g) ?: [] as $one) {
            $one = trim($one, " \t.;");
            $slug = factsGenreSlug($one);
            if ($slug === '' || mb_strlen($one) > 40 || count($genres) >= 8) continue;
            $genres[$slug] = mb_strtoupper(mb_substr($one, 0, 1)) . mb_substr($one, 1);
            $tags['g:' . $slug] = true;
        }
    }

    if ($res !== null) $tags[factsResTag($res)] = true;
    $ordered = [];
    foreach (FACTS_GROUPS as $grp) foreach (array_keys($tags) as $t) if (str_starts_with($t, $grp . ':')) $ordered[] = $t;

    return [
        'res' => $res,
        'vbitrate' => $rates ? (int)round(array_sum($rates) / count($rates)) : null,
        'vbitrate_max' => $vmax ? max($vmax) : null, 'vmode' => $vmode,
        'obitrate' => $orates ? (int)round(array_sum($orates) / count($orates)) : null, 'obitrate_max' => $omax ? max($omax) : null, 'omode' => $omode,
        'abitrate' => $best['rate'] ?? null, 'abitrate_max' => $best['max'] ?? null, 'amode' => $best['mode'] ?? null,
        'acodec' => $best['codec'] ?? null, 'achannels' => $best['ch'] ?? null,
        'year_from' => $yFrom, 'year_to' => $yTo,
        'seasons' => $seasons, 'episodes' => $episodes, 'duration_min' => $duration,
        'imdb' => factsRating($plain, 'IMDb'), 'filmweb' => factsRating($plain, 'Filmweb'),
        'pics' => preg_match('/\[img\b|!\[[^\]]*\]\(|upl-image/i', $text) ? 1 : 0,
        'tags' => $ordered, 'genres' => $genres,
    ];
}

function factsMode(array $cfg): string
{
    return ($cfg['facts_source'] ?? 'desc') === 'desc_name' ? 'desc_name' : 'desc';
}

function factsFromName(string $name): array
{
    $name = preg_replace(['~[\[(【]\s*[^\]\)】\s]*\.(?:pl|org|com|net|to|re|mx|bz|info|cc|ws|me|io|se|ru|eu|xyz|lol|ag|is|co|uk|tv)\s*[\])】]~iu',
                          '~(?<![A-Za-z0-9])www\.[^\s\])】]+~iu'], ' ', $name) ?? $name;
    $n = ' ' . mb_strtolower($name) . ' ';
    $has = static fn(string $re): bool => preg_match('/(?<![a-z0-9])(?:' . $re . ')(?![a-z0-9])/u', $n) === 1;
    $caps = static fn(string $re): bool => preg_match('/(?<![A-Za-z0-9])(?:' . $re . ')(?![A-Za-z0-9])/u', ' ' . $name . ' ') === 1;
    $res = null;
    if ($has('2160p|(?:3840|4096)x\d{3,4}')) $res = 2160;
    elseif ($has('1440p|2560x\d{3,4}|qhd')) $res = 1440;
    elseif ($has('1080[pi]|1920x\d{3,4}|fhd|full[ ._-]?hd')) $res = 1080;
    elseif ($has('720p|1280x\d{3,4}')) $res = 720;
    elseif ($has('576[pi]|480[pi]|360p|720x(?:576|480)')) $res = 480;
    $gate = $res !== null || $has('hd-?rip|sat-?rip|dvb-?rip|tv-?rip|bd-?rip|web-?dl|web-?rip|xxx|s\d{1,2}e\d{1,3}') || $caps('PAL|NTSC')
         || preg_match('/\.(?:mkv|mp4|avi|m4v|wmv|mov|webm)\s*$/iu', $name);
    if ($res === null && $has('4k|uhd')) $res = 2160;
    $codec = null;
    foreach (['v:h265' => 'x265|h[ .]?265|hevc', 'v:h264' => 'x264|h[ .]?264|avc', 'v:av1' => 'av1', 'v:vp9' => 'vp9',
              'v:xvid' => 'xvid|divx', 'v:mpeg2' => 'mpeg-?2', 'v:vc1' => 'vc-?1'] as $id => $re) {
        if ($has($re)) { $codec = $id; break; }
    }
    $src = null;
    foreach (['s:remux' => 'remux|bdremux', 's:webrip' => 'web-?rip', 's:webdl' => 'web-?dl', 's:bluray' => 'blu-?ray|bd-?rip|br-?rip|bd25|bd50',
              's:hdtv' => 'hdtv|tv-?rip|pdtv', 's:dvd' => 'dvd-?rip|dvd5|dvd9|dvdscr', 's:cam' => 'hd-?cam|cam-?rip|telesync|hd-?ts|hd-?tc|telecine'] as $id => $re) {
        if ($has($re)) { $src = $id; break; }
    }
    $gate = $gate || $codec !== null || $src !== null;
    if ($src === null && $caps('WEB')) $src = 's:webdl';
    if ($src === null && $res !== null && $caps('AMZN|NF|DSNP|HMAX|ATVP|PCOK|HULU|PMTP|SKST|CRAV')) $src = 's:webdl';
    if ($src === null && $caps('DVD')) $src = 's:dvd';
    if ($src === null && $caps('CAM')) $src = 's:cam';
    if (!$gate) return ['tags' => [], 'year' => null];
    $tags = [];
    if ($res !== null) $tags[] = factsResTag($res);
    if ($codec !== null) $tags[] = $codec;
    if ($has('hdr10\+|hdr10plus|hdr10p')) $tags[] = 'h:hdr10p';
    if (preg_match('/(?<![a-z0-9])hdr10(?![a-z0-9+]|plus)/u', $n)) $tags[] = 'h:hdr10';
    if ($has('dovi|dolby[ ._-]?vision') || $caps('DV[5-8]?')) $tags[] = 'h:dv';
    if ($has('hlg')) $tags[] = 'h:hlg';
    if (!array_filter($tags, fn($t) => str_starts_with($t, 'h:')) && $has('hdr')) $tags[] = 'h:hdr';
    if ($has('atmos')) $tags[] = 'a:atmos';
    if ($has('true-?hd')) $tags[] = 'a:truehd';
    if ($has('dts-?hd[ ._-]?ma|dts-?x|dtsx')) $tags[] = 'a:dtshdma';
    elseif ($has('dts(?:-?hd)?')) $tags[] = 'a:dts';
    if ($has('e-?ac-?3|eac3|dd\+|ddp(?:[ ._]?[257](?:[ ._]?[01])?)?')) $tags[] = 'a:eac3';
    elseif ($has('ac-?3|dd[ ._]?[257](?:[ ._]?[01])?')) $tags[] = 'a:ac3';
    if ($has('aac(?:[ ._]?[257](?:[ ._]?[01])?)?')) $tags[] = 'a:aac';
    if ($has('flac')) $tags[] = 'a:flac';
    if ($caps('OPUS') || $has('opus[ ._]?[1-7]\.?[01]|(?:av1|vvc|hevc|x26[45]|vp9|aac)[ ._-]+opus|opus[ ._-]+(?:av1|vvc|hevc|x26[45]|vp9)|\[opus\]')) $tags[] = 'a:opus';
    if ($has('mp3')) $tags[] = 'a:mp3';
    if ($has('l?pcm')) $tags[] = 'a:pcm';
    if ($src !== null) $tags[] = $src;
    $lang = [];
    if ($has('lektor(?:[ ._-]?pl)?')) $lang[] = 'l:lektor_pl';
    if ($has('dubbing(?:[ ._-]?pl)?|pldub|dubpl|dub[ ._-]?pl|pl[ ._-]?dub')) $lang[] = 'l:dub_pl';
    if ($has('napisy(?:[ ._-]?pl)?|plsubs?|subpl|pl[ ._-]?subs?|subs?[ ._-]?pl')) $lang[] = 'l:sub_pl';
    if (!$lang && ($has('polish') || $caps('PL|POL'))) $lang[] = 'l:audio_pl';
    $nAudio = (string)preg_replace('/(?<![a-z0-9])(?:eng?|english)[ ._-]?subs?(?![a-z0-9])/u', ' ', $n);
    if ($nAudio !== $n) $lang[] = 'l:sub_en';
    if (preg_match('/(?<![a-z0-9])(?:eng|english)(?![a-z0-9])/u', $nAudio)) $lang[] = 'l:audio_en';
    if (preg_match('/(?<![A-Za-z0-9])(?:MULTi|MULTI|Multi)\d?(?![A-Za-z0-9])(?![ ._-]?(?:subs?|SUBS?|Subs?))/u', $name) || $has('dual[ ._-]?audio')) $lang[] = 'l:multi';
    $tags = array_merge($tags, $lang);
    $tags[] = $has('s\d{1,2}e\d{1,3}|s\d{1,2}(?:-s?\d{1,2})?|season|sezon|complete[ ._-]series|\d{1,2}x\d{2,3}|odc(?:inek)?[ ._-]?\d{1,4}|episode[ ._-]?\d{1,4}|(?:mini)?serial') || preg_match('/\s-\s(?!19\d\d|20\d\d)\d{2,4}(?:v\d)?\s|\[(?!19\d\d\]|20\d\d\])\d{1,4}(?:v\d)?\]|第\s?\d+\s?[话話集]|全\d+集/u', $n) ? 't:series' : 't:movie';
    $year = null;
    $max = (int)date('Y') + 1;
    if (preg_match('/[(\[]\s*(19[2-9][0-9]|20[0-9][0-9])(?![0-9])/', $n, $ym) && (int)$ym[1] <= $max) $year = (int)$ym[1];
    elseif (preg_match_all('/(?<![0-9])(19[2-9][0-9]|20[0-9][0-9])(?![0-9])/', $n, $ym)) {
        foreach (array_reverse($ym[1]) as $y) { if ((int)$y <= $max) { $year = (int)$y; break; } }
    }
    $order = array_flip(FACTS_GROUPS);
    usort($tags, fn($a, $b) => [$order[$a[0]] ?? 99] <=> [$order[$b[0]] ?? 99]);
    return ['tags' => array_values(array_unique($tags)), 'year' => $year];
}

function factsNameChips(string $name): ?array
{
    $f = factsFromName($name);
    if (!$f['tags']) return null;
    $tags = array_map(fn($id) => ['id' => $id, 'g' => substr($id, 0, 1), 'l' => factsTagLabel($id)], $f['tags']);
    return array_filter(['tags' => $tags, 'year' => $f['year'] !== null ? (string)$f['year'] : null, 'from' => 'name', 'max' => factsChipsMax($GLOBALS['cfg'] ?? [])],
                        fn($v) => $v !== null);
}

function factsChipsMax(array $cfg): int
{
    return max(1, min(20, (int)($cfg['facts_chips_max'] ?? 6) ?: 6));
}

function factsChipsOn(array $cfg, string $place): bool
{
    return (['search' => $cfg['facts_chips_search'] ?? '1', 'info' => $cfg['facts_chips_info'] ?? '1', 'panel' => $cfg['facts_chips_panel'] ?? '1',
             'lists' => $cfg['facts_chips_lists'] ?? '1'][$place] ?? '1') === '1';
}

function factsRowChips(?array $desc, string $name, array $cfg, string $place = ''): ?array
{
    if (!factsChipsOn($cfg, $place)) return null;
    if ($desc !== null) return $desc;
    return factsMode($cfg) === 'desc_name' && $name !== '' ? factsNameChips($name) : null;
}

function factsNameReady(PDO $db): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $ready = function_exists('schemaTableExists') ? schemaTableExists($db, 'name_facts') : (bool)$db->query("SHOW TABLES LIKE 'name_facts'")->fetchColumn(); }
        catch (\Throwable $e) { $ready = false; }
    }
    return $ready;
}

function factsNameCodes(array $tags): array
{
    $kind = in_array('t:series', $tags, true) ? 2 : (in_array('t:movie', $tags, true) ? 1 : 0);
    $res = 0;
    $bits = 0;
    foreach ($tags as $id) {
        if (isset(FACTS_NAME_RES[$id])) $res = max($res, FACTS_NAME_RES[$id]);
        $bit = array_search($id, FACTS_NAME_BITS, true);
        if ($bit !== false) $bits |= 1 << $bit;
    }
    return [$kind, $res, $bits];
}

function factsNameStore(PDO $db, array $rows): int
{
    if (!$rows || !factsNameReady($db)) return 0;
    $up = [];
    $gone = [];
    $blocks = function_exists('tagsBlocked') ? tagsBlocked($db, array_map(fn($r) => (string)($r['info_hash'] ?? ''), $rows)) : [];
    foreach ($rows as $r) {
        $hash = strtolower((string)($r['info_hash'] ?? ''));
        if (!preg_match('/^[0-9a-f]{40}$/', $hash)) continue;
        $f = factsFromName((string)($r['name'] ?? ''));
        if (isset($blocks[$hash])) $f['tags'] = array_values(array_diff($f['tags'], $blocks[$hash]));
        if (!$f['tags']) { $gone[] = $hash; continue; }
        [$kind, $res, $bits] = factsNameCodes($f['tags']);
        $up[] = [$hash, $kind, $res, (int)($f['year'] ?? 0), $bits];
    }
    foreach (array_chunk($up, 500) as $chunk) {
        $sql = "INSERT INTO name_facts (info_hash, nf_kind, nf_res, nf_year, nf_bits) VALUES " . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)'))
             . " ON DUPLICATE KEY UPDATE nf_kind = VALUES(nf_kind), nf_res = VALUES(nf_res), nf_year = VALUES(nf_year), nf_bits = VALUES(nf_bits)";
        $st = $db->prepare($sql);
        $st->execute(array_merge(...$chunk));
    }
    foreach (array_chunk($gone, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $db->prepare("DELETE FROM name_facts WHERE info_hash IN ($in)")->execute($chunk);
    }
    return count($up);
}

function factsNameTick(PDO $db, array $cfg, float $budget = 15.0): array
{
    if (factsMode($cfg) !== 'desc_name' || !factsNameReady($db)) return ['read' => 0, 'done' => true];
    if (($cfg['name_facts_ver'] ?? '1') !== (string)FACTS_NAME_VERSION) {
        $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('name_facts_cursor', ''), ('name_facts_ver', ?)")->execute([(string)FACTS_NAME_VERSION]);
        $cfg['name_facts_cursor'] = '';
    }
    $t0 = microtime(true);
    $cursor = (string)($cfg['name_facts_cursor'] ?? '');
    if ($cursor === 'done') return ['read' => 0, 'done' => true];
    $read = 0;
    $st = $db->prepare("SELECT info_hash, name FROM index_catalog WHERE info_hash > ? ORDER BY info_hash LIMIT 5000");
    while (microtime(true) - $t0 < $budget) {
        $st->execute([$cursor]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            $read += factsNameWhitelist($db);
            $cursor = 'done';
            break;
        }
        $read += factsNameStore($db, $rows);
        $cursor = (string)$rows[count($rows) - 1]['info_hash'];
    }
    $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('name_facts_cursor', ?)")->execute([$cursor]);
    return ['read' => $read, 'done' => $cursor === 'done'];
}

function factsNameWhitelist(PDO $db): int
{
    $rows = $db->query("SELECT info_hash, name FROM whitelist WHERE banned = 0 AND name IS NOT NULL AND name <> ''")->fetchAll(PDO::FETCH_ASSOC);
    $n = factsNameStore($db, $rows);
    $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('name_facts_wl_at', ?)")->execute([(string)time()]);
    return $n;
}

function factsNamePredicate(array $f): ?array
{
    if (!empty($f['pics']) || is_array($f['imdb'] ?? null) || is_array($f['filmweb'] ?? null) || is_array($f['vbitrate'] ?? null)
        || is_array($f['duration'] ?? null) || is_array($f['seasons'] ?? null) || is_array($f['episodes'] ?? null)) return null;
    $where = [];
    $args = [];
    foreach ($f['tags'] ?? [] as $set) {
        $res = [];
        $kinds = [];
        $mask = 0;
        foreach ($set as $id) {
            if (isset(FACTS_NAME_RES[$id])) $res[] = FACTS_NAME_RES[$id];
            elseif ($id === 't:movie' || $id === 't:series') $kinds[] = $id === 't:movie' ? 1 : 2;
            elseif (($bit = array_search($id, FACTS_NAME_BITS, true)) !== false) $mask |= 1 << $bit;
        }
        $or = [];
        if ($res) { $or[] = 'nf.nf_res IN (' . implode(',', array_fill(0, count($res), '?')) . ')'; array_push($args, ...$res); }
        if ($kinds) { $or[] = 'nf.nf_kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')'; array_push($args, ...$kinds); }
        if ($mask) { $or[] = '(nf.nf_bits & ?) <> 0'; $args[] = $mask; }
        if (!$or) return null;
        $where[] = count($or) > 1 ? '(' . implode(' OR ', $or) . ')' : $or[0];
    }
    if (in_array($f['type'] ?? '', ['movie', 'series'], true)) { $where[] = 'nf.nf_kind = ?'; $args[] = $f['type'] === 'movie' ? 1 : 2; }
    $y = $f['year'] ?? null;
    if (is_array($y) && ($y[0] !== null || $y[1] !== null)) {
        $where[] = 'nf.nf_year > 0';
        if ($y[0] !== null) { $where[] = 'nf.nf_year >= ?'; $args[] = $y[0]; }
        if ($y[1] !== null) { $where[] = 'nf.nf_year <= ?'; $args[] = $y[1]; }
    }
    if (!$where) return null;
    return [implode(' AND ', $where), $args];
}

function factsNameTemplate(array $f): ?array
{
    $p = factsNamePredicate($f);
    if ($p === null) return null;
    return ['tpl' => '(SELECT 1 FROM name_facts nf WHERE nf.info_hash = {T}.info_hash AND ' . $p[0] . ' LIMIT 1) IS NOT NULL', 'args' => $p[1]];
}

function factsNameFilter(PDO $db, array $f, int $cap = FACTS_NAME_LIST_CAP): ?array
{
    $p = factsNamePredicate($f);
    if ($p === null) return null;
    if (!factsNameReady($db)) return ['hashes' => []];
    $sql = "SELECT nf.info_hash FROM name_facts nf WHERE " . $p[0] . " LIMIT " . ($cap + 1);
    $st = $db->prepare($sql);
    $st->execute($p[1]);
    $hashes = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($hashes) <= $cap) return ['hashes' => $hashes];
    return ['tpl' => '(SELECT 1 FROM name_facts nf WHERE nf.info_hash = {T}.info_hash AND ' . $p[0] . ' LIMIT 1) IS NOT NULL', 'args' => $p[1]];
}

function factsRequest(array $get): array
{
    return [
        'facts' => ['tags' => factsTagsParam($get['tags'] ?? ''), 'type' => (string)($get['type'] ?? ''), 'pics' => ($get['pics'] ?? '') === '1',
                    'year' => factsRange($get['year'] ?? null, 'year'), 'imdb' => factsRange($get['imdb'] ?? null, 'imdb'),
                    'filmweb' => factsRange($get['filmweb'] ?? null, 'filmweb'), 'vbitrate' => factsRange($get['vbitrate'] ?? null, 'rate'),
                    'duration' => factsRange($get['duration'] ?? null, 'count'), 'seasons' => factsRange($get['seasons'] ?? null, 'count'),
                    'episodes' => factsRange($get['episodes'] ?? null, 'count'), 'notags' => factsTagsParam($get['notags'] ?? ''),
                    'rating' => factsRange($get['rating'] ?? null, 'pct'), 'votes' => factsRange($get['votes'] ?? null, 'count'),
                    'steam' => factsRange($get['steam'] ?? null, 'pct')],
        'seen' => factsRange($get['seen'] ?? null, 'date'),
        'size' => factsRange($get['size'] ?? null, 'size'),
        'files' => factsRange($get['filecount'] ?? null, 'count'),
        'seeders' => factsRange($get['seeders'] ?? null, 'count'),
    ];
}

function factsFilterCondsMain(PDO $db, array $cfg, array $f, bool $canContent, bool $signedIn): array
{
    $dims = [];
    foreach ($f['tags'] ?? [] as $set) $dims[] = [['tags' => [$set]], $set];
    if (in_array($f['type'] ?? '', ['movie', 'series'], true)) $dims[] = [['type' => $f['type']], ['t:' . $f['type']]];
    if (is_array($f['year'] ?? null)) $dims[] = [['year' => $f['year']], []];
    $descOnly = array_filter(['pics' => !empty($f['pics']) ? true : null, 'imdb' => $f['imdb'] ?? null, 'filmweb' => $f['filmweb'] ?? null,
                              'vbitrate' => $f['vbitrate'] ?? null, 'duration' => $f['duration'] ?? null, 'seasons' => $f['seasons'] ?? null,
                              'episodes' => $f['episodes'] ?? null], fn($v) => $v !== null);
    if ($descOnly) $dims[] = [$descOnly, []];
    if (!$dims) return [];
    $byName = factsMode($cfg) === 'desc_name';
    $lists = [];
    $extra = [];
    foreach ($dims as $k => [$d, $ids]) {
        $hashes = $canContent ? (factsFilterHashes($db, $d, $signedIn) ?? []) : [];
        $extra[$k] = null;
        if ($ids && function_exists('tagsFilterExplicit') && ($ex = tagsFilterExplicit($db, $ids)) !== null) {
            if (isset($ex['hashes'])) $hashes = array_merge($hashes, $ex['hashes']);
            else $extra[$k] = [$ex['tpl'], $ex['args']];
        }
        $lists[$k] = array_values(array_unique(array_map('strtolower', $hashes)));
    }
    $joint = $byName ? factsNameFilter($db, $f) : null;
    $jointList = $joint['hashes'] ?? [];
    $jointTpl = isset($joint['tpl']) ? [$joint['tpl'], $joint['args']] : null;
    if (count($dims) === 1) {
        $tpl = $jointTpl;
        if ($extra[0] !== null) $tpl = $tpl === null ? $extra[0] : ['(' . $tpl[0] . ' OR ' . $extra[0][0] . ')', array_merge($tpl[1], $extra[0][1])];
        return [[array_values(array_unique(array_merge($jointList, $lists[0]))), $tpl[0] ?? null, $tpl[1] ?? []]];
    }
    $union = array_values(array_unique(array_merge(...array_values($lists))));
    $mixed = null;
    if ($union || array_filter($extra)) {
        $parts = [];
        $args = [];
        if ($union && !array_filter($extra)) {
            $parts[] = 'info_hash IN (' . implode(',', array_fill(0, count($union), '?')) . ')';
            $args = $union;
        }
        foreach ($dims as $k => [$d, $ids]) {
            $or = [];
            if ($lists[$k]) {
                $or[] = 'info_hash IN (' . implode(',', array_fill(0, count($lists[$k]), '?')) . ')';
                array_push($args, ...$lists[$k]);
            }
            if ($extra[$k] !== null) { $or[] = $extra[$k][0]; array_push($args, ...$extra[$k][1]); }
            if ($byName && ($nf = factsNameTemplate($d)) !== null) {
                $or[] = $nf['tpl'];
                array_push($args, ...$nf['args']);
            }
            $parts[] = $or ? '(' . implode(' OR ', $or) . ')' : '1 = 0';
        }
        $mixed = ['(' . implode(' AND ', $parts) . ')', $args];
    }
    if ($jointTpl !== null && $mixed !== null) $tpl = ['(' . $jointTpl[0] . ' OR ' . $mixed[0] . ')', array_merge($jointTpl[1], $mixed[1])];
    else $tpl = $jointTpl ?? $mixed;
    return [[$jointList, $tpl[0] ?? null, $tpl[1] ?? []]];
}

function factsEngineFilters(PDO $db, array $cfg, array $req, bool $canContent, bool $signedIn): array
{
    $conds = factsFilterConds($db, $cfg, $req['facts'] ?? [], $canContent, $signedIn);
    $size = $req['size'] ?? null;
    $files = $req['files'] ?? null;
    $seeders = $req['seeders'] ?? null;
    $seen = $req['seen'] ?? null;
    return array_filter([
        'seen_min' => $seen !== null && $seen[0] !== null ? $seen[0] . ' 00:00:00' : null,
        'seen_max' => $seen !== null && $seen[1] !== null ? $seen[1] . ' 23:59:59' : null,
        'size_min' => $size !== null && $size[0] !== null ? (int)$size[0] : null,
        'size_max' => $size !== null && $size[1] !== null ? (int)$size[1] : null,
        'files_min' => $files !== null && $files[0] !== null ? (int)$files[0] : null,
        'files_max' => $files !== null && $files[1] !== null ? (int)$files[1] : null,
        'seeders_min' => $seeders !== null && $seeders[0] !== null ? (int)$seeders[0] : null,
        'facts_conds' => $conds ?: null,
    ], fn($v) => $v !== null);
}

function factsListFilters(PDO $db, array $cfg, array $req, string $seeders, string $table): array
{
    $where = [];
    $params = [];
    foreach ([['total_size', $req['size'] ?? null, true], ['files_count', $req['files'] ?? null, true], [$seeders, $req['seeders'] ?? null, false]] as [$col, $r, $upper]) {
        if (!is_array($r)) continue;
        if ($r[0] !== null) { $where[] = $col . ' >= ?'; $params[] = $r[0]; }
        if ($upper && $r[1] !== null) { $where[] = $col . ' <= ?'; $params[] = $r[1]; }
    }
    foreach (factsFilterConds($db, $cfg, $req['facts'] ?? [], true, true) as [$list, $tpl, $args]) {
        $parts = [];
        if ($list) {
            $parts[] = 'info_hash IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
            foreach ($list as $h) $params[] = $h;
        }
        if ($tpl !== null) {
            $parts[] = str_replace('{T}', '`' . $table . '`', $tpl);
            foreach ($args as $v) $params[] = $v;
        }
        $where[] = $parts ? '(' . implode(' OR ', $parts) . ')' : '1 = 0';
    }
    return [$where, $params];
}

function factsRefresh(PDO $db, string $hash): void
{
    $hash = strtolower($hash);
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return;
    try {
        if (factsMode($GLOBALS['cfg'] ?? []) === 'desc_name') {
            $st = $db->prepare("SELECT name FROM index_catalog WHERE info_hash = ? UNION ALL SELECT name FROM whitelist WHERE info_hash = ? AND banned = 0 LIMIT 1");
            $st->execute([$hash, $hash]);
            $name = $st->fetchColumn();
            if ($name !== false) factsNameStore($db, [['info_hash' => $hash, 'name' => (string)$name]]);
        }
        $st = $db->prepare("SELECT id FROM whitelist WHERE info_hash = ? AND description IS NOT NULL AND description <> '' LIMIT 1");
        $st->execute([$hash]);
        if (($id = $st->fetchColumn()) !== false) factsTouch($db, 'wl', (string)$id);
        $st = $db->prepare("SELECT id FROM hash_content WHERE info_hash = ? AND description IS NOT NULL AND description <> '' LIMIT 1");
        $st->execute([$hash]);
        if (($id = $st->fetchColumn()) !== false) factsTouch($db, 'idx', (string)$id);
    } catch (\Throwable $e) {
        error_log('[facts] refresh ' . $hash . ': ' . $e->getMessage());
    }
}

function factsCounts(PDO $db, array $cfg): array
{
    $n = [];
    $genres = [];
    if (factsTableReady($db)) {
        $rows = $db->query("SELECT info_hash, tags, genres FROM desc_facts")->fetchAll(PDO::FETCH_ASSOC);
        $pub = $rows ? factsPublished($db, array_map(fn($r) => strtolower((string)$r['info_hash']), $rows), true) : [];
        foreach ($rows as $r) {
            if (!isset($pub[strtolower((string)$r['info_hash'])])) continue;
            $labels = [];
            foreach (explode('|', (string)$r['genres']) as $pair) {
                if (!str_contains($pair, '=')) continue;
                [$k, $v] = explode('=', $pair, 2);
                $labels[$k] = $v;
            }
            foreach (array_filter(explode(',', trim((string)$r['tags'], ','))) as $id) {
                if (!factsTagValid($id)) continue;
                $n[$id] = ($n[$id] ?? 0) + 1;
                if (str_starts_with($id, 'g:')) $genres[substr($id, 2)] = $labels[substr($id, 2)] ?? substr($id, 2);
            }
        }
    }
    if (factsMode($cfg) === 'desc_name' && factsNameReady($db)) {
        foreach ($db->query("SELECT nf_res, COUNT(*) FROM name_facts GROUP BY nf_res")->fetchAll(PDO::FETCH_NUM) as [$res, $c]) {
            $id = array_search((int)$res, FACTS_NAME_RES, true);
            if ($id !== false) $n[$id] = ($n[$id] ?? 0) + (int)$c;
        }
        foreach ($db->query("SELECT nf_kind, COUNT(*) FROM name_facts GROUP BY nf_kind")->fetchAll(PDO::FETCH_NUM) as [$kind, $c]) {
            $id = [1 => 't:movie', 2 => 't:series'][(int)$kind] ?? null;
            if ($id !== null) $n[$id] = ($n[$id] ?? 0) + (int)$c;
        }
        $sums = [];
        foreach (array_keys(FACTS_NAME_BITS) as $i) $sums[] = 'SUM((nf_bits >> ' . (int)$i . ') & 1)';
        $columns = implode(', ', $sums);
        $row = $db->query("SELECT $columns FROM name_facts")->fetch(PDO::FETCH_NUM) ?: [];
        foreach (FACTS_NAME_BITS as $i => $id) if (!empty($row[$i])) $n[$id] = ($n[$id] ?? 0) + (int)$row[$i];
    }
    arsort($n);
    return ['at' => time(), 'n' => $n, 'g' => $genres];
}

function factsCountsTick(PDO $db, array $cfg): void
{
    $c = factsCounts($db, $cfg);
    $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('facts_counts', ?)")->execute([json_encode($c, JSON_UNESCAPED_UNICODE)]);
}

function factsTableReady(PDO $db): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $ready = function_exists('schemaTableExists') ? schemaTableExists($db, 'desc_facts') : (bool)$db->query("SHOW TABLES LIKE 'desc_facts'")->fetchColumn(); }
        catch (\Throwable $e) { $ready = false; }
    }
    return $ready;
}

function factsStore(PDO $db, string $hash, string $src, string $text, string $title): array
{
    $f = factsFromDescription($text, $title);
    if (function_exists('tagsBlocked') && ($blocked = tagsBlocked($db, [$hash])[strtolower($hash)] ?? [])) $f['tags'] = array_values(array_diff($f['tags'], $blocked));
    $genres = implode('|', array_map(fn($k, $v) => $k . '=' . str_replace(['|', '='], ' ', $v), array_keys($f['genres']), $f['genres']));
    $st = $db->prepare("INSERT INTO desc_facts (info_hash, src, ver, text_sha, res, vbitrate, year_from, year_to, seasons, episodes, duration_min, imdb, filmweb, pics, tags, genres,
                                                vbitrate_max, vmode, obitrate, obitrate_max, omode, abitrate, abitrate_max, amode, acodec, achannels, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                        ON DUPLICATE KEY UPDATE src = VALUES(src), ver = VALUES(ver), text_sha = VALUES(text_sha), res = VALUES(res), vbitrate = VALUES(vbitrate),
                            vbitrate_max = VALUES(vbitrate_max), vmode = VALUES(vmode), obitrate = VALUES(obitrate), obitrate_max = VALUES(obitrate_max),
                            omode = VALUES(omode), abitrate = VALUES(abitrate), abitrate_max = VALUES(abitrate_max), amode = VALUES(amode),
                            acodec = VALUES(acodec), achannels = VALUES(achannels),
                            year_from = VALUES(year_from), year_to = VALUES(year_to), seasons = VALUES(seasons), episodes = VALUES(episodes),
                            duration_min = VALUES(duration_min), imdb = VALUES(imdb), filmweb = VALUES(filmweb), pics = VALUES(pics), tags = VALUES(tags),
                            genres = VALUES(genres), updated_at = VALUES(updated_at)");
    $st->execute([$hash, $src, FACTS_VERSION, sha1($text), $f['res'], $f['vbitrate'], $f['year_from'], $f['year_to'], $f['seasons'], $f['episodes'],
                  $f['duration_min'], $f['imdb'], $f['filmweb'], $f['pics'], $f['tags'] ? ',' . implode(',', $f['tags']) . ',' : '', mb_substr($genres, 0, 400),
                  $f['vbitrate_max'], $f['vmode'], $f['obitrate'], $f['obitrate_max'], $f['omode'], $f['abitrate'], $f['abitrate_max'], $f['amode'],
                  $f['acodec'] !== null ? mb_substr($f['acodec'], 0, 24) : null, $f['achannels']]);
    if (function_exists('thumbsFromText')) thumbsFromText($db, $hash, $text);
    return $f;
}

function factsTouch(PDO $db, string $src, string $key): void
{
    if (!in_array($src, ['wl', 'idx'], true) || !factsTableReady($db)) return;
    try {
        $sql = $src === 'wl' ? "SELECT info_hash, name, description FROM whitelist WHERE id = ?"
                             : "SELECT c.info_hash, i.name, c.description FROM hash_content c LEFT JOIN index_hashes i ON i.info_hash = c.info_hash WHERE c.id = ?";
        $st = $db->prepare($sql);
        $st->execute([(int)$key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return;
        $hash = strtolower((string)$r['info_hash']);
        if (trim((string)$r['description']) === '') {
            $db->prepare("DELETE FROM desc_facts WHERE info_hash = ?")->execute([$hash]);
            return;
        }
        factsStore($db, $hash, $src, (string)$r['description'], (string)($r['name'] ?? ''));
    } catch (\Throwable $e) {
        error_log('[facts] ' . $src . ' ' . $key . ': ' . $e->getMessage());
    }
}

function factsTick(PDO $db, int $limit = FACTS_TICK_BATCH): int
{
    if (!factsTableReady($db)) return 0;
    $n = 0;
    foreach (['wl' => "SELECT w.id FROM whitelist w LEFT JOIN desc_facts f ON f.info_hash = w.info_hash
                        WHERE w.description IS NOT NULL AND w.description <> '' AND (f.info_hash IS NULL OR f.ver <> ? OR f.text_sha <> CONVERT(SHA1(w.description) USING ascii))
                        ORDER BY w.id LIMIT " . (int)$limit,
              'idx' => "SELECT c.id FROM hash_content c LEFT JOIN desc_facts f ON f.info_hash = c.info_hash
                        WHERE c.description IS NOT NULL AND c.description <> '' AND (f.info_hash IS NULL OR f.ver <> ? OR f.text_sha <> CONVERT(SHA1(c.description) USING ascii))
                        ORDER BY c.id LIMIT " . (int)$limit] as $src => $sql) {
        $st = $db->prepare($sql);
        $st->execute([FACTS_VERSION]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { factsTouch($db, $src, (string)$id); $n++; }
    }
    $db->exec("DELETE f FROM desc_facts f LEFT JOIN whitelist w ON w.info_hash = f.info_hash AND w.description IS NOT NULL AND w.description <> ''
                LEFT JOIN hash_content c ON c.info_hash = f.info_hash AND c.description IS NOT NULL AND c.description <> ''
                WHERE w.id IS NULL AND c.id IS NULL");
    return $n;
}

function factsPublished(PDO $db, array $hashes, bool $signedIn): array
{
    $hashes = array_values(array_unique(array_filter(array_map('strtolower', array_map('strval', $hashes)), fn($h) => preg_match('/^[0-9a-f]{40}$/', $h) === 1)));
    if (!$hashes) return [];
    $out = [];
    foreach (array_chunk($hashes, 1000) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT info_hash FROM whitelist WHERE info_hash IN ($in) AND banned = 0 AND content_status = 'approved' AND description IS NOT NULL AND description <> ''");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $h) $out[strtolower((string)$h)] = true;
        $st = $db->prepare("SELECT c.info_hash FROM hash_content c LEFT JOIN banned_hashes b ON b.info_hash = c.info_hash
                             WHERE c.info_hash IN ($in) AND c.content_status = 'approved' AND c.description IS NOT NULL AND c.description <> '' AND b.info_hash IS NULL");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $h) $out[strtolower((string)$h)] = true;
        if (!$signedIn) {
            try {
                $st = $db->prepare("SELECT info_hash FROM forum_descs WHERE info_hash IN ($in) AND audience = 'members'");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $h) unset($out[strtolower((string)$h)]);
            } catch (\Throwable $e) {
                error_log('[facts] the forum audience was not read: ' . $e->getMessage());
            }
        }
    }
    return $out;
}

function factsChipsFor(PDO $db, array $hashes, bool $signedIn = true): array
{
    if (!factsTableReady($db)) return [];
    $pub = factsPublished($db, $hashes, $signedIn);
    if (!$pub) return [];
    $list = array_keys($pub);
    $in = implode(',', array_fill(0, count($list), '?'));
    $st = $db->prepare("SELECT * FROM desc_facts WHERE info_hash IN ($in)");
    $st->execute($list);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[strtolower((string)$r['info_hash'])] = factsChips($r);
    return $out;
}

function factsChips(array $r): array
{
    $genres = [];
    foreach (explode('|', (string)($r['genres'] ?? '')) as $pair) {
        if (!str_contains($pair, '=')) continue;
        [$k, $v] = explode('=', $pair, 2);
        $genres[$k] = $v;
    }
    $tags = [];
    foreach (array_filter(explode(',', trim((string)($r['tags'] ?? ''), ','))) as $id) {
        if (!factsTagValid($id)) continue;
        $tags[] = ['id' => $id, 'g' => substr($id, 0, 1), 'l' => factsTagLabel($id, $genres)];
    }
    $year = null;
    if ($r['year_from'] !== null) {
        $from = (int)$r['year_from'];
        $to = (int)$r['year_to'];
        $year = $to === FACTS_ONGOING ? $from . '–' : ($to > $from ? $from . '–' . $to : (string)$from);
    }
    $cfg = $GLOBALS['cfg'] ?? [];
    $int = static fn($v) => $v !== null && $v !== '' ? (int)$v : null;
    $rates = [];
    if (($cfg['facts_chips_rates'] ?? '1') === '1') {
        foreach (['o' => ['obitrate', 'obitrate_max', 'omode'], 'v' => ['vbitrate', 'vbitrate_max', 'vmode'], 'a' => ['abitrate', 'abitrate_max', 'amode']] as $k => [$avg, $max, $mode]) {
            $one = array_filter(['r' => $int($r[$avg] ?? null), 'x' => $int($r[$max] ?? null), 'm' => ($r[$mode] ?? null) ?: null], fn($v) => $v !== null);
            if ($k === 'a') $one += array_filter(['c' => ($r['acodec'] ?? null) ?: null, 'ch' => ($r['achannels'] ?? null) ?: null], fn($v) => $v !== null);
            if (isset($one['r']) || isset($one['x'])) $rates[$k] = $one;
        }
    }
    $ratings = ($cfg['facts_chips_ratings'] ?? '1') === '1';
    return array_filter([
        'tags' => $tags,
        'year' => $year,
        'rates' => $rates,
        'seasons' => $r['seasons'] !== null ? (int)$r['seasons'] : null,
        'episodes' => $r['episodes'] !== null ? (int)$r['episodes'] : null,
        'duration' => $r['duration_min'] !== null ? (int)$r['duration_min'] : null,
        'imdb' => $ratings && $r['imdb'] !== null ? (float)$r['imdb'] : null,
        'filmweb' => $ratings && $r['filmweb'] !== null ? (float)$r['filmweb'] : null,
        'max' => factsChipsMax($cfg),
    ], fn($v) => $v !== null && $v !== []);
}

function factsTagsParam($raw): array
{
    $out = [];
    foreach (explode(',', is_string($raw) ? $raw : '') as $set) {
        $ids = [];
        foreach (explode('|', $set) as $id) {
            $id = strtolower(trim($id));
            if ($id !== '' && factsTagValid($id) && !in_array($id, $ids, true)) $ids[] = $id;
        }
        if ($ids) $out[] = array_slice($ids, 0, 12);
        if (count($out) >= 8) break;
    }
    return $out;
}

function factsCatalog(array $cfg = [], ?PDO $db = null): array
{
    $counts = json_decode((string)($cfg['facts_counts'] ?? ''), true);
    $n = ($cfg['facts_filter_counts'] ?? '1') === '1' && is_array($counts['n'] ?? null) ? $counts['n'] : null;
    $out = [];
    foreach (array_merge(array_keys(FACTS_LABELS), FACTS_WORDS) as $id) {
        if (str_starts_with($id, 't:')) continue;
        $c = ['id' => $id, 'g' => substr($id, 0, 1), 'l' => factsTagLabel($id), 'a' => FACTS_ALIASES[$id] ?? ''];
        if ($n !== null) $c['n'] = (int)($n[$id] ?? 0);
        $out[] = $c;
    }
    foreach (is_array($counts['g'] ?? null) ? $counts['g'] : [] as $slug => $label) {
        if (!preg_match('/^[a-z0-9-]{2,40}$/', (string)$slug)) continue;
        $c = ['id' => 'g:' . $slug, 'g' => 'g', 'l' => mb_substr((string)$label, 0, 40), 'a' => ''];
        if ($n !== null) $c['n'] = (int)($n['g:' . $slug] ?? 0);
        $out[] = $c;
    }
    $order = array_flip(FACTS_GROUPS);
    usort($out, fn($a, $b) => [$order[$a['g']] ?? 99, -($a['n'] ?? 0)] <=> [$order[$b['g']] ?? 99, -($b['n'] ?? 0)]);
    if ($db !== null && function_exists('tagsCatalog') && tagsEnabled($cfg)) {
        foreach (tagsCatalog($db) as $c) {
            if ($n === null) unset($c['n']);
            $out[] = $c;
        }
    }
    return $out;
}

function factsBytes(string $v): ?int
{
    if (!preg_match('/^(\d+(?:[.,]\d+)?)\s*([kmgt](?:i?b)?|b)?$/i', trim($v), $m)) return null;
    $n = (float)str_replace(',', '.', $m[1]);
    $unit = strtolower($m[2] ?? '');
    $pow = $unit === '' ? 3 : ($unit === 'b' ? 0 : strpos('kmgt', $unit[0]) + 1);
    $bytes = $n * (1024 ** $pow);
    return $bytes > 0 && $bytes < 9.0e18 ? (int)round($bytes) : null;
}

function factsRange($raw, string $kind): ?array
{
    if (!is_string($raw) || trim($raw) === '' || strlen($raw) > 60) return null;
    $parts = str_contains($raw, '..') ? explode('..', $raw, 2) : [$raw, $raw];
    $read = static function (string $v) use ($kind) {
        $v = trim($v);
        if ($v === '') return null;
        if ($kind === 'size') return factsBytes($v);
        if ($kind === 'date') return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
        if (!preg_match('/^\d{1,9}(?:[.,]\d{1,2})?$/', $v)) return null;
        $n = (float)str_replace(',', '.', $v);
        if ($kind === 'rate') return (int)round($n * 1000);
        if ($kind === 'pct') return (int)min(100, $n);
        return in_array($kind, ['imdb', 'filmweb'], true) ? min(10.0, $n) : (int)$n;
    };
    $min = $read($parts[0]);
    $max = $read($parts[1]);
    if ($min === null && $max === null) return null;
    if ($min !== null && $max !== null && $min > $max) [$min, $max] = [$max, $min];
    return [$min, $max];
}

function factsFilterHashes(PDO $db, array $f, bool $signedIn): ?array
{
    $where = [];
    $args = [];
    foreach ($f['tags'] ?? [] as $set) {
        $or = [];
        foreach ($set as $id) { $or[] = 'tags LIKE ?'; $args[] = '%,' . $id . ',%'; }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    if (in_array($f['type'] ?? '', ['movie', 'series'], true)) { $where[] = 'tags LIKE ?'; $args[] = '%,t:' . $f['type'] . ',%'; }
    foreach (['year' => ['year_to', 'year_from'], 'imdb' => ['imdb', 'imdb'], 'filmweb' => ['filmweb', 'filmweb'], 'vbitrate' => ['vbitrate', 'vbitrate'],
              'duration' => ['duration_min', 'duration_min'], 'seasons' => ['seasons', 'seasons'], 'episodes' => ['episodes', 'episodes']] as $k => [$colMin, $colMax]) {
        $r = $f[$k] ?? null;
        if (!is_array($r)) continue;
        if ($r[0] !== null) { $where[] = $colMin . ' >= ?'; $args[] = $r[0]; }
        if ($r[1] !== null) { $where[] = $colMax . ' <= ?'; $args[] = $r[1]; }
    }
    if (!empty($f['pics'])) $where[] = 'pics = 1';
    if (!$where) return null;
    if (!factsTableReady($db)) return [];
    $sql = "SELECT info_hash FROM desc_facts WHERE " . implode(' AND ', $where) . " LIMIT " . (FACTS_FILTER_CAP * 2);
    $st = $db->prepare($sql);
    $st->execute($args);
    $hashes = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
    return array_slice(array_keys(factsPublished($db, $hashes, $signedIn)), 0, FACTS_FILTER_CAP);
}

const FACTS_SORTS = ['year', 'imdb', 'filmweb', 'rating', 'votes', 'steam', 'desc'];
const FACTS_SORT_ALL = 2000;
const FACTS_SORT_SCAN = 20000;

function factsFilterConds(PDO $db, array $cfg, array $f, bool $canContent, bool $signedIn): array
{
    $conds = factsFilterCondsMain($db, $cfg, $f, $canContent, $signedIn);
    foreach ($f['notags'] ?? [] as $set) {
        $c = factsExcludeCond($db, $cfg, $set, $canContent, $signedIn);
        if ($c !== null) $conds[] = $c;
    }
    $rated = factsRatingCond($db, $cfg, $f);
    if ($rated !== null) $conds[] = $rated;
    return $conds;
}

function factsExcludeCond(PDO $db, array $cfg, array $set, bool $canContent, bool $signedIn): ?array
{
    $ids = array_values(array_filter($set, fn($id) => factsTagValid((string)$id)));
    if (!$ids) return null;
    $hashes = $canContent ? (factsFilterHashes($db, ['tags' => [$ids]], $signedIn) ?? []) : [];
    $tpl = [];
    $args = [];
    if (function_exists('tagsFilterExplicit') && ($ex = tagsFilterExplicit($db, $ids)) !== null) {
        if (isset($ex['hashes'])) $hashes = array_merge($hashes, $ex['hashes']);
        else { $tpl[] = $ex['tpl']; array_push($args, ...$ex['args']); }
    }
    if (factsMode($cfg) === 'desc_name' && ($nf = factsNameTemplate(['tags' => [$ids]])) !== null) {
        $tpl[] = $nf['tpl'];
        array_push($args, ...$nf['args']);
    }
    $hashes = array_values(array_unique(array_map('strtolower', $hashes)));
    if (!$hashes && !$tpl) return null;
    return [$hashes, $tpl ? '(' . implode(' OR ', $tpl) . ')' : null, $args, true];
}

function factsRepValues(array $cfg, int $votes, int $scoreX100): ?array
{
    if ($votes < 1) return null;
    $stars = function_exists('repMode') && repMode($cfg) === 'stars';
    $p = $stars ? ($scoreX100 / 100 - 0.5) / 4.5 : $scoreX100 / 10000;
    $p = max(0.0, min(1.0, $p));
    $steam = $p - ($p - 0.5) * pow($votes + 1, -log10(2));
    return ['rating' => round($p * 100, 1), 'votes' => $votes, 'steam' => round($steam * 100, 1)];
}

function factsRatedSet(PDO $db, array $cfg): array
{
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = [];
    try {
        $hashes = $db->query("SELECT DISTINCT info_hash FROM hash_votes LIMIT 20000")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return $memo;
    }
    foreach (array_chunk(array_values(array_unique(array_map('strtolower', $hashes))), 1000) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        foreach (["SELECT info_hash, votes_count, score_x100 FROM index_hashes WHERE info_hash IN ($in) AND votes_count > 0",
                  "SELECT info_hash, votes_count, score_x100 FROM whitelist WHERE info_hash IN ($in) AND votes_count > 0 AND banned = 0"] as $sql) {
            $st = $db->prepare($sql);
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$h, $n, $s]) {
                $v = factsRepValues($cfg, (int)$n, (int)$s);
                if ($v !== null) $memo[strtolower((string)$h)] = $v;
            }
        }
    }
    return $memo;
}

function factsRatingCond(PDO $db, array $cfg, array $f): ?array
{
    $want = array_filter(['rating' => $f['rating'] ?? null, 'votes' => $f['votes'] ?? null, 'steam' => $f['steam'] ?? null], 'is_array');
    if (!$want) return null;
    $hashes = [];
    foreach (factsRatedSet($db, $cfg) as $h => $v) {
        foreach ($want as $k => [$min, $max]) {
            if (($min !== null && $v[$k] < $min) || ($max !== null && $v[$k] > $max)) continue 2;
        }
        $hashes[] = $h;
    }
    return [$hashes, null, [], false];
}

function factsDescDates(PDO $db, ?array $hashes, bool $signedIn): array
{
    $out = [];
    $clause = '';
    $args = [];
    if ($hashes !== null) {
        $hashes = array_values(array_unique(array_map('strtolower', $hashes)));
        if (!$hashes) return [];
        $clause = ' AND info_hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')';
        $args = $hashes;
    }
    foreach (["SELECT info_hash, COALESCE(content_reviewed_at, created_at) FROM whitelist WHERE banned = 0 AND content_status = 'approved' AND description IS NOT NULL$clause LIMIT 50000",
              "SELECT info_hash, COALESCE(content_reviewed_at, created_at) FROM hash_content WHERE content_status = 'approved' AND description IS NOT NULL$clause LIMIT 50000"] as $sql) {
        try {
            $st = $db->prepare($sql);
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$h, $at]) {
                $h = strtolower((string)$h);
                if (!isset($out[$h]) || strcmp((string)$at, $out[$h]) > 0) $out[$h] = (string)$at;
            }
        } catch (\Throwable $e) {
            error_log('[facts] description dates: ' . $e->getMessage());
        }
    }
    return array_intersect_key($out, factsPublished($db, array_keys($out), $signedIn));
}

function factsDescValues(PDO $db, string $col, ?array $hashes, bool $signedIn): array
{
    if (!in_array($col, ['imdb', 'filmweb', 'year_from'], true) || !factsTableReady($db)) return [];
    $clause = '';
    $args = [];
    if ($hashes !== null) {
        $hashes = array_values(array_unique(array_map('strtolower', $hashes)));
        if (!$hashes) return [];
        $clause = ' AND info_hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')';
        $args = $hashes;
    }
    $sql = "SELECT info_hash, $col FROM desc_facts WHERE $col IS NOT NULL AND $col > 0$clause LIMIT 50000";
    $st = $db->prepare($sql);
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$h, $v]) $out[strtolower((string)$h)] = (float)$v;
    return array_intersect_key($out, factsPublished($db, array_keys($out), $signedIn));
}

function factsSortByNames(PDO $db, array $cfg): bool
{
    return factsMode($cfg) === 'desc_name' && factsNameReady($db);
}

function factsSortValues(PDO $db, array $cfg, string $key, array $rows, bool $canContent, bool $signedIn): array
{
    $hashes = array_map(fn($r) => strtolower((string)$r['info_hash']), $rows);
    if (in_array($key, ['rating', 'votes', 'steam'], true)) {
        $out = [];
        foreach ($rows as $r) {
            $v = factsRepValues($cfg, (int)($r['votes_count'] ?? 0), (int)($r['score_x100'] ?? 0));
            if ($v !== null) $out[strtolower((string)$r['info_hash'])] = $v[$key];
        }
        return $out;
    }
    if ($key === 'desc') return $canContent ? factsDescDates($db, $hashes, $signedIn) : [];
    if ($key === 'imdb' || $key === 'filmweb') return $canContent ? factsDescValues($db, $key, $hashes, $signedIn) : [];
    if (!factsSortByNames($db, $cfg)) return $canContent ? factsDescValues($db, 'year_from', $hashes, $signedIn) : [];
    $out = [];
    if ($hashes) {
        foreach (array_chunk($hashes, 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("SELECT info_hash, nf_year FROM name_facts WHERE info_hash IN ($in) AND nf_year > 0");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$h, $y]) $out[strtolower((string)$h)] = (float)$y;
        }
    }
    return $out;
}

function factsSortStream(PDO $db, array $cfg, string $key, bool $asc, $after, int $limit, bool $canContent, bool $signedIn): array
{
    if ($after === 'end') return [[], 'end'];
    if ($key === 'year' && factsSortByNames($db, $cfg)) {
        $limit = max(1, $limit);
        $orderClause = $asc ? 'nf_year ASC, info_hash ASC' : 'nf_year DESC, info_hash DESC';
        $clause = $asc ? '(nf_year > ? OR (nf_year = ? AND info_hash > ?))' : '(nf_year < ? OR (nf_year = ? AND info_hash < ?))';
        if ($after === null) {
            $st = $db->prepare("SELECT info_hash, nf_year FROM name_facts WHERE nf_year > 0 ORDER BY $orderClause LIMIT $limit");
            $st->execute();
        } else {
            $st = $db->prepare("SELECT info_hash, nf_year FROM name_facts WHERE nf_year > 0 AND $clause ORDER BY $orderClause LIMIT $limit");
            $st->execute([$after[0], $after[0], $after[1]]);
        }
        $rows = $st->fetchAll(PDO::FETCH_NUM);
        if (!$rows) return [[], 'end'];
        $last = end($rows);
        return [array_map(fn($r) => strtolower((string)$r[0]), $rows), count($rows) < $limit ? 'end' : [(int)$last[1], (string)$last[0]]];
    }
    if (in_array($key, ['rating', 'votes', 'steam'], true)) {
        $vals = array_map(fn($v) => $v[$key], factsRatedSet($db, $cfg));
    } elseif ($key === 'desc') {
        $vals = $canContent ? factsDescDates($db, null, $signedIn) : [];
    } else {
        $vals = $canContent ? factsDescValues($db, $key === 'year' ? 'year_from' : $key, null, $signedIn) : [];
    }
    $keys = array_keys($vals);
    usort($keys, fn($a, $b) => $asc ? [$vals[$a], $a] <=> [$vals[$b], $b] : [$vals[$b], $b] <=> [$vals[$a], $a]);
    return [array_map('strval', $keys), 'end'];
}

function factsSortedSearch(PDO $db, array $cfg, array $q, string $key, bool $asc, bool $canContent, bool $signedIn): array
{
    $page = max(1, (int)($q['page'] ?? 1));
    $perPage = max(1, min(200, (int)($q['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;
    $base = ['sort' => 'last:desc', 'per_page' => 200, 'page' => 1] + $q;
    $first = indexSearchCatalogue($db, $cfg, $base);
    $total = (int)$first['total'];
    $capped = !empty($first['total_capped']);
    $out = ['rows' => [], 'total' => $total, 'total_capped' => $capped, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage)), 'sort_limited' => false];
    $all = array_key_exists('sort_all', $q) ? max(0, (int)$q['sort_all']) : FACTS_SORT_ALL;
    if (!$capped && $total <= $all) {
        $rows = $first['rows'];
        for ($p = 2; count($rows) < $total && $p <= (int)ceil(FACTS_SORT_ALL / 200); $p++) {
            $more = indexSearchCatalogue($db, $cfg, ['page' => $p] + $base);
            if (!$more['rows']) break;
            array_push($rows, ...$more['rows']);
        }
        $vals = factsSortValues($db, $cfg, $key, $rows, $canContent, $signedIn);
        $known = [];
        $unknown = [];
        foreach ($rows as $i => $r) {
            $h = strtolower((string)$r['info_hash']);
            if (isset($vals[$h])) $known[] = [$vals[$h], $i, $r];
            else $unknown[] = $r;
        }
        usort($known, fn($a, $b) => $asc ? [$a[0], $a[1]] <=> [$b[0], $b[1]] : [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        $out['rows'] = array_slice(array_merge(array_column($known, 2), $unknown), $offset, $perPage);
        return $out;
    }
    $matched = [];
    $seenSet = [];
    $scanned = 0;
    $after = null;
    $exhausted = false;
    while (count($matched) < $offset + $perPage && $scanned < FACTS_SORT_SCAN) {
        [$batch, $after] = factsSortStream($db, $cfg, $key, $asc, $after, 1000, $canContent, $signedIn);
        if (!$batch) { $exhausted = true; break; }
        foreach (array_chunk($batch, 200) as $chunk) {
            $scanned += count($chunk);
            foreach ($chunk as $h) $seenSet[$h] = true;
            $r = indexSearchCatalogue($db, $cfg, ['only_hashes' => $chunk, 'page' => 1] + $base);
            $byHash = [];
            foreach ($r['rows'] as $row) $byHash[strtolower((string)$row['info_hash'])] = $row;
            foreach ($chunk as $h) if (isset($byHash[$h])) $matched[] = $byHash[$h];
            if (count($matched) >= $offset + $perPage || $scanned >= FACTS_SORT_SCAN) break;
        }
        if ($after === 'end' && count($matched) < $offset + $perPage) { $exhausted = true; break; }
    }
    $rows = array_slice($matched, $offset, $perPage);
    if (count($rows) < $perPage && $exhausted) {
        $need = $perPage - count($rows);
        $skip = max(0, $offset - count($matched));
        $cond = $key === 'year' && factsSortByNames($db, $cfg)
            ? [[], '(SELECT 1 FROM name_facts nf WHERE nf.info_hash = {T}.info_hash AND nf.nf_year > 0 LIMIT 1) IS NOT NULL', [], true]
            : [array_keys($seenSet), null, [], true];
        $rest = $base;
        $rest['facts_conds'] = array_merge($base['facts_conds'] ?? [], [$cond]);
        $rest['per_page'] = $perPage;
        $firstPage = intdiv($skip, $perPage) + 1;
        $got = [];
        for ($p = $firstPage; $p <= $firstPage + 1 && count($got) < ($skip % $perPage) + $need; $p++) {
            $r = indexSearchCatalogue($db, $cfg, ['page' => $p] + $rest);
            if (!$r['rows']) break;
            array_push($got, ...$r['rows']);
        }
        array_push($rows, ...array_slice($got, $skip % $perPage, $need));
    } elseif (count($rows) < $perPage && !$exhausted) {
        $out['sort_limited'] = true;
    }
    $out['rows'] = $rows;
    return $out;
}

function factsListAttach(PDO $db, array $cfg, array $rows): array
{
    $chipsOn = factsChipsOn($cfg, 'lists');
    $thumbsOn = function_exists('thumbsOn') && thumbsOn($cfg, 'lists');
    $repOn = function_exists('repChipOn') && repChipOn($cfg);
    if (!$rows || (!$chipsOn && !$thumbsOn && !$repOn)) return $rows;
    $hashes = [];
    foreach ($rows as $r) {
        if (!empty($r['info_hash'])) $hashes[] = strtolower((string)$r['info_hash']);
    }
    if (!$hashes) return $rows;
    $canContent = userCan($db, $cfg, 'content.view') && ($cfg['wl_allow_description'] ?? '0') === '1';
    $signedIn = function_exists('richtextViewerSignedIn') && richtextViewerSignedIn($db);
    $desc = $chipsOn && $canContent ? factsChipsFor($db, $hashes, $signedIn) : [];
    $me = usersEnabled($cfg) ? currentUser($db) : null;
    $extra = $chipsOn && function_exists('tagsForHashes') ? tagsForHashes($db, $hashes, $me ? (int)$me['id'] : null, $me !== null && userCan($db, $cfg, 'tags.moderate')) : [];
    $thumbs = $thumbsOn && $canContent ? thumbsFor($db, $cfg, $hashes, $signedIn) : [];
    $reps = $repOn ? repChipsFor($db, $cfg, $hashes) : [];
    foreach ($rows as &$r) {
        $h = strtolower((string)($r['info_hash'] ?? ''));
        if ($h === '') continue;
        if (isset($thumbs[$h])) $r['thumb'] = $thumbs[$h];
        if (isset($reps[$h])) $r['rep'] = $reps[$h];
        if (!$chipsOn) continue;
        $chips = factsRowChips($desc[$h] ?? null, (string)($r['name'] ?? ''), $cfg, 'lists');
        if (function_exists('tagsMergeChips')) $chips = tagsMergeChips($chips, $extra[$h] ?? null, $cfg);
        if ($chips !== null) $r['facts'] = $chips;
    }
    unset($r);
    return $rows;
}
