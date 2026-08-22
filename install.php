<?php
/**
 * Bitrix24 Custom Booking Widget Installer
 * Binds CRM_LEAD_DETAIL_TAB and CRM_DEAL_DETAIL_TAB placements.
 */
require_once __DIR__ . '/crest.php';
require_once __DIR__ . '/db.php';

// Ensure DB tables are initialized
$db = DB::getConnection();

// Save current settings if available in request
if (!empty($_REQUEST['DOMAIN']) && !empty($_REQUEST['AUTH_ID'])) {
    $arSettings = [
        'DOMAIN' => $_REQUEST['DOMAIN'],
        'AUTH_ID' => $_REQUEST['AUTH_ID'],
        'REFRESH_ID' => $_REQUEST['REFRESH_ID'] ?? '',
    ];
    CRest::setSettings($arSettings);
}

// Compute dynamic handler URL based on current host/request
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$handlerUrl = $protocol . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/index.php';

$placementsToRegister = [
    [
        'PLACEMENT' => 'CRM_LEAD_DETAIL_TAB',
        'HANDLER' => $handlerUrl,
        'TITLE' => 'Appointments & Booking',
        'LANG_ALL' => [
            'en' => ['TITLE' => 'Appointments & Booking'],
            'ru' => ['TITLE' => 'Запись и Бронирование'],
            'ar' => ['TITLE' => 'الحجوزات والمواعيد'],
        ],
    ],
    [
        'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
        'HANDLER' => $handlerUrl,
        'TITLE' => 'Appointments & Booking',
        'LANG_ALL' => [
            'en' => ['TITLE' => 'Appointments & Booking'],
            'ru' => ['TITLE' => 'Запись и Бронирование'],
            'ar' => ['TITLE' => 'الحجوزات والمواعيد'],
        ],
    ],
];

$results = [];
foreach ($placementsToRegister as $placement) {
    $res = CRest::call('placement.bind', $placement);
    $results[$placement['PLACEMENT']] = $res;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking Widget Installation</title>
    <script src="//api.bitrix24.com/api/v1/"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; padding: 40px; color: #1e293b; }
        .card { background: white; max-width: 600px; margin: 0 auto; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        h1 { font-size: 22px; color: #0f172a; margin-top: 0; }
        .status { padding: 12px 16px; border-radius: 8px; font-weight: 500; margin-bottom: 20px; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        pre { background: #f1f5f9; padding: 15px; border-radius: 6px; font-size: 13px; overflow-x: auto; }
        .btn { display: inline-block; background: #2563eb; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: 500; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Bitrix24 Booking Widget Installation</h1>
        <p>Registering tab placements inside CRM Lead Card and CRM Deal Card...</p>

        <?php foreach ($results as $placementCode => $res): ?>
            <?php if (isset($res['result']) && $res['result'] === true): ?>
                <div class="status success">
                    ✓ Placement <strong><?= htmlspecialchars($placementCode) ?></strong> registered successfully!
                </div>
            <?php else: ?>
                <div class="status error">
                    ✗ Failed to register placement <strong><?= htmlspecialchars($placementCode) ?></strong>: 
                    <?= htmlspecialchars($res['error_description'] ?? json_encode($res)) ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <h3>Handler URL Registered:</h3>
        <pre><?= htmlspecialchars($handlerUrl) ?></pre>

        <a href="#" onclick="BX24.installComplete(); return false;" class="btn">Finish Installation</a>
    </div>

    <script>
        if (typeof BX24 !== 'undefined') {
            BX24.init(function() {
                BX24.installComplete();
            });
        }
    </script>
</body>
</html>
