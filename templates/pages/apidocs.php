<?php
/**
 * The partner integration guide: ?action=apidocs&scope=…&approve=…&fields=…
 *
 * ── why it is driven by the query string ───────────────────────────────────────────────────────
 * Every partner gets a key with different answers: this one publishes straight to the tracker, that
 * one goes through review, a third must send a title and a link, a fourth is a forum bridging its
 * members. A single page describing all the combinations is a page where everybody reads three
 * paragraphs that do not apply to them and misses the one that does. So the operator hands out an
 * address, and the address IS the configuration — the panel builds it when the key is made, next to
 * the key itself.
 *
 * ── why it is unlisted rather than locked ─────────────────────────────────────────────────────
 * Nothing here is a secret. It is the shape of a public API: the endpoint, the header, the JSON. The
 * key is what is secret, and the key is never on this page — it goes in the same mail, from a person.
 * Locking the documentation behind the credential it documents would mean a partner cannot read how
 * to use the thing until they have already worked out how to use it. It carries a robots noindex
 * because it is written for one reader, not for a search engine.
 *
 * Nothing here reads the database and nothing here is written by a partner: every value below is
 * either a literal or one of a fixed set chosen by a query parameter.
 */
$apiOn = ($cfg['api_enabled'] ?? '0') === '1';

// A fixed vocabulary, chosen by key. A query parameter that is not one of these is not an error —
// it is somebody editing the address, and the honest response is the default page.
$docScopes = ['whitelist' => 'v1/whitelist/submit', 'abuse' => 'v1/blacklist/submit',
              'users' => 'v1/users/lookup',
              'federation' => 'v1/federation/export', 'auth' => 'v1/auth/login',
              'all' => 'v1/whitelist/submit'];
$docScope = isset($_GET['scope']) && isset($docScopes[(string)$_GET['scope']]) ? (string)$_GET['scope'] : 'whitelist';
$docReview = ((string)($_GET['approve'] ?? 'auto')) === 'review';
$docFields = function_exists('apiClientCleanFields') ? apiClientCleanFields($_GET['fields'] ?? [], $docScope) : [];
// ABSOLUTE. Every address on this page is copied into somebody else's code on somebody else's
// server; "/api.php?endpoint=…" is their host, not this one.
$docBase = function_exists('apiAbsoluteBase') ? apiAbsoluteBase($cfg) : rtrim(getBaseUrl(), '/');
$docApi = $docBase . '/api.php?endpoint=';
$docUrl = $docApi . $docScopes[$docScope];

// Which chapters this page prints. A key scoped 'all' is one key doing several jobs, so it gets
// every chapter; every other key gets exactly the one it can use, and no paragraph it cannot.
//
// The bridge belongs to the 'users' scope rather than a scope of its own — that is what
// api/v1/auth_*.php enforces — so a users key is told about both halves of what it can do.
$showSubmit = in_array($docScope, ['whitelist', 'all'], true);
// The reporting half. `approve=auto` means something much heavier here than it does above, and the
// chapter says so where somebody integrating will read it.
$showAbuse  = in_array($docScope, ['abuse', 'all'], true);
$showAuth   = in_array($docScope, ['auth', 'users', 'all'], true);
$showUsers  = in_array($docScope, ['users', 'all'], true);
$showFed    = in_array($docScope, ['federation', 'all'], true);

