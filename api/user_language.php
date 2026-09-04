<?php
/**
 * user_language — the signed-in user's interface language.
 *
 * GET  → {language, choices, site_default}
 * POST {csrf_token, language} — '' means "follow the site default", which is not the same as any
 *       particular language: change the site default and this account follows it, while an account
 *       that picked English stays English.
 *
 * Limited to langForUsers(): the admin decides which translations an account may choose, and a
 * language kept out of that list is still reachable with an explicit `?lang=` link but is not
 * something a user can pin to their account.
 */
require_once __DIR__ . '/../includes/lang.php';

if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in'], 401);

$choices = langForUsers($cfg);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
    $lang = strtolower(trim((string)($input['language'] ?? '')));
    if ($lang !== '' && !isset($choices[$lang])) {
        jsonResponse(['error' => 'That language is not one this site offers.'], 400);
    }
    $db->prepare("UPDATE users SET language = ? WHERE id = ?")
       ->execute([$lang === '' ? null : $lang, (int)$u['id']]);
    jsonResponse(['success' => true, 'language' => $lang,
                  'message' => __('account.lang_saved')]);
}

jsonResponse([
    'success'      => true,
    'language'     => (string)($u['language'] ?? ''),
    'choices'      => $choices,
    'site_default' => (string)($cfg['default_language'] ?? LANG_FALLBACK),
]);
