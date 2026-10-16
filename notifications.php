<?php
// notifications.php - Internal Notification Center, Automated Reminders & Dispatch Log
require_once 'config.php';
require_once 'rental_service.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? RentalService::getUserRole($conn, $user_id);
$is_staff = in_array($user_role, [RentalService::ROLE_ADMIN, RentalService::ROLE_RENTAL_MANAGER, RentalService::ROLE_FINANCE_STAFF], true);

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

// Handle POST actions (CSRF protected)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $notifId = (int)($_POST['notification_id'] ?? 0);
        if ($notifId > 0) {
            RentalService::markNotificationRead($conn, $notifId, $user_id);
            $_SESSION['message'] = "Notification marked as read.";
        }
        header("Location: notifications.php");
        exit();
    } elseif ($action === 'mark_all_read') {
        RentalService::markAllNotificationsRead($conn, $user_id, $user_role);
        $_SESSION['message'] = "All notifications marked as read.";
        header("Location: notifications.php");
        exit();
    } elseif ($action === 'trigger_reminders' && $is_staff) {
        $stats = RentalService::triggerAutomatedReminders($conn);
        RentalService::logSystemAudit($conn, $user_id, $user_role, 'operational', 'TRIGGER_REMINDERS', null, 'system', "Manual execution of automated reminders (Pickups: {$stats['pickups']}, Returns: {$stats['returns']}, Overdue: {$stats['overdue']})");
        $_SESSION['message'] = "Automated reminder check completed: {$stats['pickups']} pickup reminders, {$stats['returns']} return reminders, {$stats['overdue']} overdue alerts processed.";
        header("Location: notifications.php");
        exit();
    }
}

$filter = $_GET['filter'] ?? 'all'; // 'all' or 'unread'
$allNotifications = RentalService::getNotifications($conn, $user_id, $user_role, 80);
$unreadCount = RentalService::getUnreadNotificationCount($conn, $user_id, $user_role);

$notifications = [];
foreach ($allNotifications as $n) {
    if ($filter === 'unread' && $n['status'] !== 'unread') {
        continue;
    }
    $notifications[] = $n;
}

// Function to format relative time
function get_time_ago($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . "m ago";
    if ($diff < 86400) return floor($diff / 3600) . "h ago";
    if ($diff < 604800) return floor($diff / 86400) . "d ago";
    return date("M j, Y H:i", $time);
}

