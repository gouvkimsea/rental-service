<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid or expired session. Please refresh and try again.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirmPassword'] ?? '';

        if (strlen($username) < 3) {
            $error = "Username must be at least 3 characters.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } elseif ($password !== $confirm) {
            $error = "Passwords do not match.";
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE name = ? OR email = ?");
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $error = "Username or email is already registered.";
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $username, $email, $hashed);
                if ($stmt->execute()) {
                    header("Location: login.php?registered=1");
                    exit();
                } else {
                    $error = "Registration failed. Please try again.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - JOUL Rental Service</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<div class="container">
    <div class="row justify-content-center align-items-center vh-100">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow-sm p-4 rounded-3 border">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <div class="text-center mb-3">
                        <a href="Service.php" class="logo text-decoration-none" style="font-size:24px;">JOUL Rental</a>
                        <p class="text-muted small mt-1">Create your customer rental account</p>
                    </div>

                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger py-2 small text-center mb-3"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <div class="form-group mb-3">
                        <label class="form-label small fw-semibold">Username</label>
                        <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Choose username" required autofocus minlength="3">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label small fw-semibold">Email Address</label>
                        <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="you@example.com" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label small fw-semibold">Password</label>
                        <input type="password" class="form-control" name="password" placeholder="At least 6 characters" required minlength="6">
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label small fw-semibold">Confirm Password</label>
                        <input type="password" class="form-control" name="confirmPassword" placeholder="Re-enter password" required minlength="6">
                    </div>
                    <div class="d-grid mb-3">
                        <button class="submit-button" type="submit">Create Account</button>
                    </div>
                    <p class="auth-switch text-center mb-0 small text-muted">
                        Already have an account? <a href="login.php" class="fw-semibold text-decoration-none" style="color:var(--color-forest);">Log in</a>
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
