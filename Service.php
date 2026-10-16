<?php
require_once 'config.php';

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
  <title>Rental car</title>
  <link rel="stylesheet" href="style.css">
  <link href="https://fonts.googleapis.com/css2?family=Odibee+Sans&family=Saira+Semi+Condensed&display=swap" rel="stylesheet">
</head>
<body>
<header class="site-header">
    <a class="logo" href="#top">Joul Rental</a>
    <nav class="site-nav">
        <a href="#catalog">Catalog</a>
        <a href="#mission">Mission</a>
        <a href="#contact">Contact</a>
    </nav>
   <div class="action-bar">
   <a href="user_orders.php">
        <button class="cart-button" type="button">My Orders</button>
    </a> 
    <a href="<?= $name ? 'profile.php' : 'login.php' ?>">
        <button class="cart-button" type="button"><?= $name ? 'Profile' : 'Login' ?></button>
    </a>
   </div>
    
    
</header>

<main id="top">
    <section class="hero">
        <div class="hero__content">
            <p class="eyebrow">Explore the world with rental cars</p>
            <h1 id="hero-title">On The Road To Adventure</h1>
            <p>Discover the freedom of the open road with our rental cars. Whether you're planning a weekend getaway or a long road trip, we have the perfect vehicle for your journey.</p>
            <a class="primary-link" href="#catalog">Shop the catalog</a>
        </div>
        <div class="hero__image-wrap">
    <img src="img/car1.png" alt="Car" class="hero__image" id="heroImage">
</div>
    </section>

    <section class="use-cases">
        <div>Home and atmosphere</div>
        <div>Office and mood</div>
        <div>A gift and a person</div>
        <div>Joy for no reason</div>
    </section>

    <section class="section" id="mission">
        <p class="eyebrow">Our mission</p>
        <h2>Explore the world</h2>
        <div class="mission-grid">
            <article><h3>Getting the best experience</h3><p>Your journey starts with safety, affordability, and reliability.</p></article>
            <article><h3>Affordable</h3><p>Rent smart. Drive safe. Travel affordably.</p></article>
            <article><h3>Safety and insurance</h3><p>Your trusted partner for safe, reliable, and affordable travel</p></article>
        </div>
    </section>

    <section class="section" id="catalog">
        <p class="eyebrow">Catalog</p>
        <h2>Choose Your Service Choice.</h2>
        <div class="product-grid">
           <?php

$catImages = [
    'City Car' => 'car1.png',
    'Van'     => 'car4.png',
    'Bicycle'     => 'car6.png',
    'Scooter'     => 'car5.png',
];
$defaultImage = 'car2.png';
?>

<?php while ($cat = $cat_result->fetch_assoc()): ?>
    <?php $img = $catImages[$cat['name']] ?? $defaultImage; ?>
    <article class="product-card">
      <img src="img/<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($cat['name']) ?>" loading="lazy">
        <div class="product-card__body">
            <p class="product-card__category"><?= htmlspecialchars($cat['name']) ?></p>
            <h3><?= strtoupper(htmlspecialchars($cat['name'])) ?></h3>
            <div class="product-card__footer">
                <a href="second1.php?category=<?= $cat['id'] ?>"><button type="button">See more</button></a>
            </div>
        </div>
    </article>
<?php endwhile; ?>
        </div>
    </section>

    <section class="contact" id="contact">
        <div>
            <p class="eyebrow">Get in touch</p>
            <h2>Do you have any questions?</h2>
            <p class="eyebrow">Visit us now</p>
            <a class="primary-link primary-link--light" href="https://youtu.be/dQw4w9WgXcQ?si=SIQt5dQ_iu3bM3lt">Rental@gmail.edu</a>
        </div>
        <div class="map-embed">
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3007.769389202288!2d104.89540667362697!3d11.582407943813374!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3109517bf7757d23%3A0x965c34888684bf1!2sParagon%20International%20University!5e1!3m2!1sen!2skh!4v1782904226703!5m2!1sen!2skh" allowfullscreen loading="lazy"></iframe>
        </div>
        <ul class="hours-list">
            <li><span>Sunday</span><span>Closed</span></li>
            <li><span>Monday</span><span>9:00 a.m. – 8:00 p.m.</span></li>
            <li><span>Tuesday</span><span>9:00 a.m. – 8:00 p.m.</span></li>
            <li><span>Wednesday</span><span>9:00 a.m. – 8:00 p.m.</span></li>
            <li><span>Thursday</span><span>9:00 a.m. – 8:00 p.m.</span></li>
            <li><span>Friday</span><span>9:00 a.m. – 8:00 p.m.</span></li>
            <li><span>Saturday</span><span>9:00 a.m. – 6:00 p.m.</span></li>
        </ul>
    </section>
    <script>
    const heroImages = ['img/car1.png', 'img/car2.png', 'img/car5.png', 'img/car4.png'];
    let currentIndex = 0;
    const heroImg = document.getElementById('heroImage');

    setInterval(() => {
        currentIndex = (currentIndex + 1) % heroImages.length;
        heroImg.style.opacity = 0;

        setTimeout(() => {
            heroImg.src = heroImages[currentIndex];
            heroImg.style.opacity = 1;
        }, 300); 
    }, 2000); 
</script>
</main>
</body>
</html>