// Helper for type styling
function get_notif_type_meta($type) {
    $map = [
        'new_booking' => ['icon' => '📋', 'color' => '#2563eb', 'bg' => '#eff6ff', 'label' => 'New Booking'],
        'booking_confirmed' => ['icon' => '✅', 'color' => '#16a34a', 'bg' => '#f0fdf4', 'label' => 'Confirmed'],
        'booking_declined' => ['icon' => '❌', 'color' => '#dc2626', 'bg' => '#fef2f2', 'label' => 'Declined'],
        'payment_verified' => ['icon' => '💳', 'color' => '#059669', 'bg' => '#ecfdf5', 'label' => 'Payment'],
        'upcoming_pickup' => ['icon' => '🚗', 'color' => '#d97706', 'bg' => '#fffbeb', 'label' => 'Pickup Reminder'],
        'upcoming_return' => ['icon' => '⏰', 'color' => '#7c3aed', 'bg' => '#f5f3ff', 'label' => 'Return Due'],
        'overdue_rental' => ['icon' => '🚨', 'color' => '#b91c1c', 'bg' => '#fef2f2', 'label' => 'OVERDUE'],
        'maint_conflict' => ['icon' => '⚠️', 'color' => '#b45309', 'bg' => '#fffbeb', 'label' => 'Maintenance Alert'],
        'booking_cancelled' => ['icon' => '🚫', 'color' => '#e11d48', 'bg' => '#fff1f2', 'label' => 'Cancelled'],
        'refund_processed' => ['icon' => '💵', 'color' => '#0284c7', 'bg' => '#f0f9ff', 'label' => 'Refund'],
        'handover_completed' => ['icon' => '🔑', 'color' => '#16a34a', 'bg' => '#f0fdf4', 'label' => 'Handover'],
        'return_completed' => ['icon' => '🏁', 'color' => '#0d9488', 'bg' => '#f0fdfa', 'label' => 'Return Recorded']
    ];
    return $map[$type] ?? ['icon' => 'ℹ️', 'color' => '#475569', 'bg' => '#f8fafc', 'label' => 'Notification'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Center - RentalKH</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .notif-container { max-width: 960px; margin: 0 auto; padding: 24px; }
        .notif-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
        .notif-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin-bottom: 12px; display: flex; gap: 16px; align-items: flex-start; transition: all 0.15s ease; position: relative; }
        .notif-card:hover { border-color: #cbd5e1; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .notif-card.unread { border-left: 4px solid #2563eb; background: #fafcff; }
        .notif-icon-box { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .notif-content { flex: 1; }
        .notif-title-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px; flex-wrap: wrap; gap: 8px; }
        .notif-title { font-weight: 700; font-size: 15px; color: #1e293b; margin: 0; }
        .notif-time { font-size: 12px; color: #64748b; }
        .notif-message { font-size: 13px; color: #475569; line-height: 1.5; margin: 0 0 8px 0; }
        .notif-footer { display: flex; align-items: center; gap: 12px; font-size: 12px; }
        .filter-tab { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; text-decoration: none; background: #f1f5f9; color: #475569; }
        .filter-tab.active { background: #1e293b; color: #fff; }
        .badge-count { background: #e53e3e; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 11px; font-weight: 700; }
        .btn-sm-action { background: #f8fafc; border: 1px solid #cbd5e1; color: #334155; padding: 4px 10px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
        .btn-sm-action:hover { background: #e2e8f0; }
        .btn-trigger { background: #0284c7; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .btn-trigger:hover { background: #0369a1; }
    </style>
</head>
<body>

<?php if ($is_staff): ?>
<div class="app-shell" style="grid-template-columns:240px minmax(0,1fr);">
    <!-- Sidebar -->
    <aside class="app-sidebar">
        <a class="logo" href="Service.php">Joul Rental</a>
        <nav class="side-nav">
            <a href="dashboard.php"><span class="dot"></span>Overview</a>
            <?php if (RentalService::checkPermission($conn, $user_id, 'manage_vehicles')): ?>
                <a href="Product.php"><span class="dot"></span>Products</a>
                <a href="product_edit.php"><span class="dot"></span>Product Management</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $user_id, 'manage_maintenance')): ?>
                <a href="maintenance.php"><span class="dot"></span>Maintenance</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $user_id, 'manage_categories')): ?>
                <a href="categories.php"><span class="dot"></span>Categories</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $user_id, 'manage_bookings')): ?>
                <a href="orders.php"><span class="dot"></span>Orders</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $user_id, 'view_finance_reports')): ?>
                <a href="finance.php"><span class="dot"></span>Finance</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $user_id, 'manage_customers') || RentalService::checkPermission($conn, $user_id, 'manage_staff_roles')): ?>
                <a href="users.php"><span class="dot"></span>Users</a>
            <?php endif; ?>
            <a class="active" href="notifications.php">
                <span class="dot"></span>Notifications
                <?php if ($unreadCount > 0): ?>
                    <span class="badge-count" style="margin-left:auto;"><?= $unreadCount ?></span>
                <?php endif; ?>
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="profile-avatar"><?= strtoupper(substr($_SESSION['username'],0,2)) ?></div>
            <div>
                <p class="name"><?= htmlspecialchars($_SESSION['username']) ?></p>
                <p class="role"><span style="text-transform: capitalize; font-size:11px; color:#64748b;"><?= htmlspecialchars($user_role) ?></span> | <a href="logout.php" style="color: #a72d25; text-decoration: none; font-weight: bold;">Logout</a></p>
            </div>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div>
                <a href="notifications.php" class="logo"><h1>Notifications & Operational Alerts</h1></a>
                <p class="subtitle"><?= date('l, F j, Y') ?> • Role: <strong><?= htmlspecialchars(strtoupper($user_role)) ?></strong></p>
            </div>
            <div class="topbar-actions">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="trigger_reminders">
                    <button type="submit" class="btn-trigger">🔄 Run Automated Reminder Scan</button>
                </form>
            </div>
        </header>
<?php else: ?>
    <!-- Customer Header -->
    <header class="site-header" style="background:#fff; border-bottom:1px solid #e2e8f0; padding:12px 24px;">
        <a class="logo" href="Service.php">Joul Rental</a>
        <nav class="site-nav">
            <a href="Service.php#catalog">Catalog</a>
            <a href="user_orders.php">My Bookings</a>
            <a href="notifications.php" style="font-weight:bold; color:#0284c7;">
                Notifications <?php if ($unreadCount > 0): ?><span class="badge-count"><?= $unreadCount ?></span><?php endif; ?>
            </a>
            <a href="profile.php">Profile</a>
        </nav>
        <div class="action-bar">
            <a href="logout.php"><button class="cart-button" type="button" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca;">Logout</button></a>
        </div>
    </header>
<?php endif; ?>

        <div class="notif-container">
            <?php if ($message): ?>
                <div class="alert alert-success" style="background:#dcfce7; color:#166534; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="background:#fee2e2; color:#991b1b; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Notification Header & Filter Controls -->
            <div class="notif-header">
                <div style="display:flex; gap:8px; align-items:center;">
                    <a href="notifications.php?filter=all" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
                        All Notifications (<?= count($allNotifications) ?>)
                    </a>
                    <a href="notifications.php?filter=unread" class="filter-tab <?= $filter === 'unread' ? 'active' : '' ?>">
                        Unread <?php if ($unreadCount > 0): ?><span class="badge-count"><?= $unreadCount ?></span><?php endif; ?>
                    </a>
                </div>

                <div style="display:flex; gap:8px;">
                    <?php if ($unreadCount > 0): ?>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn-sm-action">✓ Mark All as Read</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Notification List -->
            <?php if (empty($notifications)): ?>
                <div style="text-align:center; padding:60px 20px; background:#fff; border-radius:8px; border:1px solid #e2e8f0; color:#64748b;">
                    <div style="font-size:36px; margin-bottom:12px;">🔔</div>
                    <h3 style="margin:0 0 6px 0; color:#1e293b;">No notifications found</h3>
                    <p style="margin:0; font-size:13px;">You are completely up to date. Alerts and reminders will appear here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $n): ?>
                    <?php 
                        $meta = get_notif_type_meta($n['notification_type']);
                        $isUnread = ($n['status'] === 'unread');
                    ?>
                    <div class="notif-card <?= $isUnread ? 'unread' : '' ?>">
                        <div class="notif-icon-box" style="background:<?= $meta['bg'] ?>; color:<?= $meta['color'] ?>;">
                            <?= $meta['icon'] ?>
                        </div>
                        <div class="notif-content">
                            <div class="notif-title-row">
                                <h4 class="notif-title">
                                    <span style="font-size:11px; text-transform:uppercase; font-weight:700; color:<?= $meta['color'] ?>; margin-right:6px;"><?= $meta['label'] ?></span>
                                    <?= htmlspecialchars($n['title']) ?>
                                </h4>
                                <span class="notif-time"><?= get_time_ago($n['created_at']) ?> • <?= date('M j, g:i A', strtotime($n['created_at'])) ?></span>
                            </div>
                            <p class="notif-message"><?= nl2br(htmlspecialchars($n['message'])) ?></p>
                            
                            <div class="notif-footer">
                                <?php if (!empty($n['reference_id'])): ?>
                                    <?php if ($n['reference_type'] === 'order'): ?>
                                        <?php if ($is_staff): ?>
                                            <a href="order_detail.php?id=<?= (int)$n['reference_id'] ?>" class="btn-sm-action" style="color:#0284c7;">
                                                🔍 View Booking Order #<?= (int)$n['reference_id'] ?>
                                            </a>
                                        <?php else: ?>
                                            <a href="user_orders.php" class="btn-sm-action" style="color:#0284c7;">
                                                🔍 View My Bookings
                                            </a>
                                        <?php endif; ?>
                                    <?php elseif ($n['reference_type'] === 'maintenance' && $is_staff): ?>
                                        <a href="maintenance.php" class="btn-sm-action" style="color:#854d0e;">
                                            🛠️ View Maintenance Hub
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($isUnread): ?>
                                    <form method="POST" style="margin:0; display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                                        <button type="submit" class="btn-sm-action" style="color:#16a34a;">✓ Mark Read</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:11px;">✓ Read <?= $n['read_at'] ? date('M j, g:i A', strtotime($n['read_at'])) : '' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

<?php if ($is_staff): ?>
    </div>
</div>
<?php endif; ?>

</body>
</html>
