<?php
require_once 'config.php';
require_once 'rental_service.php';

$error = '';
$success = '';
$invalid_link_error = '';
$token = trim($_GET['token'] ?? '');
$email = trim($_GET['email'] ?? '');

if (!$token || !$email) {
    $invalid_link_error = 'Invalid or missing password reset link. Please verify the link or request a new one.';
} else {
    // Validate token
    $stmt = $conn->prepare("SELECT created_at FROM password_resets WHERE email = ? AND token = ?");
    $stmt->bind_param("ss", $email, $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $invalid_link_error = 'This password reset link is invalid or has already been used.';
    } else {
        $reset_data = $result->fetch_assoc();

        // Check expiration (1 hour)
        if (strtotime($reset_data['created_at']) < strtotime('-1 hour')) {
            $delExpired = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $delExpired->bind_param("s", $email);
            $delExpired->execute();
            $invalid_link_error = 'This password reset link has expired (links are valid for 1 hour). Please request a new one.';
        }
    }
}

if (!$invalid_link_error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $new_password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

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

            RentalService::logSystemAudit($conn, null, 'guest', 'auth_permission', 'PASSWORD_RESET', null, 'user', "Password reset completed successfully for email: $email");

            $success = "Your password has been reset successfully! You can now <a href='login.php' class='fw-bold'>Log in</a>.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - JOUL Rental Service</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #eef3f1; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .reset-card { width: 100%; max-width: 440px; background: #fff; padding: 32px; border-radius: 12px; box-shadow: 0 10px 30px rgba(25,42,50,0.08); border: 1px solid var(--color-border, #cbd8d4); }
        .reset-card h2 { color: var(--color-forest, #174f45); font-family: Inter, Arial, sans-serif; font-size: 24px; font-weight: 780; margin-bottom: 20px; text-align: center; }
        .submit-btn { background: var(--color-forest, #174f45); color: #fff; border: none; padding: 10px; border-radius: 6px; font-weight: 600; width: 100%; }
        .submit-btn:hover { background: #133f37; color: #fff; }
    </style>
</head>
<body>
    <div class="reset-card">
        <h2>Reset Password</h2>

        <?php if ($invalid_link_error): ?>
            <div class="alert alert-danger text-center py-3 mb-4">
                <?= htmlspecialchars($invalid_link_error) ?>
            </div>
            <div class="d-grid gap-2">
                <a href="forgot_password.php" class="btn btn-primary submit-btn text-center text-decoration-none">Request New Reset Link</a>
                <a href="login.php" class="btn btn-outline-secondary text-center">Back to Login</a>
            </div>
        <?php elseif ($success): ?>
            <div class="alert alert-success text-center py-3 mb-4">
                <?= $success ?>
            </div>
            <div class="d-grid">
                <a href="login.php" class="btn btn-primary submit-btn text-center text-decoration-none">Go to Login</a>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold">New Password</label>
                    <input type="password" name="password" class="form-control" placeholder="At least 6 characters" required autofocus minlength="6">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" required minlength="6">
                </div>
                <button type="submit" class="submit-btn mb-3">Save New Password</button>
                <div class="text-center">
                    <a href="login.php" class="text-muted small text-decoration-none">&larr; Back to Login</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
