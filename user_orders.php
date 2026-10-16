<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

// Automatically expire stale pending bookings
RentalService::expireStaleBookings($conn);

// Handle cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order_id'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
    $cancel_id = (int)$_POST['cancel_order_id'];
    $reason = trim($_POST['cancel_reason'] ?? 'Cancelled by customer');
    
    $res = RentalService::cancelBooking($conn, $cancel_id, $user_id, $reason, false);
    if ($res['success']) {
        $_SESSION['message'] = $res['message'];
    } else {
        $_SESSION['error'] = $res['message'];
    }
    header("Location: user_orders.php");
    exit();
}

$orders = $conn->prepare("
    SELECT id, booking_ref, total_amount, subtotal, deposit_amount, status, 
           order_date, start_date, start_time, end_date, end_time, expires_at
    FROM orders
    WHERE user_id = ?
    ORDER BY order_date DESC
");
$orders->bind_param("i", $user_id);
$orders->execute();
$result = $orders->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Rental Bookings</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .status-pill--active { background: #e0f2fe; color: #0284c7; }
        .status-pill--completed { background: #dcfce7; color: #166534; }
        .status-pill--expired { background: #f3f4f6; color: #6b7280; }
        .status-pill--cancelled { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
<div style="max-width: 900px; margin: 40px auto; padding: 25px; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
        <h2 style="margin:0;">My Rental Bookings</h2>
        <div style="display:flex; gap:10px; align-items:center;">
            <a href="notifications.php" style="text-decoration:none; background:#f1f5f9; padding:8px 14px; border-radius:6px; font-size:13px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:6px;">
                🔔 Notifications <?php 
                    $uNotif = RentalService::getUnreadNotificationCount($conn, $user_id, 'customer');
                    if ($uNotif > 0): 
                ?>
                <span style="background:#e53e3e; color:#fff; border-radius:10px; padding:1px 6px; font-size:11px; font-weight:700;"><?= $uNotif ?></span>
                <?php endif; ?>
            </a>
            <a href="Service.php#catalog" class="submit-btn" style="text-decoration: none; padding: 8px 16px; font-size:13px;">Browse Vehicles</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success" style="margin-bottom: 20px; padding: 12px; background: #dfe7d8; border-radius: 6px;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px; padding: 12px; background: #fee2e2; border-radius: 6px; color: #b91c1c;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($result->num_rows === 0): ?>
        <div style="text-align: center; padding: 40px; color: #666;">
            <p>You have not made any bookings yet.</p>
            <a href="second1.php" class="submit-btn" style="text-decoration: none; display: inline-block; margin-top: 10px;">Explore Vehicles</a>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Booking Ref</th>
                    <th>Date Placed</th>
                    <th>Rental Schedule</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td>
                        <a href="order_detail.php?id=<?= $row['id'] ?>" style="font-weight: bold; font-family: monospace;">
                            <?= htmlspecialchars($row['booking_ref'] ?: '#' . $row['id']) ?>
                        </a>
                    </td>
                    <td><?= date('M j, Y', strtotime($row['order_date'])) ?></td>
                    <td style="font-size: 13px;">
                        <?php if ($row['start_date'] && $row['end_date']): ?>
                            <strong><?= date('M j', strtotime($row['start_date'])) ?></strong> (<?= date('g:i A', strtotime($row['start_time'])) ?>)
                            <br>&rarr; <strong><?= date('M j, Y', strtotime($row['end_date'])) ?></strong> (<?= date('g:i A', strtotime($row['end_time'])) ?>)
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong>$<?= number_format($row['total_amount'], 2) ?></strong>
                        <?php if ($row['deposit_amount'] > 0): ?>
                            <div style="font-size: 10px; color: #666;">Incl. $<?= number_format($row['deposit_amount'], 2) ?> deposit</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-pill status-pill--<?= strtolower($row['status']) ?>">
                            <?= htmlspecialchars($row['status']) ?>
                        </span>
                        <?php if ($row['status'] === 'Pending' && $row['expires_at']): ?>
                            <div style="font-size: 10px; color: #b45309; margin-top: 2px;">
                                Expires: <?= date('M j, g:i A', strtotime($row['expires_at'])) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="white-space: nowrap;">
                        <a href="order_detail.php?id=<?= $row['id'] ?>" style="margin-right: 10px; font-size:13px;">View Details</a>
                        <?php if (in_array($row['status'], ['Pending', 'Approved'])): ?>
                            <form method="post" style="display:inline-block;" onsubmit="return confirmCancellation(this);">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="cancel_order_id" value="<?= $row['id'] ?>">
                                <input type="hidden" name="cancel_reason" value="Customer requested cancellation">
                                <button type="submit" style="background:none; border:none; color:#a72d25; text-decoration:underline; cursor:pointer; font-size:13px;">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <div style="margin-top: 20px;">
        <a href="Service.php" style="text-decoration: none; color: #666; font-size: 13px;">&larr; Back to Home</a>
    </div>
</div>

<script>
function confirmCancellation(form) {
    const reason = prompt('Please let us know the reason for cancellation (optional):', 'Change of travel plans');
    if (reason === null) return false;
    form.querySelector('[name="cancel_reason"]').value = reason || 'Customer requested cancellation';
    return true;
}
</script>
</body>
</html>