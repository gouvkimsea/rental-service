<?php
// config.php - Database connection and session start

// Harden session cookie before starting the session
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');

session_start();

// Generate CSRF token once per session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$dbname = getenv('DB_NAME') ?: 'rentalkh';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    // Fallback to remote database if local connection fails
    $conn = @new mysqli('sql206.infinityfree.com', 'if0_42319720', 'itGnmRoSMiQFObz', 'if0_42319720_rentalkh');
}

if ($conn->connect_error) {
    error_log('DB connection failed: ' . $conn->connect_error);
    die('A server error occurred: Unable to connect to the database. Please ensure MySQL is running.');
}
date_default_timezone_set('Asia/Phnom_Penh');

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;
    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

?>