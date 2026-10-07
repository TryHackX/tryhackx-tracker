<?php
/**
 * Descriptions and source links: what people write ABOUT a torrent, wherever the torrent lives.
 *
 * Two homes, one contract. A registered torrent keeps its words on its `whitelist` row, as it has
 * since 1.17.0. A torrent the tracker has only SEEN (an `index_hashes` row and nothing else — every
 * hash in blacklist mode, and every unregistered one in whitelist mode) keeps them in `hash_content`,
 * a table added in 1.53.0 so that describing such a torrent does not have to register it: a whitelist
 * row is a registration, the index poll drops a whitelisted hash out of the index, and in whitelist
 * mode the accesslist is built from those rows. Words are not a registration. Every reader of a
 * description goes through richtextContentFor(), which looks in both places, and every writer goes
 * through contentAttach() — the whitelist form, the Info panel and the review queue included — so
 * the two homes cannot drift apart in what they allow.
 *
 * Who wrote it is recorded (`content_user_id`) and shown with the text, and the author is told what
 * happened to it: a description sent into a queue that never answers is the last description that
 * person writes.
 */
require_once __DIR__ . '/richtext.php';
// How fast descriptions may be written, the same words twice, a guest's CAPTCHA (1.71.0): the site's one
// anti-spam layer, which the description endpoints ask wherever they are loaded from.
require_once __DIR__ . '/antispam.php';

/** Descriptions or source links are switched on at all. */
function contentEnabled(array $cfg): bool {
    return (($cfg['wl_allow_description'] ?? '0') === '1') || (($cfg['wl_allow_source_url'] ?? '0') === '1');
}

function contentTableFor(string $kind): string {
    if ($kind === 'wl') return 'whitelist';
    if ($kind === 'idx') return 'hash_content';
    throw new InvalidArgumentException('unknown content kind: ' . $kind);
}

/**
 * The record that carries (or would carry) the words for one hash: the whitelist row when there is
 * one, otherwise the hash_content row. Null when neither exists.
 */
function contentRecordFor(PDO $db, string $hash): ?array {
    $hash = strtolower($hash);
    $st = $db->prepare("SELECT id, name, source_url, description, description_format, content_status,
                               content_user_id, content_rejected_note, content_credits, banned
                          FROM whitelist WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        return ['kind' => 'wl', 'id' => (int)$r['id'], 'info_hash' => $hash, 'name' => $r['name'],
                'source_url' => $r['source_url'], 'description' => $r['description'],
                'description_format' => (string)$r['description_format'], 'content_status' => (string)$r['content_status'],
                'content_user_id' => $r['content_user_id'] !== null ? (int)$r['content_user_id'] : null,
                'content_rejected_note' => $r['content_rejected_note'],
                'content_credits' => $r['content_credits'] !== null ? (string)$r['content_credits'] : null,
                'banned' => (int)$r['banned'] === 1];
    }
    $st = $db->prepare("SELECT c.id, c.source_url, c.description, c.description_format, c.content_status,
                               c.content_user_id, c.content_rejected_note, c.content_credits, i.name
                          FROM hash_content c LEFT JOIN index_hashes i ON i.info_hash = c.info_hash
                         WHERE c.info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        // The second home has no `banned` column of its own — the ban list is the only witness, so
        // ask it. Hardcoding false here made every caller that trusts this flag (contentAttach, the
        // Info panel, richtextContentFor) treat a banned hash as an ordinary one, which is the whole
        // difference between the two homes disappearing at exactly the point it matters.
        return ['kind' => 'idx', 'id' => (int)$r['id'], 'info_hash' => $hash, 'name' => $r['name'],
                'source_url' => $r['source_url'], 'description' => $r['description'],
                'description_format' => (string)$r['description_format'], 'content_status' => (string)$r['content_status'],
                'content_user_id' => $r['content_user_id'] !== null ? (int)$r['content_user_id'] : null,
                'content_rejected_note' => $r['content_rejected_note'],
                'content_credits' => $r['content_credits'] !== null ? (string)$r['content_credits'] : null,
                'banned' => function_exists('isHashBanned') && isHashBanned($db, $hash)];
    }
    return null;
}

function contentOccupied(array $rec): bool {
    return ($rec['description'] !== null && $rec['description'] !== '')
        || ($rec['source_url'] !== null && $rec['source_url'] !== '');
}

