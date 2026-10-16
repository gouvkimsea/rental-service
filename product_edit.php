<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $id > 0;

// Fetch product data if editing
$product = null;
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    if (!$product) { header("Location: Product.php"); exit(); }
}

// Fetch categories for dropdown
$categories = $conn->query("SELECT id, name FROM categories ORDER BY name");

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF validation
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request. Please go back and try again.');
    }

    $name        = substr(trim($_POST['name']), 0, 100);
    $category_id = (int)$_POST['category_id'];
    $price       = (float)$_POST['price'];
    $quantity    = (int)$_POST['quantity'];
    $description = substr(trim($_POST['description']), 0, 1000);
    $image_name  = $product['image_name'] ?? ''; // keep old if no new upload

    // Handle image upload
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $ext  = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($ext, $allowed_exts) || !in_array($mime, $allowed_mimes)) {
            $error = 'Only image files (jpg, png, gif, webp) are allowed.';
        } else {
            $upload_dir = 'image/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $image_name = uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_name);
        }
    }

    if (!isset($error)) {
        if ($is_edit) {
            $stmt = $conn->prepare("UPDATE products SET name=?, category_id=?, price=?, quantity=?, description=?, image_name=? WHERE id=?");
            $stmt->bind_param("sidissi", $name, $category_id, $price, $quantity, $description, $image_name, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO products (name, category_id, price, quantity, description, image_name) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sidiss", $name, $category_id, $price, $quantity, $description, $image_name);
        }
        if ($stmt->execute()) {
            header("Location: Product.php");
            exit();
        } else {
            error_log('Product save error: ' . $stmt->error);
            $error = 'Failed to save product. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $is_edit ? 'Edit' : 'Add' ?> Product</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell" style="grid-template-columns:240px minmax(0,1fr);">
    <aside class="app-sidebar"><a class="logo" href="Service.php">Joul Rental</a> <nav class="side-nav">
            <a  href="dashboard.php"><span class="dot"></span>Overview</a>
            <a href="Product.php"><span class="dot"></span>Products</a>
            <a class="active" href="product_edit.php"><span class="dot"></span>Product Management</a>
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
            <a href="product_edit.php" class="logo"> <h1>Product Management</h1> </a>
            <p class="subtitle"><?= date('l, F j, Y') ?></p>
        </header>
        <header class="app-topbar"><h1><?= $is_edit ? 'Edit' : 'Add' ?> Product</h1></header>
        <main class="dashboard-body">
            <?php if (isset($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($product['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id" required>
                        <option value="">Select</option>
                        <?php while ($cat = $categories->fetch_assoc()): ?>
                        <option value="<?= $cat['id'] ?>" <?= (isset($product) && $product['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Price ($)</label>
                    <input type="number" step="0.01" name="price" value="<?= $product['price'] ?? '' ?>" required>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" value="<?= htmlspecialchars($product['quantity'] ?? '1') ?>" min="1" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Image</label>
                    <input type="file" name="image" accept="image/*">
                    <?php if (isset($product) && $product['image_name']): ?>
                        <p>Current: <img src="image/<?= htmlspecialchars($product['image_name']) ?>" width="100"></p>
                    <?php endif; ?>
                </div>
                <button type="submit" class="submit-btn"><?= $is_edit ? 'Update' : 'Add' ?> Product</button>
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <a href="Product.php" style="margin-left:20px;">Cancel</a>
            </form>
        </main>
    </div>
</div>
</body>
</html>
