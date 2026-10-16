<?php
// order_detail.php - Detailed Booking Breakdown and Audit
require_once 'config.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = (int)$_SESSION['user_id'];
$is_admin = !empty($_SESSION['is_admin']);

// Fetch order with user information
if ($is_admin) {
    $stmt = $conn->prepare("
        SELECT o.*, u.name as customer_name, u.email as customer_email, 
               u.phone as customer_phone, u.address as customer_address, 
               u.id_card_number, u.driver_license
        FROM orders o 
        JOIN users u ON o.user_id = u.id 
        WHERE o.id = ?
    ");
    $stmt->bind_param("i", $order_id);
} else {
    $stmt = $conn->prepare("
        SELECT o.*, u.name as customer_name, u.email as customer_email, 
               u.phone as customer_phone, u.address as customer_address, 
               u.id_card_number, u.driver_license
        FROM orders o 
        JOIN users u ON o.user_id = u.id 
        WHERE o.id = ? AND o.user_id = ?
    ");
    $stmt->bind_param("ii", $order_id, $user_id);
}
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Booking not found or you do not have permission to view it.");
}

// Handle Admin status change from detail page
$msg = '';
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    $newStatus = $_POST['action'];
    $res = RentalService::updateBookingStatus($conn, $order_id, $newStatus);
    if ($res['success']) {
        $order['status'] = $newStatus;
        $msg = "Booking status updated to $newStatus.";
    } else {
        $msg = $res['message'];
    }
}

