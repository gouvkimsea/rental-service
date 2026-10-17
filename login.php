<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Brute-force protection: lock out after 5 failed attempts
    if (($_SESSION['login_attempts'] ?? 0) >= 5) {
        $error_msg = "Too many failed attempts. Please close and reopen your browser to try again.";
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        $stmt = $conn->prepare("SELECT id, name, password, is_admin, is_active, role FROM users WHERE name = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if ($row['is_active'] == 0) {
                $error_msg = 'Your account has been disabled. Please contact support.';
            } elseif (password_verify($password, $row['password']) || $password === $row['password']) {
                if ($password === $row['password']) {
                    $new_hash = password_hash($password, PASSWORD_DEFAULT);
                    $up = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $up->bind_param("si", $new_hash, $row['id']);
                    $up->execute();
                }
                // Prevent session fixation — regenerate ID on privilege change
                session_regenerate_id(true);
                $_SESSION['login_attempts'] = 0;
                $_SESSION['user_id']   = (int)$row['id'];
                $_SESSION['username']  = $row['name'];
                $_SESSION['is_admin']  = (int)$row['is_admin'];
                $role = !empty($row['role']) ? $row['role'] : ($row['is_admin'] ? 'admin' : 'customer');
                $_SESSION['role']      = $role;

                RentalService::logSystemAudit($conn, (int)$row['id'], $role, 'auth_permission', 'LOGIN_SUCCESS', (int)$row['id'], 'user', "User {$row['name']} logged in successfully as $role");

                if (in_array($role, ['admin', 'rental_manager', 'finance_staff'], true)) {
                    header("Location: dashboard.php");
                } else {
                    header("Location: Service.php");
                }
                exit();
            }
        }
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        RentalService::logSystemAudit($conn, null, 'guest', 'auth_permission', 'LOGIN_FAILED', null, null, "Failed login attempt for username: $username");
        $error_msg = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In - JOUL Rental Service</title>
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
                        <p class="text-muted small mt-1">Sign in to your rental account</p>
                    </div>

                    <?php if (isset($_GET['registered'])): ?>
                        <div class="alert alert-success py-2 small text-center mb-3">Account created! You can now log in.</div>
                    <?php endif; ?>

                    <?php if (isset($error_msg)): ?>
                        <div class="alert alert-danger py-2 small text-center mb-3"><?= htmlspecialchars($error_msg) ?></div>
                    <?php endif; ?>

                    <div class="form-group mb-3">
                        <label class="form-label small fw-semibold">Username</label>
                        <input type="text" name="username" class="form-control" placeholder="Enter username" required autofocus>
                    </div>
                    <div class="form-group mb-2">
                        <label class="form-label small fw-semibold">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                    </div>
                    <div class="text-end mb-3">
                        <a href="forgot_password.php" class="text-muted small text-decoration-none">Forgot password?</a>
                    </div>
                    <div class="d-grid mb-3">
                        <button type="submit" class="submit-button">Sign In</button>
                    </div>
                    <p class="auth-switch text-center mb-0 small text-muted">
                        Don't have an account? <a href="signin.php" class="fw-semibold text-decoration-none" style="color:var(--color-forest);">Sign up</a>
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
