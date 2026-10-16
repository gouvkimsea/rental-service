<?php
require_once 'config.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

if (!$token || !$email) {
    die('Invalid link.');
}

// Validate token
$stmt = $conn->prepare("SELECT created_at FROM password_resets WHERE email = ? AND token = ?");
$stmt->bind_param("ss", $email, $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die('Invalid or expired password reset link.');
}
$reset_data = $result->fetch_assoc();

// Check expiration (e.g. 1 hour)
if (strtotime($reset_data['created_at']) < strtotime('-1 hour')) {
    $delExpired = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $delExpired->bind_param("s", $email);
    $delExpired->execute();
    die('This password reset link has expired. Please request a new one.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die('Invalid request.');
    }
    
    $new_password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $upd->bind_param("ss", $hashed, $email);
        $upd->execute();
        
        $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
        $del->bind_param("s", $email);
        $del->execute();
        
        $success = "Password reset successfully! You can now <a href='login.php'>Login</a>.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container { max-width: 400px; margin: 80px auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="container">
        <h2>Reset Password</h2>
        
        <?php if ($error): ?>
            <div class="alert" style="color:#a72d25; margin-bottom:15px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success" style="background:#dfe7d8; padding:10px; border-radius:4px;">
                <?= $success ?>
            </div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="password" required>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <button type="submit" class="submit-btn" style="width:100%;">Save Password</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
