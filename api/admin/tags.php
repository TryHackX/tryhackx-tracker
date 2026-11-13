<?php
jsonResponse(['success' => true, 'manage' => panelCan($db, $cfg, 'panel.tags.manage')] + tagsAdminList($db, [
    'status'   => (string)($_GET['status'] ?? ''),
    'q'        => mb_substr((string)($_GET['q'] ?? ''), 0, 40),
    'sort'     => (string)($_GET['sort'] ?? 'uses'),
    'page'     => (int)($_GET['page'] ?? 1),
    'per_page' => (int)($_GET['per_page'] ?? 50),
]));
