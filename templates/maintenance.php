<?php
/**
 * The page index.php sends when the database cannot be reached (503, Retry-After: 60).
 *
 * Deliberately NOT rendered through layout.php: the layout reads $cfg for the site name, the nav
 * asks the database who is signed in, and every one of those is the thing that just failed. This
 * file uses the dictionary (a PHP array on disk) and nothing else, so it cannot fail for the reason
 * it is shown -- and the check in tests/audit_fixes_test.php keeps it that way.
 *
 * The site name lives in the settings table, which is exactly what is unreachable here, and
 * config/app.php carries only the password hash; so the title is generic on purpose rather than a
 * stale guess. English with the same sentence in Polish under it: langInit() needs $cfg, so the
 * visitor's language is unknown, and two lines cover everyone the site ships a translation for.
 *
 * Both lines go through langFor(), not __()/_h(): those read the dictionary langInit() loaded, and
 * nothing has run langInit() here -- before it, a lookup hands back the key. langFor() reads the
 * language file itself, so it is the one lookup that is right before the request has a language.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= htmlspecialchars(langFor('en', 'maintenance.title'), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               background: #0f1117; color: #e6e6e6; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 32rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.6rem; margin: 0 0 1rem; }
        p { margin: 0 0 .75rem; }
        p[lang="pl"] { color: #a8a8a8; }
    </style>
</head>
<body>
    <main>
        <h1><?= htmlspecialchars(langFor('en', 'maintenance.h1'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars(langFor('en', 'maintenance.body'), ENT_QUOTES, 'UTF-8') ?></p>
        <p lang="pl"><?= htmlspecialchars(langFor('pl', 'maintenance.body'), ENT_QUOTES, 'UTF-8') ?></p>
    </main>
</body>
</html>
