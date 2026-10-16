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

    $name             = substr(trim($_POST['name']), 0, 100);
    $category_id      = (int)$_POST['category_id'];
    $reg_number       = strtoupper(substr(trim($_POST['reg_number'] ?? ''), 0, 50));
    $price            = max(0.01, (float)$_POST['price']);
    $hourly_price     = max(0.00, (float)($_POST['hourly_price'] ?? 0));
    $quantity         = max(1, (int)$_POST['quantity']);
    $status           = in_array($_POST['status'] ?? '', ['available', 'rented', 'maintenance', 'inactive']) ? $_POST['status'] : 'available';
    $condition_status = in_array($_POST['condition_status'] ?? '', ['excellent', 'good', 'fair', 'needs_service']) ? $_POST['condition_status'] : 'good';
    $mileage          = max(0, (int)($_POST['mileage'] ?? 0));
    $fuel_type        = substr(trim($_POST['fuel_type'] ?? 'Petrol'), 0, 30);
    $transmission     = substr(trim($_POST['transmission'] ?? 'Automatic'), 0, 30);
    $seats            = max(1, (int)($_POST['seats'] ?? 2));
    $buffer_hours     = max(0, (int)($_POST['buffer_hours'] ?? 2));
    $description      = substr(trim($_POST['description'] ?? ''), 0, 1000);
    $image_name       = $product['image_name'] ?? ''; // keep old if no new upload

    // Check duplicate registration number
    if (!empty($reg_number)) {
        if ($is_edit) {
            $checkReg = $conn->prepare("SELECT id FROM products WHERE reg_number = ? AND id != ?");
            $checkReg->bind_param("si", $reg_number, $id);
        } else {
            $checkReg = $conn->prepare("SELECT id FROM products WHERE reg_number = ?");
            $checkReg->bind_param("s", $reg_number);
        }
        $checkReg->execute();
        if ($checkReg->get_result()->num_rows > 0) {
            $error = "Registration / License plate number '$reg_number' is already assigned to another vehicle.";
        }
    } else {
        // Auto-generate if left empty
        $reg_number = 'RKH-' . strtoupper(substr(uniqid(), -5));
    }

    // Handle image upload
    if (!isset($error) && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
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
            $stmt = $conn->prepare("
                UPDATE products 
                SET name=?, category_id=?, reg_number=?, price=?, hourly_price=?, quantity=?, 
                    status=?, condition_status=?, mileage=?, fuel_type=?, transmission=?, 
                    seats=?, buffer_hours=?, description=?, image_name=? 
                WHERE id=?
            ");
            $stmt->bind_param(
                "sisddissssiisssi", 
                $name, $category_id, $reg_number, $price, $hourly_price, $quantity,
                $status, $condition_status, $mileage, $fuel_type, $transmission,
                $seats, $buffer_hours, $description, $image_name, $id
            );
        } else {
            $stmt = $conn->prepare("
                INSERT INTO products (
                    name, category_id, reg_number, price, hourly_price, quantity,
                    status, condition_status, mileage, fuel_type, transmission,
                    seats, buffer_hours, description, image_name
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "sisddissssiisss", 
                $name, $category_id, $reg_number, $price, $hourly_price, $quantity,
                $status, $condition_status, $mileage, $fuel_type, $transmission,
                $seats, $buffer_hours, $description, $image_name
            );
        }

        if ($stmt->execute()) {
            header("Location: Product.php");
            exit();
        } else {
            error_log('Product save error: ' . $stmt->error);
            $error = 'Failed to save vehicle: ' . $stmt->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $is_edit ? 'Edit' : 'Add' ?> Vehicle</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-full { grid-column: span 2; }
    </style>
</head>
<body>
<div class="app-shell" style="grid-template-columns:240px minmax(0,1fr);">
    <aside class="app-sidebar">
        <a class="logo" href="Service.php">Joul Rental</a> 
        <nav class="side-nav">
            <a href="dashboard.php"><span class="dot"></span>Overview</a>
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
            <a href="product_edit.php" class="logo"><h1>Vehicle Inventory Management</h1></a>
            <p class="subtitle"><?= date('l, F j, Y') ?></p>
        </header>
        <header class="app-topbar"><h2><?= $is_edit ? 'Edit' : 'Add New' ?> Vehicle</h2></header>
        <main class="dashboard-body">
            <?php if (isset($error)): ?><div class="alert alert-danger" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Vehicle Name / Model</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($product['name'] ?? '') ?>" placeholder="e.g. Toyota Prius, Honda Click, Trek Marlin" required>
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" required>
                            <option value="">Select Category</option>
                            <?php while ($cat = $categories->fetch_assoc()): ?>
                            <option value="<?= $cat['id'] ?>" <?= (isset($product) && $product['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Registration / License Plate Number</label>
                        <input type="text" name="reg_number" value="<?= htmlspecialchars($product['reg_number'] ?? '') ?>" placeholder="e.g. 2AB-1234, RKH-004">
                        <small style="color:#666;">Unique identifier for this vehicle.</small>
                    </div>

                    <div class="form-group">
                        <label>Daily Rental Rate ($ / day)</label>
                        <input type="number" step="0.01" name="price" value="<?= $product['price'] ?? '' ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Hourly Rental Rate ($ / hour)</label>
                        <input type="number" step="0.01" name="hourly_price" value="<?= $product['hourly_price'] ?? '0.00' ?>">
                    </div>

                    <div class="form-group">
                        <label>Inventory Quantity</label>
                        <input type="number" name="quantity" value="<?= htmlspecialchars($product['quantity'] ?? '1') ?>" min="1" required>
                    </div>

                    <div class="form-group">
                        <label>Inventory Status</label>
                        <select name="status">
                            <option value="available" <?= (isset($product) && $product['status'] === 'available') ? 'selected' : '' ?>>Available</option>
                            <option value="rented" <?= (isset($product) && $product['status'] === 'rented') ? 'selected' : '' ?>>Rented</option>
                            <option value="maintenance" <?= (isset($product) && $product['status'] === 'maintenance') ? 'selected' : '' ?>>Maintenance</option>
                            <option value="inactive" <?= (isset($product) && $product['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Condition Status</label>
                        <select name="condition_status">
                            <option value="excellent" <?= (isset($product) && $product['condition_status'] === 'excellent') ? 'selected' : '' ?>>Excellent</option>
                            <option value="good" <?= (isset($product) && $product['condition_status'] === 'good') ? 'selected' : '' ?>>Good</option>
                            <option value="fair" <?= (isset($product) && $product['condition_status'] === 'fair') ? 'selected' : '' ?>>Fair</option>
                            <option value="needs_service" <?= (isset($product) && $product['condition_status'] === 'needs_service') ? 'selected' : '' ?>>Needs Service</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Mileage / Odometer (km)</label>
                        <input type="number" name="mileage" value="<?= htmlspecialchars($product['mileage'] ?? '0') ?>" min="0">
                    </div>

                    <div class="form-group">
                        <label>Fuel Type / Engine</label>
                        <select name="fuel_type">
                            <option value="Petrol" <?= (isset($product) && $product['fuel_type'] === 'Petrol') ? 'selected' : '' ?>>Petrol / Gasoline</option>
                            <option value="Diesel" <?= (isset($product) && $product['fuel_type'] === 'Diesel') ? 'selected' : '' ?>>Diesel</option>
                            <option value="Electric" <?= (isset($product) && $product['fuel_type'] === 'Electric') ? 'selected' : '' ?>>Electric</option>
                            <option value="Hybrid" <?= (isset($product) && $product['fuel_type'] === 'Hybrid') ? 'selected' : '' ?>>Hybrid</option>
                            <option value="Manual / Pedal" <?= (isset($product) && $product['fuel_type'] === 'Manual / Pedal') ? 'selected' : '' ?>>Manual / Pedal (Bicycle)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Transmission</label>
                        <select name="transmission">
                            <option value="Automatic" <?= (isset($product) && $product['transmission'] === 'Automatic') ? 'selected' : '' ?>>Automatic</option>
                            <option value="Manual" <?= (isset($product) && $product['transmission'] === 'Manual') ? 'selected' : '' ?>>Manual</option>
                            <option value="N/A" <?= (isset($product) && $product['transmission'] === 'N/A') ? 'selected' : '' ?>>N/A (Bicycle / Other)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Seating Capacity</label>
                        <input type="number" name="seats" value="<?= htmlspecialchars($product['seats'] ?? '2') ?>" min="1" max="60">
                    </div>

                    <div class="form-group">
                        <label>Turnaround Buffer (Hours)</label>
                        <input type="number" name="buffer_hours" value="<?= htmlspecialchars($product['buffer_hours'] ?? '2') ?>" min="0" max="48">
                        <small style="color:#666;">Cleaning/inspection buffer before next booking.</small>
                    </div>

                    <div class="form-group form-full">
                        <label>Description & Features</label>
                        <textarea name="description" rows="3" placeholder="Condition details, included accessories, insurance coverage..."><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group form-full">
                        <label>Vehicle Photo</label>
                        <input type="file" name="image" accept="image/*">
                        <?php if (isset($product) && $product['image_name']): ?>
                            <p style="margin-top: 10px;">Current: <img src="image/<?= htmlspecialchars($product['image_name']) ?>" width="120" style="border-radius: 6px; vertical-align:middle;"></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="margin-top: 24px;">
                    <button type="submit" class="submit-btn"><?= $is_edit ? 'Update Vehicle' : 'Add Vehicle' ?></button>
                    <a href="Product.php" style="margin-left:20px; text-decoration:none; color:#666;">Cancel</a>
                </div>
            </form>
        </main>
    </div>
</div>
</body>
</html>
