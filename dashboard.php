<?php
require_once 'config.php';
require_once 'rental_service.php';

RentalService::requirePermission($conn, 'view_dashboard');

$currentUserId = (int)$_SESSION['user_id'];
$currentUserRole = $_SESSION['role'] ?? RentalService::getUserRole($conn, $currentUserId);
$unread_sidebar_count = RentalService::getUnreadNotificationCount($conn, $currentUserId, $currentUserRole);

// Expire stale reservations
RentalService::expireStaleBookings($conn);

// Fetch operational metrics
$operational = RentalService::getOperationalDashboardMetrics($conn);

// --- STATS ---
$revenue = $conn->query("SELECT SUM(total_amount) AS total FROM orders WHERE order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status IN ('Approved', 'Active', 'Completed')")->fetch_assoc()['total'] ?? 0;
$orders = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['cnt'] ?? 0;
$new_customers = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_admin = 0")->fetch_assoc()['cnt'] ?? 0;
$avg_order = $conn->query("SELECT AVG(total_amount) AS avg FROM orders WHERE order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status IN ('Approved', 'Active', 'Completed')")->fetch_assoc()['avg'] ?? 0;

// --- WEEKLY CHART ---
$weekly = $conn->query("
    SELECT DATE(order_date) AS day, SUM(total_amount) AS daily_total
    FROM orders
    WHERE order_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status IN ('Approved', 'Active', 'Completed')
    GROUP BY DATE(order_date)
");
$chart = [];
while ($row = $weekly->fetch_assoc()) {
    $chart[date('D', strtotime($row['day']))] = $row['daily_total'];
}
// Fill missing days with 0
$days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$heights = [];
$maxVal = !empty($chart) ? max($chart) : 1;
foreach ($days as $d) {
    $heights[] = isset($chart[$d]) ? round(($chart[$d] / $maxVal) * 100) : 0;
}

// --- RECENT ORDERS ---
$recent_orders = $conn->query("
    SELECT o.id, o.booking_ref, u.name AS customer, o.total_amount, o.status
    FROM orders o
    JOIN users u ON o.user_id = u.id
    ORDER BY o.order_date DESC
    LIMIT 5
");

// --- TOP PRODUCTS ---
$top_products = $conn->query("
    SELECT p.name, SUM(oi.quantity) AS units, SUM(oi.quantity * oi.price) AS revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY p.id
    ORDER BY units DESC
    LIMIT 3
");

// --- RECENT ACTIVITY ---
$activity = $conn->query("
    SELECT 'New order' AS action, o.id AS order_id, u.name AS customer, o.order_date AS time
    FROM orders o
    JOIN users u ON o.user_id = u.id
    ORDER BY o.order_date DESC
    LIMIT 4
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Operations Overview</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .ops-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 24px; }
        .ops-card { background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 14px 18px; text-decoration: none; color: inherit; display: flex; flex-direction: column; transition: all 0.15s ease; }
        .ops-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .ops-card-label { font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; margin-bottom: 4px; }
        .ops-card-val { font-size: 22px; font-weight: 800; color: #0f172a; }
        .ops-card--alert { border-color: #fecaca; background: #fff5f5; }
        .ops-card--alert .ops-card-val { color: #dc2626; }
        .ops-card--warn { border-color: #fef08a; background: #fefce8; }
        .ops-card--warn .ops-card-val { color: #854d0e; }
        .ops-card--info { border-color: #bfdbfe; background: #eff6ff; }
        .ops-card--info .ops-card-val { color: #1d4ed8; }
        .badge-count { background: #e53e3e; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 11px; font-weight: 700; }
    </style>
</head>
<body>
<div class="app-shell" style="grid-template-columns:240px minmax(0,1fr);">
    <!-- Sidebar -->
    <aside class="app-sidebar">
        <a class="logo" href="Service.php">Joul Rental</a>
        <nav class="side-nav">
            <a class="active" href="dashboard.php"><span class="dot"></span>Overview</a>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_vehicles')): ?>
                <a href="Product.php"><span class="dot"></span>Products</a>
                <a href="product_edit.php"><span class="dot"></span>Product Management</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_maintenance')): ?>
                <a href="maintenance.php"><span class="dot"></span>Maintenance</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_categories')): ?>
                <a href="categories.php"><span class="dot"></span>Categories</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_bookings')): ?>
                <a href="orders.php"><span class="dot"></span>Orders</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'view_finance_reports')): ?>
                <a href="finance.php"><span class="dot"></span>Finance</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_customers') || RentalService::checkPermission($conn, $currentUserId, 'manage_staff_roles')): ?>
                <a href="users.php"><span class="dot"></span>Users</a>
            <?php endif; ?>
            <a href="notifications.php">
                <span class="dot"></span>Notifications
                <?php if ($unread_sidebar_count > 0): ?>
                    <span class="badge-count" style="margin-left:auto;"><?= $unread_sidebar_count ?></span>
                <?php endif; ?>
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="profile-avatar"><?= strtoupper(substr($_SESSION['username'],0,2)) ?></div>
            <div>
                <p class="name"><?= htmlspecialchars($_SESSION['username']) ?></p>
                <p class="role"><span style="text-transform: capitalize; font-size:11px; color:#64748b;"><?= htmlspecialchars($currentUserRole) ?></span> | <a href="logout.php" style="color: #a72d25; text-decoration: none; font-weight: bold;">Logout</a></p>
            </div>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div>
                <a href="dashboard.php" class="logo"> <h1>Operations Overview</h1> </a>
                <p class="subtitle"><?= date('l, F j, Y') ?> • Role: <strong><?= htmlspecialchars(strtoupper($currentUserRole)) ?></strong></p>
            </div>
            <div class="topbar-actions" style="display:flex; align-items:center; gap:10px;">
                <a href="notifications.php" style="text-decoration:none; position:relative; background:#f1f5f9; padding:8px 14px; border-radius:6px; font-size:13px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:6px;">
                    🔔 Notifications
                    <?php if ($unread_sidebar_count > 0): ?>
                        <span class="badge-count"><?= $unread_sidebar_count ?></span>
                    <?php endif; ?>
                </a>
                <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_vehicles')): ?>
                    <a href="Product.php" class="primary-link">Inventory</a>
                <?php endif; ?>
            </div>
        </header>

        <main class="dashboard-body">
            <!-- Operational Control Strip -->
            <div class="ops-strip">
                <a href="orders.php?status=upcoming_pickups" class="ops-card ops-card--info">
                    <span class="ops-card-label">🚗 Upcoming Pickups (24h)</span>
                    <span class="ops-card-val"><?= $operational['upcoming_pickups'] ?></span>
                </a>
                <a href="orders.php?status=Active" class="ops-card">
                    <span class="ops-card-label">🛣️ Active Rentals On-Road</span>
                    <span class="ops-card-val"><?= $operational['active_rentals'] ?></span>
                </a>
                <a href="orders.php?status=overdue_returns" class="ops-card <?= $operational['overdue_returns'] > 0 ? 'ops-card--alert' : '' ?>">
                    <span class="ops-card-label">⚠️ Overdue Returns</span>
                    <span class="ops-card-val"><?= $operational['overdue_returns'] ?></span>
                </a>
                <a href="orders.php?status=maint_conflict" class="ops-card <?= $operational['maintenance_conflicts'] > 0 ? 'ops-card--alert' : '' ?>">
                    <span class="ops-card-label">🚨 Maintenance Conflicts</span>
                    <span class="ops-card-val"><?= $operational['maintenance_conflicts'] ?></span>
                </a>
                <a href="maintenance.php" class="ops-card <?= $operational['vehicles_under_maintenance'] > 0 ? 'ops-card--warn' : '' ?>">
                    <span class="ops-card-label">🛠️ Fleet In Service</span>
                    <span class="ops-card-val"><?= $operational['vehicles_under_maintenance'] ?></span>
                </a>
            </div>

            <!-- Stats -->
            <section class="stat-grid">
                <div class="stat-card">
                    <div class="stat-card-head"><span>Revenue (30d)</span></div>
                    <strong>$<?= number_format($revenue, 2) ?></strong>
                    <span class="delta delta--up">Last 30 days</span>
                </div>
                <div class="stat-card">
                    <div class="stat-card-head"><span>Orders</span></div>
                    <strong><?= $orders ?></strong>
                    <span class="delta delta--up">Last 30 days</span>
                </div>
                <div class="stat-card">
                    <div class="stat-card-head"><span>New Customers</span></div>
                    <strong><?= $new_customers ?></strong>
                    <span class="delta delta--up">Last 30 days</span>
                </div>
                <div class="stat-card">
                    <div class="stat-card-head"><span>Avg. Order Value</span></div>
                    <strong>$<?= number_format($avg_order, 2) ?></strong>
                    <span class="delta delta--up">Last 30 days</span>
                </div>
            </section>

            <!-- Two column layout -->
            <div class="dashboard-grid">
                <div>
                    <!-- Revenue chart -->
                    <section class="panel">
                        <div class="panel-head"><h2>Revenue This Week</h2></div>
                        <div class="bar-chart">
                            <?php foreach ($days as $i => $day): ?>
                            <div class="bar-col">
                                <div class="bar" style="height: <?= $heights[$i] ?>%;"></div>
                                <span><?= $day ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <!-- Recent orders -->
                    <section class="panel">
                        <div class="panel-head"><h2>Recent Orders</h2><a href="orders.php">View all</a></div>
                        <table class="data-table">
                            <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Total</th></tr></thead>
                            <tbody>
                            <?php while ($row = $recent_orders->fetch_assoc()): ?>
                            <tr>
                                <td class="order-id">
                                    <a href="order_detail.php?id=<?= $row['id'] ?>" style="font-family:monospace; font-weight:bold;">
                                        <?= htmlspecialchars($row['booking_ref'] ?: '#' . $row['id']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($row['customer']) ?></td>
                                <td><span class="status-pill status-pill--<?= strtolower($row['status']) ?>"><?= ucfirst($row['status']) ?></span></td>
                                <td>$<?= number_format($row['total_amount'], 2) ?></td>
                            </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </section>
                </div>

                <!-- Sidebar column -->
                <aside>
                    <section class="panel">
                        <div class="panel-head"><h2>Top Products</h2></div>
                        <?php while ($p = $top_products->fetch_assoc()): ?>
                        <div class="product-list-item">
                            <div class="product-list-thumb" style="background:var(--color-sage);"></div>
                            <div>
                                <p class="name"><?= htmlspecialchars($p['name']) ?></p>
                                <p class="units"><?= $p['units'] ?> units sold</p>
                            </div>
                            <span class="revenue">$<?= number_format($p['revenue'], 2) ?></span>
                        </div>
                        <?php endwhile; ?>
                    </section>

                    <section class="panel">
                        <div class="panel-head"><h2>Recent Activity</h2></div>
                        <?php while ($act = $activity->fetch_assoc()): ?>
                        <div class="activity-item">
                            <span class="activity-dot"></span>
                            <div>
                                <p><?= $act['action'] ?> <strong>#<?= $act['order_id'] ?></strong> by <?= htmlspecialchars($act['customer']) ?></p>
                                <time><?= time_elapsed_string($act['time']) ?></time>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </section>
                </aside>
            </div>
        </main>
    </div>
</div>

</body>
</html>
