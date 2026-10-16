<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit();
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$statusFilter = $_GET['status'] ?? 'all';
$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : 0;

$whereClauses = ["1=1"];
if ($statusFilter !== 'all') {
    if ($statusFilter === 'archived') {
        $whereClauses[] = "p.is_archived = 1";
    } else {
        $cleanStatus = $conn->real_escape_string($statusFilter);
        $whereClauses[] = "p.status = '$cleanStatus' AND p.is_archived = 0";
    }
} else {
    // Show active by default, or all
    $showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] == '1';
    if (!$showArchived) {
        $whereClauses[] = "p.is_archived = 0";
    }
}

if ($categoryFilter > 0) {
    $whereClauses[] = "p.category_id = $categoryFilter";
}

$whereSql = implode(' AND ', $whereClauses);

$products = $conn->query("
    SELECT p.*, c.name AS category_name,
           (SELECT COUNT(*) FROM vehicle_maintenance vm WHERE vm.product_id = p.id AND vm.status IN ('scheduled', 'in_progress')) as active_maintenance_count
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE $whereSql
    ORDER BY p.is_archived ASC, p.id DESC
");

$categories = $conn->query("SELECT id, name FROM categories ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Inventory</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-available { background: #dfe7d8; color: #2d5a27; }
        .badge-rented { background: #e1effe; color: #1e429f; }
        .badge-maintenance { background: #fef08a; color: #854d0e; }
        .badge-inactive { background: #fde8e8; color: #9b1c1c; }
        .badge-cond-excellent { background: #dcfce7; color: #166534; }
        .badge-cond-good { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-cond-fair { background: #fef9c3; color: #854d0e; }
        .badge-cond-needs_service { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
<div class="app-shell" style="grid-template-columns:240px minmax(0,1fr);">
    <aside class="app-sidebar">
        <a class="logo" href="Service.php">Joul Rental</a>
        <nav class="side-nav">
            <a href="dashboard.php"><span class="dot"></span>Overview</a>
            <a class="active" href="Product.php"><span class="dot"></span>Products</a>
            <a href="product_edit.php"><span class="dot"></span>Product Management</a>
            <a href="maintenance.php"><span class="dot"></span>Maintenance</a>
            <a href="categories.php"><span class="dot"></span>Categories</a>
            <a href="orders.php"><span class="dot"></span>Orders</a>
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
                <a href="Product.php" class="logo"><h1>Vehicle Inventory</h1></a>
                <p class="subtitle"><?= date('l, F j, Y') ?></p>
            </div>
            <div class="topbar-actions">
                <a href="product_edit.php" class="submit-btn" style="text-decoration:none; display:inline-block; padding: 10px 18px;">+ Add New Vehicle</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-success" style="margin: 20px; padding: 12px; background: #dfe7d8; border-radius: 6px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Filters Bar -->
        <div style="margin: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: center; background:#fff; padding:15px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <form method="get" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; width:100%;">
                <label style="font-weight:600; font-size:13px;">Filter Category:
                    <select name="category" onchange="this.form.submit()" style="padding:6px 10px; border-radius:4px; border:1px solid #ccc;">
                        <option value="0">All Categories</option>
                        <?php while ($c = $categories->fetch_assoc()): ?>
                            <option value="<?= $c['id'] ?>" <?= $categoryFilter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </label>

                <label style="font-weight:600; font-size:13px;">Status:
                    <select name="status" onchange="this.form.submit()" style="padding:6px 10px; border-radius:4px; border:1px solid #ccc;">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Active Vehicles</option>
                        <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="rented" <?= $statusFilter === 'rented' ? 'selected' : '' ?>>Rented</option>
                        <option value="maintenance" <?= $statusFilter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option>
                    </select>
                </label>
                <?php if ($categoryFilter || $statusFilter !== 'all'): ?>
                    <a href="Product.php" style="color:#666; font-size:13px; text-decoration:underline;">Clear Filters</a>
                <?php endif; ?>
            </form>
        </div>

        <main class="dashboard-body">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Photo</th>
                        <th>Vehicle / Reg #</th>
                        <th>Category</th>
                        <th>Rates</th>
                        <th>Status</th>
                        <th>Condition</th>
                        <th>Qty</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($products && $products->num_rows > 0): ?>
                    <?php while ($row = $products->fetch_assoc()): ?>
                    <tr style="<?= $row['is_archived'] ? 'opacity: 0.6; background: #fafafa;' : '' ?>">
                        <td><?= $row['id'] ?></td>
                        <td>
                            <img src="<?= htmlspecialchars($row['image_name'] ? 'image/'.$row['image_name'] : 'img/car8.png') ?>" width="55" height="40" style="object-fit:cover; border-radius:4px;">
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($row['name']) ?></strong>
                            <div style="font-size: 11px; color: #666; font-family: monospace;">
                                Plate: <?= htmlspecialchars($row['reg_number'] ?: 'N/A') ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($row['category_name']) ?></td>
                        <td>
                            <strong>$<?= number_format($row['price'], 2) ?></strong>/day
                            <?php if ($row['hourly_price'] > 0): ?>
                                <div style="font-size:11px; color:#666;">$<?= number_format($row['hourly_price'], 2) ?>/hr</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['is_archived']): ?>
                                <span class="badge badge-inactive">Archived</span>
                            <?php else: ?>
                                <span class="badge badge-<?= htmlspecialchars($row['status']) ?>">
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-cond-<?= htmlspecialchars($row['condition_status']) ?>">
                                <?= ucwords(str_replace('_', ' ', $row['condition_status'])) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($row['quantity'] ?? '1') ?></td>
                        <td class="actions" style="white-space: nowrap;">
                            <a href="product_edit.php?id=<?= $row['id'] ?>" style="margin-right:8px;">Edit</a>
                            <a href="maintenance.php?product_id=<?= $row['id'] ?>" style="color:#854d0e; margin-right:8px;">Service</a>
                            <?php if ($row['is_archived']): ?>
                                <a href="product_delete.php?id=<?= $row['id'] ?>&action=unarchive" onclick="return confirm('Restore this vehicle to active inventory?')" style="color:#2d5a27;">Restore</a>
                            <?php else: ?>
                                <a href="product_delete.php?id=<?= $row['id'] ?>&action=archive" onclick="return confirm('Archive vehicle #<?= $row['id'] ?>? This safely preserves all rental history.')" style="color:#a72d25;">Archive</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align:center; padding:30px; color:#666;">No vehicles match the selected criteria.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </main>
    </div>
</div>
</body>
</html>
