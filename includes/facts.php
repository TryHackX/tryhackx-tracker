<?php

const FACTS_VERSION = 1;
const FACTS_TEXT_MAX = 400000;
const FACTS_FILTER_CAP = 5000;
const FACTS_TICK_BATCH = 40;
const FACTS_TAGS_MAX = 6;
const FACTS_GROUPS = ['q', 'v', 'h', 'a', 's', 'l', 't', 'g'];
const FACTS_ONGOING = 9999;

const FACTS_LABELS = [
    'q:2160p' => '2160p', 'q:1440p' => '1440p', 'q:1080p' => '1080p', 'q:720p' => '720p', 'q:sd' => 'SD',
    'v:h264' => 'H.264', 'v:h265' => 'H.265', 'v:av1' => 'AV1', 'v:vp9' => 'VP9', 'v:mpeg2' => 'MPEG-2', 'v:xvid' => 'XviD', 'v:vc1' => 'VC-1',
    'h:hdr10' => 'HDR10', 'h:hdr10p' => 'HDR10+', 'h:dv' => 'Dolby Vision', 'h:hlg' => 'HLG', 'h:hdr' => 'HDR',
    'a:aac' => 'AAC', 'a:ac3' => 'AC-3', 'a:eac3' => 'E-AC-3', 'a:atmos' => 'Atmos', 'a:dts' => 'DTS', 'a:dtshdma' => 'DTS-HD MA',
    'a:truehd' => 'TrueHD', 'a:flac' => 'FLAC', 'a:opus' => 'Opus', 'a:mp3' => 'MP3', 'a:pcm' => 'PCM',
    's:webdl' => 'WEB-DL', 's:webrip' => 'WEBRip', 's:bluray' => 'BluRay', 's:remux' => 'REMUX', 's:hdtv' => 'HDTV', 's:dvd' => 'DVD', 's:cam' => 'CAM/TS',
    'l:multi' => 'MULTi',
];
const FACTS_WORDS = ['l:lektor_pl', 'l:dub_pl', 'l:sub_pl', 'l:audio_pl', 'l:audio_en', 'l:sub_en', 't:movie', 't:series'];

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

function factsFromDescription(string $text, string $title = ''): array
{
    $plain = factsPlain($text);
    $sections = factsSections($plain);
    $tags = [];
    $res = null;
    $rates = [];
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
            foreach (factsHdr((string)factsField($sec, ['hdr format', 'transfer characteristics'])) as $x) $tags[$x] = true;
        } elseif ($sec['type'] === 'audio') {
            foreach (factsAudio((string)factsField($sec, ['format']), (string)factsField($sec, ['commercial name']), (string)factsField($sec, ['title'])) as $x) $tags[$x] = true;
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
        }
    }
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
        'year_from' => $yFrom, 'year_to' => $yTo,
        'seasons' => $seasons, 'episodes' => $episodes, 'duration_min' => $duration,
        'imdb' => factsRating($plain, 'IMDb'), 'filmweb' => factsRating($plain, 'Filmweb'),
        'pics' => preg_match('/\[img\b|!\[[^\]]*\]\(|upl-image/i', $text) ? 1 : 0,
        'tags' => $ordered, 'genres' => $genres,
    ];
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
    $genres = implode('|', array_map(fn($k, $v) => $k . '=' . str_replace(['|', '='], ' ', $v), array_keys($f['genres']), $f['genres']));
    $st = $db->prepare("INSERT INTO desc_facts (info_hash, src, ver, text_sha, res, vbitrate, year_from, year_to, seasons, episodes, duration_min, imdb, filmweb, pics, tags, genres, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                        ON DUPLICATE KEY UPDATE src = VALUES(src), ver = VALUES(ver), text_sha = VALUES(text_sha), res = VALUES(res), vbitrate = VALUES(vbitrate),
                            year_from = VALUES(year_from), year_to = VALUES(year_to), seasons = VALUES(seasons), episodes = VALUES(episodes),
                            duration_min = VALUES(duration_min), imdb = VALUES(imdb), filmweb = VALUES(filmweb), pics = VALUES(pics), tags = VALUES(tags),
                            genres = VALUES(genres), updated_at = VALUES(updated_at)");
    $st->execute([$hash, $src, FACTS_VERSION, sha1($text), $f['res'], $f['vbitrate'], $f['year_from'], $f['year_to'], $f['seasons'], $f['episodes'],
                  $f['duration_min'], $f['imdb'], $f['filmweb'], $f['pics'], $f['tags'] ? ',' . implode(',', $f['tags']) . ',' : '', mb_substr($genres, 0, 400)]);
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
    return array_filter([
        'tags' => $tags,
        'year' => $year,
        'vbitrate' => $r['vbitrate'] !== null ? (int)$r['vbitrate'] : null,
        'seasons' => $r['seasons'] !== null ? (int)$r['seasons'] : null,
        'episodes' => $r['episodes'] !== null ? (int)$r['episodes'] : null,
        'duration' => $r['duration_min'] !== null ? (int)$r['duration_min'] : null,
        'imdb' => $r['imdb'] !== null ? (float)$r['imdb'] : null,
        'filmweb' => $r['filmweb'] !== null ? (float)$r['filmweb'] : null,
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

function factsCatalog(): array
{
    $out = [];
    foreach (array_merge(array_keys(FACTS_LABELS), FACTS_WORDS) as $id) {
        if (str_starts_with($id, 't:')) continue;
        $out[] = ['id' => $id, 'g' => substr($id, 0, 1), 'l' => factsTagLabel($id)];
    }
    $order = array_flip(FACTS_GROUPS);
    usort($out, fn($a, $b) => [$order[$a['g']] ?? 99] <=> [$order[$b['g']] ?? 99]);
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
        if (!preg_match('/^\d{1,9}(?:[.,]\d{1,2})?$/', $v)) return null;
        $n = (float)str_replace(',', '.', $v);
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
    foreach (['year' => ['year_to', 'year_from'], 'imdb' => ['imdb', 'imdb'], 'filmweb' => ['filmweb', 'filmweb']] as $k => [$colMin, $colMax]) {
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
