<?php
// Admin: search catalogue for the Settings page — group definitions plus the hidden keyword list
// (includes/settings_catalog.php). Served through the API instead of being printed into the page so
// the aliases stay out of the HTML; assets/js/admin-settings.js merges them with the labels/hints it
// reads from the DOM to rank the settings search.
require_once __DIR__ . '/../../includes/settings_catalog.php';
jsonResponse(settingsCatalogPayload());
