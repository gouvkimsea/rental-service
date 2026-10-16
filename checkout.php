<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in first.']);
    exit();
}

// Limit request body to 1 MB to prevent memory exhaustion
$raw = file_get_contents('php://input', false, null, 0, 1048576);
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

$cart = $data['cart'] ?? [];
$start_date = $data['start_date'] ?? null;
$end_date = $data['end_date'] ?? null;

if (empty($cart)) {
    echo json_encode(['success' => false, 'message' => 'Cart is empty.']);
    exit();
}
if (!$start_date || !$end_date) {
    echo json_encode(['success' => false, 'message' => 'Rental dates are required.']);
    exit();
}

$start_time = strtotime($start_date);
$end_time = strtotime($end_date);
$today_time = strtotime(date('Y-m-d')) - 86400; // 1-day grace period for timezones

if (!$start_time || !$end_time || $start_time < $today_time || $end_time < $start_time) {
    echo json_encode(['success' => false, 'message' => 'Invalid rental dates.']);
    exit();
}

$days = ceil(($end_time - $start_time) / 86400) + 1;
$user_id = $_SESSION['user_id'];
$total = 0;

$product_ids = array_keys($cart);
$placeholders = implode(',', array_fill(0, count($product_ids), '?'));
$types = str_repeat('i', count($product_ids));
$stmt = $conn->prepare("SELECT id, price FROM products WHERE id IN ($placeholders)");
$stmt->bind_param($types, ...$product_ids);
$stmt->execute();
$result = $stmt->get_result();
$prices = [];
while ($row = $result->fetch_assoc()) {
    $prices[$row['id']] = $row['price'];
}

$order_items = [];
foreach ($cart as $id => $qty) {
    $qty = (int)$qty;
    // Reject negative, zero, or unreasonably large quantities
    if ($qty <= 0 || $qty > 100) continue;
    if (isset($prices[$id])) {
        $price = $prices[$id];
        $total += ($price * $qty) * $days;
        $order_items[] = ['product_id' => $id, 'quantity' => $qty, 'price' => $price];
    }
}

if (empty($order_items)) {
    echo json_encode(['success' => false, 'message' => 'Invalid products in cart.']);
    exit();
}

$conn->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
$conn->begin_transaction();
try {
    // Lock the products to serialize concurrent checkouts for the same products
    $lock_stmt = $conn->prepare("SELECT id, quantity FROM products WHERE id IN ($placeholders) FOR UPDATE");
    $lock_stmt->bind_param($types, ...$product_ids);
    $lock_stmt->execute();
    $lock_result = $lock_stmt->get_result();
    $product_quantities = [];
    while ($row = $lock_result->fetch_assoc()) {
        $product_quantities[$row['id']] = $row['quantity'];
    }

    // Check Availability for all products in cart by summing booked quantities
    $conflict_ids = [];
    foreach ($order_items as $item) {
        $conflict_ids[] = $item['product_id'];
    }
    $c_placeholders = implode(',', array_fill(0, count($conflict_ids), '?'));
    $c_types = str_repeat('i', count($conflict_ids)) . 'ss';
    $check_sql = "
        SELECT oi.product_id, SUM(oi.quantity) as booked_qty
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        WHERE oi.product_id IN ($c_placeholders)
          AND o.status != 'Declined'
          AND NOT (o.end_date < ? OR o.start_date > ?)
        GROUP BY oi.product_id
    ";
    $check_stmt = $conn->prepare($check_sql);
    $bind_params = $conflict_ids;
    $bind_params[] = $start_date;
    $bind_params[] = $end_date;
    $check_stmt->bind_param($c_types, ...$bind_params);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    $booked_quantities = [];
    while ($row = $check_result->fetch_assoc()) {
        $booked_quantities[$row['product_id']] = (int)$row['booked_qty'];
    }

    foreach ($order_items as $item) {
        $pid = $item['product_id'];
        $req_qty = $item['quantity'];
        $total_qty = $product_quantities[$pid] ?? 0;
        $booked = $booked_quantities[$pid] ?? 0;
        
        if ($req_qty + $booked > $total_qty) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'One or more selected vehicles do not have enough availability during these dates.']);
            exit();
        }
    }

    // Insert order
    $stmt = $conn->prepare("INSERT INTO orders (user_id, total_amount, status, start_date, end_date) VALUES (?, ?, 'Pending', ?, ?)");
    $stmt->bind_param("idss", $user_id, $total, $start_date, $end_date);
    $stmt->execute();
    $order_id = $conn->insert_id;

    $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
    foreach ($order_items as $item) {
        $stmt->bind_param("iiid", $order_id, $item['product_id'], $item['quantity'], $item['price']);
        $stmt->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'order_id' => $order_id]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Order failed: ' . $e->getMessage()]);
}
?>