<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit();
}

// Expire stale reservations
RentalService::expireStaleBookings($conn);

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
    <title>Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <!-- Sidebar -->
    <aside class="app-sidebar">
        <a class="logo" href="Service.php">Joul Rental</a>
        <nav class="side-nav">
            <a class="active" href="dashboard.php"><span class="dot"></span>Overview</a>
            <a href="Product.php"><span class="dot"></span>Products</a>
            <a href="product_edit.php"><span class="dot"></span>Product Management</a>
            <a href="maintenance.php"><span class="dot"></span>Maintenance</a>
            <a href="categories.php"><span class="dot"></span>Categories</a>
            <a href="orders.php"><span class="dot"></span>Orders</a>
            <a href="users.php"><span class="dot"></span>Users</a>
        </nav>
        <div class="sidebar-footer">
            <div class="profile-avatar"><?= strtoupper(substr($_SESSION['username'],0,2)) ?></div>
            <div>
                <p class="name"><?= htmlspecialchars($_SESSION['username']) ?></p>
                <p class="role"><a href="logout.php" style="color: #a72d25; text-decoration: none; font-weight: bold;">Logout</a></p>
            </div>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div>
                <a href="dashboard.php" class="logo"> <h1>Overview</h1> </a>
                <p class="subtitle"><?= date('l, F j, Y') ?></p>
            </div>
            <div class="topbar-actions">

                <a href="Product.php" class="primary-link">Add product</a>
            </div>
        </header>

        <main class="dashboard-body">
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
