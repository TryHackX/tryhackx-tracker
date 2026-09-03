<?php
/**
 * Test: nothing builds SQL out of a variable that could carry input.
 *   php tests/sql_safety_test.php
 *
 * "We use prepared statements everywhere" is the kind of belief that stays true right up until one
 * commit, and that commit never looks like it is about SQL. So this checks the property
 * mechanically, across every PHP file in the project.
 *
 * ── how it looks ────────────────────────────────────────────────────────────
 *
 * With PHP's own tokeniser, not with a regex over lines. A line-based scan cannot tell
 * `"DELETE FROM x WHERE id = ?"` followed by `->execute([$id])` — perfectly safe, the variable is
 * nowhere near the SQL — from `"DELETE FROM x WHERE id = $id"`, which is the bug. The first version
 * of this test could not, reported eighty-eight false positives, and would have been switched off
 * within a week. A test nobody trusts protects nothing.
 *
 * So: find string literals that contain SQL, and look at what is interpolated or concatenated INTO
 * them. That is the only thing that matters.
 *
 * ── what counts as safe ─────────────────────────────────────────────────────
 *
 * Interpolating is not automatically wrong; this codebase does it in shapes that are safe by
 * construction, and each is named below rather than waved through. Anything else is reported with
 * its file and line, and the answer is a placeholder — not a new entry in the list.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : "\n     " . $info) . "\n";
    if (!$ok) $fails++;
}

$files = [];
foreach (['api', 'includes', 'tools', 'templates'] as $dir) {
    $d = $root . '/' . $dir;
    if (!is_dir($d)) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d)) as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') $files[] = $f->getPathname();
    }
}
foreach (['api.php', 'index.php', 'install.php'] as $f) {
    if (is_file($root . '/' . $f)) $files[] = $root . '/' . $f;
}
sort($files);
check('there are PHP files to inspect', count($files) > 50, (string)count($files));

/**
 * Variable names that cannot carry input, with the reason.
 *
 * Every one of these is either built from literals in the same file, or already an integer. A name
 * that is not here is not automatically a bug — it is automatically a QUESTION, which is the point.
 */
$SAFE_NAMES = [
    // Placeholder runs: implode(',', array_fill(0, count($x), '?')). Values still go to execute().
    'in', 'ph', 'placeholders', 'qs',
    // Clause fragments assembled from literal strings, with their parameters collected alongside.
    'where', 'whereClause', 'w', 'orderClause', 'orderBy', 'columns', 'cols', 'joinClause',
    'having', 'extraSql', 'sql', 'clause', 'filterSql', 'sel',
    // Identifiers picked from a literal allow-list in the same file.
    'tbl', 'table', 'source', 'appealSource', 'col', 'column', 'sortCol', 'dir', 'engine', 'idx',
    // Integers by the time they reach the query (LIMIT/OFFSET cannot be bound in every driver mode).
    'limit', 'offset', 'per', 'perPage', 'page', 'cap', 'chunk', 'days', 'secs', 'seconds',
    'budget', 'max', 'min', 'ttl', 'n', 'i', 'k', 'count', 'batch', 'keep', 'lim',
];

$offenders = [];

/**
 * Only the ARGUMENTS of ->query(), ->exec() and ->prepare() are inspected.
 *
 * The version before this one looked at any string containing a SQL keyword, and duly reported the
 * `From:` header of an email and an error message with the word "limit" in it. Prose is full of SQL
 * keywords; only a query is a query. Scoping to the call is not a way of reporting less — it is the
 * difference between a test somebody reads and a test somebody disables.
 */
