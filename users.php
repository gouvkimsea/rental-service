<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['is_admin'] != 1) {
    header("Location: login.php");
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request.');
    }
    
    $target_id = (int)$_POST['user_id'];
    // Don't allow admin to disable themselves
    if ($target_id === $_SESSION['user_id']) {
        $message = "You cannot disable your own account.";
    } else {
        $new_status = ($_POST['action'] === 'enable') ? 1 : 0;
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $target_id);
        if ($stmt->execute()) {
            $message = "User status updated successfully.";
        }
    }
}

// Fetch all users
$query = "
    SELECT u.id, u.name, u.email, u.is_admin, u.is_active, COUNT(o.id) as total_orders
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
    <title>Manage Users</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .badge-active { background: #dfe7d8; color: #2d5a27; }
        .badge-disabled { background: #fde8e8; color: #9b1c1c; }
        .badge-admin { background: #e1effe; color: #1e429f; }
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
            <a href="users.php" class="logo"><h1>User Management</h1></a>
            <p class="subtitle"><?= date('l, F j, Y') ?></p>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-success" style="margin: 20px; padding: 12px; background: #dfe7d8; border-radius: 6px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-head"><h2>All Registered Users</h2></div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Orders</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td>#<?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['email']) ?></td>
                        <td>
                            <?php if ($row['is_admin']): ?>
                                <span class="badge badge-admin">Admin</span>
                            <?php else: ?>
                                <span>Customer</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $row['total_orders'] ?></td>
                        <td>
                            <?php if ($row['is_active']): ?>
                                <span class="badge badge-active">Active</span>
                            <?php else: ?>
                                <span class="badge badge-disabled">Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['id'] !== $_SESSION['user_id']): ?>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('Are you sure?');">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                    <?php if ($row['is_active']): ?>
                                        <button type="submit" name="action" value="disable" style="background:none; border:none; color:#a72d25; text-decoration:underline; cursor:pointer;">Disable</button>
                                    <?php else: ?>
                                        <button type="submit" name="action" value="enable" style="background:none; border:none; color:#2d5a27; text-decoration:underline; cursor:pointer;">Enable</button>
                                    <?php endif; ?>
                                </form>
                            <?php else: ?>
                                <span style="color:#999; font-size:13px;">(You)</span>
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