// The example body, built from the same three answers the page is describing, so what a partner
// copies is what their key will actually accept.
$exItem = ['magnet' => 'magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567'];
if (in_array('name', $docFields, true)) $exItem['name'] = 'Example release name';
$exRef = [];
if (in_array('url', $docFields, true)) $exRef['url'] = 'https://partner.example.org/post/1234';
if (in_array('source_id', $docFields, true)) $exRef['post_id'] = 1234;
if ($exRef) $exItem['ref'] = $exRef;
$exBody = json_encode(['items' => [$exItem], 'source' => 'api'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$exReply = json_encode([
    'ok' => true,
    'results' => [['index' => 0, 'hash' => '0123456789abcdef0123456789abcdef01234567',
                   'status' => $docReview ? 'pending' : 'added', 'error' => null]],
    'summary' => $docReview ? ['added' => 0, 'exists' => 0, 'banned' => 0, 'invalid' => 0, 'pending' => 1]
                            : ['added' => 1, 'exists' => 0, 'banned' => 0, 'invalid' => 0, 'pending' => 0],
    'auto_approve' => !$docReview,
    'required_fields' => $docFields,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// The abuse example, built from the same answers. Only the fields this key must send are shown:
// an integrator copying this should be copying something their key will accept.
$exAbuseItem = ['magnet' => 'magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567'];
if (in_array('title', $docFields, true)) $exAbuseItem['title'] = 'Example Film (2026)';
if (in_array('evidence_url', $docFields, true)) $exAbuseItem['evidence_url'] = 'https://rightsholder.example/catalogue/1234';
if (in_array('reason', $docFields, true)) $exAbuseItem['reason'] = 'Unlicensed copy of our film';
$exAbuseBody = ['items' => [$exAbuseItem]];
if (in_array('reporter', $docFields, true)) {
    $exAbuseBody = ['reporter' => ['name' => 'Example Pictures', 'representative' => 'A. Nowak',
                                   'company' => 'Example Anti-Piracy', 'email' => 'abuse@example.org']] + $exAbuseBody;
}
if (in_array('statement', $docFields, true)) $exAbuseBody['statement'] = true;
$exAbuse = json_encode($exAbuseBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$exAbuseReply = json_encode([
    'ok' => true,
    'results' => [['index' => 0, 'hash' => '0123456789abcdef0123456789abcdef01234567',
                   'status' => $docReview ? 'received' : 'blocked', 'report_id' => 41, 'error' => null]],
    'summary' => $docReview ? ['received' => 1, 'blocked' => 0, 'duplicate' => 0, 'already_blocked' => 0, 'invalid' => 0]
                            : ['received' => 0, 'blocked' => 1, 'duplicate' => 0, 'already_blocked' => 0, 'invalid' => 0],
    'auto_block' => !$docReview,
    'required_fields' => $docFields,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$exLogin = json_encode(['external_id' => '412', 'username' => 'kasia', 'email' => 'kasia@example.org'],
                       JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$exLoginReply = json_encode([
    'ok' => true, 'created' => true, 'merged' => false,
    'user' => ['id' => 7, 'username' => 'kasia', 'status' => 'active'],
    'handoff' => ['token' => 'a1b2…', 'url' => $docBase . '/?action=bridge&token=a1b2…', 'expires_in' => 120],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
<h1><?= _h('apidocs.h1') ?></h1>

<?php if (!$apiOn): ?>
<div class="alert alert-error show"><?= _h('apidocs.api_off') ?></div>
<?php endif; ?>

<p><?= __('apidocs.intro') ?></p>

<div class="apidocs-summary">
    <div class="apidocs-fact"><span class="apidocs-k"><?= _h('apidocs.k_endpoint') ?></span>
        <code class="apidocs-v"><?= sanitize($docUrl) ?></code></div>
    <?php if ($showAbuse): ?>
    <div class="apidocs-fact"><span class="apidocs-k"><?= _h('apidocs.k_blocking') ?></span>
        <span class="apidocs-v"><?= _h($docReview ? 'apidocs.block_review' : 'apidocs.block_auto') ?></span></div>
    <?php endif; ?>
    <?php if ($showSubmit): ?>
    <div class="apidocs-fact"><span class="apidocs-k"><?= _h('apidocs.k_approval') ?></span>
        <span class="apidocs-v"><?= _h($docReview ? 'apidocs.approve_review' : 'apidocs.approve_auto') ?></span></div>
    <div class="apidocs-fact"><span class="apidocs-k"><?= _h('apidocs.k_required') ?></span>
        <span class="apidocs-v"><?= $docFields ? sanitize(implode(', ', $docFields)) : _h('apidocs.required_none') ?></span></div>
    <?php endif; ?>
    <?php if ($showAbuse && !$showSubmit): ?>
    <div class="apidocs-fact"><span class="apidocs-k"><?= _h('apidocs.k_required') ?></span>
        <span class="apidocs-v"><?= $docFields ? sanitize(implode(', ', $docFields)) : _h('apidocs.required_none') ?></span></div>
    <?php endif; ?>
</div>

<h2><?= _h('apidocs.h_auth') ?></h2>
<p><?= __('apidocs.auth_body') ?></p>
<pre class="apidocs-pre"><code>Authorization: Bearer &lt;key_id&gt;.&lt;secret&gt;
Content-Type: application/json</code></pre>

<?php if ($showSubmit): ?>
<h2><?= _h('apidocs.h_request') ?></h2>
<p><?= __('apidocs.request_body') ?></p>
<pre class="apidocs-pre"><code>POST <?= sanitize($docApi . 'v1/whitelist/submit') ?>

<?= sanitize($exBody) ?></code></pre>

<?php if ($docFields): ?>
<div class="alert alert-warning show"><?= __('apidocs.required_note', ['fields' => sanitize(implode(', ', $docFields))]) ?></div>
<?php endif; ?>

<h2><?= _h('apidocs.h_reply') ?></h2>
<p><?= __($docReview ? 'apidocs.reply_review' : 'apidocs.reply_auto') ?></p>
<pre class="apidocs-pre"><code><?= sanitize($exReply) ?></code></pre>

<h2><?= _h('apidocs.h_status') ?></h2>
<div class="transparency-table-wrap">
<table class="transparency-table apidocs-table">
    <thead><tr><th><?= _h('apidocs.col_status') ?></th><th><?= _h('apidocs.col_means') ?></th></tr></thead>
    <tbody>
        <tr><td><code>added</code></td><td><?= _h('apidocs.st_added') ?></td></tr>
        <?php if ($docReview): ?>
        <tr><td><code>pending</code></td><td><?= _h('apidocs.st_pending') ?></td></tr>
        <?php endif; ?>
        <tr><td><code>exists</code></td><td><?= _h('apidocs.st_exists') ?></td></tr>
        <tr><td><code>banned</code></td><td><?= _h('apidocs.st_banned') ?></td></tr>
        <tr><td><code>invalid</code></td><td><?= _h('apidocs.st_invalid') ?></td></tr>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($showAbuse): ?>
<?php /* Reporting. Deliberately its own chapter with its own warning: everything above is about
         adding a hash to a catalogue, and this is about taking a torrent away from everybody who
         has it. A partner reading only this page should still know which one they are doing. */ ?>
<h2><?= _h('apidocs.h_abuse') ?></h2>
<p><?= __('apidocs.abuse_intro') ?></p>
<div class="alert <?= $docReview ? 'alert-info' : 'alert-warning' ?> show"><?= __($docReview ? 'apidocs.abuse_note_review' : 'apidocs.abuse_note_auto') ?></div>
<pre class="apidocs-pre"><code>POST <?= sanitize($docApi . 'v1/blacklist/submit') ?>

<?= sanitize($exAbuse) ?></code></pre>

<?php if ($docFields): ?>
<div class="alert alert-warning show"><?= __('apidocs.required_note', ['fields' => sanitize(implode(', ', $docFields))]) ?></div>
<?php endif; ?>

<h2><?= _h('apidocs.h_abuse_reply') ?></h2>
<p><?= __($docReview ? 'apidocs.abuse_reply_review' : 'apidocs.abuse_reply_auto') ?></p>
<pre class="apidocs-pre"><code><?= sanitize($exAbuseReply) ?></code></pre>

<div class="transparency-table-wrap">
<table class="transparency-table apidocs-table">
    <thead><tr><th><?= _h('apidocs.col_status') ?></th><th><?= _h('apidocs.col_means') ?></th></tr></thead>
    <tbody>
        <tr><td><code>received</code></td><td><?= _h('apidocs.ab_received') ?></td></tr>
        <?php if (!$docReview): ?>
        <tr><td><code>blocked</code></td><td><?= _h('apidocs.ab_blocked') ?></td></tr>
        <?php endif; ?>
        <tr><td><code>duplicate</code></td><td><?= _h('apidocs.ab_duplicate') ?></td></tr>
        <tr><td><code>already_blocked</code></td><td><?= _h('apidocs.ab_already') ?></td></tr>
        <tr><td><code>invalid</code></td><td><?= _h('apidocs.ab_invalid') ?></td></tr>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($showAuth): ?>
<?php /* The bridge. Its shape is unlike everything else here — two of its five steps happen in a
         BROWSER — so the four numbered lines are the order somebody has to build it in, and the
         redirect is called out rather than left implied by an example. */ ?>
<h2><?= _h('apidocs.h_bridge') ?></h2>
<p><?= __('apidocs.bridge_intro') ?></p>
<ol class="apidocs-rules">
    <li><?= __('apidocs.bridge_step1') ?></li>
    <li><?= __('apidocs.bridge_step2') ?></li>
    <li><?= __('apidocs.bridge_step3') ?></li>
    <li><?= __('apidocs.bridge_step4') ?></li>
</ol>
<pre class="apidocs-pre"><code>POST <?= sanitize($docApi . 'v1/auth/login') ?>

<?= sanitize($exLogin) ?></code></pre>
<pre class="apidocs-pre"><code><?= sanitize($exLoginReply) ?></code></pre>
<p><?= __('apidocs.bridge_reverse') ?></p>
<div class="transparency-table-wrap">
<table class="transparency-table apidocs-table">
    <thead><tr><th><?= _h('apidocs.col_endpoint') ?></th><th><?= _h('apidocs.col_means') ?></th></tr></thead>
    <tbody>
        <tr><td><code>v1/auth/login</code></td><td><?= __('apidocs.ep_auth_login') ?></td></tr>
        <tr><td><code>v1/auth/logout</code></td><td><?= __('apidocs.ep_auth_logout') ?></td></tr>
        <tr><td><code>v1/auth/verify</code></td><td><?= __('apidocs.ep_auth_verify') ?></td></tr>
        <tr><td><code>v1/auth/merge</code></td><td><?= __('apidocs.ep_auth_merge') ?></td></tr>
        <tr><td><code>v1/auth/status</code></td><td><?= __('apidocs.ep_auth_status') ?></td></tr>
    </tbody>
</table>
</div>
<div class="alert alert-warning show"><?= __('apidocs.bridge_warning') ?></div>
<?php endif; ?>

<?php if ($showUsers): ?>
<h2><?= _h('apidocs.h_users') ?></h2>
<p><?= __('apidocs.users_intro') ?></p>
<div class="transparency-table-wrap">
<table class="transparency-table apidocs-table">
    <thead><tr><th><?= _h('apidocs.col_endpoint') ?></th><th><?= _h('apidocs.col_means') ?></th></tr></thead>
    <tbody>
        <tr><td><code>v1/users/lookup</code></td><td><?= __('apidocs.ep_users_lookup') ?></td></tr>
        <tr><td><code>v1/users/provision</code></td><td><?= __('apidocs.ep_users_provision') ?></td></tr>
        <tr><td><code>v1/users/grant</code></td><td><?= __('apidocs.ep_users_grant') ?></td></tr>
        <tr><td><code>v1/users/revoke</code></td><td><?= __('apidocs.ep_users_revoke') ?></td></tr>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($showFed): ?>
<h2><?= _h('apidocs.h_fed') ?></h2>
<p><?= __('apidocs.fed_intro') ?></p>
<div class="transparency-table-wrap">
<table class="transparency-table apidocs-table">
    <thead><tr><th><?= _h('apidocs.col_endpoint') ?></th><th><?= _h('apidocs.col_means') ?></th></tr></thead>
    <tbody>
        <tr><td><code>v1/federation/ping</code></td><td><?= __('apidocs.ep_fed_ping') ?></td></tr>
        <tr><td><code>v1/federation/export</code></td><td><?= __('apidocs.ep_fed_export') ?></td></tr>
    </tbody>
</table>
</div>
<?php endif; ?>

<h2><?= _h('apidocs.h_rules') ?></h2>
<ul class="apidocs-rules">
    <?php if ($showSubmit): ?>
    <li><?= __('apidocs.rule_idempotent') ?></li>
    <li><?= __('apidocs.rule_additive') ?></li>
    <li><?= __('apidocs.rule_batch', ['n' => defined('API_MAX_ITEMS') ? API_MAX_ITEMS : 500]) ?></li>
    <?php endif; ?>
    <li><?= __('apidocs.rule_limits') ?></li>
    <li><?= __('apidocs.rule_errors') ?></li>
</ul>

<h2><?= _h('apidocs.h_curl') ?></h2>
<?php
// The one command that proves the key works, for the scope it actually has. A guide whose "check
// it works" line calls an endpoint this key cannot reach is a guide that opens with a 403.
$curlUrl = $showSubmit ? $docApi . 'v1/whitelist/submit' : $docUrl;
$curlBody = $showSubmit
    ? json_encode(['items' => [$exItem], 'source' => 'api'], JSON_UNESCAPED_SLASHES)
    : ($docScope === 'auth' ? json_encode(['external_id' => '412', 'username' => 'kasia'], JSON_UNESCAPED_SLASHES)
                            : ($showUsers ? json_encode(['login' => 'kasia'], JSON_UNESCAPED_SLASHES) : '{}'));
?>
<pre class="apidocs-pre"><code>curl -sS -X POST '<?= sanitize($curlUrl) ?>' \
  -H 'Authorization: Bearer &lt;key_id&gt;.&lt;secret&gt;' \
  -H 'Content-Type: application/json' \
  --data '<?= sanitize($curlBody) ?>'</code></pre>

<p class="text-muted apidocs-foot"><?= __('apidocs.foot') ?></p>
