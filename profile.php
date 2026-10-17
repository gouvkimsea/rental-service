<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request. Please go back and try again.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $phone          = substr(trim($_POST['phone'] ?? ''), 0, 30);
        $address        = substr(trim($_POST['address'] ?? ''), 0, 500);
        $driver_license = substr(trim($_POST['driver_license'] ?? ''), 0, 50);
        $id_card_number = substr(trim($_POST['id_card_number'] ?? ''), 0, 50);
        $email          = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);

        if (!$email) {
            $error = "Please provide a valid email address.";
        } else {
            // Check if email taken by someone else
            $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkEmail->bind_param("si", $email, $user_id);
            $checkEmail->execute();
            if ($checkEmail->get_result()->num_rows > 0) {
                $error = "This email is already registered to another account.";
            } else {
                $stmt = $conn->prepare("
                    UPDATE users 
                    SET email = ?, phone = ?, address = ?, driver_license = ?, id_card_number = ? 
                    WHERE id = ?
                ");
                $stmt->bind_param("sssssi", $email, $phone, $address, $driver_license, $id_card_number, $user_id);
                if ($stmt->execute()) {
                    $msg = "Profile information updated successfully.";
                } else {
                    $error = "Error updating profile.";
                }
            }
        }
    } elseif ($action === 'change_password') {
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $currHash = $stmt->get_result()->fetch_assoc()['password'] ?? '';

        $current_pass = $_POST['current_password'] ?? '';
        $new_pass_raw = $_POST['new_password'] ?? '';

        if (!password_verify($current_pass, $currHash) && $current_pass !== $currHash) {
            $error = "Current password is incorrect.";
        } elseif (strlen($new_pass_raw) < 6) {
            $error = "New password must be at least 6 characters.";
        } else {
            $new_pass = password_hash($new_pass_raw, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $upd->bind_param("si", $new_pass, $user_id);
            if ($upd->execute()) {
                $msg = "Password updated successfully.";
            } else {
                $error = "Error updating password.";
            }
        }
    }
}

// Fetch current user details
$stmt = $conn->prepare("SELECT name, email, phone, address, driver_license, id_card_number, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Profile - JOUL Rental</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .profile-container {
            max-width: 840px;
            margin: 32px auto;
            padding: 0 16px;
        }
        .profile-card {
            background: #fff;
            border: 1px solid var(--color-border, #e2e8f0);
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            margin-bottom: 24px;
        }
        .profile-avatar {
            width: 56px;
            height: 56px;
            background: var(--color-forest, #164e3f);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 750;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-full {
            grid-column: span 2;
        }
        @media (max-width: 640px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-full { grid-column: span 1; }
        }
    </style>
</head>
<body>
<header class="site-header">
    <a href="Service.php" class="logo">JOUL Rental</a>
    <nav class="site-nav">
        <a href="Service.php">Home</a>
        <a href="second1.php">Fleet Catalog</a>
        <a href="user_orders.php">My Bookings</a>
    </nav>
    <div class="action-bar">
        <a href="user_orders.php" class="cart-button" style="text-decoration:none;">My Bookings</a>
        <a href="logout.php" class="cart-button" style="text-decoration:none; background:#7f1d1d; color:#fff;">Logout</a>
    </div>
</header>

<main class="profile-container">
    <div class="profile-card">
        <div style="display:flex; align-items:center; gap:16px; margin-bottom:24px;">
            <div class="profile-avatar"><?= strtoupper(substr($user['name'], 0, 2)) ?></div>
            <div>
                <h1 style="margin:0 0 2px 0; font-size:22px; font-weight:800; color:var(--color-forest);"><?= htmlspecialchars($user['name']) ?></h1>
                <div style="color:#64748b; font-size:13px;">Customer account active since <?= date('F Y', strtotime($user['created_at'])) ?></div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success" style="margin-bottom:20px; padding:12px 16px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:6px; color:#065f46; font-size:14px;">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom:20px; padding:12px 16px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#991b1b; font-size:14px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Contact & Verification Details -->
        <h2 style="font-size:16px; font-weight:700; color:#0f172a; border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin:0 0 16px 0;">
            Personal &amp; Driver Verification Details
        </h2>
        <form method="post" style="margin-bottom: 32px;">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="update_profile">

            <div class="form-grid">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+855 12 345 678">
                </div>
                <div class="form-group">
                    <label>Driver's License #</label>
                    <input type="text" name="driver_license" value="<?= htmlspecialchars($user['driver_license'] ?? '') ?>" placeholder="Required for car/van rentals">
                </div>
                <div class="form-group">
                    <label>National ID / Passport #</label>
                    <input type="text" name="id_card_number" value="<?= htmlspecialchars($user['id_card_number'] ?? '') ?>" placeholder="Identity verification code">
                </div>
                <div class="form-group form-full">
                    <label>Residential / Hotel Address</label>
                    <textarea name="address" rows="2" placeholder="Your street address or hotel for rental delivery..."><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                </div>
            </div>
            <button type="submit" class="submit-btn" style="margin-top:14px; width:auto; padding:9px 20px;">Save Profile Details</button>
        </form>

        <!-- Change Password -->
        <h2 style="font-size:16px; font-weight:700; color:#0f172a; border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin:0 0 16px 0;">
            Change Account Password
        </h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="change_password">

            <div class="form-grid">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label>New Password (min 6 characters)</label>
                    <input type="password" name="new_password" required minlength="6">
                </div>
            </div>
            <button type="submit" class="submit-btn" style="margin-top:14px; width:auto; padding:9px 20px; background:#334155;">Update Password</button>
        </form>

        <div style="margin-top:32px; padding-top:16px; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <a href="Service.php" style="text-decoration:none; color:#64748b; font-size:13px;">&larr; Back to Fleet Storefront</a>
            <a href="user_orders.php" class="primary-link" style="text-decoration:none; font-size:13px; padding:7px 14px;">View My Bookings &rarr;</a>
        </div>
    </div>
</main>
</body>
</html>
