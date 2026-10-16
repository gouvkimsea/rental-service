<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['action'])) {
    // CSRF validation
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request. Please go back and try again.');
    }

    $order_id = (int)$_POST['order_id'];
    $action   = $_POST['action'];
    $new_status = ($action === 'approve') ? 'Approved' : 'Declined';

    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $order_id);

    if ($stmt->execute()) {
        $_SESSION['message'] = "Order #$order_id has been $new_status.";
    } else {
        $_SESSION['message'] = "Error updating order #$order_id.";
    }
    header("Location: orders.php");   
    exit();
}
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ---------- Fetch recent orders ----------
$recent_orders = $conn->query("
    SELECT o.id, u.name AS customer, o.total_amount, o.status
    FROM orders o
    JOIN users u ON o.user_id = u.id
    ORDER BY o.order_date DESC
    LIMIT 15
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell" style="grid-template-columns:240px minmax(0,1fr);">
    <aside class="app-sidebar">
        <a class="logo" href="Service.php">Joul Rental</a>
        <nav class="side-nav">
            <a href="dashboard.php"><span class="dot"></span>Overview</a>
            <a href="Product.php"><span class="dot"></span>Products</a>
            <a href="product_edit.php"><span class="dot"></span>Product Management</a>
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
            <a href="orders.php" class="logo"><h1>Orders</h1></a>
            <p class="subtitle"><?= date('l, F j, Y') ?></p>
        </header>

        <!-- Flash message -->
        <?php if ($message): ?>
            <div class="alert alert-success" style="margin: 20px; padding: 12px; background: #dfe7d8; border-radius: 6px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-head"><h2>Recent Orders</h2></div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $recent_orders->fetch_assoc()): ?>
                    <tr>
                        <td class="order-id"><a href="order_detail.php?id=<?= $row['id'] ?>">#<?= $row['id'] ?></a></td>
                        <td><?= htmlspecialchars($row['customer']) ?></td>
                        <td>
                            <span class="status-pill status-pill--<?= strtolower($row['status']) ?>">
                                <?= ucfirst($row['status']) ?>
                            </span>
                        </td>
                        <td>$<?= number_format($row['total_amount'], 2) ?></td>
                        <td>
                            <?php if (strtolower($row['status']) === 'pending'): ?>
                                <form method="post" style="display: flex; gap: 8px;">
                                    <input type="hidden" name="order_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <button type="submit" name="action" value="approve" class="btn-approve"
                                        onclick="return confirm('Approve order #<?= $row['id'] ?>?')">
                                        Approve
                                    </button>
                                    <button type="submit" name="action" value="decline" class="btn-decline"
                                        onclick="return confirm('Decline order #<?= $row['id'] ?>?')">
                                        Decline
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="color: var(--color-muted); font-size: 13px;">No action</span>
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