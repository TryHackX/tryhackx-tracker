<?php
/**
 * Sounds (1.56.0, schema 61): a short noise when something arrives, for the readers who ask for one.
 *
 * Two halves. The LIBRARY is what can be played: the files shipped under assets/sounds/ (ids `b:<name>`,
 * served as the static files they are) and what the owner uploads from Settings (ids `c:<id>`, kept
 * as rows of `sounds` and streamed by api/sound.php — a row rather than a file because the web root
 * is installed read-only on purpose and a backup that carries the database carries these too). The
 * PREFERENCES are one JSON blob per account (`users.sound_prefs`): whether sounds are on at all
 * (OFF until the reader says otherwise — a page that starts making noise uninvited is a page people
 * mute for good), how loud, how long the pre-roll is, and which sound answers which event. An event
 * left unset follows the site default the owner picked (`sound_default_<kind>`); '' means "not this
 * one, whatever the default is".
 *
 * The pre-roll is the odd feature, and it exists because of real hardware: an amplifier on the far
 * end of an HDMI link wakes up when a stream starts and eats the first second of it, so a one-second
 * chime played into a sleeping amplifier is a chime nobody hears. The page therefore opens the
 * stream, plays `pre` milliseconds of either silence or a very quiet 60 Hz hum (for amplifiers that
 * stand by until they sense a signal) and only then the sound. All of that is assets/js/sounds.js;
 * this file only stores the numbers and clamps them.
 *
 * Playback is the browser's business, and browsers refuse to make a sound until the person has
 * touched the page. The script says so honestly rather than pretending: a sound that was due while
 * the page was still untouched is shown as a small note beside the account link, and played the
 * moment the reader clicks anywhere.
 *
 * Nothing here trusts a file by its name or its declared type. What the owner uploads is sniffed
 * (MP3 frames, an Ogg page, a RIFF/WAVE header), measured, capped, and stored under a type this code
 * chose — a browser is never handed the uploader's Content-Type back.
 *
 * The DISPLAY name is the other half of that care and is checked against the whole library, shipped
 * clips included (soundNameProblem): two sounds called "Email notification" are two identical lines
 * in every select on the site, and the reader picking one of them is guessing.
 */

const SOUNDS_MAX_BYTES = 512 * 1024;
const SOUNDS_MIN_BYTES = 256;
const SOUNDS_MAX_CUSTOM = 40;
const SOUNDS_MAX_MS = 15000;
const SOUNDS_PREROLL_MAX_MS = 3000;
const SOUNDS_NAME_MAX = 60;
const SOUNDS_NAME_MIN = 2;

/** The feature as a whole: accounts on, and the owner has not switched it off. */
function soundsEnabled(array $cfg): bool
{
    return usersEnabled($cfg) && (($cfg['sounds_enabled'] ?? '1') === '1');
}

/**
 * The events a sound can answer. A message from a FRIEND is its own event (the pulse and the inbox
 * poll say how many of the waiting messages are from friends — includes/people.php,
 * pmUnreadCountFriends); 'message' is everyone else. The shoutbox adds its own kinds when it arrives.
 */
function soundEventKinds(?array $cfg = null): array
{
    $kinds = ['notification', 'message_friend', 'message'];
    // The shoutbox's three exist only while the shoutbox does: a shout from a friend, a shout from
    // anyone else, and an @-mention of me. Off, they are not offered anywhere — not in Settings, not
    // on the account's tab — and a stored choice for them is simply not resolved.
    $cfg = $cfg ?? (is_array($GLOBALS['cfg'] ?? null) ? $GLOBALS['cfg'] : null);
    if ($cfg !== null && function_exists('shoutEnabled') && shoutEnabled($cfg)) {
        $kinds[] = 'shout_friend';
        $kinds[] = 'shout';
        $kinds[] = 'mention';
    }
    return $kinds;
}

function soundBuiltinDir(): string
{
    return dirname(__DIR__) . '/assets/sounds';
}

/** "notification-center" -> "Notification center". */
function soundPrettyName(string $base): string
{
    $s = preg_replace('/[-_]+/', ' ', $base);
    return ucfirst(trim((string)$s));
}

