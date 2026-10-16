<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Use prepared statement and fetch password for old-password verification
$stmt = $conn->prepare("SELECT name, email, password FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['new_password'])) {
    // CSRF validation
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request. Please go back and try again.');
    }

    // Require current password before allowing a change
    $current_pass = $_POST['current_password'] ?? '';
    if (!password_verify($current_pass, $user['password'])) {
        $msg = "Current password is incorrect.";
    } else {
        $new_pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->bind_param("si", $new_pass, $user_id);
        if ($upd->execute()) {
            $msg = "Password updated successfully.";
        } else {
            $msg = "Error updating password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile</title>
    <link rel="stylesheet" href="style.css">
    <style>
.profile {
    width: 60px;
    height: 60px;
    background-color:#a72d25;
    color: white;
    border-radius: 50%;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 28px;
    font-weight: bold;
    font-family: Arial, sans-serif;
}
</style>
</head>
<body>
<div style="max-width:600px; margin:50px auto; padding:20px; background:#fff; border-radius:8px; box-shadow:0 0 10px rgba(0,0,0,0.1);">
    <div class="profile" id="profile"><?= strtoupper(substr($user['name'], 0, 2)) ?></div>
    <?php if (isset($msg)): ?><div class="alert"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <p><strong>Username:</strong> <?= htmlspecialchars($user['name']) ?></p>
    <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
    <hr>
    <h3>Change Password</h3>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <br>
        <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" class="form-group" required>
        </div>
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" class="form-group" required>
        </div>
        <button type="submit" class="submit-button">Update Password</button>
    </form>
    <br>
    <a href="logout.php" class="submit-button" style="display:inline-block; text-align:center; text-decoration:none; margin-top:8px;">Logout</a>
    <a href="Service.php" class="site-nav">Back to Home</a>
</div>
</body>
</html>
