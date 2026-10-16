<?php
// cron/worker.php - Scheduled Task Runner for RentalKH (CLI only)

if (php_sapi_name() !== 'cli' && empty($_SERVER['SHELL'])) {
    http_response_code(403);
    die("Access denied: Worker may only be executed via CLI.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../rental_service.php';

$options = getopt('', ['action::']);
$action = $options['action'] ?? 'all';

$logPrefix = "[" . date('Y-m-d H:i:s') . "] [Worker]";

echo "$logPrefix Starting RentalKH background tasks (Action: $action)...\n";

if ($action === 'expire_stale' || $action === 'all') {
    $expired = RentalService::expireStaleBookings($conn);
    echo "$logPrefix Expired $expired stale pending booking(s).\n";
}

if ($action === 'reminders' || $action === 'all') {
    $reminders = RentalService::triggerAutomatedReminders($conn);
    echo "$logPrefix Dispatched reminders: {$reminders['pickups']} upcoming pickups, {$reminders['returns']} returns due, {$reminders['overdue']} overdue alerts.\n";
}

if ($action === 'cleanup' || $action === 'all') {
    $purgedResets = RentalService::purgeExpiredPasswordResets($conn, 24);
    $purgedNotifs = RentalService::purgeOldNotifications($conn, 90);
    echo "$logPrefix Maintenance cleanup: Purged $purgedResets expired reset token(s), $purgedNotifs old read notification(s).\n";
}

echo "$logPrefix Worker completed successfully.\n";