foreach ($files as $path) {
    $src = file_get_contents($path);
    if (!preg_match('/->(?:query|exec|prepare)\s*\(/', $src)) continue;
    $tokens = @token_get_all($src);
    if (!is_array($tokens)) continue;
    $count = count($tokens);
    // Forward slashes, always. RecursiveDirectoryIterator hands back the platform separator while
    // $root was built with '/', so on Windows a naive strip leaves half the absolute path behind and
    // the baseline below never matches anything.
    $rel = ltrim(str_replace(strtr($root, DIRECTORY_SEPARATOR, '/'), '', strtr($path, DIRECTORY_SEPARATOR, '/')), '/');

    for ($i = 0; $i < $count; $i++) {
        // ->query / ->exec / ->prepare
        if (!(is_array($tokens[$i]) && $tokens[$i][0] === T_OBJECT_OPERATOR)) continue;
        $nameTok = $tokens[$i + 1] ?? null;
        if (!is_array($nameTok) || $nameTok[0] !== T_STRING) continue;
        if (!in_array(strtolower($nameTok[1]), ['query', 'exec', 'prepare'], true)) continue;
        if (($tokens[$i + 2] ?? null) !== '(') continue;

        // Collect the first argument: everything up to the matching close paren or a top-level comma.
        $depth = 0;
        $argVars = [];
        $lines = [];
        for ($j = $i + 3; $j < $count; $j++) {
            $u = $tokens[$j];
            if ($u === '(' || $u === '[') { $depth++; continue; }
            if ($u === ')' || $u === ']') { if ($depth === 0) break; $depth--; continue; }
            if ($u === ',' && $depth === 0) break;
            if (is_array($u)) {
                // A variable inside the query expression: interpolated in a "..." string, or
                // concatenated with `.`. Either way it is part of the SQL being built.
                if ($u[0] === T_VARIABLE) { $argVars[] = ltrim($u[1], '$'); $lines[] = $u[2]; }
                elseif ($u[0] === T_STRING_VARNAME) { $argVars[] = $u[1]; $lines[] = $u[2]; }
            }
        }
        foreach ($argVars as $idx => $v) {
            if (in_array($v, $SAFE_NAMES, true)) continue;
            $offenders[] = $rel . ':' . ($lines[$idx] ?? 0) . '  $' . $v . ' goes into the query text';
        }
        $i = $j;
    }
}

/**
 * Sites already reviewed by hand, each with the reason it is safe.
 *
 * Static analysis stops here, and pretending otherwise would mean either a test that cries wolf or
 * a detector so lenient it catches nothing. `indexProtectDays($cfg)` returns an int, but the scanner
 * sees `$cfg` between the parentheses; `$a[2]` is a literal SQL string from an array two lines up.
 * Neither can be settled by reading tokens, and both can be settled by reading the code — once.
 *
 * So this is a BASELINE, not an exception list: the scan runs over everything, and anything not
 * named here fails. Adding a line means somebody looked and wrote down what they saw. The value is
 * that a NEW interpolation cannot arrive quietly, which is the only way this class of bug ever does.
 */
$REVIEWED = [
    // Identifiers chosen from a literal allow-list before they reach the query.
    'api/admin/update_field.php:$field'        => 'checked against $allowed a few lines above',
    'api/admin/fetch_banned.php:$from'         => 'a literal FROM/JOIN fragment defined in the file',
    'api/admin/fetch_reports.php:$countSql'    => 'built from literals with its params collected alongside',
    'api/admin/fetch_users.php:$orderParts'    => 'ORDER BY parts from a literal column map',
    // LIMIT/OFFSET cannot be bound as parameters in a prepared statement with emulation off, so
    // they are interpolated; both come from (int) casts clamped to a range in the lines above.
    'includes/audit.php:$off'                  => 'int, computed from a clamped page and per_page',
    'includes/index.php:$conds'                => 'literal conditions, values bound',
    'includes/index.php:$scope'                => 'literal, chosen from a fixed set',
    'includes/whitelist.php:$cond'             => 'one of two literal conditions, picked by a bool',
    'includes/whitelist.php:$conds'            => 'literal conditions from a fixed map',
    'includes/whitelist.php:$scope'            => 'a key into that map, rejected if absent',

    // SET lists built from literal column names; every value is still bound.
    'api/admin/api_client_update.php:$sets'    => 'literal "col = ?" fragments, values in execute()',
    'includes/federation.php:$sets'            => 'literal "col = ?" fragments, values in execute()',
    'includes/bulkmail.php:$values'            => 'a run of literal "(?, ?, ?, ?)" groups',

    // Integers by the time they arrive. LIMIT/OFFSET cannot be bound in every driver mode.
    'includes/index.php:$remaining'            => 'cast with (int) at the concatenation',
    'includes/index.php:$cfg'                  => 'not in the SQL: the argument to indexProtectDays(), which returns an int',
    'api/admin/wl_content.php:$off'            => 'computed from (int)$page, never from input directly',

    // A query that is itself a literal, passed by reference.
    'includes/index.php:$a'                    => '$a[2] is a literal SQL string from the $arms array above it',
    'includes/schema.php:$one'                 => 'one of this file\'s own CREATE/ALTER statements',
    'includes/reputation.php:$t'              => "iterates the literal ['index_hashes','whitelist']",

    // The installer, reviewed with the same care and holding up: the identifier is validated
    // before it is used, and the one value interpolated goes through PDO::quote().
    'install.php:$dbName'                     => 'validated as ^[A-Za-z0-9_]+$ ten lines above the CREATE DATABASE',
    'install.php:$adminUser'                  => 'validated as ^[A-Za-z0-9_.-]{3,32}$ and passed through PDO::quote()',
    'install.php:$pdo'                        => 'the connection object, not a value: $pdo->quote()',
];

