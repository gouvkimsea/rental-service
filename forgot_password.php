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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - JOUL Rental Service</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<div class="container">
    <div class="row justify-content-center align-items-center vh-100">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow-sm p-4 rounded-3 border">
                <div class="text-center mb-3">
                    <a href="Service.php" class="logo text-decoration-none" style="font-size:24px;">JOUL Rental</a>
                    <p class="text-muted small mt-1">Account Password Recovery</p>
                </div>
                
                <?php if ($message): ?>
                    <div class="alert alert-success py-2 small text-center mb-3">
                        <?= htmlspecialchars($message) ?>
                    </div>
                    <?php if ($simulated_link): ?>
                        <div class="p-3 mb-3 bg-light border-start border-4 border-success rounded-end small">
                            <span class="text-muted d-block mb-1 fw-bold">[Development Simulator: Email Dispatch]</span>
                            Click the link below to set a new password:
                            <div class="mt-2">
                                <a href="<?= htmlspecialchars($simulated_link) ?>" class="btn btn-sm btn-outline-success w-100">Open Password Reset Link</a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="text-center mt-3">
                        <a href="login.php" class="text-decoration-none small text-muted">&larr; Return to Login</a>
                    </div>
                <?php else: ?>
                    <p class="text-muted small text-center mb-3">Enter the email registered with your account and we will send you a reset link.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <div class="form-group mb-3">
                            <label class="form-label small fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="you@example.com" required autofocus>
                        </div>
                        <div class="d-grid mb-3">
                            <button type="submit" class="submit-button">Send Reset Link</button>
                        </div>
                        <div class="text-center">
                            <a href="login.php" class="text-decoration-none small text-muted">&larr; Back to Login</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
