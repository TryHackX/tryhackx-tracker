<?php
$view = (string)($_GET['view'] ?? 'list');

if ($view === 'used') {
    $id = (int)($_GET['id'] ?? 0);
    $used = [];
    foreach (imagesWhereUsed($db, $id) as $u) {
        $hash = (string)$u['hash'];
        $u['name'] = $hash !== '' && function_exists('commentTorrentName') ? commentTorrentName($db, $hash) : '';
        if ($u['kind'] === 'comment') $u['link'] = commentLink($hash, (int)$u['id']);
        elseif ($hash !== '') $u['link'] = '?action=search&hash=' . $hash;
        else $u['link'] = '?action=' . rawurlencode((string)($u['page'] ?? ''));
        $used[] = $u;
    }
    jsonResponse(['success' => true, 'id' => $id, 'used' => $used]);
}

if ($view === 'queue') {
    jsonResponse(['success' => true, 'summary' => imagesPanelSummary($db), 'hosts' => imagesRemoteHosts($db), 'may_manage' => panelCan($db, $cfg, 'panel.images.manage')]
        + imagesRemoteList($db, (string)($_GET['status'] ?? 'pending'), (int)($_GET['page'] ?? 1)));
}

jsonResponse(['success' => true, 'summary' => imagesPanelSummary($db), 'hosts' => imagesRemoteHosts($db),
              'may_manage' => panelCan($db, $cfg, 'panel.images.manage')] + imagesPanelList($db, $cfg, $_GET));
