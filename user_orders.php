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
    <title>My Rental Bookings - JOUL Rental</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .portal-container {
            max-width: 1040px;
            margin: 32px auto;
            padding: 0 16px;
        }
        .portal-card {
            background: #fff;
            border: 1px solid var(--color-border, #e2e8f0);
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }
        .ref-badge {
            font-family: monospace;
            font-weight: 700;
            font-size: 13px;
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 4px;
            color: #0f172a;
            text-decoration: none;
        }
        .ref-badge:hover {
            color: var(--color-forest, #164e3f);
            background: #e2e8f0;
        }
    </style>
</head>
<body>
<header class="site-header">
    <a href="Service.php" class="logo">JOUL Rental</a>
    <nav class="site-nav">
        <a href="Service.php">Home</a>
        <a href="second1.php">Fleet Catalog</a>
        <a href="user_orders.php" style="color:var(--color-forest); font-weight:700;">My Bookings</a>
    </nav>
    <div class="action-bar">
        <a href="notifications.php" class="cart-button" style="text-decoration:none; display:inline-flex; align-items:center; gap:5px;">
            🔔 <?php 
                $uNotif = RentalService::getUnreadNotificationCount($conn, $user_id, 'customer');
                if ($uNotif > 0): 
            ?>
            <span style="background:#dc2626; color:#fff; border-radius:10px; padding:1px 6px; font-size:11px; font-weight:700;"><?= $uNotif ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="cart-button" style="text-decoration:none;">Profile</a>
        <a href="logout.php" class="cart-button" style="text-decoration:none; background:#7f1d1d; color:#fff;">Logout</a>
    </div>
</header>

<main class="portal-container">
    <div class="portal-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px; flex-wrap:wrap; gap:12px;">
            <div>
                <h1 style="margin:0 0 4px 0; font-size:24px; font-weight:800; color:var(--color-forest);">My Rental Bookings</h1>
                <p style="margin:0; font-size:13px; color:#64748b;">View reservation status, pickup schedules, financial breakdowns, and active contracts.</p>
            </div>
            <a href="second1.php" class="primary-link" style="padding: 8px 16px; font-size:13px; text-decoration:none;">+ New Booking</a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success" style="margin-bottom: 20px; padding: 12px 16px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; color: #065f46; font-size: 14px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px; padding: 12px 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; color: #991b1b; font-size: 14px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($result->num_rows === 0): ?>
            <div style="text-align: center; padding: 60px 20px; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                <p style="font-size:15px; color:#475569; margin-bottom:16px;">You do not have any active or past rental bookings.</p>
                <a href="second1.php" class="primary-link" style="text-decoration: none; display: inline-block; padding: 9px 20px; font-size:14px;">Browse Fleet &amp; Book a Vehicle</a>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table" style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="padding:10px 12px; text-align:left;">Booking Ref</th>
                            <th style="padding:10px 12px; text-align:left;">Date Reserved</th>
                            <th style="padding:10px 12px; text-align:left;">Rental Schedule</th>
                            <th style="padding:10px 12px; text-align:left;">Total (Escrow)</th>
                            <th style="padding:10px 12px; text-align:left;">Status</th>
                            <th style="padding:10px 12px; text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding:12px;">
                                <a href="order_detail.php?id=<?= $row['id'] ?>" class="ref-badge">
                                    <?= htmlspecialchars($row['booking_ref'] ?: '#' . $row['id']) ?>
                                </a>
                            </td>
                            <td style="padding:12px; font-size:13px; color:#475569;">
                                <?= date('M j, Y', strtotime($row['order_date'])) ?>
                            </td>
                            <td style="padding:12px; font-size: 13px;">
                                <?php if ($row['start_date'] && $row['end_date']): ?>
                                    <span style="font-weight:600; color:#0f172a;"><?= date('M j', strtotime($row['start_date'])) ?></span> <span style="color:#64748b; font-size:11px;"><?= date('g:i A', strtotime($row['start_time'])) ?></span>
                                    <span style="color:#94a3b8; margin: 0 4px;">&rarr;</span>
                                    <span style="font-weight:600; color:#0f172a;"><?= date('M j, Y', strtotime($row['end_date'])) ?></span> <span style="color:#64748b; font-size:11px;"><?= date('g:i A', strtotime($row['end_time'])) ?></span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px; font-size:13px;">
                                <strong style="color:var(--color-forest); font-size:14px;">$<?= number_format($row['total_amount'], 2) ?></strong>
                                <?php if ($row['deposit_amount'] > 0): ?>
                                    <div style="font-size: 11px; color: #64748b;">(Incl. $<?= number_format($row['deposit_amount'], 2) ?> deposit)</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px;">
                                <span class="status-pill status-pill--<?= strtolower($row['status']) ?>">
                                    <?= htmlspecialchars($row['status']) ?>
                                </span>
                                <?php if ($row['status'] === 'Pending' && $row['expires_at']): ?>
                                    <div style="font-size: 11px; color: #b45309; margin-top: 3px; font-weight:500;">
                                        Expires: <?= date('M j, g:i A', strtotime($row['expires_at'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px; text-align:right; white-space:nowrap;">
                                <a href="order_detail.php?id=<?= $row['id'] ?>" class="btn-sm" style="display:inline-block; padding:5px 10px; background:#f1f5f9; color:#0f172a; text-decoration:none; border-radius:5px; font-size:12px; font-weight:600; margin-right:6px;">
                                    Contract Details
                                </a>
                                <?php if (in_array($row['status'], ['Pending', 'Approved'])): ?>
                                    <form method="post" style="display:inline-block;" onsubmit="return confirmCancellation(this);">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="cancel_order_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="cancel_reason" value="Customer requested cancellation">
                                        <button type="submit" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:12px; font-weight:600; padding:5px;">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

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