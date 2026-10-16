<?php
// checkout.php - Secure Booking Checkout Handler with Concurrency Control
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in first.']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Limit request body to 1 MB
$raw = file_get_contents('php://input', false, null, 0, 1048576);
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
    exit();
}

$cart = $data['cart'] ?? [];
$start_date = trim($data['start_date'] ?? '');
$end_date = trim($data['end_date'] ?? '');
$start_time = trim($data['start_time'] ?? '09:00');
$end_time = trim($data['end_time'] ?? '18:00');
$shipping_address = isset($data['shipping_address']) ? substr(trim($data['shipping_address']), 0, 500) : null;

if (empty($cart)) {
    echo json_encode(['success' => false, 'message' => 'Your cart is empty.']);
    exit();
}

if (empty($start_date) || empty($end_date)) {
    echo json_encode(['success' => false, 'message' => 'Rental pickup and return dates are required.']);
    exit();
}

// Clean and sanitize cart keys and values
$sanitizedCart = [];
foreach ($cart as $pid => $qty) {
    $pid = (int)$pid;
    $qty = (int)$qty;
    if ($pid > 0 && $qty > 0 && $qty <= 100) {
        $sanitizedCart[$pid] = $qty;
    }
}

if (empty($sanitizedCart)) {
    echo json_encode(['success' => false, 'message' => 'No valid vehicles in cart.']);
    exit();
}

// Execute booking transaction via RentalService
$result = RentalService::createBooking(
    $conn,
    $user_id,
    $sanitizedCart,
    $start_date,
    $start_time,
    $end_date,
    $end_time,
    $shipping_address
);

if ($result['success']) {
    echo json_encode([
        'success' => true,
        'order_id' => $result['order_id'],
        'booking_ref' => $result['booking_ref'],
        'total_amount' => $result['pricing']['total_amount'],
        'subtotal' => $result['pricing']['subtotal'],
        'deposit_amount' => $result['pricing']['deposit_amount'],
        'days' => $result['pricing']['days'],
        'expires_at' => $result['expires_at'],
        'message' => "Booking {$result['booking_ref']} created successfully!"
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result['message']
    ]);
}