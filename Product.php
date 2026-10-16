<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit();
}

$products = $conn->query("
    SELECT p.*, c.name AS category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    ORDER BY p.id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell" style="grid-template-columns:240px minmax(0,1fr);">
    <aside class="app-sidebar">  <a class="logo" href="Service.php">Joul Rental</a>
        <nav class="side-nav">
            <a  href="dashboard.php"><span class="dot"></span>Overview</a>
            <a class="active" href="Product.php"><span class="dot"></span>Products</a>
            <a href="product_edit.php"><span class="dot"></span>Product Management</a>
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
            <a href="Product.php" class="logo"> <h1>Products</h1> </a>
            <p class="subtitle"><?= date('l, F j, Y') ?></p>
        </header>
        <main class="dashboard-body">
            <table class="admin-table">
                <thead>
                    <tr><th>ID</th><th>Image</th><th>Name</th><th>Category</th><th>Price</th><th>Quantity</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php while ($row = $products->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['id'] ?></td>
                    <td><img src="<?= htmlspecialchars($row['image_name'] ? 'image/'.$row['image_name'] : 'img/car8.png') ?>" width="50" height="50" style="object-fit:cover;"></td>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= htmlspecialchars($row['category_name']) ?></td>
                    <td>$<?= number_format($row['price'], 2) ?></td>
                    <td><?= htmlspecialchars($row['quantity'] ?? '1') ?></td>
                    <td class="actions">
                        <a href="product_edit.php?id=<?= $row['id'] ?>">Edit</a>
                        <a href="product_delete.php?id=<?= $row['id'] ?>" onclick="return confirm('Delete this product?')">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </main>
    </div>
</div>
</body>
</html>
