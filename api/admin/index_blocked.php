<?php
jsonResponse(['success' => true] + indexBlockedList($db, ($_GET['allowed'] ?? '0') === '1', (int)($_GET['page'] ?? 1)));