/** Does the index know this hash? The only other place words may be attached to. */
function contentIndexKnows(PDO $db, string $hash): bool {
    $st = $db->prepare("SELECT 1 FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([strtolower($hash)]);
    return (bool)$st->fetchColumn();
}

/** The torrent's name for a notification, from whichever row has one; the hash when none does. */
function contentNameFor(PDO $db, string $hash): string {
    $rec = contentRecordFor($db, $hash);
    $name = $rec['name'] ?? null;
    if ($name === null || $name === '') {
        $st = $db->prepare("SELECT name FROM index_hashes WHERE info_hash = ? LIMIT 1");
        $st->execute([strtolower($hash)]);
        $name = $st->fetchColumn();
    }
    return is_string($name) && $name !== '' ? $name : strtolower($hash);
}

/**
 * Who wrote the words, as the Info panel draws them: ['username', 'avatar_sha', 'credit_public'] or
 * null. The one query the name always cost (it was contentAuthorName() until 1.63.0) — the picture
 * beside the name rides along in it rather than being asked for a second time, and so (1.70.0) does
 * whether the author lets their name be shown at all (users.content_credit_public).
 */
function contentAuthorRow(PDO $db, ?int $userId): ?array {
    if ($userId === null || $userId < 1) return null;
    $st = $db->prepare("SELECT username, avatar_sha, content_credit_public FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return (is_array($u) && is_string($u['username'] ?? null) && $u['username'] !== '')
        ? ['username' => (string)$u['username'], 'avatar_sha' => $u['avatar_sha'] ?? null,
           'credit_public' => (int)($u['content_credit_public'] ?? 1) === 1] : null;
}

/** What the digest and the tab badge count: everything waiting, in both homes. */
function contentPendingCount(PDO $db): int {
    return (int)$db->query("SELECT (SELECT COUNT(*) FROM whitelist WHERE content_status = 'pending')
                                 + (SELECT COUNT(*) FROM hash_content WHERE content_status = 'pending')")->fetchColumn();
}

/* ── who wrote it: the credit chain (v81, 1.70.0) ─────────────────────────────────────────────────
 *
 * Under a description the Info panel says who wrote it and who has edited it since, in order:
 * "Description by TryHackX (first) → dominikk26 (25% edit) → Majkel (6% edit)". No version history is
 * kept for it: a moderator applying an EDIT compares the text as it stands then with the edited one
 * (contentEditShare()) and one credit is added; a REWRITE works as it always has — the proposer
 * becomes the author — and starts the chain again with them as the first. The author of record
 * (`content_user_id`) is the chain's first entry, always.
 *
 * Stored on the home row itself, `content_credits` on `whitelist` and `hash_content`, as compact JSON
 * written only by contentCreditsEncode(): a list of {"u":<account id>,"k":"f"|"e","p":<percent, edits
 * only>,"t":<unix time>}, keys in that order, no spaces. u = 0 is an account that has since been
 * deleted (contentForgetAccount()). Only accounts are credited: an anonymous proposer (accounts
 * switched off) adds nothing, and a first description without an author starts no chain.
 *
 * The text form is load-bearing in one place: a member's list of descriptions (includes/
 * profiledescs.php) finds the rows crediting them with a LIKE on `{"u":<id>,` — this project reads
 * JSON in PHP, never in SQL — and then decodes each row to know exactly how. tests/content_test.php
 * pins the shape.
 */
const CONTENT_CREDITS_MAX    = 100;      // entries kept: the first and the newest 99
const CONTENT_EDIT_KINDS     = ['rewrite', 'edit'];
const CONTENT_ARCHIVE_NOTE   = 'replaced by a later proposal';     // contentEditApply()'s kept version
const CONTENT_ARCHIVE_KEEP   = 10;       // …of which the newest this many are kept per description (1.70.0)
const CONTENT_WITHDRAWN_NOTE = 'withdrawn: the description was deleted';
const CONTENT_SHARE_BUDGET   = 200000;   // steps the exact comparison may walk (see contentEditShare())

/**
 * A stored chain as a list of entries, every field checked; an entry that is not one is dropped. With
 * nothing usable and an author on the row — a row written before v81 or by hand — the author alone,
 * as the first: the credit the row has always shown.
 */
function contentCreditsDecode(?string $json, ?int $authorId = null): array {
    $out = [];
    $raw = ($json !== null && $json !== '') ? json_decode($json, true) : null;
    if (is_array($raw)) {
        foreach ($raw as $e) {
            if (!is_array($e) || !isset($e['u'], $e['k']) || !is_int($e['u']) || $e['u'] < 0) continue;
            $k = $e['k'] === 'f' ? 'f' : ($e['k'] === 'e' ? 'e' : null);
            if ($k === null) continue;
            $one = ['u' => $e['u'], 'k' => $k];
            if ($k === 'e') {
                $p = isset($e['p']) && is_int($e['p']) ? $e['p'] : 0;
                if ($p < 1) continue;
                $one['p'] = min(100, $p);
            }
            $one['t'] = isset($e['t']) && is_int($e['t']) && $e['t'] > 0 ? $e['t'] : 0;
            $out[] = $one;
        }
    }
    if (!$out && $authorId !== null && $authorId > 0) $out[] = ['u' => $authorId, 'k' => 'f', 't' => 0];
    return $out;
}

/** The chain as it is stored — or NULL for an empty one. Keys in a fixed order; see the note above. */
function contentCreditsEncode(array $chain): ?string {
    $rows = [];
    foreach ($chain as $e) {
        $one = ['u' => max(0, (int)$e['u']), 'k' => ($e['k'] ?? '') === 'e' ? 'e' : 'f'];
        if ($one['k'] === 'e') $one['p'] = max(1, min(100, (int)($e['p'] ?? 1)));
        $one['t'] = max(0, (int)($e['t'] ?? 0));
        $rows[] = $one;
    }
    return $rows ? (string)json_encode($rows, JSON_UNESCAPED_SLASHES) : null;
}

/** A chain that starts with this author as the first — NULL when there is no account to credit. */
function contentCreditsStart(?int $userId, ?int $at = null): ?string {
    return ($userId !== null && $userId > 0) ? contentCreditsEncode([['u' => $userId, 'k' => 'f', 't' => $at ?? time()]]) : null;
}

/**
 * One applied edit added to a chain, by the merge rule:
 *   · nothing for 0% (the texts were the same) or for a proposer who is no account;
 *   · the same member as the LAST entry: after their own FIRST (the author refining their own text)
 *     nothing is added — they stay the first; after their own EDIT the two are one edit, the shares
 *     added (at most 100) and the time the newer one's;
 *   · otherwise a new entry. At most CONTENT_CREDITS_MAX are kept: the first, and the newest after it.
 */
function contentCreditsAppend(array $chain, ?int $userId, int $pct, int $at): array {
    if ($userId === null || $userId <= 0 || $pct <= 0) return $chain;
    $pct = min(100, $pct);
    $n = count($chain);
    if ($n > 0 && (int)$chain[$n - 1]['u'] === $userId) {
        if ($chain[$n - 1]['k'] === 'f') return $chain;
        $chain[$n - 1]['p'] = min(100, (int)$chain[$n - 1]['p'] + $pct);
        $chain[$n - 1]['t'] = $at;
        return $chain;
    }
    $chain[] = ['u' => $userId, 'k' => 'e', 'p' => $pct, 't' => $at];
    if (count($chain) > CONTENT_CREDITS_MAX) {
        $chain = array_merge([$chain[0]], array_slice($chain, count($chain) - (CONTENT_CREDITS_MAX - 1)));
    }
    return $chain;
}

/** The LIKE that finds a chain crediting this account (the text contentCreditsEncode() writes). */
function contentCreditsLikeFor(int $userId): string {
    return '%{"u":' . max(0, $userId) . ',%';
}

/**
 * What a chain says about one account: 'author' (the first), 'share' (its edits' shares added, at most
 * 100), 'at' (its newest credit's time, 0 when the chain does not say) — or null when it is not in it.
 */
function contentCreditsFor(array $chain, int $userId): ?array {
    if ($userId <= 0) return null;
    $out = null;
    foreach ($chain as $i => $e) {
        if ((int)$e['u'] !== $userId) continue;
        $out = $out ?? ['author' => false, 'share' => 0, 'at' => 0];
        if ($i === 0 && $e['k'] === 'f') $out['author'] = true;
        if ($e['k'] === 'e') $out['share'] = min(100, $out['share'] + (int)$e['p']);
        $out['at'] = max($out['at'], (int)$e['t']);
    }
    return $out;
}

/**
 * The chain as a reader is shown it, entry by entry: ['kind' => 'first'|'edit', 'pct' => int|null,
 * 'state' => 'shown'|'hidden'|'deleted', 'name' => string|null, 'avatar' => address|'',
 * 'profile' => bool, 'you' => bool]. ONE query for every account in it.
 *
 * `hidden` is an account whose owner switched their name off (users.content_credit_public = 0): no
 * name, no picture, no link — the page says "a member" — and that holds for the owner too, who is
 * told it is them (`you`) rather than shown what nobody else sees. `deleted` is u = 0 or an account
 * that is not there any more. A name links to its profile only where profiles are on and the account
 * is active (a suspended one's profile answers not-found).
 */
function contentCreditsForDisplay(PDO $db, array $cfg, array $chain, ?int $viewerId = null): array {
    if (!$chain) return [];
    $ids = [];
    foreach ($chain as $e) if ((int)$e['u'] > 0) $ids[(int)$e['u']] = true;
    $users = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT id, username, avatar_sha, status, content_credit_public FROM users WHERE id IN ($ph)");
        $st->execute(array_keys($ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $users[(int)$u['id']] = $u;
    }
    $profiles = function_exists('profilesEnabled') && profilesEnabled($cfg);
    $base = function_exists('getBaseUrl') ? getBaseUrl() : '/';
    $out = [];
    foreach ($chain as $e) {
        $uid = (int)$e['u'];
        $u = $users[$uid] ?? null;
        $row = ['kind' => $e['k'] === 'e' ? 'edit' : 'first', 'pct' => $e['k'] === 'e' ? (int)$e['p'] : null,
                'state' => 'deleted', 'name' => null, 'avatar' => '', 'profile' => false,
                'you' => $viewerId !== null && $viewerId > 0 && $uid === $viewerId];
        if ($u !== null && is_string($u['username'] ?? null) && $u['username'] !== '') {
            if ((int)($u['content_credit_public'] ?? 1) !== 1) {
                $row['state'] = 'hidden';
            } else {
                $row['state'] = 'shown';
                $row['name'] = (string)$u['username'];
                $row['profile'] = $profiles && ($u['status'] ?? '') === 'active';
                $row['avatar'] = function_exists('userAvatarField')
                    ? userAvatarField(['username' => (string)$u['username'], 'avatar_sha' => $u['avatar_sha'] ?? null], 20, $base, $cfg) : '';
            }
        }
        $out[] = $row;
    }
    return $out;
}

/** Is this account's name shown on the descriptions it wrote? (Missing or anything but 0 is yes.) */
function contentCreditPublic(?array $user): bool {
    return $user !== null && (int)($user['content_credit_public'] ?? 1) === 1;
}

/**
 * The line every description editor shows before anything is sent (1.70.0) — the Info panel's, for a
 * first description, an edit and a rewrite alike, and the whitelist form's: the words are public, and
 * whether this reader's name goes with them, as their own switch says, with the way to Privacy. HTML:
 * the dictionary's own markup, the name and the address escaped.
 */
function contentPublicLine(?array $viewer, string $baseUrl): string {
    if ($viewer === null) return __('descs.public_anon');
    $url = sanitize($baseUrl . '?action=account#acc-privacy');
    return contentCreditPublic($viewer)
        ? __('descs.public_named', ['name' => sanitize((string)($viewer['username'] ?? '')), 'url' => $url])
        : __('descs.public_hidden', ['url' => $url]);
}

/* ── how much an edit changed: the share formula (v81) ────────────────────────────────────────────
 *
 * The WORDS of the text as it stands (A) and of the edited text (B): the source as typed — the
 * formatting a person added is part of what they wrote — with line breaks made one kind, Unicode NFC
 * (when PHP has intl), lower case (a word re-cased is barely an edit) and the zero-width characters
 * taken out, then split on white space. L = the longest common subsequence of the two word lists (the
 * words kept, in order). The words taken out are |A| − L, the words put in |B| − L, and
 *
 *     share = round(100 × max(|A| − L, |B| − L) / max(|A|, |B|))
 *
 * — so 25 words of 100 replaced is 25%, 100 words added to 100 is 50% (half of the text is theirs),
 * 6 of 100 removed is 6%. Whenever the text, its format or its source link differ at all, the share is
 * at least 1% (a comma, a line break, a different link); texts that are the same are 0% and add no
 * credit. Rounded half up, never above 100.
 *
 * L is exact: the common beginning and end are set aside first, and the rest is measured by Myers'
 * difference algorithm, which finds the fewest words to take out and put in (D) and so L = (|A| + |B|
 * − D) / 2 — in time that grows with how much CHANGED, not with how long the text is: a few words fixed
 * across a long description cost next to nothing. It walks at most CONTENT_SHARE_BUDGET steps (a few
 * tens of milliseconds), enough for texts that differ by up to 631 words put in or taken out — every
 * edit of a description this site accepts (4,000 characters, some 700 words, by default) short of one
 * replaced nearly whole. Past that, L is the words both texts have counted without their order,
 * held under what the walk has already proved (D is more than it could reach) — a figure that can
 * only credit an editor with less, never more. Every step is deterministic: the same two texts always
 * give the same number.
 */
function contentShareTokens(?string $text): array {
    $s = str_replace(["\r\n", "\r"], "\n", (string)$text);
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_C);
        if (is_string($n)) $s = $n;
    }
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $s) ?? $s;
    $t = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($t) ? $t : [];
}

/**
 * The fewest items to take out of $a and put in to make $b (Myers, "An O(ND) Difference Algorithm",
 * 1986: the greedy forward search, keeping only the furthest point on each diagonal), or null once the
 * search has walked $budget steps without finishing. The lists hold small integers.
 */
function contentEditDistance(array $a, array $b, int $budget): ?int {
    $n = count($a); $m = count($b);
    $v = [1 => 0];
    $steps = 0;
    for ($d = 0, $max = $n + $m; $d <= $max; $d++) {
        for ($k = -$d; $k <= $d; $k += 2) {
            if (++$steps > $budget) return null;
            $x = ($k === -$d || ($k !== $d && $v[$k - 1] < $v[$k + 1])) ? $v[$k + 1] : $v[$k - 1] + 1;
            $y = $x - $k;
            while ($x < $n && $y < $m && $a[$x] === $b[$y]) { $x++; $y++; }
            $v[$k] = $x;
            if ($x >= $n && $y >= $m) return $d;
        }
    }
    return $n + $m;
}

/** How many words of A are kept in B (L above): exact, or the documented bound past the budget. */
function contentShareKept(array $A, array $B): int {
    $na = count($A); $nb = count($B);
    $pre = 0;
    while ($pre < $na && $pre < $nb && $A[$pre] === $B[$pre]) $pre++;
    $suf = 0;
    while ($suf < $na - $pre && $suf < $nb - $pre && $A[$na - 1 - $suf] === $B[$nb - 1 - $suf]) $suf++;
    $midA = array_slice($A, $pre, $na - $pre - $suf);
    $midB = array_slice($B, $pre, $nb - $pre - $suf);
    $n = count($midA); $m = count($midB);
    if ($n === 0 || $m === 0) return $pre + $suf;
    // Words as small integers: comparing ints is what keeps the walk cheap.
    $ids = [];
    $toId = static function (array $l) use (&$ids): array {
        $o = [];
        foreach ($l as $t) $o[] = $ids[$t] ??= count($ids);
        return $o;
    };
    $d = contentEditDistance($toId($midA), $toId($midB), CONTENT_SHARE_BUDGET);
    if ($d !== null) return $pre + $suf + intdiv($n + $m - $d, 2);
    // Past the budget: the words both have, their order aside — and never more than the walk allows.
    // Each distance d costs d + 1 steps, so every distance below the largest d with d(d+1)/2 <= budget
    // was searched in full without reaching the end: D is at least that d (631 for 200 000).
    $ca = array_count_values($midA); $cb = array_count_values($midB);
    $both = 0;
    foreach ($ca as $t => $c) $both += min($c, $cb[$t] ?? 0);
    $proven = (int)floor((sqrt(8 * CONTENT_SHARE_BUDGET + 1) - 1) / 2);
    return $pre + $suf + max(0, min($both, intdiv($n + $m - $proven, 2)));
}

/**
 * The share of the description an edit changes, 0..100 — see the formula above. $now and $new are
 * ['description' => …, 'description_format' => …, 'source_url' => …].
 */
function contentEditShare(array $now, array $new): int {
    $ta = (string)($now['description'] ?? '');
    $tb = (string)($new['description'] ?? '');
    $same = $ta === $tb
        && (string)($now['description_format'] ?? 'bbcode') === (string)($new['description_format'] ?? 'bbcode')
        && (string)($now['source_url'] ?? '') === (string)($new['source_url'] ?? '');
    if ($same) return 0;
    $A = contentShareTokens($ta);
    $B = contentShareTokens($tb);
    $max = max(count($A), count($B));
    if ($max === 0) return 1;
    $kept = contentShareKept($A, $B);
    $changed = max(count($A) - $kept, count($B) - $kept);
    return max(1, min(100, (int)round(100 * $changed / $max)));
}

/**
 * Attach words to a hash — or, when it already has some, PROPOSE replacing them.
 *
 * $in = ['description', 'description_format', 'source_url', 'kind'?]; $user = the signed-in submitter
 * or null. The permission asked is the SUBMITTER's own (userIdHasPermission), not the session's, so
 * the same function answers the same way from a CLI test and from a web request; with no submitter
 * the session decides, which is how an anonymous visitor on an install without accounts gets the
 * legacy answer for content.*.
 *
 * `kind` (1.70.0) says what a proposal is: 'rewrite' (the default, as it has always been — applied,
 * the proposer becomes the author) or 'edit' (applied, the author stays and the proposer is credited
 * with their share). An edit is of a PUBLISHED description, by a signed-in member who may read it —
 * the editor was filled with its text — and one that changes nothing is refused. On an empty record
 * the kind means nothing: those are first words.
 *
 * Returns ['ok' => true, 'saved' => bool, 'pending' => bool, 'proposed' => bool, 'kind' => 'wl'|'idx']
 * or ['ok' => false, 'error' => sentence, 'code' => http status].
 */
function contentAttach(PDO $db, array $cfg, string $hash, array $in, ?array $user, string $ip): array {
    $hash = strtolower($hash);
    $descOn = ($cfg['wl_allow_description'] ?? '0') === '1';
    $srcOn  = ($cfg['wl_allow_source_url'] ?? '0') === '1';
    $desc = $descOn ? trim((string)($in['description'] ?? '')) : '';
    $src  = $srcOn ? trim((string)($in['source_url'] ?? '')) : '';
    // Normalised whatever else arrives: both homes store it in an ENUM('markdown','bbcode'), so a
    // format nobody asked for is a write that either fails or lands as ''. It used to be checked only
    // on the path that has a description, and a submission carrying just a source link wrote it raw.
    $fmt  = (string)($in['description_format'] ?? 'bbcode');
    if (!in_array($fmt, richtextFormats($cfg), true)) $fmt = richtextFormats($cfg)[0];
    if ($desc === '' && $src === '') {
        return ['ok' => false, 'error' => __('api.content.nothing_to_attach'), 'code' => 400];
    }
    $can = function (string $perm) use ($db, $cfg, $user): bool {
        return $user !== null
            ? userIdHasPermission($db, $cfg, (int)$user['id'], $perm)
            : userCan($db, $cfg, $perm);
    };
    if ($src !== '') {
        $e = richtextValidateSourceUrl($src, $cfg);
        if ($e !== null) return ['ok' => false, 'error' => $e, 'code' => 400];
    }
    if ($desc !== '') {
        $e = richtextValidate($desc, $fmt, $cfg);
        if ($e !== null) return ['ok' => false, 'error' => $e, 'code' => 400];
    }

    $rec = contentRecordFor($db, $hash);
    // Either home. A ban is about the hash, not about which table happens to hold its words.
    if ($rec !== null && $rec['banned']) {
        return ['ok' => false, 'error' => __('api.content.hash_banned'), 'code' => 403];
    }
    if ($rec === null) {
        // Not registered. Words may still be attached to a hash the index has SEEN — and to nothing
        // else: a description of a hash nobody has met would be a catalogue entry made of hearsay.
        if (function_exists('isHashBanned') && isHashBanned($db, $hash)) {
            return ['ok' => false, 'error' => __('api.content.hash_banned'), 'code' => 403];
        }
        if (!contentIndexKnows($db, $hash)) {
            return ['ok' => false, 'error' => __('api.content.unknown_hash'), 'code' => 404];
        }
        // Asked HERE, not only at the UPDATE below: the INSERT is what brings the record into
        // existence, and a submitter who is about to be refused must not leave one behind. An empty
        // hash_content row is not harmless — it is a record, and contentAttach then reads "an empty
        // record exists" for the next person, who gets the submit path rather than the proposal one.
        if (!$can('content.submit')) {
            return ['ok' => false, 'error' => __('api.wl.content_needs_access'), 'code' => 403];
        }
        $db->prepare("INSERT IGNORE INTO hash_content (info_hash) VALUES (?)")->execute([$hash]);
        $rec = contentRecordFor($db, $hash);
        if ($rec === null) return ['ok' => false, 'error' => __('api.content.unknown_hash'), 'code' => 404];
    }
    $userId = $user !== null ? (int)$user['id'] : null;

    if (contentOccupied($rec)) {
        // Anyone can describe anyone's torrent, so the first person to do it is not automatically the
        // right one — and "whoever submits last wins" would be an invitation. A later submission is a
        // proposal a moderator decides on; nothing changes for readers until somebody says so.
        if (!$can('content.propose')) {
            return ['ok' => false, 'error' => __('api.wl.propose_needs_access'), 'code' => 403];
        }
        $maxPending = max(0, min(50, (int)($cfg['wl_edit_max_pending'] ?? 3)));
        if ($maxPending === 0) {
            return ['ok' => false, 'error' => __('api.wl.proposals_not_accepted'), 'code' => 409];
        }
        $editKind = (string)($in['kind'] ?? 'rewrite');
        if (!in_array($editKind, CONTENT_EDIT_KINDS, true)) $editKind = 'rewrite';
        if ($editKind === 'edit') {
            if ($user === null || $rec['content_status'] !== 'approved' || !$can('content.view')) {
                return ['ok' => false, 'error' => __('api.content.edit_not_allowed'), 'code' => 400];
            }
            if (contentEditShare($rec, ['description' => $desc !== '' ? $desc : null, 'description_format' => $fmt,
                                        'source_url' => $src !== '' ? $src : null]) === 0) {
                return ['ok' => false, 'error' => __('api.content.edit_unchanged'), 'code' => 400];
            }
        }
        $col = $rec['kind'] === 'wl' ? 'whitelist_id' : 'hash_content_id';
        $st = $db->prepare("SELECT COUNT(*) FROM wl_content_edits WHERE `$col` = ? AND status = 'pending'");
        $st->execute([$rec['id']]);
        if ((int)$st->fetchColumn() >= $maxPending) {
            return ['ok' => false, 'error' => __('api.wl.proposals_pending_limit', ['max' => $maxPending]), 'code' => 429];
        }
        $db->prepare("INSERT INTO wl_content_edits (whitelist_id, hash_content_id, info_hash, source_url, description,
                             description_format, kind, ip, user_id)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$rec['kind'] === 'wl' ? $rec['id'] : null, $rec['kind'] === 'idx' ? $rec['id'] : null, $hash,
                      $src !== '' ? $src : null, $desc !== '' ? $desc : null, $fmt, $editKind, $ip, $userId]);
        return ['ok' => true, 'saved' => false, 'pending' => false, 'proposed' => true, 'kind' => $rec['kind'], 'edit_kind' => $editKind];
    }

    if (!$can('content.submit')) {
        return ['ok' => false, 'error' => __('api.wl.content_needs_access'), 'code' => 403];
    }
    // An empty record. Published at once when the operator has said so, otherwise it waits — and a
    // registered torrent is registered and serving either way.
    $auto = ($cfg['wl_content_autopublish'] ?? '0') === '1';
    $status = ($auto || ($cfg['wl_content_review'] ?? '1') !== '1') ? 'approved' : 'pending';
    $table = contentTableFor($rec['kind']);
    // The chain starts here: the author, as the first (1.70.0).
    $db->prepare("UPDATE `$table` SET source_url = ?, description = ?, description_format = ?, content_status = ?,
                         content_user_id = ?, content_reviewed_at = NULL, content_rejected_note = NULL, content_credits = ?
                   WHERE id = ?")
       ->execute([$src !== '' ? $src : null, $desc !== '' ? $desc : null, $fmt, $status, $userId,
                  contentCreditsStart($userId), $rec['id']]);
    return ['ok' => true, 'saved' => true, 'pending' => $status === 'pending', 'proposed' => false, 'kind' => $rec['kind']];
}

