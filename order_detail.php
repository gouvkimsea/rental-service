<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? 0;

// Fetch order
if ($is_admin) {
    $stmt = $conn->prepare("SELECT o.*, u.name as customer_name, u.email as customer_email FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
    $stmt->bind_param("i", $order_id);
} else {
    $stmt = $conn->prepare("SELECT o.*, u.name as customer_name, u.email as customer_email FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ? AND o.user_id = ?");
    $stmt->bind_param("ii", $order_id, $user_id);
}
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Order not found or you do not have permission to view it.");
}

// Fetch items
$items_stmt = $conn->prepare("
    SELECT oi.*, p.name as product_name, p.image_name
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$items_stmt->bind_param("i", $order_id);
$items_stmt->execute();
$items = $items_stmt->get_result();

$start_time = strtotime($order['start_date'] ?? '');
$end_time = strtotime($order['end_date'] ?? '');
$days = 1;
if ($start_time && $end_time) {
    $days = ceil(($end_time - $start_time) / 86400) + 1;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Detail #<?= $order['id'] ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .detail-container { max-width: 800px; margin: 40px auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .info-box { background: #f9f9f9; padding: 15px; border-radius: 6px; }
        .item-list { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .item-list th, .item-list td { text-align: left; padding: 12px; border-bottom: 1px solid #eee; }
        .item-list th { background: #f1f1f1; }
        .item-image { width: 60px; height: auto; border-radius: 4px; vertical-align: middle; margin-right: 10px; }
        .total-box { text-align: right; font-size: 1.2em; font-weight: bold; padding-top: 20px; border-top: 2px solid #ddd; }
    </style>
</head>
<body>
<div class="detail-container">
    <h2>Order #<?= $order['id'] ?> Details</h2>
    <div style="margin-bottom: 20px;">
        <span class="status-pill status-pill--<?= strtolower($order['status']) ?>">
            <?= htmlspecialchars($order['status']) ?>
        </span>
    </div>

    <div class="info-grid">
        <div class="info-box">
            <h3>Customer Info</h3>
            <p><strong>Name:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($order['customer_email']) ?></p>
            <p><strong>Order Date:</strong> <?= date('M j, Y g:i A', strtotime($order['order_date'])) ?></p>
        </div>
        <div class="info-box">
            <h3>Rental Period</h3>
            <?php if ($order['start_date'] && $order['end_date']): ?>
                <p><strong>Start Date:</strong> <?= date('M j, Y', $start_time) ?></p>
                <p><strong>End Date:</strong> <?= date('M j, Y', $end_time) ?></p>
                <p><strong>Duration:</strong> <?= $days ?> day(s)</p>
            <?php else: ?>
                <p>No dates specified</p>
            <?php endif; ?>
        </div>
    </div>

    <h3>Reserved Vehicles</h3>
    <table class="item-list">
        <thead>
            <tr>
                <th>Vehicle</th>
                <th>Daily Rate</th>
                <th>Quantity</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($item = $items->fetch_assoc()): ?>
                <?php $subtotal = ($item['price'] * $item['quantity']) * $days; ?>
                <tr>
                    <td>
                        <?php if($item['image_name']): ?>
                            <img src="image/<?= htmlspecialchars($item['image_name']) ?>" class="item-image" alt="vehicle">
                        <?php endif; ?>
                        <?= htmlspecialchars($item['product_name']) ?>
                    </td>
                    <td>$<?= number_format($item['price'], 2) ?>/day</td>
                    <td><?= $item['quantity'] ?></td>
                    <td>$<?= number_format($subtotal, 2) ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div class="total-box">
        Grand Total: $<?= number_format($order['total_amount'], 2) ?>
    </div>

    <div style="margin-top: 30px; text-align: center;">
        <?php if ($is_admin): ?>
            <a href="orders.php" class="submit-btn" style="text-decoration:none; display:inline-block;">Back to Orders</a>
        <?php else: ?>
            <a href="user_orders.php" class="submit-btn" style="text-decoration:none; display:inline-block;">Back to My Orders</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
