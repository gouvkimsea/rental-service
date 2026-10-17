<?php
require_once 'config.php';
require_once 'rental_service.php';

$category_id = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$name = $_SESSION['username'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

$all_cats = $conn->query("SELECT id, name FROM categories ORDER BY name");

// Build query to only show active, unarchived vehicles
$sql = "SELECT p.*, c.name AS category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.is_archived = 0 AND p.status != 'inactive'";
if ($category_id > 0) {
    $sql .= " AND p.category_id = " . (int)$category_id;
}
$sql .= " ORDER BY (p.status = 'available') DESC, p.id DESC";
$result = $conn->query($sql);

$activeCategoryName = 'All Vehicles';
if ($category_id > 0) {
    $c_stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
    $c_stmt->bind_param("i", $category_id);
    $c_stmt->execute();
    $c_res = $c_stmt->get_result();
    if ($c_res->num_rows > 0) {
        $activeCategoryName = $c_res->fetch_assoc()['name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($activeCategoryName) ?> - JOUL Rental Fleet</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .filter-btn {
            display: inline-flex;
            align-items: center;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
            border: 1px solid var(--color-border, #cbd8d4);
            color: var(--color-ink, #1e293b);
            background: #fff;
        }
        .filter-btn:hover {
            border-color: var(--color-forest, #164e3f);
            color: var(--color-forest, #164e3f);
        }
        .filter-btn.active {
            background: var(--color-forest, #164e3f);
            border-color: var(--color-forest, #164e3f);
            color: #fff;
        }
        .catalog-header {
            max-width: var(--max-width, 1200px);
            margin: 0 auto;
            padding: 32px clamp(16px, 4vw, 48px) 0;
        }
        .catalog-title {
            font-size: clamp(1.6rem, 3vw, 2.2rem);
            font-weight: 800;
            color: var(--color-forest, #164e3f);
            margin-bottom: 6px;
        }
        .card-container {
            max-width: var(--max-width, 1200px);
            margin: 0 auto;
            padding: 24px clamp(16px, 4vw, 48px) 48px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 20px;
        }
        .vehicle-card {
            background: #fff;
            border: 1px solid var(--color-border, #e2e8f0);
            border-radius: 10px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .vehicle-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
        }
        .vehicle-card-top {
            position: relative;
            height: 190px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .vehicle-card-top img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .vehicle-card-body {
            padding: 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .vehicle-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }
        .vehicle-reg-tag {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .vehicle-card-footer {
            padding: 14px 16px;
            border-top: 1px solid var(--color-border, #e2e8f0);
            background: #fafafa;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .cart-dates label {
            display: block;
            margin-bottom: 10px;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
        }
        .cart-dates input {
            width: 100%;
            margin-top: 4px;
            padding: 7px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
        }
    </style>
</head>
<body>
<nav class="site-header">
    <a href="Service.php" class="logo">JOUL Rental</a>
    <div style="display:flex; align-items:center; gap:12px;">
        <a href="Service.php#catalog" class="filter-btn">&larr; Fleet Categories</a>
        <a href="user_orders.php" class="filter-btn">My Bookings</a>
        <button class="cart-button" type="button" data-open-cart style="display:inline-flex; align-items:center; gap:6px;">
            Cart <span data-cart-count style="background:#fff; color:var(--color-forest); border-radius:10px; padding:1px 7px; font-weight:800; font-size:12px;">0</span>
        </button>
    </div>
</nav>

<div class="catalog-header">
    <div class="filter-bar">
        <a href="second1.php?category=0" class="filter-btn <?= $category_id === 0 ? 'active' : '' ?>">
            All Vehicles
        </a>
        <?php while ($c = $all_cats->fetch_assoc()): ?>
            <a href="second1.php?category=<?= $c['id'] ?>" class="filter-btn <?= $category_id == $c['id'] ? 'active' : '' ?>">
                <?= htmlspecialchars($c['name']) ?>
            </a>
        <?php endwhile; ?>
    </div>
    <div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px;">
        <h1 class="catalog-title"><?= htmlspecialchars($activeCategoryName) ?></h1>
        <p style="margin:0; color:#64748b; font-size:14px;">Select your preferred vehicle and choose rental dates in your cart to book instantly.</p>
    </div>
</div>

<div class="card-container">
    <?php if ($result && $result->num_rows > 0): ?>
        <?php while ($row = $result->fetch_assoc()): ?>
            <?php 
                $isMaintenance = ($row['status'] === 'maintenance'); 
                $isBookable = !$isMaintenance && ($row['status'] !== 'inactive');
            ?>
            <article class="vehicle-card" style="<?= !$isBookable ? 'opacity: 0.8;' : '' ?>">
                <div class="vehicle-card-top">
                    <img src="<?= htmlspecialchars($row['image_name'] ? 'image/'.$row['image_name'] : 'img/car1.png') ?>" alt="<?= htmlspecialchars($row['name']) ?>" loading="lazy">
                    <span class="badge badge-<?= htmlspecialchars($row['status']) ?>" style="position:absolute; top:12px; right:12px;">
                        <?= ucfirst($row['status']) ?>
                    </span>
                </div>
                <div class="vehicle-card-body">
                    <div class="vehicle-meta-row">
                        <span style="font-size:12px; font-weight:600; color:var(--color-forest);"><?= htmlspecialchars($row['category_name']) ?></span>
                        <?php if (!empty($row['reg_number'])): ?>
                            <span class="vehicle-reg-tag"><?= htmlspecialchars($row['reg_number']) ?></span>
                        <?php endif; ?>
                    </div>
                    <h2 class="vehicle-name" style="font-size:18px; margin:0 0 10px 0;"><?= htmlspecialchars($row['name']) ?></h2>
                    
                    <!-- Vehicle Specifications -->
                    <div style="display:flex; flex-wrap:wrap; gap:5px; margin-bottom:12px;">
                        <?php if ($row['transmission'] && $row['transmission'] !== 'N/A'): ?>
                            <span class="spec-pill"><?= htmlspecialchars($row['transmission']) ?></span>
                        <?php endif; ?>
                        <?php if ($row['fuel_type']): ?>
                            <span class="spec-pill"><?= htmlspecialchars($row['fuel_type']) ?></span>
                        <?php endif; ?>
                        <span class="spec-pill"><?= (int)$row['seats'] ?> Seat<?= $row['seats'] > 1 ? 's' : '' ?></span>
                        <span class="spec-pill">Condition: <?= ucwords(str_replace('_', ' ', $row['condition_status'])) ?></span>
                    </div>

                    <?php if ($row['description']): ?>
                        <p style="font-size:13px; color:#64748b; margin:0; line-height:1.5; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
                            <?= htmlspecialchars($row['description']) ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="vehicle-card-footer">
                    <div>
                        <span style="font-size:20px; font-weight:800; color:var(--color-forest);">$<?= number_format($row['price'], 2) ?></span>
                        <span style="font-size:12px; color:#64748b;">/ day</span>
                    </div>
                    <?php if ($isBookable): ?>
                        <button class="book-btn" type="button"
                                data-add-to-cart
                                data-product-id="<?= $row['id'] ?>"
                                data-name="<?= htmlspecialchars($row['name']) ?>"
                                data-price="<?= $row['price'] ?>"
                                style="padding:8px 16px; font-size:13px;">
                            Book Now
                        </button>
                    <?php else: ?>
                        <button class="book-btn" type="button" disabled style="background:#cbd5e1; color:#64748b; cursor:not-allowed; padding:8px 14px; font-size:13px;">
                            <?= $isMaintenance ? 'Under Maintenance' : 'Unavailable' ?>
                        </button>
                    <?php endif; ?>
                </div>
            </article>
        <?php endwhile; ?>
    <?php else: ?>
        <div style="grid-column: 1 / -1; padding: 60px 20px; text-align: center; background: #fff; border: 1px solid var(--color-border); border-radius: 10px;">
            <p style="font-size:16px; color:#64748b; margin-bottom:16px;">No vehicles are currently available in the "<?= htmlspecialchars($activeCategoryName) ?>" category.</p>
            <a href="second1.php?category=0" class="primary-link" style="display:inline-block; padding:8px 18px; font-size:14px;">Browse All Vehicles</a>
        </div>
    <?php endif; ?>
</div>

<!-- Cart & Booking Modal Drawer -->
<aside class="cart-panel" aria-labelledby="cart-title" aria-hidden="true" data-cart-panel>
    <div class="cart-panel__header">
        <h2 id="cart-title" style="margin:0; font-size:18px; font-weight:750;">Rental Booking Drawer</h2>
        <button class="icon-button" type="button" aria-label="Close cart" data-close-cart>&times;</button>
    </div>
    <div class="cart-items" data-cart-items></div>
    <p class="cart-empty" data-cart-empty>Your booking cart is empty. Select a vehicle to begin.</p>
    
    <div class="cart-panel__footer">
        <div class="cart-dates">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <label>
                    Pickup Date:
                    <input type="date" id="cart-start-date" required>
                </label>
                <label>
                    Pickup Time:
                    <input type="time" id="cart-start-time" value="09:00" required>
                </label>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <label>
                    Return Date:
                    <input type="date" id="cart-end-date" required>
                </label>
                <label>
                    Return Time:
                    <input type="time" id="cart-end-time" value="18:00" required>
                </label>
            </div>

            <label>
                Delivery / Hotel Address (Optional):
                <input type="text" id="cart-address" placeholder="e.g., Hotel Name, Room 302 or Airport Terminal">
            </label>

            <div id="cart-days-msg" style="font-size: 13px; font-weight:600; text-align: right; min-height: 18px; margin-top:4px;"></div>
        </div>

        <div class="cart-total" style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e2e8f0; padding:12px 0 6px;">
            <span style="font-size:14px; color:#475569;">Estimated Subtotal:</span>
            <strong data-cart-total style="font-size:1.3em; color:var(--color-forest);">$0</strong>
        </div>
        <p style="font-size:11px; color:#64748b; margin:0 0 12px 0;">* A standard refundable security deposit ($50) will be added to the final contract upon reservation.</p>
        <button class="checkout-button" type="button" data-checkout style="width:100%; padding:12px; font-size:15px; font-weight:700;">
            Confirm &amp; Book Now
        </button>
    </div>
</aside>
<div class="cart-overlay" data-cart-overlay></div>

<script src="script.js" defer></script>
</body>
</html>
