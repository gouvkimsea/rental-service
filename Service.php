<?php
require_once 'config.php';
require_once 'rental_service.php';

$name = $_SESSION['username'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

// Fetch all categories to display as product cards
$cat_result = $conn->query("SELECT id, name FROM categories ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>JOUL Rental Service - Professional Vehicle & Fleet Rental</title>
  <link rel="stylesheet" href="style.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<header class="site-header">
    <a class="logo" href="Service.php">JOUL Rental</a>
    <nav class="site-nav">
        <a href="#catalog">Fleet Categories</a>
        <a href="second1.php">Browse All Vehicles</a>
        <a href="#standards">Safety Standards</a>
        <a href="#contact">Contact & Hours</a>
    </nav>
    <div class="action-bar">
        <a href="user_orders.php" class="cart-button" style="text-decoration:none;">My Bookings</a>
        <?php if ($name): ?>
            <?php 
                $uNotif = RentalService::getUnreadNotificationCount($conn, (int)$user_id, 'customer');
            ?>
            <a href="notifications.php" class="cart-button" style="text-decoration:none; display:inline-flex; align-items:center; gap:5px;">
                🔔 <?php if ($uNotif > 0): ?>
                    <span style="background:#dc2626; color:#fff; border-radius:10px; padding:1px 6px; font-size:11px; font-weight:700;"><?= $uNotif ?></span>
                <?php endif; ?>
            </a>
            <a href="profile.php" class="cart-button" style="text-decoration:none;">Profile</a>
            <a href="logout.php" class="cart-button" style="text-decoration:none; background:#7f1d1d; color:#fff;">Logout</a>
        <?php else: ?>
            <a href="login.php" class="cart-button" style="text-decoration:none;">Sign In</a>
        <?php endif; ?>
    </div>
</header>

<main id="top">
    <section class="hero">
        <div class="hero__content">
            <p class="eyebrow">Verified Fleet &bull; Transparent Escrow &bull; Phnom Penh</p>
            <h1 id="hero-title">Reliable Vehicle Rentals for Business & Travel</h1>
            <p>From fuel-efficient city cars and spacious passenger vans to clean electric scooters and bicycles. Every rental includes digital multi-point inspection, guaranteed turnaround buffer, and separate escrow deposit protection.</p>
            <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:20px;">
                <a class="primary-link" href="second1.php">Browse All Vehicles</a>
                <a class="primary-link" href="#catalog" style="background:#fff; color:var(--color-forest); border:1px solid var(--color-forest);">View Categories</a>
            </div>
        </div>
        <div class="hero__image-wrap">
            <img src="img/car1.png" alt="JOUL Rental Fleet Vehicle" class="hero__image" id="heroImage">
        </div>
    </section>

    <section class="use-cases">
        <div><strong>Instant Booking:</strong> Real-time availability & concurrency protection</div>
        <div><strong>Turnaround Buffer:</strong> 2-hour cleaning & safety inspection window</div>
        <div><strong>Escrow Protected:</strong> Deposits tracked & refunded seamlessly</div>
        <div><strong>Roadside Support:</strong> Dedicated maintenance dispatch in Phnom Penh</div>
    </section>

    <section class="section" id="standards">
        <p class="eyebrow">Fleet Assurance</p>
        <h2>Built on Operational Integrity</h2>
        <div class="mission-grid">
            <article>
                <h3>Pre-Handover Multi-Point Inspection</h3>
                <p>Every vehicle undergoes digital verification of tires, fluids, brakes, and odometer before keys are handed over, ensuring complete road safety.</p>
            </article>
            <article>
                <h3>Transparent Accounting & Escrow</h3>
                <p>Security deposits are held in a segregated escrow account and are strictly protected. Deductions occur only with documented fuel or damage reports.</p>
            </article>
            <article>
                <h3>Turnaround Buffers & Sanitation</h3>
                <p>Our booking engine enforces mandatory turnaround buffers between back-to-back rentals so vehicles are thoroughly washed, inspected, and serviced.</p>
            </article>
        </div>
    </section>

    <section class="section" id="catalog">
        <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:28px; flex-wrap:wrap; gap:16px;">
            <div>
                <p class="eyebrow" style="margin-bottom:4px;">Fleet Categories</p>
                <h2 style="margin:0;">Select Your Vehicle Category</h2>
            </div>
            <a href="second1.php" class="primary-link" style="padding:8px 16px; font-size:14px;">View All Vehicles &rarr;</a>
        </div>
        <div class="product-grid">
           <?php
            $catImages = [
                'City Car' => 'car1.png',
                'Van'      => 'car4.png',
                'Bicycle'  => 'car6.png',
                'Scooter'  => 'car5.png',
            ];
            $defaultImage = 'car2.png';
            ?>
            <?php while ($cat = $cat_result->fetch_assoc()): ?>
                <?php $img = $catImages[$cat['name']] ?? $defaultImage; ?>
                <article class="product-card">
                    <img src="img/<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($cat['name']) ?>" loading="lazy">
                    <div class="product-card__body">
                        <p class="product-card__category">Fleet Category</p>
                        <h3><?= htmlspecialchars($cat['name']) ?></h3>
                        <div class="product-card__footer" style="margin-top:14px;">
                            <a href="second1.php?category=<?= $cat['id'] ?>" class="primary-link" style="text-align:center; text-decoration:none; width:100%; font-size:13px; padding:9px 12px;">
                                Explore <?= htmlspecialchars($cat['name']) ?> Fleet &rarr;
                            </a>
                        </div>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
    </section>

    <section class="contact" id="contact">
        <div>
            <p class="eyebrow">Customer Support</p>
            <h2>Questions or Custom Bookings?</h2>
            <p style="color:#d1fae5; margin-bottom:18px;">Our fleet operations team is available 7 days a week to assist with individual, corporate, or long-term rentals.</p>
            <p style="margin:0 0 8px 0; font-size:15px; color:#fff;">
                <strong>Email:</strong> <a href="mailto:support@joulrental.com" style="color:#fff; text-decoration:underline;">support@joulrental.com</a>
            </p>
            <p style="margin:0 0 16px 0; font-size:15px; color:#fff;">
                <strong>Phone:</strong> +855 (0) 23 999 888
            </p>
            <p style="margin:0; font-size:14px; color:#a7f3d0;">
                St. 315, Boeng Kak 1, Tuol Kouk, Phnom Penh, Cambodia
            </p>
        </div>
        <div class="map-embed">
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3007.769389202288!2d104.89540667362697!3d11.582407943813374!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3109517bf7757d23%3A0x965c34888684bf1!2sParagon%20International%20University!5e1!3m2!1sen!2skh!4v1782904226703!5m2!1sen!2skh" title="Office Location Map" allowfullscreen loading="lazy"></iframe>
        </div>
        <ul class="hours-list">
            <li><span>Sunday</span><span>8:00 a.m. – 6:00 p.m.</span></li>
            <li><span>Monday</span><span>8:00 a.m. – 8:00 p.m.</span></li>
            <li><span>Tuesday</span><span>8:00 a.m. – 8:00 p.m.</span></li>
            <li><span>Wednesday</span><span>8:00 a.m. – 8:00 p.m.</span></li>
            <li><span>Thursday</span><span>8:00 a.m. – 8:00 p.m.</span></li>
            <li><span>Friday</span><span>8:00 a.m. – 8:00 p.m.</span></li>
            <li><span>Saturday</span><span>8:00 a.m. – 8:00 p.m.</span></li>
        </ul>
    </section>
</main>
</body>
</html>
