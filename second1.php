<?php
require_once 'config.php';

$category_id = isset($_GET['category']) ? (int)$_GET['category'] : 0;

$all_cats = $conn->query("SELECT id, name FROM categories ORDER BY name");

// Build query
$sql = "SELECT p.*, c.name AS category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id";
if ($category_id > 0) {
    $sql .= " WHERE p.category_id = $category_id";
}
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Odibee+Sans&family=Inter&display=swap" rel="stylesheet">
</head>
<body>
<nav>
<?php
$label = 'Joul Rental';
if ($category_id > 0) {
    $c_stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
    $c_stmt->bind_param("i", $category_id);
    $c_stmt->execute();
    $c_res = $c_stmt->get_result();
    if ($c_res->num_rows > 0) {
        $label = $c_res->fetch_assoc()['name'];
    }
}
echo "<a href='Service.php' class='logo'>" . htmlspecialchars($label) . "</a>";
?>
    <button class="cart-button" type="button" data-open-cart>
        Cart <span data-cart-count>0</span>
    </button>
</nav>
<div style="padding: 20px 40px; max-width: 1200px; margin: 0 auto;">
    <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
        <a href="Service.php#catalog" style="display: inline-flex; align-items: center; padding: 8px 16px; background: #e0e0e0; color: #333; text-decoration: none; border-radius: 20px; font-weight: 600; font-family: 'Inter', sans-serif; transition: background 0.3s; margin-right: 15px;">
            &larr; Home
        </a>
        
        <a href="second1.php?category=0" style="display: inline-flex; align-items: center; padding: 8px 16px; background: <?= $category_id === 0 ? '#222' : '#f1f1f1' ?>; color: <?= $category_id === 0 ? '#fff' : '#333' ?>; text-decoration: none; border-radius: 20px; font-weight: 600; font-family: 'Inter', sans-serif; transition: background 0.3s;">
            All Vehicles
        </a>
        
        <?php while ($c = $all_cats->fetch_assoc()): ?>
            <a href="second1.php?category=<?= $c['id'] ?>" style="display: inline-flex; align-items: center; padding: 8px 16px; background: <?= $category_id == $c['id'] ? '#222' : '#f1f1f1' ?>; color: <?= $category_id == $c['id'] ? '#fff' : '#333' ?>; text-decoration: none; border-radius: 20px; font-weight: 600; font-family: 'Inter', sans-serif; transition: background 0.3s;">
                <?= htmlspecialchars($c['name']) ?>
            </a>
        <?php endwhile; ?>
    </div>
    <p style="margin:0;">Browse our full catalog of vehicles to find the perfect match for your journey.</p>
</div>

<div class="card-container">
    <?php if ($result && $result->num_rows > 0): ?>
        <?php while ($row = $result->fetch_assoc()): ?>
            <div class="card">
                <div class="card-top">
                    <img src="<?= htmlspecialchars($row['image_name'] ? 'image/'.$row['image_name'] : 'img/car8.png') ?>" alt="<?= htmlspecialchars($row['name']) ?>">
                </div>
                <div class="card-body">
                    <div class="vehicle-type"><?= htmlspecialchars($row['category_name']) ?></div>
                    <h2 class="vehicle-name"><?= htmlspecialchars($row['name']) ?></h2>
                </div>
                <div class="card-footer">
                    <div class="price-box">
                        <span class="price">$<?= number_format($row['price'], 2) ?></span> / day
                    </div>
                    <button class="book-btn" type="button"
                            data-add-to-cart
                            data-product-id="<?= $row['id'] ?>"
                            data-name="<?= htmlspecialchars($row['name']) ?>"
                            data-price="<?= $row['price'] ?>">
                        Book Now
                    </button>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p>No cars available in this category.</p>
    <?php endif; ?>
</div>

<!-- Cart Panel -->
<aside class="cart-panel" aria-labelledby="cart-title" aria-hidden="true" data-cart-panel>
    <div class="cart-panel__header">
        <h2 id="cart-title">Your cart</h2>
        <button class="icon-button" type="button" aria-label="Close cart" data-close-cart>&times;</button>
    </div>
    <div class="cart-items" data-cart-items></div>
    <p class="cart-empty" data-cart-empty>Your cart is empty.</p>
    <div class="cart-panel__footer">
        <div class="cart-dates" style="margin-bottom: 15px; display:flex; flex-direction:column; gap:8px;">
            <label style="display:flex; justify-content:space-between; align-items:center;">
                Start Date: <input type="date" id="cart-start-date">
            </label>
            <label style="display:flex; justify-content:space-between; align-items:center;">
                End Date: <input type="date" id="cart-end-date">
            </label>
            <div id="cart-days-msg" style="font-size: 13px; color: #666; text-align: right; min-height: 18px;"></div>
        </div>
        <div class="cart-total">
            <span>Total</span>
            <strong data-cart-total>$0</strong>
        </div>
        <button class="checkout-button" type="button" data-checkout>Checkout</button>
    </div>
</aside>
<div class="cart-overlay" data-cart-overlay></div>

<script src="script.js" defer></script>
</body>
</html>
<?php $conn->close(); ?>