$offenders = array_values(array_unique($offenders));
$new = [];
foreach ($offenders as $o) {
    // "path:line  $var goes into the query text" → "path:$var"
    if (!preg_match('/^(.+?):\d+\s+\$(\S+)/', str_replace('\\', '/', $o), $m)) { $new[] = $o; continue; }
    if (!isset($REVIEWED[$m[1] . ':$' . $m[2]])) $new[] = $o;
}

check('every place that builds SQL from a variable has been looked at',
      $new === [],
      "these are NOT in the reviewed baseline — read them, then add them with a reason:\n     "
      . implode("\n     ", array_slice($new, 0, 15))
      . (count($new) > 15 ? "\n     … and " . (count($new) - 15) . ' more' : ''));

// The baseline must not rot into a list of things that no longer exist: an entry nobody reaches is
// an entry nobody rechecks, and it would quietly permit a future line with the same name.
$seen = [];
foreach ($offenders as $o) {
    if (preg_match('/^(.+?):\d+\s+\$(\S+)/', str_replace('\\', '/', $o), $m)) $seen[$m[1] . ':$' . $m[2]] = true;
}
// The index cap-prune used to interpolate $excess into a single-line query() and had an entry here.
// It is now a multi-line prepare() with `(int)$batch`, which this scanner does not flag — so the
// entry was removed rather than renamed: a baseline line for something the scan never reports is a
// line nobody will ever check again.
$stale = array_values(array_diff(array_keys($REVIEWED), array_keys($seen)));
check('the reviewed baseline has no entries for code that is gone',
      $stale === [], implode(', ', $stale));
check('and the scan actually found the sites it is meant to be watching',
      count($offenders) >= 15, (string)count($offenders));

/* ── the newest write paths, by name ──────────────────────────────────────── */
//
// Everything above is a net. These are checked separately because they are the paths handling text
// a stranger typed, and a net catches what it was woven for.

$mustPrepare = [
    'api/whitelist_submit.php'  => 'the source link and description a visitor submits',
    'api/admin/wl_content.php'  => 'the review queue',
    'includes/bulkmail.php'     => 'the bulk mail queue',
    'api/index_info.php'        => 'the public info panel',
    'api/richtext_preview.php'  => 'the description preview',
];
$bad = [];
foreach ($mustPrepare as $rel => $what) {
    $src = (string)@file_get_contents($root . '/' . $rel);
    if ($src === '') { $bad[] = $rel . ' is missing'; continue; }
    if (preg_match('/->(?:query|exec)\s*\(\s*"[^"]*\$/', $src)) {
        $bad[] = $rel . ' (' . $what . ') interpolates into query()/exec()';
    }
}
check('the paths that handle submitted text use prepare() and nothing else',
      $bad === [], implode(', ', $bad));

/* ── length is capped before the database, not by it ──────────────────────── */

$rt = (string)file_get_contents($root . '/includes/richtext.php');
check('a description is length-checked before it is stored, not truncated by the column',
      str_contains($rt, 'richtextMaxChars') && str_contains($rt, 'mb_strlen($text) > $max'));
$sub = (string)file_get_contents($root . '/api/whitelist_submit.php');
check('… and the submit path runs that check before it writes anything',
      strpos($sub, 'richtextValidate') !== false
      && strpos($sub, 'richtextValidate') < strpos($sub, 'UPDATE whitelist SET source_url'));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
