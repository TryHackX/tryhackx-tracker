<?php
// Admin: list federation peers (outbound bearer never echoed — only has_bearer).
jsonResponse([
    'peers' => fedPeersList($db),
    'enabled' => fedEnabled($cfg),
    'export_enabled' => fedExportEnabled($cfg),
    'node_name' => fedNodeName($cfg),
    'api_enabled' => (($cfg['api_enabled'] ?? '0') === '1'),
]);