/**
 * Tell the person, when there is a person: type 'content', one line and a body — the two dictionary keys and their
 * values, written in THEIR language (1.74.0, QUAL-18: until then in the moderator's, whose request writes it).
 */
function contentNotify(PDO $db, array $cfg, ?int $userId, string $titleKey, string $bodyKey, array $vars = []): void {
    if ($userId === null || $userId < 1 || !function_exists('userNotify')) return;
    $lang = recipientLangOf($db, $cfg, $userId);
    userNotify($db, $userId, 'content', langFor($lang, $titleKey, $vars), langFor($lang, $bodyKey, $vars));
}

/** The record behind a review action, or null. */
function contentRowById(PDO $db, string $kind, int $id): ?array {
    $table = contentTableFor($kind);
    $st = $db->prepare("SELECT id, info_hash, source_url, description, description_format, content_status, content_user_id,
                               content_credits
                          FROM `$table` WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function contentApprove(PDO $db, array $cfg, string $kind, int $id): bool {
    $row = contentRowById($db, $kind, $id);
    if (!$row) return false;
    $table = contentTableFor($kind);
    $db->prepare("UPDATE `$table` SET content_status = 'approved', content_reviewed_at = NOW(),
                         content_rejected_note = NULL WHERE id = ?")->execute([$id]);
    $name = contentNameFor($db, (string)$row['info_hash']);
    contentNotify($db, $cfg, $row['content_user_id'] !== null ? (int)$row['content_user_id'] : null,
        'notify.content_published', 'notify.content_published_body', ['name' => $name, 'hash' => $row['info_hash']]);
    return true;
}

function contentReject(PDO $db, array $cfg, string $kind, int $id, ?string $note): bool {
    $row = contentRowById($db, $kind, $id);
    if (!$row) return false;
    $note = $note !== null ? mb_substr(trim($note), 0, 255) : '';
    $table = contentTableFor($kind);
    // Kept, not deleted. If the same submitter argues, the text they actually sent is still here.
    $db->prepare("UPDATE `$table` SET content_status = 'rejected', content_reviewed_at = NOW(),
                         content_rejected_note = ? WHERE id = ?")->execute([$note !== '' ? $note : null, $id]);
    $name = contentNameFor($db, (string)$row['info_hash']);
    contentNotify($db, $cfg, $row['content_user_id'] !== null ? (int)$row['content_user_id'] : null,
        'notify.content_rejected', $note !== '' ? 'notify.content_rejected_body_note' : 'notify.content_rejected_body',
        ['name' => $name, 'note' => $note]);
    return true;
}

/** Delete the words outright — and the credits that went with them. The torrent stays whatever it was. */
function contentClear(PDO $db, string $kind, int $id): bool {
    $table = contentTableFor($kind);
    $st = $db->prepare("UPDATE `$table` SET source_url = NULL, description = NULL, content_status = 'none',
                               content_user_id = NULL, content_reviewed_at = NULL, content_rejected_note = NULL,
                               content_credits = NULL
                         WHERE id = ?");
    $st->execute([$id]);
    return $st->rowCount() > 0;
}

/**
 * A pending proposal row, with the home it belongs to. `kind` is the HOME here ('wl' | 'idx', as it has
 * been since 1.53.0); the proposal's own kind, the v81 column of the same name, is `edit_kind`.
 */
function contentEditById(PDO $db, int $editId): ?array {
    $st = $db->prepare("SELECT * FROM wl_content_edits WHERE id = ? AND status = 'pending' LIMIT 1");
    $st->execute([$editId]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) return null;
    $e['edit_kind'] = in_array((string)($e['kind'] ?? ''), CONTENT_EDIT_KINDS, true) ? (string)$e['kind'] : 'rewrite';
    $e['kind'] = !empty($e['whitelist_id']) ? 'wl' : 'idx';
    $e['target_id'] = !empty($e['whitelist_id']) ? (int)$e['whitelist_id'] : (int)$e['hash_content_id'];
    return $e;
}

/**
 * What applying this proposal NOW would do, for the review queue's card and for the apply itself:
 * ['kind' => 'rewrite'|'edit', 'share' => 0..100 (an edit's, against the text as it stands),
 *  'as_rewrite' => bool — an edit of a record that has since been emptied (the panel's Clear) has no
 *  author to stay, so it goes in as a rewrite would].
 */
function contentEditPreview(array $cur, array $e): array {
    $kind = ($e['edit_kind'] ?? 'rewrite') === 'edit' ? 'edit' : 'rewrite';
    $empty = ($cur['description'] ?? null) === null && ($cur['source_url'] ?? null) === null;
    $asRewrite = $kind === 'edit' && ($empty || ($cur['content_status'] ?? '') === 'none');
    return ['kind' => $kind, 'as_rewrite' => $asRewrite,
            'share' => $kind === 'edit' && !$asRewrite ? contentEditShare($cur, $e) : 0];
}

/**
 * Apply a proposal: the words it carries replace what is published, the replaced version is kept as
 * a rejected proposal of its own (note CONTENT_ARCHIVE_NOTE) — the newest CONTENT_ARCHIVE_KEEP of them per
 * description (contentArchivePrune()), for whoever has to see what a description said before; nothing on
 * the site reads them back or puts one back (contentEditById() takes pending rows only) — and both sides
 * hear.
 *
 * A REWRITE: the proposer becomes the author, and the credit chain starts again with them as the
 * first. An EDIT (1.70.0): the author of record stays, the text as it stands now is compared with the
 * edited one (contentEditShare()) and the proposer is credited with that share (contentCreditsAppend(),
 * its merge rule included); the proposer is told their share, the author that their description was
 * edited — not replaced.
 */
function contentEditApply(PDO $db, array $cfg, array $e): bool {
    $kind = (string)$e['kind'];
    $table = contentTableFor($kind);
    $cur = contentRowById($db, $kind, (int)$e['target_id']);
    if (!$cur) return false;
    $pv = contentEditPreview($cur, $e);
    if ($cur['description'] !== null || $cur['source_url'] !== null) {
        $db->prepare("INSERT INTO wl_content_edits (whitelist_id, hash_content_id, info_hash, source_url, description,
                             description_format, status, note, user_id, reviewed_at)
                      VALUES (?, ?, ?, ?, ?, ?, 'rejected', ?, ?, NOW())")
           ->execute([$kind === 'wl' ? (int)$e['target_id'] : null, $kind === 'idx' ? (int)$e['target_id'] : null,
                      (string)$e['info_hash'], $cur['source_url'], $cur['description'],
                      (string)$cur['description_format'], CONTENT_ARCHIVE_NOTE, $cur['content_user_id']]);
        contentArchivePrune($db, $kind, (int)$e['target_id']);
    }
    $proposer = $e['user_id'] !== null ? (int)$e['user_id'] : null;
    $previous = $cur['content_user_id'] !== null ? (int)$cur['content_user_id'] : null;
    $edit = $pv['kind'] === 'edit' && !$pv['as_rewrite'];
    if ($edit) {
        $chain = contentCreditsAppend(contentCreditsDecode($cur['content_credits'] ?? null, $previous), $proposer, $pv['share'], time());
        $author = $previous;
    } else {
        $chain = $proposer !== null ? [['u' => $proposer, 'k' => 'f', 't' => time()]] : [];
        $author = $proposer;
    }
    $db->prepare("UPDATE `$table` SET source_url = ?, description = ?, description_format = ?,
                         content_status = 'approved', content_user_id = ?, content_reviewed_at = NOW(),
                         content_rejected_note = NULL, content_credits = ? WHERE id = ?")
       ->execute([$e['source_url'], $e['description'], (string)$e['description_format'],
                  $author, contentCreditsEncode($chain), (int)$e['target_id']]);
    $db->prepare("UPDATE wl_content_edits SET status = 'applied', reviewed_at = NOW() WHERE id = ?")->execute([(int)$e['id']]);
    $name = contentNameFor($db, (string)$e['info_hash']);
    if ($edit) {
        contentNotify($db, $cfg, $proposer, 'notify.content_edit_applied', 'notify.content_edit_applied_body',
                      ['name' => $name, 'pct' => $pv['share']]);
        if ($previous !== null && $previous !== $proposer) {
            contentNotify($db, $cfg, $previous, 'notify.content_edited', 'notify.content_edited_body',
                          ['name' => $name, 'pct' => $pv['share']]);
        }
        return true;
    }
    contentNotify($db, $cfg, $proposer, 'notify.content_proposal_applied', 'notify.content_proposal_applied_body', ['name' => $name]);
    if ($previous !== null && $previous !== $proposer) {
        contentNotify($db, $cfg, $previous, 'notify.content_replaced', 'notify.content_replaced_body', ['name' => $name]);
    }
    return true;
}

/**
 * Keep the newest CONTENT_ARCHIVE_KEEP replaced versions of ONE description and delete the older ones
 * (1.70.0). "We do not keep every edit": every applied proposal used to add a full copy of the text it
 * replaced, for ever, and nothing ever read them back. Run at apply time, right after the new copy goes in,
 * for that home row only — so an install's history shrinks one description at a time, as each is edited
 * again, and never in a migration. Only the rows contentEditApply() writes are touched (status `rejected`
 * with CONTENT_ARCHIVE_NOTE): a proposal still waiting, one applied, one a moderator turned down and one a
 * delete withdrew are never deleted here. Two statements, both through the home's pending-count key
 * (idx_edits_wl_status / idx_edits_hc_status, v82): the newest row past the ones kept, then everything of
 * that description's archive at or below it. Returns how many went.
 */
function contentArchivePrune(PDO $db, string $kind, int $homeId): int {
    if ($homeId <= 0) return 0;
    $col = $kind === 'wl' ? 'whitelist_id' : 'hash_content_id';
    $st = $db->prepare("SELECT id FROM wl_content_edits WHERE `$col` = ? AND status = 'rejected' AND note = ?
                         ORDER BY id DESC LIMIT 1 OFFSET " . CONTENT_ARCHIVE_KEEP);
    $st->execute([$homeId, CONTENT_ARCHIVE_NOTE]);
    $edge = $st->fetchColumn();
    if ($edge === false) return 0;
    $del = $db->prepare("DELETE FROM wl_content_edits WHERE `$col` = ? AND status = 'rejected' AND note = ? AND id <= ?");
    $del->execute([$homeId, CONTENT_ARCHIVE_NOTE, (int)$edge]);
    return $del->rowCount();
}

function contentEditReject(PDO $db, array $cfg, array $e): bool {
    $db->prepare("UPDATE wl_content_edits SET status = 'rejected', reviewed_at = NOW() WHERE id = ?")->execute([(int)$e['id']]);
    $name = contentNameFor($db, (string)$e['info_hash']);
    $isEdit = ($e['edit_kind'] ?? 'rewrite') === 'edit';
    contentNotify($db, $cfg, $e['user_id'] !== null ? (int)$e['user_id'] : null,
                  $isEdit ? 'notify.content_edit_rejected' : 'notify.content_proposal_rejected',
                  'notify.content_proposal_rejected_body', ['name' => $name]);
    return true;
}

/* ── taking a description down (v81, 1.70.0) ──────────────────────────────────────────────────────
 *
 * From the Info panel, beside Edit and Propose: the words and their source link go, as the panel's
 * Clear takes them (the torrent stays whatever it was), the proposals still waiting on them are
 * withdrawn — they would have changed nothing that is left — and one line goes to the audit log.
 */

/**
 * May this member take this record's words down, and as whom: 'own' — the author of record, holding
 * `content.delete_own`, whatever the status (their text waiting for a moderator, or turned down, is
 * theirs to withdraw as well) — or 'any' — `content.delete_any`, a PUBLISHED description of a hash
 * that is not banned: what is published is what the Info panel shows, and what waits is the review
 * queue's business. Asked of the ACCOUNT, never of a panel session; null with accounts off, for a
 * visitor who is not signed in, and for a record with nothing on it.
 */
function contentDeleteRight(PDO $db, array $cfg, ?array $rec, ?array $me): ?string {
    if ($rec === null || !contentOccupied($rec) || $me === null || !usersEnabled($cfg)) return null;
    $uid = (int)($me['id'] ?? 0);
    if ($uid <= 0) return null;
    if (($rec['content_user_id'] ?? null) === $uid && userIdHasPermission($db, $cfg, $uid, 'content.delete_own')) return 'own';
    if (($rec['content_status'] ?? '') === 'approved' && empty($rec['banned'])
        && userIdHasPermission($db, $cfg, $uid, 'content.delete_any')) return 'any';
    return null;
}

/**
 * Take the words down — the caller has asked contentDeleteRight() and got $right. Returns
 * ['ok' => true, 'withdrawn' => n]. The author hears when somebody else did it; so does everybody
 * whose waiting proposal was withdrawn with it (but the one who did it).
 *
 * `$opts['notify'] === false` (1.71.0 part E, includes/reports.php): the author is NOT told here — the Reports
 * page's removal is silent, or it is loud and the author gets ONE warning from there instead of two notices.
 * The proposers whose waiting words went with it are still told: that is about their proposal, not a verdict
 * on the author. The panel's caller has asked panelCan() itself; `$me` may be the owner's session (id 0).
 */
function contentDelete(PDO $db, array $cfg, array $rec, array $me, string $right, array $opts = []): array {
    $meId = (int)($me['id'] ?? 0);
    $name = contentNameFor($db, (string)$rec['info_hash']);
    $author = $rec['content_user_id'] ?? null;
    $col = $rec['kind'] === 'wl' ? 'whitelist_id' : 'hash_content_id';
    $st = $db->prepare("SELECT id, user_id FROM wl_content_edits WHERE `$col` = ? AND status = 'pending'");
    $st->execute([(int)$rec['id']]);
    $waiting = $st->fetchAll(PDO::FETCH_ASSOC);
    contentClear($db, (string)$rec['kind'], (int)$rec['id']);
    $withdrawn = 0;
    if ($waiting) {
        $up = $db->prepare("UPDATE wl_content_edits SET status = 'rejected', reviewed_at = NOW(), note = ? WHERE id = ? AND status = 'pending'");
        $told = [];
        foreach ($waiting as $w) {
            $up->execute([CONTENT_WITHDRAWN_NOTE, (int)$w['id']]);
            $withdrawn += $up->rowCount();
            $pid = $w['user_id'] !== null ? (int)$w['user_id'] : 0;
            if ($pid > 0 && $pid !== $meId && !isset($told[$pid])) {
                $told[$pid] = true;
                contentNotify($db, $cfg, $pid, 'notify.content_proposal_withdrawn', 'notify.content_proposal_withdrawn_body', ['name' => $name]);
            }
        }
    }
    if ($right === 'any' && $author !== null && (int)$author !== $meId && ($opts['notify'] ?? true) !== false) {
        contentNotify($db, $cfg, (int)$author, 'notify.content_deleted', 'notify.content_deleted_body', ['name' => $name]);
    }
    if (function_exists('auditLog')) {
        $authorName = null;
        if ($author !== null) {
            $au = $db->prepare("SELECT username FROM users WHERE id = ?");
            $au->execute([(int)$author]);
            $authorName = $au->fetchColumn() ?: null;
        }
        auditLog($db, 'content.delete', [
            'target_type' => $rec['kind'] === 'wl' ? 'whitelist' : 'hash',
            'target_id'   => (string)$rec['info_hash'],
            'summary'     => (string)($me['username'] ?? ('#' . $meId)) . ' deleted the description of ' . $name
                             . ($right === 'own' ? ' (their own)' : ' by ' . ($authorName ?? 'nobody')),
            'detail'      => ['right' => $right, 'home' => $rec['kind'], 'id' => (int)$rec['id'], 'status' => (string)$rec['content_status'],
                              'author_id' => $author !== null ? (int)$author : null, 'author' => $authorName, 'withdrawn' => $withdrawn,
                              'silent' => ($opts['notify'] ?? true) === false],
        ]);
    }
    return ['ok' => true, 'withdrawn' => $withdrawn];
}

/**
 * An account is being deleted (userDeleteCascade()): its credits become "a deleted account" (u = 0),
 * it stops being the author of record of anything, and its proposals stop naming it. The words stay —
 * a description is the torrent's, not the account's. Without this a new account given the same id
 * (MySQL 5.7 hands out the highest deleted one again after a restart) would inherit the author's
 * right to delete them and the credit for them.
 */
function contentForgetAccount(PDO $db, int $userId): int {
    if ($userId <= 0) return 0;
    $changed = 0;
    foreach (['whitelist', 'hash_content'] as $table) {
        try {
            $st = $db->prepare("SELECT id, content_user_id, content_credits FROM `$table` WHERE content_user_id = ? OR content_credits LIKE ?");
            $st->execute([$userId, contentCreditsLikeFor($userId)]);
            $up = $db->prepare("UPDATE `$table` SET content_user_id = ?, content_credits = ? WHERE id = ?");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $uid = $r['content_user_id'] !== null ? (int)$r['content_user_id'] : null;
                $chain = contentCreditsDecode($r['content_credits'], $uid);
                foreach ($chain as &$c) if ((int)$c['u'] === $userId) $c['u'] = 0;
                unset($c);
                $up->execute([$uid === $userId ? null : $uid, contentCreditsEncode($chain), (int)$r['id']]);
                $changed++;
            }
        } catch (\Throwable $e) { /* a table this install does not have yet */ }
    }
    try {
        $db->prepare("UPDATE wl_content_edits SET user_id = NULL WHERE user_id = ?")->execute([$userId]);
    } catch (\Throwable $e) { /* idem */ }
    return $changed;
}
