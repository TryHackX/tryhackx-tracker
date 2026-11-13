<?php
jsonResponse(['success' => true, 'manage' => panelCan($db, $cfg, 'panel.tags.manage')] + tagsCatAdmin($db));
