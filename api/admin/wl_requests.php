<?php
jsonResponse(['success' => true, 'manage' => panelCan($db, $cfg, 'panel.whitelist.add'), 'on' => wlReqOn($cfg)]
             + wlReqList($db, (string)($_GET['status'] ?? 'open'), (int)($_GET['page'] ?? 1)));
