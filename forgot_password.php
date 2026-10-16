<?php
require_once 'config.php';

$message = '';
$simulated_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die('Invalid request.');
    }
    
    $email = trim($_POST['email']);
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 1) {
        $token = bin2hex(random_bytes(32));
        
        // Insert or update token
        $insert = $conn->prepare("REPLACE INTO password_resets (email, token, created_at) VALUES (?, ?, NOW())");
        $insert->bind_param("ss", $email, $token);
        $insert->execute();
        
        // Simulate email
        $simulated_link = "reset_password.php?email=" . urlencode($email) . "&token=" . $token;
        $message = "If the email exists, a password reset link has been sent.";
    } else {
        // Generic message prevents email enumeration
        $message = "If the email exists, a password reset link has been sent.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container { max-width: 400px; margin: 80px auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .simulated-email { margin-top: 20px; padding: 15px; background: #eef5eb; border-left: 4px solid #a72d25; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Forgot Password</h2>
        <p style="margin-bottom:20px; color:#666;">Enter your email to receive a password reset link.</p>
        
        <?php if ($message): ?>
            <div class="alert alert-success" style="background:#dfe7d8; padding:10px; border-radius:4px; margin-bottom:15px;">
                <?= htmlspecialchars($message) ?>
            </div>
            <?php if ($simulated_link): ?>
                <div class="simulated-email">
                    <strong>[Simulation - Email not sent in local dev]</strong><br><br>
                    Please click this link to reset your password:<br>
                    <a href="<?= $simulated_link ?>">Reset Password</a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required>
                </div>
                <button type="submit" class="submit-btn" style="width:100%;">Send Reset Link</button>
            </form>
        <?php endif; ?>
        <p style="text-align:center; margin-top:20px;">
            <a href="login.php" style="color:#666;">Back to Login</a>
        </p>
    </div>
</body>
</html>
