<?php
// tests/rental_business_tests.php - Automated Business Logic & Concurrency Test Suite
require_once __DIR__ . '/../config.php';

echo "========================================================\n";
echo "   RentalKH Business Logic & Booking Engine Test Suite  \n";
echo "========================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $testName, bool $condition, string $detail = '') {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "[PASS] $testName" . ($detail ? " ($detail)" : "") . "\n";
        $testsPassed++;
    } else {
        echo "[FAIL] $testName" . ($detail ? " - ERROR: $detail" : "") . "\n";
        $testsFailed++;
    }
}

// Ensure test user exists
$conn->query("
    INSERT INTO users (name, password, email, is_admin, is_active) 
    VALUES ('test_customer', 'hash', 'test_customer@rental.com', 0, 1) 
    ON DUPLICATE KEY UPDATE is_active = 1
");
$testUser = $conn->query("SELECT id FROM users WHERE name = 'test_customer'")->fetch_assoc();
$userId = (int)$testUser['id'];

// Create dedicated test vehicle
$conn->query("
    INSERT INTO products (name, category_id, reg_number, price, hourly_price, quantity, status, condition_status, buffer_hours, is_archived)
    VALUES ('Test Unit Moto 150', 1, 'TEST-MOTO-01', 25.00, 5.00, 1, 'available', 'excellent', 2, 0)
    ON DUPLICATE KEY UPDATE status = 'available', is_archived = 0, quantity = 1, price = 25.00, buffer_hours = 2
");
$testProd = $conn->query("SELECT id FROM products WHERE reg_number = 'TEST-MOTO-01'")->fetch_assoc();
$prodId = (int)$testProd['id'];

// Clean previous test orders for this test vehicle
$conn->query("DELETE o FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.product_id = $prodId");
$conn->query("DELETE FROM vehicle_maintenance WHERE product_id = $prodId");

// ---------------------------------------------------------
// TEST 1: Pricing Calculation (Server-Side)
// ---------------------------------------------------------
echo "\n--- 1. Testing Server-Side Pricing Calculation ---\n";
$start = date('Y-m-d', strtotime('+10 days'));
$end   = date('Y-m-d', strtotime('+13 days')); // 4 days rental
$pricing = RentalService::calculatePricing($conn, [$prodId => 1], $start, '09:00', $end, '18:00');

assertTest(
    "Server pricing calculation",
    $pricing['success'] && $pricing['days'] === 4 && $pricing['subtotal'] == 100.00,
    "Expected 4 days @ \$25 = \$100.00 subtotal, calculated: \${$pricing['subtotal']}"
);

// ---------------------------------------------------------
// TEST 2: Invalid Dates Validation
// ---------------------------------------------------------
echo "\n--- 2. Testing Date & Time Validation ---\n";
// Past date
$pastRes = RentalService::createBooking($conn, $userId, [$prodId => 1], '2020-01-01', '09:00', '2020-01-05', '18:00');
assertTest("Reject past start date", !$pastRes['success'], $pastRes['message'] ?? '');

// End date before start date
$invRes = RentalService::createBooking($conn, $userId, [$prodId => 1], $end, '09:00', $start, '18:00');
assertTest("Reject end date before start date", !$invRes['success'], $invRes['message'] ?? '');

// ---------------------------------------------------------
// TEST 3: Valid Booking Creation
// ---------------------------------------------------------
echo "\n--- 3. Testing Valid Booking Creation ---\n";
$bookStart = date('Y-m-d', strtotime('+20 days'));
$bookEnd   = date('Y-m-d', strtotime('+22 days')); // 3 days

$bRes1 = RentalService::createBooking(
    $conn,
    $userId,
    [$prodId => 1],
    $bookStart,
    '10:00',
    $bookEnd,
    '14:00',
    'Hotel Intercontinental, Room 402'
);

$booking1Id = $bRes1['order_id'] ?? 0;
$booking1Ref = $bRes1['booking_ref'] ?? '';

assertTest(
    "Create valid booking",
    $bRes1['success'] && !empty($booking1Ref) && str_starts_with($booking1Ref, 'RNT-'),
    "Created Booking Ref: $booking1Ref (ID: $booking1Id)"
);

// Verify database record
$savedOrder = $conn->query("SELECT * FROM orders WHERE id = $booking1Id")->fetch_assoc();
assertTest(
    "Database booking verification",
    $savedOrder['status'] === 'Pending' && !empty($savedOrder['expires_at']),
    "Status: {$savedOrder['status']}, Expires at: {$savedOrder['expires_at']}"
);

// ---------------------------------------------------------
// TEST 4: Overlapping Bookings Prevention
// ---------------------------------------------------------
echo "\n--- 4. Testing Overlapping Bookings Prevention ---\n";
// Attempt to book exactly during the same window
$overlapRes = RentalService::createBooking(
    $conn,
    $userId,
    [$prodId => 1],
    date('Y-m-d', strtotime('+21 days')),
    '10:00',
    date('Y-m-d', strtotime('+23 days')),
    '14:00'
);
assertTest("Reject overlapping booking", !$overlapRes['success'], $overlapRes['message'] ?? '');

// ---------------------------------------------------------
// TEST 5: Turnaround Buffer Hours Prevention
// ---------------------------------------------------------
echo "\n--- 5. Testing Turnaround Buffer Enforcement ---\n";
// Vehicle buffer is 2 hours. Booking ends at +22 days 14:00.
// Trying to book at +22 days 15:00 (within the 2-hour buffer window) should fail!
$bufferOverlapRes = RentalService::createBooking(
    $conn,
    $userId,
    [$prodId => 1],
    $bookEnd,
    '15:00', // Only 1 hour after previous return, buffer requires 2 hours!
    date('Y-m-d', strtotime('+24 days')),
    '12:00'
);
assertTest("Reject booking within turnaround buffer", !$bufferOverlapRes['success'], $bufferOverlapRes['message'] ?? '');

// Booking AFTER buffer (+22 days 17:00, 3 hours after return) should succeed!
$afterBufferRes = RentalService::createBooking(
    $conn,
    $userId,
    [$prodId => 1],
    $bookEnd,
    '17:00',
    date('Y-m-d', strtotime('+24 days')),
    '12:00'
);
assertTest("Allow booking after turnaround buffer", $afterBufferRes['success'], $afterBufferRes['booking_ref'] ?? '');

// ---------------------------------------------------------
// TEST 6: Vehicle Under Maintenance Availability
// ---------------------------------------------------------
echo "\n--- 6. Testing Maintenance Window Conflict ---\n";
// Schedule maintenance for this vehicle at +30 to +32 days
$mStart = date('Y-m-d', strtotime('+30 days'));
$mEnd   = date('Y-m-d', strtotime('+32 days'));
$conn->query("
    INSERT INTO vehicle_maintenance (product_id, maintenance_type, start_date, end_date, status)
    VALUES ($prodId, 'Brake Replacement', '$mStart', '$mEnd', 'scheduled')
");

$mConflictRes = RentalService::createBooking(
    $conn,
    $userId,
    [$prodId => 1],
    date('Y-m-d', strtotime('+31 days')),
    '09:00',
    date('Y-m-d', strtotime('+33 days')),
    '18:00'
);
assertTest("Reject booking during scheduled maintenance", !$mConflictRes['success'], $mConflictRes['message'] ?? '');

// ---------------------------------------------------------
// TEST 7: Inactive / Archived Vehicle Prevention
// ---------------------------------------------------------
echo "\n--- 7. Testing Inactive & Archived Vehicle Protection ---\n";
$conn->query("UPDATE products SET status = 'inactive' WHERE id = $prodId");
$inactiveRes = RentalService::createBooking(
    $conn,
    $userId,
    [$prodId => 1],
    date('Y-m-d', strtotime('+40 days')),
    '09:00',
    date('Y-m-d', strtotime('+42 days')),
    '18:00'
);
assertTest("Reject booking for inactive vehicle", !$inactiveRes['success'], $inactiveRes['message'] ?? '');

// Restore vehicle to available
$conn->query("UPDATE products SET status = 'available' WHERE id = $prodId");

// ---------------------------------------------------------
// TEST 8: Customer Cancellation Rules
// ---------------------------------------------------------
echo "\n--- 8. Testing Booking Cancellation Logic ---\n";
$cancelRes = RentalService::cancelBooking($conn, $booking1Id, $userId, "Travel plans changed");
assertTest("Customer cancellation of pending booking", $cancelRes['success'], $cancelRes['message'] ?? '');

$cancelledOrder = $conn->query("SELECT status, cancellation_reason FROM orders WHERE id = $booking1Id")->fetch_assoc();
assertTest(
    "Booking status updated to Cancelled", 
    $cancelledOrder['status'] === 'Cancelled' && $cancelledOrder['cancellation_reason'] === 'Travel plans changed',
    "Status: {$cancelledOrder['status']}, Reason: {$cancelledOrder['cancellation_reason']}"
);

// Vehicle should now be immediately bookable again for the original cancelled period!
$rebookRes = RentalService::createBooking(
    $conn,
    $userId,
    [$prodId => 1],
    $bookStart,
    '10:00',
    $bookEnd,
    '14:00'
);
assertTest("Cancelled vehicle slot freed for new booking", $rebookRes['success'], $rebookRes['booking_ref'] ?? '');

// ---------------------------------------------------------
// TEST 9: Reservation Expiration
// ---------------------------------------------------------
echo "\n--- 9. Testing Stale Reservation Expiration ---\n";
if ($rebookRes['success']) {
    $tempId = $rebookRes['order_id'];
    // Artificially age the expiration timestamp into the past
    $conn->query("UPDATE orders SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = $tempId");
    $expiredCount = RentalService::expireStaleBookings($conn);
    $checkExp = $conn->query("SELECT status FROM orders WHERE id = $tempId")->fetch_assoc();

    assertTest("Expire overdue pending reservation", $checkExp['status'] === 'Expired', "Order status is now {$checkExp['status']}");
}

// ---------------------------------------------------------
// TEST 10: Concurrency Simulation (Double Booking Attack)
// ---------------------------------------------------------
echo "\n--- 10. Testing Atomic Concurrency & Race Condition ---\n";
// We simulate 2 simultaneous requests attempting to book the single unit for the exact same dates
$concStart = date('Y-m-d', strtotime('+50 days'));
$concEnd   = date('Y-m-d', strtotime('+52 days'));

// First transaction
$attempt1 = RentalService::createBooking($conn, $userId, [$prodId => 1], $concStart, '09:00', $concEnd, '18:00');
// Immediate second transaction for same inventory
$attempt2 = RentalService::createBooking($conn, $userId, [$prodId => 1], $concStart, '09:00', $concEnd, '18:00');

assertTest("First concurrent request succeeds", $attempt1['success'], $attempt1['booking_ref'] ?? '');
assertTest("Second concurrent request blocked safely", !$attempt2['success'], $attempt2['message'] ?? '');

// Clean up test vehicle & bookings
$conn->query("DELETE o FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.product_id = $prodId");
$conn->query("DELETE FROM vehicle_maintenance WHERE product_id = $prodId");
$conn->query("DELETE FROM products WHERE id = $prodId");
$conn->query("DELETE FROM users WHERE name = 'test_customer'");

echo "\n========================================================\n";
echo "   Test Results: $testsPassed Passed, $testsFailed Failed\n";
echo "========================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
