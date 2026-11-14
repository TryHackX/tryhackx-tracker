<?php
jsonResponse(['success' => true, 'manage' => panelCan($db, $cfg, 'panel.tags.manage')] + tagsForumAdmin($db, $cfg));
