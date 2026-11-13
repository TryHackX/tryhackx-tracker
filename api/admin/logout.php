<?php
// Panel logout. When the panel session rode on a site sign-in (admin-group user), drop only the
// panel part — the owner stays signed in on the public site. Classic sessions log out fully.
// POST only. The router exempts GET from the CSRF check, so a GET that changes session state is
// a link on any page that signs the admin out. Every caller in the panel already POSTs.
requirePost();
adminPanelSessionDrop();
jsonResponse(['success' => true]);
