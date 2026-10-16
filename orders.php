<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit();
}

// Expire any stale pending bookings automatically
RentalService::expireStaleBookings($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['action'])) {
    // CSRF validation
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request. Please go back and try again.');
    }

    $order_id = (int)$_POST['order_id'];
    $action   = $_POST['action'];

    $statusMap = [
        'approve'  => 'Approved',
        'activate' => 'Active',
        'complete' => 'Completed',
        'decline'  => 'Declined',
        'cancel'   => 'Cancelled'
    ];

    if (isset($statusMap[$action])) {
        $new_status = $statusMap[$action];
        $res = RentalService::updateBookingStatus($conn, $order_id, $new_status);
        if ($res['success']) {
            $_SESSION['message'] = "Booking #$order_id status changed to $new_status.";
        } else {
            $_SESSION['message'] = "Error updating booking #$order_id: " . $res['message'];
        }
    }
    header("Location: orders.php");   
    exit();
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$statusFilter = $_GET['status'] ?? 'all';
$whereSql = "1=1";
if ($statusFilter !== 'all') {
    $cleanStatus = $conn->real_escape_string($statusFilter);
    $whereSql = "o.status = '$cleanStatus'";
}

// ---------- Fetch orders with details ----------
$orders_query = $conn->query("
    SELECT o.id, o.booking_ref, u.name AS customer, u.email AS customer_email, u.phone AS customer_phone,
           o.total_amount, o.subtotal, o.deposit_amount, o.status, o.order_date,
           o.start_date, o.start_time, o.end_date, o.end_time, o.expires_at,
           (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE $whereSql
    ORDER BY o.order_date DESC
    LIMIT 50
");

// Count stats
$statsRes = $conn->query("
    SELECT status, COUNT(*) AS cnt 
    FROM orders 
    GROUP BY status
");
$counts = ['Pending' => 0, 'Approved' => 0, 'Active' => 0, 'Completed' => 0, 'Cancelled' => 0, 'Declined' => 0, 'Expired' => 0];
while ($row = $statsRes->fetch_assoc()) {
    $counts[$row['status']] = (int)$row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Management</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .status-pill--active { background: #e0f2fe; color: #0284c7; }
        .status-pill--completed { background: #dcfce7; color: #166534; }
        .status-pill--expired { background: #f3f4f6; color: #6b7280; }
        .status-pill--cancelled { background: #fee2e2; color: #b91c1c; }
        .filter-chip { display: inline-flex; align-items: center; padding: 6px 14px; border-radius: 20px; font-size: 13px; text-decoration: none; font-weight: 600; background: #f3f4f6; color: #333; margin-right: 6px; margin-bottom: 6px; }
        .filter-chip.active { background: #222; color: #fff; }
        .btn-action { padding: 4px 10px; border-radius: 4px; border: none; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-activate { background: #0284c7; color: #fff; }
        .btn-complete { background: #16a34a; color: #fff; }
    </style>
</head>
<body>
<div class="app-shell" style="grid-template-columns:240px minmax(0,1fr);">
    <aside class="app-sidebar">
        <a class="logo" href="Service.php">Joul Rental</a>
        <nav class="side-nav">
            <a href="dashboard.php"><span class="dot"></span>Overview</a>
            <a href="Product.php"><span class="dot"></span>Products</a>
            <a href="product_edit.php"><span class="dot"></span>Product Management</a>
            <a href="maintenance.php"><span class="dot"></span>Maintenance</a>
            <a href="categories.php"><span class="dot"></span>Categories</a>
            <a class="active" href="orders.php"><span class="dot"></span>Orders</a>
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
                <a href="orders.php" class="logo"><h1>Booking Management</h1></a>
                <p class="subtitle"><?= date('l, F j, Y') ?></p>
            </div>
        </header>

        <!-- Flash message -->
        <?php if ($message): ?>
            <div class="alert alert-success" style="margin: 20px; padding: 12px; background: #dfe7d8; border-radius: 6px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Filter Chips -->
        <div style="margin: 20px 20px 0 20px; display: flex; flex-wrap: wrap;">
            <a href="orders.php?status=all" class="filter-chip <?= $statusFilter === 'all' ? 'active' : '' ?>">
                All Bookings (<?= array_sum($counts) ?>)
            </a>
            <a href="orders.php?status=Pending" class="filter-chip <?= $statusFilter === 'Pending' ? 'active' : '' ?>">
                Pending (<?= $counts['Pending'] ?>)
            </a>
            <a href="orders.php?status=Approved" class="filter-chip <?= $statusFilter === 'Approved' ? 'active' : '' ?>">
                Approved (<?= $counts['Approved'] ?>)
            </a>
            <a href="orders.php?status=Active" class="filter-chip <?= $statusFilter === 'Active' ? 'active' : '' ?>">
                Active Rental (<?= $counts['Active'] ?>)
            </a>
            <a href="orders.php?status=Completed" class="filter-chip <?= $statusFilter === 'Completed' ? 'active' : '' ?>">
                Completed (<?= $counts['Completed'] ?>)
            </a>
            <a href="orders.php?status=Cancelled" class="filter-chip <?= $statusFilter === 'Cancelled' ? 'active' : '' ?>">
                Cancelled (<?= $counts['Cancelled'] ?>)
            </a>
            <a href="orders.php?status=Declined" class="filter-chip <?= $statusFilter === 'Declined' ? 'active' : '' ?>">
                Declined (<?= $counts['Declined'] ?>)
            </a>
            <a href="orders.php?status=Expired" class="filter-chip <?= $statusFilter === 'Expired' ? 'active' : '' ?>">
                Expired (<?= $counts['Expired'] ?>)
            </a>
        </div>

        <section class="panel" style="margin: 20px;">
            <div class="panel-head"><h2>Rental Bookings</h2></div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Booking Ref</th>
                        <th>Customer</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($orders_query && $orders_query->num_rows > 0): ?>
                    <?php while ($row = $orders_query->fetch_assoc()): ?>
                        <tr>
                            <td class="order-id">
                                <a href="order_detail.php?id=<?= $row['id'] ?>" style="font-weight: bold; font-family: monospace;">
                                    <?= htmlspecialchars($row['booking_ref'] ?: '#' . $row['id']) ?>
                                </a>
                                <div style="font-size: 11px; color: #888;">
                                    Placed <?= date('M j, Y', strtotime($row['order_date'])) ?>
                                </div>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($row['customer']) ?></strong>
                                <div style="font-size: 12px; color: #666;"><?= htmlspecialchars($row['customer_email']) ?></div>
                                <?php if ($row['customer_phone']): ?>
                                    <div style="font-size: 11px; color: #888;">Tel: <?= htmlspecialchars($row['customer_phone']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px;">
                                <?php if ($row['start_date'] && $row['end_date']): ?>
                                    <strong><?= date('M j, Y', strtotime($row['start_date'])) ?></strong> (<?= date('g:i A', strtotime($row['start_time'])) ?>)
                                    <br>&rarr; <strong><?= date('M j, Y', strtotime($row['end_date'])) ?></strong> (<?= date('g:i A', strtotime($row['end_time'])) ?>)
                                <?php else: ?>
                                    <span style="color:#888;">No dates specified</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-pill status-pill--<?= strtolower($row['status']) ?>">
                                    <?= htmlspecialchars($row['status']) ?>
                                </span>
                                <?php if ($row['status'] === 'Pending' && $row['expires_at']): ?>
                                    <div style="font-size: 10px; color: #854d0e; margin-top: 3px;">
                                        Expires: <?= date('M j, g:i A', strtotime($row['expires_at'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong>$<?= number_format($row['total_amount'], 2) ?></strong>
                                <?php if ($row['deposit_amount'] > 0): ?>
                                    <div style="font-size: 11px; color: #666;">(Deposit: $<?= number_format($row['deposit_amount'], 2) ?>)</div>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap;">
                                <a href="order_detail.php?id=<?= $row['id'] ?>" style="margin-right: 8px; font-size:12px;">View</a>

                                <form method="post" style="display: inline-flex; gap: 4px; vertical-align: middle;">
                                    <input type="hidden" name="order_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                                    <?php if ($row['status'] === 'Pending'): ?>
                                        <button type="submit" name="action" value="approve" class="btn-approve" onclick="return confirm('Approve booking?')">Approve</button>
                                        <button type="submit" name="action" value="decline" class="btn-decline" onclick="return confirm('Decline booking?')">Decline</button>
                                    <?php elseif ($row['status'] === 'Approved'): ?>
                                        <button type="submit" name="action" value="activate" class="btn-action btn-activate" onclick="return confirm('Mark vehicle as picked up by customer?')">Pick Up (Active)</button>
                                        <button type="submit" name="action" value="cancel" class="btn-decline" onclick="return confirm('Cancel approved booking?')">Cancel</button>
                                    <?php elseif ($row['status'] === 'Active'): ?>
                                        <button type="submit" name="action" value="complete" class="btn-action btn-complete" onclick="return confirm('Confirm vehicle returned in good order and complete booking?')">Return (Complete)</button>
                                    <?php else: ?>
                                        <span style="color: var(--color-muted); font-size: 12px;">Archived</span>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 30px; color: #666;">No bookings found for the selected status.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>
</div>
</body>
</html>