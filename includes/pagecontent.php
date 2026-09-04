<?php
/**
 * Editable Terms and Info pages.
 *
 * Both pages ship as PHP templates and both contain CONDITIONALS — `trackerMode($cfg)` decides
 * whether the whitelist paragraphs appear, `usersEnabled($cfg)` decides whether the account terms
 * do. That is the whole difficulty here, and it is why this file generates the default text from the
 * current configuration rather than storing a fixed copy:
 *
 *   - "Restore default" always hands back a page that matches how the tracker is configured RIGHT
 *     NOW, not how it was configured when somebody first opened the editor.
 *   - An operator who saves a custom page is told, in as many words, that switching tracker mode
 *     will no longer change it. That is a real consequence of editing and it is better said out loud
 *     than discovered when the whitelist paragraphs stop matching reality.
 *
 * The stored text goes through the same renderer as every other piece of author-written content
 * (includes/richtext.php), so the sanitising, the link rules and the limits are the ones already in
 * use — there is no second, weaker path into a public page.
 *
 * MARKDOWN IS THE DEFAULT FORMAT FOR THESE TWO PAGES, and not by taste: the renderer's BBCode branch
 * has no heading tag at all (grep `[h1]` — it does not exist), while its Markdown branch maps
 * `#`..`######` onto real headings. A Terms page is mostly headings and numbered lists. BBCode is
 * still offered, with sizes standing in for headings, because the operator may prefer the toolbar.
 */

// REQUIRED, not probed for.
//
// The default text is assembled from `trackerMode()` and `usersEnabled()`. Guarding those with
// function_exists() meant that a caller which had not loaded them got a page with the whitelist and
// account sections silently missing — a wrong default that looks like a correct one, which is the
// worst kind. Naming the dependency makes a missing include an error instead.
require_once __DIR__ . '/whitelist.php';
require_once __DIR__ . '/users.php';

const PAGECONTENT_PAGES = ['tos', 'info'];
const PAGECONTENT_MAX = 60000;

/** The pages this can edit, with the labels the panel shows and the route each one serves. */
function pageContentCatalog(): array {
    return [
        'tos'  => ['label' => 'Terms of Service', 'route' => 'tos',  'template' => 'templates/pages/tos.php'],
        'info' => ['label' => 'Tracker Information', 'route' => 'info', 'template' => 'templates/pages/info.php'],
    ];
}

