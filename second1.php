<?php
require_once 'config.php';

$category_id = isset($_GET['category']) ? (int)$_GET['category'] : 0;

$all_cats = $conn->query("SELECT id, name FROM categories ORDER BY name");

// Build query to only show active, unarchived vehicles
$sql = "SELECT p.*, c.name AS category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.is_archived = 0 AND p.status != 'inactive'";
if ($category_id > 0) {
    $sql .= " AND p.category_id = $category_id";
}
$sql .= " ORDER BY (p.status = 'available') DESC, p.id DESC";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Vehicles - RentalKH</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Odibee+Sans&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .badge { padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-available { background: #dfe7d8; color: #2d5a27; }
        .badge-maintenance { background: #fef08a; color: #854d0e; }
        .badge-rented { background: #e0f2fe; color: #0284c7; }
        .spec-pill { display: inline-block; background: #f1f5f9; color: #475569; font-size: 11px; padding: 2px 7px; border-radius: 4px; margin-right: 4px; margin-bottom: 4px; }
    </style>
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
    <div style="display:flex; align-items:center; gap:12px;">
        <a href="user_orders.php" style="color:#222; text-decoration:none; font-weight:600; font-size:14px;">My Bookings</a>
        <button class="cart-button" type="button" data-open-cart>
            Cart <span data-cart-count>0</span>
        </button>
    </div>
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
    <p style="margin:0; color:#555;">Select your preferred vehicle and choose rental dates in your cart to book immediately.</p>
</div>

<div class="card-container">
    <?php if ($result && $result->num_rows > 0): ?>
        <?php while ($row = $result->fetch_assoc()): ?>
            <?php 
                $isMaintenance = ($row['status'] === 'maintenance'); 
                $isBookable = !$isMaintenance && ($row['status'] !== 'inactive');
            ?>
            <div class="card" style="<?= !$isBookable ? 'opacity: 0.75;' : '' ?>">
                <div class="card-top" style="position:relative;">
                    <img src="<?= htmlspecialchars($row['image_name'] ? 'image/'.$row['image_name'] : 'img/car8.png') ?>" alt="<?= htmlspecialchars($row['name']) ?>">
                    <span class="badge badge-<?= htmlspecialchars($row['status']) ?>" style="position:absolute; top:12px; right:12px;">
                        <?= ucfirst($row['status']) ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="vehicle-type"><?= htmlspecialchars($row['category_name']) ?></div>
                    <h2 class="vehicle-name" style="margin-bottom:6px;"><?= htmlspecialchars($row['name']) ?></h2>
                    
                    <!-- Vehicle Specifications -->
                    <div style="margin-bottom: 10px;">
                        <?php if ($row['transmission'] && $row['transmission'] !== 'N/A'): ?>
                            <span class="spec-pill"><?= htmlspecialchars($row['transmission']) ?></span>
                        <?php endif; ?>
                        <?php if ($row['fuel_type']): ?>
                            <span class="spec-pill"><?= htmlspecialchars($row['fuel_type']) ?></span>
                        <?php endif; ?>
                        <span class="spec-pill"><?= $row['seats'] ?> Seat<?= $row['seats'] > 1 ? 's' : '' ?></span>
                        <span class="spec-pill" style="color:#166534; background:#f0fdf4;">Condition: <?= ucwords(str_replace('_', ' ', $row['condition_status'])) ?></span>
                    </div>

                    <?php if ($row['description']): ?>
                        <p style="font-size:12px; color:#666; margin:0 0 10px 0; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
                            <?= htmlspecialchars($row['description']) ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <div class="price-box">
                        <span class="price">$<?= number_format($row['price'], 2) ?></span> / day
                    </div>
                    <?php if ($isBookable): ?>
                        <button class="book-btn" type="button"
                                data-add-to-cart
                                data-product-id="<?= $row['id'] ?>"
                                data-name="<?= htmlspecialchars($row['name']) ?>"
                                data-price="<?= $row['price'] ?>">
                            Book Now
                        </button>
                    <?php else: ?>
                        <button class="book-btn" type="button" disabled style="background:#ccc; cursor:not-allowed;">
                            <?= $isMaintenance ? 'Under Service' : 'Unavailable' ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p style="padding:40px; color:#666; text-align:center;">No vehicles currently available in this category.</p>
    <?php endif; ?>
</div>

<!-- Cart & Booking Modal Panel -->
<aside class="cart-panel" aria-labelledby="cart-title" aria-hidden="true" data-cart-panel>
    <div class="cart-panel__header">
        <h2 id="cart-title">Your Rental Cart</h2>
        <button class="icon-button" type="button" aria-label="Close cart" data-close-cart>&times;</button>
    </div>
    <div class="cart-items" data-cart-items></div>
    <p class="cart-empty" data-cart-empty>Your cart is empty.</p>
    <div class="cart-panel__footer">
        <div class="cart-dates" style="margin-bottom: 15px; display:flex; flex-direction:column; gap:10px;">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                <label style="font-size:12px; font-weight:600;">
                    Pickup Date:
                    <input type="date" id="cart-start-date" style="width:100%; padding:6px; margin-top:2px; border-radius:4px; border:1px solid #ccc;">
                </label>
                <label style="font-size:12px; font-weight:600;">
                    Pickup Time:
                    <input type="time" id="cart-start-time" value="09:00" style="width:100%; padding:6px; margin-top:2px; border-radius:4px; border:1px solid #ccc;">
                </label>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                <label style="font-size:12px; font-weight:600;">
                    Return Date:
                    <input type="date" id="cart-end-date" style="width:100%; padding:6px; margin-top:2px; border-radius:4px; border:1px solid #ccc;">
                </label>
                <label style="font-size:12px; font-weight:600;">
                    Return Time:
                    <input type="time" id="cart-end-time" value="18:00" style="width:100%; padding:6px; margin-top:2px; border-radius:4px; border:1px solid #ccc;">
                </label>
            </div>

            <label style="font-size:12px; font-weight:600;">
                Delivery / Hotel Address (Optional):
                <input type="text" id="cart-address" placeholder="e.g., Hotel Name, Room 302 or Airport Pickup" style="width:100%; padding:6px; margin-top:2px; border-radius:4px; border:1px solid #ccc;">
            </label>

            <div id="cart-days-msg" style="font-size: 13px; color: #666; text-align: right; min-height: 18px;"></div>
        </div>

        <div class="cart-total" style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #eee; padding-top:10px;">
            <span>Estimated Total:</span>
            <strong data-cart-total style="font-size:1.3em;">$0</strong>
        </div>
        <button class="checkout-button" type="button" data-checkout style="margin-top:12px;">Confirm & Book Now</button>
    </div>
</aside>
<div class="cart-overlay" data-cart-overlay></div>

<script src="script.js" defer></script>
</body>
</html>
<?php $conn->close(); ?>