// Fetch items
$items_stmt = $conn->prepare("
    SELECT oi.*, p.name as product_name, p.reg_number, p.image_name, c.name as category_name
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE oi.order_id = ?
");
$items_stmt->bind_param("i", $order_id);
$items_stmt->execute();
$items = $items_stmt->get_result();

$start_time = strtotime(($order['start_date'] ?? '') . ' ' . ($order['start_time'] ?? '00:00:00'));
$end_time   = strtotime(($order['end_date'] ?? '') . ' ' . ($order['end_time'] ?? '00:00:00'));
$days = 1;
if ($start_time && $end_time && $end_time > $start_time) {
    $days = max(1, ceil(($end_time - $start_time) / 86400));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking <?= htmlspecialchars($order['booking_ref'] ?: '#' . $order['id']) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .detail-container { max-width: 900px; margin: 40px auto; padding: 25px; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
        .info-box { background: #f8fafc; padding: 18px; border-radius: 6px; border: 1px solid #e2e8f0; }
        .item-list { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .item-list th, .item-list td { text-align: left; padding: 12px; border-bottom: 1px solid #eee; }
        .item-list th { background: #f1f5f9; font-size: 13px; text-transform: uppercase; color: #475569; }
        .item-image { width: 70px; height: 50px; border-radius: 4px; object-fit: cover; vertical-align: middle; margin-right: 12px; }
        .total-box { text-align: right; font-size: 1.1em; padding-top: 15px; border-top: 2px solid #e2e8f0; }
        .financial-row { display: flex; justify-content: flex-end; gap: 40px; margin-bottom: 6px; font-size: 14px; }
        .status-pill--active { background: #e0f2fe; color: #0284c7; }
        .status-pill--completed { background: #dcfce7; color: #166534; }
        .status-pill--expired { background: #f3f4f6; color: #6b7280; }
        .status-pill--cancelled { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
<div class="detail-container">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
        <div>
            <h2 style="margin:0 0 6px 0;">Booking <?= htmlspecialchars($order['booking_ref'] ?: '#' . $order['id']) ?></h2>
            <div style="font-size: 13px; color: #64748b;">Created on <?= date('F j, Y, g:i A', strtotime($order['order_date'])) ?></div>
        </div>
        <div>
            <span class="status-pill status-pill--<?= strtolower($order['status']) ?>" style="font-size: 14px; padding: 6px 14px;">
                <?= htmlspecialchars($order['status']) ?>
            </span>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success" style="margin-bottom: 20px; padding: 10px; background: #dfe7d8; border-radius: 6px;">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($order['status'] === 'Cancelled' && $order['cancellation_reason']): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px; padding: 12px; background: #fee2e2; border-radius: 6px; color: #b91c1c;">
            <strong>Cancellation Reason:</strong> <?= htmlspecialchars($order['cancellation_reason']) ?>
            <?php if ($order['cancelled_at']): ?>
                <span style="font-size: 12px; color: #666;"> (<?= date('M j, Y g:i A', strtotime($order['cancelled_at'])) ?>)</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="info-grid">
        <div class="info-box">
            <h3 style="margin-top:0; font-size:16px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">Customer Information</h3>
            <p><strong>Name:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($order['customer_email']) ?></p>
            <p><strong>Phone:</strong> <?= htmlspecialchars($order['customer_phone'] ?: 'Not provided') ?></p>
            <p><strong>Driver License:</strong> <?= htmlspecialchars($order['driver_license'] ?: 'Not provided') ?></p>
            <?php if ($order['customer_address']): ?>
                <p><strong>Address:</strong> <?= htmlspecialchars($order['customer_address']) ?></p>
            <?php endif; ?>
        </div>

        <div class="info-box">
            <h3 style="margin-top:0; font-size:16px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">Rental Schedule</h3>
            <?php if ($order['start_date'] && $order['end_date']): ?>
                <p><strong>Pickup Date:</strong> <?= date('l, M j, Y', strtotime($order['start_date'])) ?> at <?= date('g:i A', strtotime($order['start_time'])) ?></p>
                <p><strong>Return Date:</strong> <?= date('l, M j, Y', strtotime($order['end_date'])) ?> at <?= date('g:i A', strtotime($order['end_time'])) ?></p>
                <p><strong>Calculated Duration:</strong> <?= $days ?> Day(s)</p>
                <?php if ($order['status'] === 'Pending' && $order['expires_at']): ?>
                    <p style="color: #b45309;"><strong>Payment / Approval Deadline:</strong> <?= date('M j, Y, g:i A', strtotime($order['expires_at'])) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p>No specific schedule provided.</p>
            <?php endif; ?>
        </div>
    </div>

    <h3 style="margin-bottom: 12px;">Reserved Vehicles</h3>
    <table class="item-list">
        <thead>
            <tr>
                <th>Vehicle & License Plate</th>
                <th>Category</th>
                <th>Daily Rate</th>
                <th>Qty</th>
                <th>Days</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($item = $items->fetch_assoc()): ?>
                <?php 
                    $itemDays = $item['rental_days'] > 0 ? $item['rental_days'] : $days;
                    $itemSubtotal = $item['subtotal'] > 0 ? $item['subtotal'] : ($item['price'] * $item['quantity'] * $itemDays);
                ?>
                <tr>
                    <td>
                        <div style="display:flex; align-items:center;">
                            <?php if ($item['image_name']): ?>
                                <img src="image/<?= htmlspecialchars($item['image_name']) ?>" class="item-image" alt="vehicle">
                            <?php else: ?>
                                <img src="img/car8.png" class="item-image" alt="vehicle">
                            <?php endif; ?>
                            <div>
                                <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                <div style="font-size: 11px; color: #64748b; font-family: monospace;">
                                    Plate: <?= htmlspecialchars($item['reg_number'] ?: 'N/A') ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($item['category_name'] ?: 'Vehicle') ?></td>
                    <td>$<?= number_format($item['price'], 2) ?>/day</td>
                    <td><?= $item['quantity'] ?></td>
                    <td><?= $itemDays ?></td>
                    <td><strong>$<?= number_format($itemSubtotal, 2) ?></strong></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div class="total-box">
        <div class="financial-row">
            <span>Rental Subtotal:</span>
            <strong>$<?= number_format($order['subtotal'] > 0 ? $order['subtotal'] : $order['total_amount'], 2) ?></strong>
        </div>
        <?php if ($order['deposit_amount'] > 0): ?>
            <div class="financial-row">
                <span>Security Deposit (Refundable upon safe return):</span>
                <strong>$<?= number_format($order['deposit_amount'], 2) ?></strong>
            </div>
        <?php endif; ?>
        <?php if ($order['discount_amount'] > 0): ?>
            <div class="financial-row" style="color: #166534;">
                <span>Discount Applied:</span>
                <strong>-$<?= number_format($order['discount_amount'], 2) ?></strong>
            </div>
        <?php endif; ?>
        <div class="financial-row" style="font-size: 1.25em; font-weight: bold; margin-top: 10px; color: #0f172a;">
            <span>Grand Total:</span>
            <span>$<?= number_format($order['total_amount'], 2) ?></span>
        </div>
    </div>

    <!-- Admin Status Controls -->
    <?php if ($is_admin): ?>
        <div style="margin-top: 30px; padding: 20px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
            <h4 style="margin-top:0;">Change Booking Status (Admin)</h4>
            <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" name="action" value="Approved" class="btn-approve" <?= $order['status'] === 'Approved' ? 'disabled' : '' ?>>Approve</button>
                <button type="submit" name="action" value="Active" class="btn-action btn-activate" style="padding:6px 14px; background:#0284c7; color:#fff;" <?= $order['status'] === 'Active' ? 'disabled' : '' ?>>Vehicle Picked Up (Active)</button>
                <button type="submit" name="action" value="Completed" class="btn-action btn-complete" style="padding:6px 14px; background:#16a34a; color:#fff;" <?= $order['status'] === 'Completed' ? 'disabled' : '' ?>>Vehicle Returned (Complete)</button>
                <button type="submit" name="action" value="Cancelled" class="btn-decline" onclick="return confirm('Cancel this booking?')" <?= $order['status'] === 'Cancelled' ? 'disabled' : '' ?>>Cancel</button>
                <button type="submit" name="action" value="Declined" class="btn-decline" onclick="return confirm('Decline this booking?')" <?= $order['status'] === 'Declined' ? 'disabled' : '' ?>>Decline</button>
            </form>
        </div>
    <?php endif; ?>

    <div style="margin-top: 30px; text-align: center;">
        <?php if ($is_admin): ?>
            <a href="orders.php" class="submit-btn" style="text-decoration:none; display:inline-block;">&larr; Back to Orders</a>
        <?php else: ?>
            <a href="user_orders.php" class="submit-btn" style="text-decoration:none; display:inline-block;">&larr; Back to My Orders</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
