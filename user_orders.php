<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order_id'])) {
    /*if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request.');
    }*/
    $cancel_id = (int)$_POST['cancel_order_id'];
    $cancel_stmt = $conn->prepare("UPDATE orders SET status = 'Declined' WHERE id = ? AND user_id = ? AND status = 'Pending'");
    $cancel_stmt->bind_param("ii", $cancel_id, $user_id);
    $cancel_stmt->execute();
    header("Location: user_orders.php");
    exit();
}

$orders = $conn->prepare("
    SELECT id, total_amount, status, order_date, start_date, end_date
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
    <title>My Orders</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div style="max-width: 800px; margin: 40px auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1);">
    <h2>My Orders</h2>
    <?php if ($result->num_rows === 0): ?>
        <p>You have no orders yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date Placed</th>
                    <th>Rental Dates</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><a href="order_detail.php?id=<?= $row['id'] ?>">#<?= $row['id'] ?></a></td>
                    <td><?= date('M j, Y', strtotime($row['order_date'])) ?></td>
                    <td style="font-size: 13px;">
                        <?php if ($row['start_date']): ?>
                            <?= date('M j', strtotime($row['start_date'])) ?> - <?= date('M j, Y', strtotime($row['end_date'])) ?>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </td>
                    <td>$<?= number_format($row['total_amount'], 2) ?></td>
                    <td>
                        <span class="status-pill status-pill--<?= strtolower($row['status']) ?>">
                            <?= htmlspecialchars($row['status']) ?>
                        </span>
                    </td>
                    <td>
                        <a href="order_detail.php?id=<?= $row['id'] ?>" style="margin-right: 10px;">View</a>
                        <?php if (strtolower($row['status']) === 'pending'): ?>
                            <form method="post" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to cancel this order?');">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="cancel_order_id" value="<?= $row['id'] ?>">
                                <button type="submit" style="background:none; border:none; color:#a72d25; text-decoration:underline; cursor:pointer;">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <button class="submit-btn"><a href="Service.php#catalog" style="text-decoration: none; color: inherit;">Explore more</a></button>
    
</div>
</body>
</html>