/**
 * The shipped files, keyed by id. Scanned once per process; a file whose name is not a plain slug
 * is ignored rather than turned into a URL.
 */
function soundBuiltins(string $baseUrl = ''): array
{
    static $names = null;
    if ($names === null) {
        $names = [];
        $dir = soundBuiltinDir();
        $files = is_dir($dir) ? scandir($dir) : [];
        foreach ($files ?: [] as $f) {
            if (!preg_match('/^([a-z0-9][a-z0-9-]{0,48})\.(mp3|ogg|wav)$/', $f, $m)) continue;
            $names[$m[1]] = ['file' => $f, 'ext' => $m[2]];
        }
        ksort($names);
    }
    $out = [];
    foreach ($names as $base => $info) {
        $id = 'b:' . $base;
        $out[$id] = ['id' => $id, 'name' => soundPrettyName($base), 'url' => $baseUrl . 'assets/sounds/' . $info['file'],
                     'ms' => null, 'custom' => false];
    }
    return $out;
}

/** The owner's uploads, newest last, without their bytes. */
function soundCustomList(PDO $db): array
{
    static $ok = null;
    if ($ok === false) return [];
    try {
        $rows = $db->query("SELECT id, name, mime, bytes, duration_ms, sha1, created_at FROM sounds ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $ok = true;
        return $rows ?: [];
    } catch (\Throwable $e) {
        $ok = false;      // the table arrives with schema 61; a page rendered mid-upgrade has no uploads
        return [];
    }
}

/** One upload with its bytes, or null. */
function soundCustomGet(PDO $db, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $db->prepare("SELECT id, name, mime, bytes, duration_ms, sha1, data FROM sounds WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function soundCustomUrl(array $row, string $baseUrl = ''): string
{
    return $baseUrl . 'api.php?endpoint=sound&id=' . (int)$row['id'] . '&v=' . substr((string)$row['sha1'], 0, 8);
}

/** Everything that can be played, keyed by id: the shipped files first, then the uploads. */
function soundLibrary(PDO $db, string $baseUrl = ''): array
{
    $lib = soundBuiltins($baseUrl);
    foreach (soundCustomList($db) as $row) {
        $id = 'c:' . (int)$row['id'];
        $lib[$id] = ['id' => $id, 'name' => (string)$row['name'], 'url' => soundCustomUrl($row, $baseUrl),
                     'ms' => $row['duration_ms'] === null ? null : (int)$row['duration_ms'], 'custom' => true,
                     'bytes' => (int)$row['bytes'], 'num' => (int)$row['id']];
    }
    return $lib;
}

/** The library as the browser sees it: a list, no internals. */
function soundLibraryForClient(array $lib): array
{
    $out = [];
    foreach ($lib as $e) $out[] = ['id' => $e['id'], 'name' => $e['name'], 'url' => $e['url'], 'ms' => $e['ms'], 'custom' => $e['custom']];
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// What an upload is
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Walk MPEG audio frames from the first sync word. Returns the count and the length in ms, or null
 * when the bytes do not parse as a run of frames — a file that merely STARTS with a sync word is a
 * file that starts with 0xFF, which is most of them.
 */
function soundMp3Scan(string $b): ?array
{
    $n = strlen($b);
    $i = 0;
    if (substr($b, 0, 3) === 'ID3' && $n > 10) {
        $size = ((ord($b[6]) & 0x7f) << 21) | ((ord($b[7]) & 0x7f) << 14) | ((ord($b[8]) & 0x7f) << 7) | (ord($b[9]) & 0x7f);
        $i = 10 + $size + ((ord($b[5]) & 0x10) ? 10 : 0);
    }
    $bitrates = [
        1 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
        2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
    ];
    $rates = [1 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 25 => [11025, 12000, 8000]];
    $frames = 0; $ms = 0.0; $started = false;
    while ($i + 4 <= $n) {
        $h = (ord($b[$i]) << 24) | (ord($b[$i + 1]) << 16) | (ord($b[$i + 2]) << 8) | ord($b[$i + 3]);
        $sync = ($h >> 21) === 0x7ff;
        $vbits = ($h >> 19) & 3;
        $version = [3 => 1, 2 => 2, 0 => 25][$vbits] ?? null;
        $layer = 4 - (($h >> 17) & 3);
        $br = ($h >> 12) & 15;
        $sr = ($h >> 10) & 3;
        $pad = ($h >> 9) & 1;
        if (!$sync || $version === null || $layer !== 3 || $br === 0 || $br === 15 || $sr === 3) {
            if ($started) break;       // a run that stopped: the rest is garbage or a tag
            $i++;
            if ($i > 65536) return null;   // no frame within the first 64 KB: not an MP3
            continue;
        }
        $kbps = $bitrates[$version === 1 ? 1 : 2][$br];
        $rate = $rates[$version][$sr];
        $samples = $version === 1 ? 1152 : 576;
        $len = intdiv(intdiv($samples, 8) * $kbps * 1000, $rate) + $pad;
        if ($len < 24 || $i + $len > $n) break;
        $started = true;
        $frames++;
        $ms += $samples * 1000 / $rate;
        $i += $len;
    }
    if ($frames < 4) return null;
    return ['frames' => $frames, 'ms' => (int)round($ms)];
}

/** RIFF/WAVE: the fmt and data chunks, for a length; null when the header is not a WAVE at all. */
function soundWavScan(string $b): ?array
{
    if (strlen($b) < 44 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WAVE') return null;
    $i = 12; $n = strlen($b); $rate = 0; $ch = 0; $bits = 0; $data = 0;
    while ($i + 8 <= $n) {
        $id = substr($b, $i, 4);
        $size = unpack('V', substr($b, $i + 4, 4))[1];
        if ($id === 'fmt ' && $i + 24 <= $n) {
            $f = unpack('vformat/vch/Vrate/Vbps/vblock/vbits', substr($b, $i + 8, 16));
            $ch = (int)$f['ch']; $rate = (int)$f['rate']; $bits = (int)$f['bits'];
        } elseif ($id === 'data') {
            $data = min($size, $n - $i - 8);
        }
        $i += 8 + $size + ($size & 1);
    }
    // No fmt chunk (or nonsense in it) is no WAVE, whatever the twelve bytes at the front said.
    if ($rate <= 0 || $ch <= 0) return null;
    $bytesPerSec = $rate * $ch * intdiv(max(8, $bits), 8);
    return ['ms' => $bytesPerSec > 0 ? (int)round($data * 1000 / $bytesPerSec) : null];
}

/**
 * Ogg (Vorbis or Opus): the identification header on the first page gives the sample rate (Opus
 * always counts at 48 kHz, minus its pre-skip), the last page's granule position the total number of
 * samples. Null when either is missing — a length that cannot be measured cannot be capped.
 */
function soundOggScan(string $b): ?array
{
    $n = strlen($b);
    if ($n < 58 || substr($b, 0, 4) !== 'OggS') return null;
    $nseg = ord($b[26]);
    $off = 27 + $nseg;                          // the first page's payload: the identification header
    if ($off + 20 > $n) return null;
    $rate = 0;
    $preskip = 0;
    if (substr($b, $off, 8) === 'OpusHead') {
        $rate = 48000;
        $preskip = unpack('v', substr($b, $off + 10, 2))[1];
    } elseif (substr($b, $off, 7) === "\x01vorbis") {
        $rate = unpack('V', substr($b, $off + 12, 4))[1];
    } else {
        return null;
    }
    if ($rate <= 0) return null;
    // The last page: the newest 'OggS' that is a page header (version byte 0), scanning backwards.
    $hay = $b;
    while (true) {
        $pos = strrpos($hay, 'OggS');
        if ($pos === false) return null;
        if ($pos + 27 <= $n && ord($b[$pos + 4]) === 0) break;
        $hay = substr($hay, 0, $pos);
    }
    $granule = unpack('P', substr($b, $pos + 6, 8))[1];
    if (!is_int($granule) || $granule < 0) return null;
    return ['ms' => (int)round(max(0, $granule - $preskip) * 1000 / $rate)];
}

/**
 * What these bytes are, decided from the bytes. ['mime' => …, 'ext' => …, 'ms' => int|null] or
 * null for anything that is not one of the three formats every browser plays.
 */
function soundSniff(string $b): ?array
{
    if (substr($b, 0, 4) === 'OggS') {
        $ogg = soundOggScan($b);
        return $ogg === null ? null : ['mime' => 'audio/ogg', 'ext' => 'ogg', 'ms' => $ogg['ms']];
    }
    $wav = soundWavScan($b);
    if ($wav !== null) return ['mime' => 'audio/wav', 'ext' => 'wav', 'ms' => $wav['ms']];
    $mp3 = soundMp3Scan($b);
    if ($mp3 !== null) return ['mime' => 'audio/mpeg', 'ext' => 'mp3', 'ms' => $mp3['ms']];
    return null;
}

/** A display name for an upload: printable, trimmed, bounded; '' when nothing usable is left. */
function soundCleanName(string $name): string
{
    $name = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name) ?? '');
    $name = preg_replace('/\s+/', ' ', $name) ?? '';
    if (function_exists('mb_substr')) $name = mb_substr($name, 0, SOUNDS_NAME_MAX);
    else $name = substr($name, 0, SOUNDS_NAME_MAX);
    return $name;
}

/** What two names are compared by: cleaned, then case-folded. "Ding" and " ding " are one name. */
function soundNameKey(string $name): string
{
    $name = soundCleanName($name);
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

/**
 * Every name the library already answers to, as comparison keys — the SHIPPED clips' pretty names
 * as well as the uploads, because a select showing "Email notification" twice is a select nobody
 * can pick from. `$exceptId` is the row being renamed: a name is not taken by itself.
 */
function soundNamesTaken(PDO $db, int $exceptId = 0): array
{
    $keys = [];
    foreach (soundBuiltins() as $e) $keys[soundNameKey((string)$e['name'])] = true;
    foreach (soundCustomList($db) as $r) {
        if ((int)$r['id'] === $exceptId) continue;
        $keys[soundNameKey((string)$r['name'])] = true;
    }
    return $keys;
}

/**
 * The one place the name rules live, so an upload and a rename cannot drift apart: something is
 * left after cleaning, it is long enough to read, and no other sound in the library answers to it
 * already. Returns a lang key, or null when the name is fine. (The upper bound is not an error:
 * soundCleanName() has already cut the name to SOUNDS_NAME_MAX.)
 */
function soundNameProblem(PDO $db, string $name, int $exceptId = 0): ?string
{
    $name = soundCleanName($name);
    if ($name === '') return 'api.sounds.name_required';
    $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($len < SOUNDS_NAME_MIN) return 'api.sounds.name_short';
    if (isset(soundNamesTaken($db, $exceptId)[soundNameKey($name)])) return 'api.sounds.name_taken';
    return null;
}

/**
 * Keep an upload. ['ok' => true, 'row' => …] or ['ok' => false, 'error' => <lang key>]. The bytes
 * are already decoded by the caller; this decides whether they are a sound at all.
 *
 * The name is judged first: it is the cheapest check of the lot, and a file rejected for its bytes
 * after its name was already refused would tell the owner the second-best reason.
 */
function soundStore(PDO $db, string $name, string $bytes): array
{
    $name = soundCleanName($name);
    if (($bad = soundNameProblem($db, $name)) !== null) return ['ok' => false, 'error' => $bad];
    $len = strlen($bytes);
    if ($len < SOUNDS_MIN_BYTES) return ['ok' => false, 'error' => 'api.sounds.too_small'];
    if ($len > SOUNDS_MAX_BYTES) return ['ok' => false, 'error' => 'api.sounds.too_large'];
    if (count(soundCustomList($db)) >= SOUNDS_MAX_CUSTOM) return ['ok' => false, 'error' => 'api.sounds.too_many'];
    $kind = soundSniff($bytes);
    if ($kind === null) return ['ok' => false, 'error' => 'api.sounds.not_audio'];
    if ($kind['ms'] !== null && $kind['ms'] > SOUNDS_MAX_MS) return ['ok' => false, 'error' => 'api.sounds.too_long'];
    $sha = sha1($bytes);
    $st = $db->prepare("SELECT id FROM sounds WHERE sha1 = ?");
    $st->execute([$sha]);
    if ($st->fetchColumn()) return ['ok' => false, 'error' => 'api.sounds.duplicate'];
    $st = $db->prepare("INSERT INTO sounds (name, mime, bytes, duration_ms, sha1, data) VALUES (?, ?, ?, ?, ?, ?)");
    $st->bindValue(1, $name);
    $st->bindValue(2, $kind['mime']);
    $st->bindValue(3, $len, PDO::PARAM_INT);
    $st->bindValue(4, $kind['ms'], $kind['ms'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(5, $sha);
    $st->bindValue(6, $bytes, PDO::PARAM_LOB);
    $st->execute();
    $id = (int)$db->lastInsertId();
    return ['ok' => true, 'row' => ['id' => $id, 'name' => $name, 'mime' => $kind['mime'], 'bytes' => $len,
                                     'duration_ms' => $kind['ms'], 'sha1' => $sha]];
}

/**
 * Rename an upload. Only the display text changes: the id, the bytes and the sha1 stay, so every
 * site default and every reader's pick still points at the same sound and nothing has to be saved
 * again. ['ok' => true, 'row' => ['id' => …, 'name' => …]] or ['ok' => false, 'error' => <lang key>].
 */
function soundRename(PDO $db, int $id, string $name): array
{
    $st = $db->prepare("SELECT id FROM sounds WHERE id = ?");
    $st->execute([$id]);
    if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'api.sounds.unknown'];
    $name = soundCleanName($name);
    if (($bad = soundNameProblem($db, $name, $id)) !== null) return ['ok' => false, 'error' => $bad];
    $st = $db->prepare("UPDATE sounds SET name = ? WHERE id = ?");
    $st->execute([$name, $id]);
    return ['ok' => true, 'row' => ['id' => $id, 'name' => $name]];
}

/**
 * Remove an upload. A site default that named it is cleared with it, so nothing points at a sound
 * that is gone; a reader's own choice that named it resolves to "the site default" from now on —
 * soundPrefsValidate() drops an id it cannot find whenever the preferences are read.
 */
function soundDelete(PDO $db, int $id): bool
{
    $st = $db->prepare("DELETE FROM sounds WHERE id = ?");
    $st->execute([$id]);
    if ($st->rowCount() < 1) return false;
    foreach (soundEventKinds() as $k) {
        $st = $db->prepare("DELETE FROM settings WHERE `key` = ? AND `value` = ?");
        $st->execute(['sound_default_' . $k, 'c:' . $id]);
    }
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// A reader's preferences
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Off, moderately loud, a second of silence first, every event on the site default. Silence rather
 * than the tone: opening the stream is what wakes an HDMI link, and the tone — meant for amplifiers
 * that stand by until they sense a signal — was heard as a buzz before every chime by the first
 * person to try it. It stays an option for the amplifier that needs it.
 */
function soundPrefsDefault(): array
{
    $ev = [];
    foreach (soundEventKinds() as $k) $ev[$k] = null;
    return ['on' => 0, 'vol' => 60, 'pre' => 1000, 'pre_kind' => 'silence', 'ev' => $ev];
}

/**
 * Whatever came in (a stored blob, a form, nothing) as a preference set this code vouches for.
 * Numbers are clamped, the pre-roll rounded to tenths of a second, an unknown kind of pre-roll
 * becomes the default, and an event may name a sound in the library, '' (this event stays silent)
 * or null (follow the site default) — anything else is null.
 */
function soundPrefsValidate($raw, array $libraryIds): array
{
    $p = soundPrefsDefault();
    if (is_string($raw)) $raw = json_decode($raw, true);
    if (!is_array($raw)) return $p;
    $p['on'] = !empty($raw['on']) ? 1 : 0;
    if (isset($raw['vol']) && is_numeric($raw['vol'])) $p['vol'] = max(0, min(100, (int)$raw['vol']));
    if (isset($raw['pre']) && is_numeric($raw['pre'])) $p['pre'] = max(0, min(SOUNDS_PREROLL_MAX_MS, (int)(round((int)$raw['pre'] / 100) * 100)));
    if (isset($raw['pre_kind']) && in_array($raw['pre_kind'], ['silence', 'hum'], true)) $p['pre_kind'] = $raw['pre_kind'];
    else $p['pre_kind'] = 'silence';
    $ev = is_array($raw['ev'] ?? null) ? $raw['ev'] : [];
    foreach (soundEventKinds() as $k) {
        if (!array_key_exists($k, $ev) || $ev[$k] === null) { $p['ev'][$k] = null; continue; }
        $v = is_scalar($ev[$k]) ? (string)$ev[$k] : '';
        $p['ev'][$k] = $v === '' ? '' : (in_array($v, $libraryIds, true) ? $v : null);
    }
    return $p;
}

/** The stored blob as it is, or null. */
function soundPrefsRaw(PDO $db, array $user): ?string
{
    if (array_key_exists('sound_prefs', $user)) return $user['sound_prefs'] === null ? null : (string)$user['sound_prefs'];
    $st = $db->prepare("SELECT sound_prefs FROM users WHERE id = ?");
    $st->execute([(int)$user['id']]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (string)$v;
}

/** This account's preferences, validated against the library it can choose from. */
function soundPrefsFor(PDO $db, array $user, array $library): array
{
    return soundPrefsValidate(soundPrefsRaw($db, $user), array_keys($library));
}

function soundPrefsSave(PDO $db, int $userId, array $prefs): void
{
    $st = $db->prepare("UPDATE users SET sound_prefs = ? WHERE id = ?");
    $st->execute([json_encode($prefs, JSON_UNESCAPED_SLASHES), $userId]);
}

/** The site defaults the owner picked, one id or '' per kind. */
function soundSiteDefaults(array $cfg, array $library): array
{
    $out = [];
    foreach (soundEventKinds() as $k) {
        $id = (string)($cfg['sound_default_' . $k] ?? '');
        $out[$k] = isset($library[$id]) ? $id : '';
    }
    return $out;
}

/** The library entry that answers this event for this reader, or null for silence. */
function soundResolve(array $cfg, array $prefs, array $library, string $kind): ?array
{
    $choice = $prefs['ev'][$kind] ?? null;
    if ($choice === '') return null;
    $id = $choice ?? (string)($cfg['sound_default_' . $kind] ?? '');
    if ($id === '') return null;
    return $library[$id] ?? null;
}

/**
 * What the page's script needs to play on its own — or null when there is nothing to play: the
 * feature is off, the reader may not use it, has not switched it on, or every event is silent.
 * Rendered into the navigation as data-sounds, so every page carries it without asking.
 */
function soundClientConfig(PDO $db, array $cfg, ?array $user, string $baseUrl = '', ?bool $may = null): ?array
{
    if ($user === null || !soundsEnabled($cfg)) return null;
    // `$may` is the reader's sounds.use; null means "ask the session" (the pages), a test passes it.
    if (!($may ?? userCan($db, $cfg, 'sounds.use'))) return null;
    // Muted is the common case and the cheap one: no library lookup for a reader who never asked.
    $raw = soundPrefsRaw($db, $user);
    $quick = $raw === null ? null : json_decode($raw, true);
    if (!is_array($quick) || empty($quick['on'])) return null;
    $lib = soundLibrary($db, $baseUrl);
    $p = soundPrefsValidate($raw, array_keys($lib));
    $ev = [];
    $any = false;
    foreach (soundEventKinds() as $k) {
        $r = soundResolve($cfg, $p, $lib, $k);
        $ev[$k] = $r ? ['id' => $r['id'], 'url' => $r['url']] : null;
        if ($r) $any = true;
    }
    if (!$any) return null;
    return ['vol' => $p['vol'], 'pre' => $p['pre'], 'pre_kind' => $p['pre_kind'], 'ev' => $ev];
}
