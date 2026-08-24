<?php
// Panel logout. When the panel session rode on a site sign-in (admin-group user), drop only the
// panel part — the owner stays signed in on the public site. Classic sessions log out fully.
adminPanelSessionDrop();
jsonResponse(['success' => true]);
