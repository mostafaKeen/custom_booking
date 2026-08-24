<?php
/**
 * Debug Log Viewer for Custom Booking Widget
 */
$logFile = __DIR__ . '/data/booking_debug.log';

if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    file_put_contents($logFile, '');
    header('Location: view_log.php');
    exit;
}

$logContent = file_exists($logFile) ? file_get_contents($logFile) : 'Log file is empty or does not exist yet.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Widget - Debug Logs</title>
    <style>
        body { font-family: monospace; background: #0f172a; color: #38bdf8; padding: 20px; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #1e293b; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #334155; }
        h1 { margin: 0; font-size: 18px; color: #f8fafc; }
        .btn { background: #2563eb; color: white; padding: 8px 16px; text-decoration: none; border-radius: 6px; font-weight: bold; font-family: sans-serif; font-size: 13px; border: none; cursor: pointer; }
        .btn-danger { background: #ef4444; }
        pre { background: #020617; color: #f1f5f9; padding: 20px; border-radius: 8px; border: 1px solid #1e293b; overflow-x: auto; font-size: 13px; line-height: 1.5; white-space: pre-wrap; word-break: break-all; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Booking Widget Debug Logs</h1>
        <div>
            <a href="view_log.php" class="btn">Refresh Logs</a>
            <a href="view_log.php?clear=1" onclick="return confirm('Clear log file?');" class="btn btn-danger">Clear Logs</a>
        </div>
    </div>
    <pre><?= htmlspecialchars($logContent) ?></pre>
</body>
</html>
