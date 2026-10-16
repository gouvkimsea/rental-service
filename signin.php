<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm = $_POST['confirmPassword'];

    if ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
       
        $check = $conn->prepare("SELECT id FROM users WHERE name = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $error = "Username or email already taken.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $email, $hashed);
            if ($stmt->execute()) {
                header("Location: login.php");
                exit();
            } else {
                $error = "Registration failed. Please try again.";
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
    <title>Sign Up</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Odibee+Sans&display=swap" rel="stylesheet">
</head>
<body>
<div class="container">
    <div class="row justify-content-center align-items-center vh-100">
        <div class="col-md-5">
            <div class="card shadow p-4 rounded-4">
                <form method="post">
                    <h2 class="text-center mb-4">Sign Up</h2>
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger text-center py-2"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <div class="form-group mb-3">
                        <label><b>Username</b></label>
                        <input type="text" class="form-control" name="username" placeholder="Username" required>
                    </div>
                    <div class="form-group mb-3">
                        <label><b>Email</b></label>
                        <input type="email" class="form-control" name="email" placeholder="Enter email" required>
                    </div>
                    <div class="form-group mb-3">
                        <label><b>Password</b></label>
                        <input type="password" class="form-control" name="password" placeholder="Enter password" required>
                    </div>
                    <div class="form-group mb-3">
                        <label><b>Confirm Password</b></label>
                        <input type="password" class="form-control" name="confirmPassword" placeholder="Confirm password" required>
                    </div>
                    <div class="d-grid mb-3">
                        <button class="submit-button" type="submit">Sign Up</button>
                    </div>
                    <p class="auth-switch text-center">Already have account? <a href="login.php">Log in</a></p>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