/** One stored override, or null when the page is still the shipped template. */
function pageContentGet(PDO $db, string $page): ?array {
    if (!in_array($page, PAGECONTENT_PAGES, true)) return null;
    try {
        $st = $db->prepare("SELECT page, format, body, enabled, updated_at, updated_by FROM page_content WHERE page = ?");
        $st->execute([$page]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null;
    }
    if (!$r) return null;
    $r['enabled'] = (int)$r['enabled'] === 1;
    return $r;
}

/** Every override, keyed by page, for the settings screen. */
function pageContentAll(PDO $db): array {
    $out = [];
    foreach (PAGECONTENT_PAGES as $p) {
        $r = pageContentGet($db, $p);
        if ($r) $out[$p] = $r;
    }
    return $out;
}

/** Store (or replace) a page. Returns ['error' => …] or ['ok' => true]. */
function pageContentSave(PDO $db, array $cfg, string $page, string $format, string $body, bool $enabled, string $who): array {
    if (!in_array($page, PAGECONTENT_PAGES, true)) return ['error' => 'Unknown page.'];
    if (!in_array($format, ['bbcode', 'markdown'], true)) return ['error' => 'Unknown format.'];
    if (strlen($body) > PAGECONTENT_MAX) {
        return ['error' => 'That is longer than ' . number_format(PAGECONTENT_MAX) . ' characters.'];
    }
    if (trim($body) === '' && $enabled) {
        return ['error' => 'An empty page cannot replace the built-in one. Turn it off instead, or press Restore.'];
    }
    // The same validator every other author-written text goes through — link rules, image counts and
    // the rest. A page written by the owner is not a reason to skip it: the owner can still paste
    // something that the renderer would refuse elsewhere, and two behaviours for one syntax is how a
    // renderer stops being predictable.
    if (function_exists('richtextValidate') && trim($body) !== '') {
        $err = richtextValidate($body, $format, $cfg);
        if ($err !== null) return ['error' => $err];
    }
    try {
        $db->prepare("INSERT INTO page_content (page, format, body, enabled, updated_at, updated_by)
                      VALUES (?, ?, ?, ?, NOW(), ?)
                      ON DUPLICATE KEY UPDATE format = VALUES(format), body = VALUES(body),
                                              enabled = VALUES(enabled), updated_at = NOW(),
                                              updated_by = VALUES(updated_by)")
           ->execute([$page, $format, $body, $enabled ? 1 : 0, mb_substr($who, 0, 64)]);
    } catch (\Throwable $e) {
        return ['error' => 'Could not store the page.'];
    }
    return ['ok' => true];
}

/** Throw the override away; the shipped template comes back on the next request. */
function pageContentReset(PDO $db, string $page): bool {
    if (!in_array($page, PAGECONTENT_PAGES, true)) return false;
    try {
        $db->prepare("DELETE FROM page_content WHERE page = ?")->execute([$page]);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Should the router use an override for this page?
 *
 * Enabled AND non-empty. A stored-but-disabled page is a draft, and a draft must not be able to
 * blank a public page by being empty.
 */
function pageContentActive(PDO $db, string $page): ?array {
    $r = pageContentGet($db, $page);
    if (!$r || !$r['enabled'] || trim((string)$r['body']) === '') return null;
    return $r;
}

// ─────────────────────────────────────────────────────────────────────────────
// The defaults, generated from the configuration the tracker is running under
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The shipped page, written out in the requested format.
 *
 * Kept deliberately close to the template's own words. Anything that differs is a difference the
 * operator would have to reconcile by hand the first time they press Restore, and the point of the
 * button is that they do not have to.
 */
function pageContentDefault(array $cfg, string $page, string $format, string $baseUrl = ''): string {
    $md = $format !== 'bbcode';
    $wl = trackerMode($cfg) === 'whitelist';
    $users = usersEnabled($cfg);
    $verify = userEmailVerifyRequired($cfg);

    // Headings: Markdown has them, BBCode does not — see the file header. `[size]` is the closest
    // BBCode gets, and saying so in the UI is better than pretending the two are equivalent.
    // Concatenated, never interpolated: inside a double-quoted string PHP reads `$t[` as the start
    // of an array index, so "[b]$t[/b]" is a parse error rather than the BBCode it looks like.
    $h1 = fn(string $t): string => $md ? '# ' . $t : '[size=24][b]' . $t . '[/b][/size]';
    $h2 = fn(string $t): string => $md ? '## ' . $t : '[size=19][b]' . $t . '[/b][/size]';
    $b  = fn(string $t): string => $md ? '**' . $t . '**' : '[b]' . $t . '[/b]';
    $link = function (string $url, string $text) use ($md): string {
        return $md ? '[' . $text . '](' . $url . ')' : '[url=' . $url . ']' . $text . '[/url]';
    };
    $ol = function (array $items) use ($md): string {
        if ($md) {
            $n = 0;
            return implode("\n", array_map(function ($i) use (&$n) { $n++; return $n . '. ' . $i; }, $items));
        }
        return "[list=1]\n" . implode("\n", array_map(fn($i) => '[*] ' . $i, $items)) . "\n[/list]";
    };

    $L = [];
    if ($page === 'tos') {
        $L[] = $h1('Terms of Service');
        $L[] = '';
        $L[] = 'By using this tracker, you agree to the following terms:';
        $L[] = '';
        $terms = [
            'The service is free for personal use. Commercial organizations require written permission.',
            'User tracking, DoS/DDoS attacks, and any attempts to disrupt the service are prohibited.',
            'We do not guarantee service uptime. Availability may be limited without prior notice.',
            'The user bears full responsibility for the legality of shared content in their jurisdiction.',
            'Commercial use policy violations are subject to a fee of EUR 5,000.',
            'We reserve the right to publish information about policy violations.',
            'We respect user privacy. We do not store personal data beyond what is necessary for tracker operation.',
        ];
        if ($wl) {
            $terms[] = 'Whitelist registrations are free and anonymous; the registrant\'s IP address is stored to '
                     . 'detect abuse. Registered info hashes may be removed or banned at any time, and abusive '
                     . 'registrants may be banned.';
        }
        $terms[] = 'Terms may change. Continued use of the service constitutes acceptance of changes.';
        $terms[] = $b('Connecting to the tracker constitutes acceptance of these terms.');
        $L[] = $ol($terms);

        if ($users) {
            $L[] = '';
            $L[] = $h2('User accounts');
            $L[] = '';
            $L[] = $ol([
                'Creating an account is free and optional — the tracker itself works without one. An account only '
                . 'unlocks member features (such as the catalogue search) according to the groups granted to it.',
                'For an account we store: the username, the password (as a salted hash — never in plain text), the '
                . 'email address' . ($verify ? ' (required and confirmed by a verification link)' : ' (optional)')
                . ', the IP addresses used at registration and sign-in (abuse prevention), group memberships with '
                . 'their expiry dates, and in-app notifications.',
                'Emails are used solely for account operation: verification links, password resets, email-change '
                . 'confirmations and expiry/security notices. Account notices can be disabled on the account page; '
                . 'we never share addresses with third parties.',
                'Changing the account email requires confirmation from the current address and then from the new '
                . 'one; a cool-down period applies between changes. This protects accounts from hijacking.',
                'Session cookies (and the optional "stay signed in" token) are strictly functional. The sign-in '
                . 'duration is chosen at login; tokens are stored only as hashes and are invalidated by a password '
                . 'change or sign-out.',
                'Accounts used for abuse (spam, attacks on the service, deliberately registering infringing content '
                . 'after warnings) may be suspended or deleted, together with their whitelist registrations.',
                'To have your account and its data removed, contact the site email from your account address (or use '
                . 'the report form). Read notifications are pruned automatically after 90 days.',
            ]);
        }
        return implode("\n", $L) . "\n";
    }

    // ── info ────────────────────────────────────────────────────────────────
    $L[] = $h1('Tracker Information');
    $L[] = '';
    $L[] = $h2('What is a BitTorrent tracker?');
    $L[] = '';
    $L[] = 'A BitTorrent tracker is a server that helps BitTorrent clients communicate. It coordinates file '
         . 'transfers between users (peers) by tracking who is sharing a given torrent. The tracker does not store '
         . 'any files — only information about active swarm participants.';
    $L[] = '';
    $L[] = $h2('What is OpenTracker?');
    $L[] = '';
    $L[] = 'OpenTracker is a high-performance BitTorrent tracker software created by erdgeist. It is open-source, '
         . 'extremely fast, and minimalist. It can handle millions of connections with minimal resource usage.';
    $L[] = '';
    $L[] = $h2('How does the tracker work?');
    $L[] = '';
    $L[] = 'A BitTorrent client sends an "announce" request to the tracker with the torrent\'s info_hash. The tracker '
         . 'responds with a list of peers currently sharing or downloading the same torrent. The tracker only knows: '
         . 'the info_hash, the peer\'s IP address, and port.';
    if ($wl) {
        $L[] = '';
        $L[] = $h2('Whitelist mode');
        $L[] = '';
        $L[] = 'This tracker runs OpenTracker in ' . $b('whitelist mode') . ': announces are only answered for info '
             . 'hashes that were ' . $b('registered') . ' beforehand. Torrents posted on our community forum are '
             . 'registered automatically; anyone else can register a magnet link or info hash for free on the '
             . $link($baseUrl . '?action=whitelist', 'Whitelist page')
             . ' (CAPTCHA + rate limits apply, the registrant\'s IP is stored to fight abuse). Registered hashes may '
             . 'be removed or banned after an abuse report. Unregistered hashes receive an empty / "not authorized" '
             . 'answer.';
    }
    $L[] = '';
    $L[] = $h2('What data does the tracker store?');
    $L[] = '';
    $L[] = 'The tracker only stores active swarms — a list of info_hashes and their associated peers (IP + port). '
         . 'This data is temporary and removed when a peer\'s session expires. No files, torrent names, or content '
         . 'are stored.';
    $L[] = '';
    $L[] = $h2('Frequently Asked Questions');
    $L[] = '';
    $faq = [
        ['Can you remove an info_hash?', $wl
            ? 'Yes — in whitelist mode a hash can be removed from (or banned on) the whitelist, after which the '
              . 'tracker stops answering announces for it. Use the report form.'
            : 'The tracker automatically removes swarms when all peers\' sessions expire. We do not control which '
              . 'torrents are tracked.'],
        ['Can you see what content is behind a hash?',
            'No. An info_hash is merely a SHA1 digest of the torrent\'s metadata. The tracker has no information '
            . 'about file contents.'],
        ['Do you keep IP address logs?',
            'No. We do not keep persistent connection logs. Random IP addresses are inserted into peer lists to '
            . 'protect privacy.'],
        ['What should copyright holders do?',
            'Since the tracker does not store any files or content, copyright holders should contact the indexing '
            . 'site (e.g., the torrent site), not the tracker. You may, however, submit a report using our form.'],
        ['Do you have .torrent files?',
            'No. The tracker does not store .torrent files. It only tracks active peer connections.'],
    ];
    foreach ($faq as [$q, $a]) {
        $L[] = $b($q);
        $L[] = '';
        $L[] = $a;
        $L[] = '';
    }
    return rtrim(implode("\n", $L)) . "\n";
}
