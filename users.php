<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['is_admin'] != 1) {
    header("Location: login.php");
    exit();
}

$message = '';
$selectedCustomer = null;
$customerOrders = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request.');
    }
    
    $target_id = (int)$_POST['user_id'];
    if ($target_id === $_SESSION['user_id']) {
        $message = "You cannot disable your own admin account.";
    } else {
        $new_status = ($_POST['action'] === 'enable') ? 1 : 0;
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $target_id);
        if ($stmt->execute()) {
            $message = "Customer status updated successfully.";
        }
    }
}

// Check if viewing specific customer history
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId > 0) {
    $cStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $cStmt->bind_param("i", $viewId);
    $cStmt->execute();
    $selectedCustomer = $cStmt->get_result()->fetch_assoc();

    if ($selectedCustomer) {
        $oStmt = $conn->prepare("
            SELECT o.*, 
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
            FROM orders o 
            WHERE o.user_id = ? 
            ORDER BY o.order_date DESC
        ");
        $oStmt->bind_param("i", $viewId);
        $oStmt->execute();
        $customerOrders = $oStmt->get_result();
    }
}

// Fetch all users
$query = "
    SELECT u.id, u.name, u.email, u.phone, u.driver_license, u.id_card_number, u.is_admin, u.is_active, u.created_at,
           COUNT(o.id) as total_orders,
           COALESCE(SUM(CASE WHEN o.status NOT IN ('Cancelled', 'Declined', 'Expired') THEN o.total_amount ELSE 0 END), 0) as total_spent
    FROM users u
    LEFT JOIN orders o ON u.id = o.user_id
    GROUP BY u.id
    ORDER BY u.is_admin DESC, u.id DESC
";
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Management</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-active { background: #dfe7d8; color: #2d5a27; }
        .badge-disabled { background: #fde8e8; color: #9b1c1c; }
        .badge-admin { background: #e1effe; color: #1e429f; }
        .status-pill--active { background: #e0f2fe; color: #0284c7; }
        .status-pill--completed { background: #dcfce7; color: #166534; }
        .status-pill--expired { background: #f3f4f6; color: #6b7280; }
        .status-pill--cancelled { background: #fee2e2; color: #b91c1c; }
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
            <a href="orders.php"><span class="dot"></span>Orders</a>
            <a class="active" href="users.php"><span class="dot"></span>Users</a>
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
                <a href="users.php" class="logo"><h1>Customer & User Management</h1></a>
                <p class="subtitle"><?= date('l, F j, Y') ?></p>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-success" style="margin: 20px; padding: 12px; background: #dfe7d8; border-radius: 6px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($selectedCustomer): ?>
            <!-- Customer Detail Card -->
            <section class="panel" style="margin: 20px; padding: 20px; border-left: 4px solid #a72d25;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div>
                        <h2 style="margin:0 0 6px 0;"><?= htmlspecialchars($selectedCustomer['name']) ?></h2>
                        <div style="font-size:13px; color:#666;">
                            Email: <strong><?= htmlspecialchars($selectedCustomer['email'] ?: 'N/A') ?></strong> | 
                            Phone: <strong><?= htmlspecialchars($selectedCustomer['phone'] ?: 'N/A') ?></strong> | 
                            License: <strong><?= htmlspecialchars($selectedCustomer['driver_license'] ?: 'N/A') ?></strong> | 
                            National ID: <strong><?= htmlspecialchars($selectedCustomer['id_card_number'] ?: 'N/A') ?></strong>
                        </div>
                        <?php if ($selectedCustomer['address']): ?>
                            <div style="font-size:13px; color:#555; margin-top:4px;">Address: <?= htmlspecialchars($selectedCustomer['address']) ?></div>
                        <?php endif; ?>
                    </div>
                    <a href="users.php" style="color:#666; font-size:13px; text-decoration:underline;">Close Details</a>
                </div>

                <h3 style="margin-top:20px; font-size:16px;">Rental History for <?= htmlspecialchars($selectedCustomer['name']) ?></h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Booking Ref</th>
                            <th>Date</th>
                            <th>Rental Window</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($customerOrders && $customerOrders->num_rows > 0): ?>
                        <?php while ($co = $customerOrders->fetch_assoc()): ?>
                            <tr>
                                <td><a href="order_detail.php?id=<?= $co['id'] ?>" style="font-family:monospace; font-weight:bold;"><?= htmlspecialchars($co['booking_ref'] ?: '#' . $co['id']) ?></a></td>
                                <td><?= date('M j, Y', strtotime($co['order_date'])) ?></td>
                                <td style="font-size:12px;">
                                    <?= date('M j', strtotime($co['start_date'])) ?> &rarr; <?= date('M j, Y', strtotime($co['end_date'])) ?>
                                </td>
                                <td>$<?= number_format($co['total_amount'], 2) ?></td>
                                <td>
                                    <span class="status-pill status-pill--<?= strtolower($co['status']) ?>">
                                        <?= htmlspecialchars($co['status']) ?>
                                    </span>
                                </td>
                                <td><a href="order_detail.php?id=<?= $co['id'] ?>">View</a></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center; padding:15px; color:#666;">No bookings found for this customer.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <section class="panel" style="margin: 20px;">
            <div class="panel-head"><h2>All Registered Users & Customers</h2></div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Contact / Phone</th>
                        <th>Driver License</th>
                        <th>Role</th>
                        <th>Bookings</th>
                        <th>Total Spent</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($row['name']) ?></strong>
                            <div style="font-size:12px; color:#666;"><?= htmlspecialchars($row['email'] ?: 'No email') ?></div>
                        </td>
                        <td>
                            <?= htmlspecialchars($row['phone'] ?: 'N/A') ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($row['driver_license'] ?: 'N/A') ?>
                        </td>
                        <td>
                            <?php if ($row['is_admin']): ?>
                                <span class="badge badge-admin">Admin</span>
                            <?php else: ?>
                                <span class="badge" style="background:#f1f5f9; color:#475569;">Customer</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="users.php?view=<?= $row['id'] ?>" style="font-weight:bold;">
                                <?= $row['total_orders'] ?> bookings
                            </a>
                        </td>
                        <td>$<?= number_format($row['total_spent'], 2) ?></td>
                        <td>
                            <span class="badge badge-<?= $row['is_active'] ? 'active' : 'disabled' ?>">
                                <?= $row['is_active'] ? 'Active' : 'Disabled' ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="users.php?view=<?= $row['id'] ?>" style="margin-right:8px; font-size:12px;">History</a>
                            <?php if ($row['id'] !== $_SESSION['user_id']): ?>
                                <form method="post" style="display:inline-block;">
                                    <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="<?= $row['is_active'] ? 'disable' : 'enable' ?>">
                                    <button type="submit" 
                                            class="<?= $row['is_active'] ? 'btn-decline' : 'btn-approve' ?>"
                                            style="padding:4px 8px; font-size:11px;"
                                            onclick="return confirm('<?= $row['is_active'] ? 'Disable' : 'Enable' ?> this user?')">
                                        <?= $row['is_active'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="color:#aaa; font-size:11px;">(You)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </section>
    </div>
</div>
</body>
</html>
