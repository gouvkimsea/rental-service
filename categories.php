<?php
require_once 'config.php';
require_once 'rental_service.php';

RentalService::requirePermission($conn, 'manage_categories');

$currentUserId = (int)$_SESSION['user_id'];
$currentUserRole = $_SESSION['role'] ?? RentalService::getUserRole($conn, $currentUserId);
$unread_sidebar_count = RentalService::getUnreadNotificationCount($conn, $currentUserId, $currentUserRole);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request.');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' && !empty($_POST['category_name'])) {
        $name = trim($_POST['category_name']);
        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $name);
        if ($stmt->execute()) {
            $catId = (int)$conn->insert_id;
            RentalService::logSystemAudit($conn, $currentUserId, $currentUserRole, 'operational', 'CATEGORY_CREATED', $catId, 'category', "Created category: $name");
            $message = "Category added successfully.";
        }
    } elseif ($action === 'delete' && !empty($_POST['category_id'])) {
        $id = (int)$_POST['category_id'];
        
        // Check if products use this category
        $check = $conn->prepare("SELECT id FROM products WHERE category_id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $message = "Cannot delete category because it is assigned to one or more products.";
        } else {
            $del = $conn->prepare("DELETE FROM categories WHERE id = ?");
            $del->bind_param("i", $id);
            if ($del->execute()) {
                RentalService::logSystemAudit($conn, $currentUserId, $currentUserRole, 'operational', 'CATEGORY_DELETED', $id, 'category', "Deleted category ID #$id");
                $message = "Category deleted successfully.";
            }
        }
    }
}

$categories = $conn->query("
    SELECT c.id, c.name, COUNT(p.id) as product_count 
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id
    GROUP BY c.id
    ORDER BY c.name
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Categories - RentalKH</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .badge-count { background: #e53e3e; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 11px; font-weight: 700; }
    </style>
</head>
<body>
<div class="app-shell" style="grid-template-columns:240px minmax(0,1fr);">
    <aside class="app-sidebar">
        <a class="logo" href="Service.php">Joul Rental</a>
        <nav class="side-nav">
            <a href="dashboard.php"><span class="dot"></span>Overview</a>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_vehicles')): ?>
                <a href="Product.php"><span class="dot"></span>Products</a>
                <a href="product_edit.php"><span class="dot"></span>Product Management</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_maintenance')): ?>
                <a href="maintenance.php"><span class="dot"></span>Maintenance</a>
            <?php endif; ?>
            <a class="active" href="categories.php"><span class="dot"></span>Categories</a>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_bookings')): ?>
                <a href="orders.php"><span class="dot"></span>Orders</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'view_finance_reports')): ?>
                <a href="finance.php"><span class="dot"></span>Finance</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_customers') || RentalService::checkPermission($conn, $currentUserId, 'manage_staff_roles')): ?>
                <a href="users.php"><span class="dot"></span>Users</a>
            <?php endif; ?>
            <a href="notifications.php">
                <span class="dot"></span>Notifications
                <?php if ($unread_sidebar_count > 0): ?>
                    <span class="badge-count" style="margin-left:auto;"><?= $unread_sidebar_count ?></span>
                <?php endif; ?>
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="profile-avatar"><?= strtoupper(substr($_SESSION['username'],0,2)) ?></div>
            <div>
                <p class="name"><?= htmlspecialchars($_SESSION['username']) ?></p>
                <p class="role"><span style="text-transform: capitalize; font-size:11px; color:#64748b;"><?= htmlspecialchars($currentUserRole) ?></span> | <a href="logout.php" style="color: #a72d25; text-decoration: none; font-weight: bold;">Logout</a></p>
            </div>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div>
                <a href="categories.php" class="logo"><h1>Category Management</h1></a>
                <p class="subtitle"><?= date('l, F j, Y') ?> • Role: <strong><?= htmlspecialchars(strtoupper($currentUserRole)) ?></strong></p>
            </div>
            <div class="topbar-actions">
                <a href="notifications.php" style="text-decoration:none; background:#f1f5f9; padding:8px 14px; border-radius:6px; font-size:13px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:6px;">
                    🔔 Notifications <?php if ($unread_sidebar_count > 0): ?><span class="badge-count"><?= $unread_sidebar_count ?></span><?php endif; ?>
                </a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-success" style="margin: 20px; padding: 12px; background: #dfe7d8; border-radius: 6px; color: #2d5a27;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div style="display:flex; gap:30px; margin:20px; align-items: flex-start;">
            <section class="panel" style="flex:1;">
                <div class="panel-head"><h2>All Categories</h2></div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Products Linked</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $categories->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= $row['product_count'] ?></td>
                            <td>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('Delete this category?');">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="category_id" value="<?= $row['id'] ?>">
                                    <button type="submit" style="background:none; border:none; color:#a72d25; text-decoration:underline; cursor:pointer;">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </section>
            
            <section class="panel" style="width:350px;">
                <div class="panel-head"><h2>Add Category</h2></div>
                <div class="panel-body" style="padding:20px;">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="add">
                        <div class="form-group" style="margin-bottom:15px;">
                            <label>Category Name</label>
                            <input type="text" name="category_name" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
                        </div>
                        <button type="submit" class="submit-btn" style="width:100%;">Add Category</button>
                    </form>
                </div>
            </section>
        </div>
    </div>
</div>
</body>
</html>
