<?php
/**
 * Diagnostic tool: Check registered placements in Bitrix24
 */
require_once __DIR__ . '/crest.php';

header('Content-Type: application/json');

$res = CRest::call('placement.get', []);

echo json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'placement_get_result' => $res
], JSON_PRETTY_PRINT);
