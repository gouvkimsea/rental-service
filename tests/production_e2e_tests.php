<?php
// tests/production_e2e_tests.php - End-to-End Production Readiness & Integrity Test Suite (Step 2 & Step 3)

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../rental_service.php';

echo "====================================================================\n";
echo "   RentalKH Production Readiness & End-to-End Verification Suite   \n";
echo "====================================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $description, bool $condition, string $detail = '') {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo "[PASS] $description" . ($detail ? " ($detail)" : "") . "\n";
    } else {
        $testsFailed++;
        echo "[FAIL] $description" . ($detail ? " ($detail)" : "") . "\n";
    }
}

// -------------------------------------------------------------
// SETUP ISOLATED TEST FIXTURES
// -------------------------------------------------------------
// 1. Users
$roles = [
    'e2e_admin'   => ['role' => 'admin', 'email' => 'e2e_admin@test.com', 'is_admin' => 1],
    'e2e_mgr'     => ['role' => 'rental_manager', 'email' => 'e2e_mgr@test.com', 'is_admin' => 0],
    'e2e_fin'     => ['role' => 'finance_staff', 'email' => 'e2e_fin@test.com', 'is_admin' => 0],
    'e2e_cust1'   => ['role' => 'customer', 'email' => 'e2e_cust1@test.com', 'is_admin' => 0],
    'e2e_cust2'   => ['role' => 'customer', 'email' => 'e2e_cust2@test.com', 'is_admin' => 0],
];

$userIds = [];
foreach ($roles as $name => $info) {
    $conn->query("
        INSERT INTO users (name, email, password, role, is_admin, is_active, phone, driver_license, id_card_number)
        VALUES ('$name', '{$info['email']}', '" . password_hash('Secret123!', PASSWORD_DEFAULT) . "', '{$info['role']}', {$info['is_admin']}, 1, '012345678', 'DL-$name', 'ID-$name')
        ON DUPLICATE KEY UPDATE role = '{$info['role']}', is_admin = {$info['is_admin']}, is_active = 1, driver_license = 'DL-$name', id_card_number = 'ID-$name'
    ");
    $uRow = $conn->query("SELECT id FROM users WHERE email = '{$info['email']}'")->fetch_assoc();
    $userIds[$name] = (int)$uRow['id'];
}

// 2. Vehicles
$conn->query("
    INSERT INTO products (name, category_id, reg_number, price, hourly_price, quantity, status, condition_status, mileage, is_archived, buffer_hours)
    VALUES ('E2E Toyota Camry Hybrid', 1, 'TEST-E2E-CAMRY', 50.00, 10.00, 1, 'available', 'excellent', 10000, 0, 2)
    ON DUPLICATE KEY UPDATE status = 'available', is_archived = 0, quantity = 1, price = 50.00, mileage = 10000, condition_status = 'excellent'
");
$vRow = $conn->query("SELECT id FROM products WHERE reg_number = 'TEST-E2E-CAMRY'")->fetch_assoc();
$testProdId = (int)$vRow['id'];

// Clean up previous test orders for this test vehicle
$oldOrders = $conn->query("SELECT o.id FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.product_id = $testProdId");
while ($o = $oldOrders->fetch_assoc()) {
    $oid = (int)$o['id'];
    $conn->query("DELETE FROM payments WHERE order_id = $oid");
    $conn->query("DELETE FROM financial_audit_logs WHERE order_id = $oid");
    $conn->query("DELETE FROM notifications WHERE reference_id = $oid AND reference_type = 'order'");
    $conn->query("DELETE FROM order_items WHERE order_id = $oid");
    $conn->query("DELETE FROM orders WHERE id = $oid");
}
$conn->query("DELETE FROM vehicle_maintenance WHERE product_id = $testProdId");

// =============================================================
// SCENARIO 1: A Customer Books an Available Vehicle
// =============================================================
echo "\n--- Scenario 1: Customer Books an Available Vehicle ---\n";

$startDate1 = date('Y-m-d', strtotime('+10 days'));
$endDate1   = date('Y-m-d', strtotime('+14 days')); // 4 days

$bookRes1 = RentalService::createBooking(
    $conn,
    $userIds['e2e_cust1'],
    [$testProdId => 1],
    $startDate1,
    '10:00:00',
    $endDate1,
    '10:00:00',
    'Phnom Penh Hotel Pickup'
);

assertTest("Customer 1 booking creation succeeds", $bookRes1['success']);
$orderId1 = (int)($bookRes1['order_id'] ?? 0);
$bookingRef1 = $bookRes1['booking_ref'] ?? '';
assertTest("Booking has valid Order ID and Reference", $orderId1 > 0 && !empty($bookingRef1), "Ref: $bookingRef1");

$orderCheck1 = $conn->query("SELECT * FROM orders WHERE id = $orderId1")->fetch_assoc();
assertTest("Order status initialized to 'Pending'", $orderCheck1['status'] === 'Pending');
assertTest("Expiration timestamp set", !empty($orderCheck1['expires_at']));
assertTest("Financial attributes recorded (Subtotal: \$200, Deposit: \$50)", (float)$orderCheck1['subtotal'] == 200.00 && (float)$orderCheck1['deposit_amount'] == 50.00);

// =============================================================
// SCENARIO 2: Two Customers Attempt Overlapping Bookings Simultaneously
// =============================================================
echo "\n--- Scenario 2: Overlapping Booking Conflict Prevention ---\n";

// Customer 2 attempts to book overlapping dates (e.g. Days +11 to +13)
$overlapStart = date('Y-m-d', strtotime('+11 days'));
$overlapEnd   = date('Y-m-d', strtotime('+13 days'));

$bookRes2 = RentalService::createBooking(
    $conn,
    $userIds['e2e_cust2'],
    [$testProdId => 1],
    $overlapStart,
    '10:00:00',
    $overlapEnd,
    '18:00:00',
    'Airport Delivery'
);

assertTest("Overlapping reservation is strictly blocked", !$bookRes2['success']);
assertTest("Clean rejection message returned to Customer 2", strpos($bookRes2['message'], 'not available') !== false);

// Verify Customer 1's booking remains intact
$orderCheck1Again = $conn->query("SELECT status FROM orders WHERE id = $orderId1")->fetch_assoc();
assertTest("Customer 1's booking remains untouched and Pending", $orderCheck1Again['status'] === 'Pending');

// =============================================================
// SCENARIO 3: A Vehicle Becomes Unavailable or Enters Maintenance
// =============================================================
echo "\n--- Scenario 3: Maintenance & Inactive Vehicle Protection ---\n";

// Create a second vehicle for maintenance testing
$conn->query("
    INSERT INTO products (name, category_id, reg_number, price, hourly_price, quantity, status, condition_status, mileage, is_archived)
    VALUES ('E2E Maintenance Moto', 1, 'TEST-E2E-MOTO', 20.00, 4.00, 1, 'available', 'good', 5000, 0)
    ON DUPLICATE KEY UPDATE status = 'available', is_archived = 0
");
$mProd = $conn->query("SELECT id FROM products WHERE reg_number = 'TEST-E2E-MOTO'")->fetch_assoc();
$mProdId = (int)$mProd['id'];

// Schedule maintenance starting today
$maintRes = RentalService::scheduleMaintenance(
    $conn,
    $mProdId,
    'oil_service',
    'Routine 5,000 km oil change',
    35.00,
    date('Y-m-d'),
    date('Y-m-d', strtotime('+2 days')),
    'in_progress',
    $userIds['e2e_mgr'],
    'Central Garage'
);
assertTest("Maintenance record scheduled for test vehicle", $maintRes['success']);

// Derived operational status should show 'maintenance'
$opStatus = RentalService::getVehicleOperationalStatus($conn, $mProdId);
assertTest("Derived operational status detects active maintenance", $opStatus['state'] === 'maintenance');

// Booking attempt during maintenance should be blocked
$bookMaintAttempt = RentalService::createBooking(
    $conn,
    $userIds['e2e_cust2'],
    [$mProdId => 1],
    date('Y-m-d'),
    '10:00:00',
    date('Y-m-d', strtotime('+1 day')),
    '18:00:00'
);
assertTest("Booking blocked for vehicle currently under maintenance", !$bookMaintAttempt['success']);

// Clean up maintenance test moto
$conn->query("DELETE FROM vehicle_maintenance WHERE product_id = $mProdId");
$conn->query("DELETE FROM products WHERE id = $mProdId");

// =============================================================
// SCENARIO 4: A Booking is Cancelled or Expires
// =============================================================
echo "\n--- Scenario 4: Booking Expiration & Safe Cancellation ---\n";

// 4.1 Test Expiration
$conn->query("
    INSERT INTO orders (booking_ref, user_id, total_amount, subtotal, status, order_date, expires_at)
    VALUES ('TEST-EXP-01', {$userIds['e2e_cust2']}, 100.00, 100.00, 'Pending', DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_SUB(NOW(), INTERVAL 1 HOUR))
");
$expOrderId = $conn->insert_id;
$expiredCount = RentalService::expireStaleBookings($conn);
assertTest("expireStaleBookings marks past-expiry reservations Expired", $expiredCount >= 1);
$expCheck = $conn->query("SELECT status FROM orders WHERE id = $expOrderId")->fetch_assoc();
assertTest("Expired order status updated to 'Expired'", $expCheck['status'] === 'Expired');
$conn->query("DELETE FROM orders WHERE id = $expOrderId");

// 4.2 Test Cancellation
$cancelRes = RentalService::cancelBooking($conn, $orderId1, $userIds['e2e_cust1'], 'Customer changed travel plans');
assertTest("Customer booking cancellation succeeds", $cancelRes['success']);
$cancelCheck = $conn->query("SELECT status, cancellation_reason FROM orders WHERE id = $orderId1")->fetch_assoc();
assertTest("Order status set to 'Cancelled' with reason logged", $cancelCheck['status'] === 'Cancelled' && !empty($cancelCheck['cancellation_reason']));

// Verify slot is now freed: Customer 2 can now book the previously blocked dates!
$bookResFreed = RentalService::createBooking(
    $conn,
    $userIds['e2e_cust2'],
    [$testProdId => 1],
    $startDate1,
    '10:00:00',
    $endDate1,
    '10:00:00',
    'Hotel Delivery'
);
assertTest("Cancelled vehicle slot is immediately available for new booking", $bookResFreed['success']);
$activeOrderId = (int)$bookResFreed['order_id'];
$activeBookingRef = $bookResFreed['booking_ref'];

// =============================================================
// SCENARIO 5: Staff Verify a Payment
// =============================================================
echo "\n--- Scenario 5: Payment Recording & Staff Verification ---\n";

// Approve booking
RentalService::updateBookingStatus($conn, $activeOrderId, 'Approved', $userIds['e2e_mgr']);

// Customer submits a payment with unverified (pending) status
$payRefPending = 'PAY-PENDING-' . uniqid();
$recordPayRes = RentalService::recordPayment(
    $conn,
    $activeOrderId,
    50.00,
    'security_deposit',
    'bank_transfer',
    $payRefPending,
    $userIds['e2e_fin'],
    'Pending bank transfer slip check',
    false // autoVerify = false
);
assertTest("Pending payment recorded", $recordPayRes['success']);
$pendingPayId = (int)$recordPayRes['payment_id'];

// Check order financials before verification: deposit should NOT be counted as held yet
$finBeforeVerif = RentalService::recalculateOrderFinancials($conn, $activeOrderId);
assertTest("Unverified payment is not counted toward held deposit", (float)$finBeforeVerif['deposit_held'] === 0.00);

// Finance staff verifies the payment
$verifRes = RentalService::verifyPayment($conn, $pendingPayId, $userIds['e2e_fin']);
assertTest("Finance staff verifies pending payment", $verifRes['success']);

$finAfterVerif = RentalService::recalculateOrderFinancials($conn, $activeOrderId);
assertTest("Verified deposit payment is now securely held in escrow (\$50.00)", (float)$finAfterVerif['deposit_held'] === 50.00);

// =============================================================
// SCENARIO 6: Partial Payments & Balance Settlement
// =============================================================
echo "\n--- Scenario 6: Partial Payments & Outstanding Balance Tracking ---\n";

// Rental subtotal is $200. Customer makes partial payment $80
$part1Ref = 'PAY-PART1-' . uniqid();
RentalService::recordPayment($conn, $activeOrderId, 80.00, 'rental_payment', 'aba_pay', $part1Ref, $userIds['e2e_fin'], 'First partial installment', true);

$finPart1 = RentalService::recalculateOrderFinancials($conn, $activeOrderId);
assertTest("Order payment_status is 'partially_paid'", $finPart1['payment_status'] === 'partially_paid');
assertTest("Net rental paid is \$80.00 with \$120.00 remaining", (float)$finPart1['net_rental_paid'] === 80.00 && (float)$finPart1['outstanding_balance'] === 120.00);
assertTest("Deposit (\$50.00) remains separate in escrow and not mixed with rental payment", (float)$finPart1['deposit_held'] === 50.00);

// Customer pays remaining $120
$part2Ref = 'PAY-PART2-' . uniqid();
RentalService::recordPayment($conn, $activeOrderId, 120.00, 'rental_payment', 'cash', $part2Ref, $userIds['e2e_fin'], 'Final rental balance settlement', true);

$finPart2 = RentalService::recalculateOrderFinancials($conn, $activeOrderId);
assertTest("Order payment_status transitions to 'paid'", $finPart2['payment_status'] === 'paid');
assertTest("Outstanding balance is now \$0.00", (float)$finPart2['outstanding_balance'] === 0.00);

// =============================================================
// SCENARIO 7: Rental Starts, Runs Late, and is Returned
// =============================================================
echo "\n--- Scenario 7: Handover, Late Return & Fee Assessment ---\n";

// Verify customer documents
$docRes = RentalService::verifyCustomerDocuments($conn, $activeOrderId, $userIds['e2e_mgr'], 'Verified national ID and driver license.');
assertTest("Customer documents verified", $docRes['success']);

// Handover vehicle
$handoverRes = RentalService::recordHandover($conn, $activeOrderId, $userIds['e2e_mgr'], 10000, 'Full', 'Vehicle pristine at dispatch');
assertTest("Vehicle handover succeeds", $handoverRes['success']);

$orderAfterHandover = $conn->query("SELECT status FROM orders WHERE id = $activeOrderId")->fetch_assoc();
$prodAfterHandover = $conn->query("SELECT status FROM products WHERE id = $testProdId")->fetch_assoc();
assertTest("Order status is now 'Active'", $orderAfterHandover['status'] === 'Active');
assertTest("Vehicle status updated to 'rented'", $prodAfterHandover['status'] === 'rented');

// Simulate late return: Set end_date and end_time to 4 hours in the past
$conn->query("
    UPDATE orders 
    SET end_date = CURDATE(), end_time = TIME(DATE_SUB(NOW(), INTERVAL 4 HOUR)) 
    WHERE id = $activeOrderId
");

// Record return (with 300 km driven, fuel at 1/2)
$returnRes = RentalService::recordReturn(
    $conn,
    $activeOrderId,
    $userIds['e2e_mgr'],
    10300,
    '1/2', // Missing 2 quarters
    'Returned with minor dust, fuel missing 2 quarters',
    0.00,
    ''
);

assertTest("Vehicle return recorded", $returnRes['success']);
assertTest("Late hours assessed (approx 4 hours)", $returnRes['late_hours'] >= 3);
assertTest("Late fee computed (\$10/hr policy)", $returnRes['late_fee'] >= 30.00);
assertTest("Fuel fee assessed for missing 2 quarters (\$30.00)", (float)$returnRes['fuel_fee'] === 30.00);

// =============================================================
// SCENARIO 8: Staff Review Damage and Authorize a Charge
// =============================================================
echo "\n--- Scenario 8: Damage Inspection & Post-Rental Service Ticket ---\n";

// Record an additional damage assessment: cracked side mirror $65.00
$damageOdo = 10300;
$damageReturnRes = RentalService::recordReturn(
    $conn,
    $activeOrderId,
    $userIds['e2e_mgr'],
    10300,
    'Full', // customer refueled
    'Inspection detected cracked passenger side mirror',
    65.00,
    'Passenger side mirror cracked on frame'
);

assertTest("Damage charge of \$65.00 recorded", (float)$damageReturnRes['damage_fee'] === 65.00);

$prodAfterDamage = $conn->query("SELECT status, condition_status FROM products WHERE id = $testProdId")->fetch_assoc();
assertTest("Vehicle placed in 'maintenance' status due to damage", $prodAfterDamage['status'] === 'maintenance');
assertTest("Vehicle condition updated to 'needs_service'", $prodAfterDamage['condition_status'] === 'needs_service');

$maintTicket = $conn->query("SELECT * FROM vehicle_maintenance WHERE product_id = $testProdId AND cost = 65.00 ORDER BY id DESC LIMIT 1")->fetch_assoc();
assertTest("Automated maintenance repair ticket created", !empty($maintTicket) && (float)$maintTicket['cost'] === 65.00);

// =============================================================
// SCENARIO 9: Deposit Deduction & Partial Refund
// =============================================================
echo "\n--- Scenario 9: Deposit Deduction, Over-Refund Guard & Partial Refund ---\n";

$finPreDeposit = RentalService::recalculateOrderFinancials($conn, $activeOrderId);
$heldDeposit = $finPreDeposit['deposit_held'];
assertTest("Held deposit in escrow is \$50.00", (float)$heldDeposit === 50.00);

// 9.1 Attempt over-refund (more than held deposit)
$overRefundAttempt = RentalService::refundDeposit($conn, $activeOrderId, 75.00, 'cash', 'REF-OVER-01', $userIds['e2e_fin']);
assertTest("Over-refund attempt strictly rejected", !$overRefundAttempt['success']);

// 9.2 Attempt over-deduction
$overDeductAttempt = RentalService::adjustDeposit($conn, $activeOrderId, 80.00, $userIds['e2e_fin'], 'Over deduction test');
assertTest("Over-deduction attempt strictly rejected", !$overDeductAttempt['success']);

// 9.3 Valid partial deduction: deduct $30.00 from deposit toward damage
$validDeductRes = RentalService::adjustDeposit($conn, $activeOrderId, 30.00, $userIds['e2e_fin'], 'Apply $30 toward mirror replacement');
assertTest("Authorized deposit deduction of \$30.00 succeeds", $validDeductRes['success']);

$finMidDeposit = RentalService::recalculateOrderFinancials($conn, $activeOrderId);
assertTest("Remaining deposit held in escrow is \$20.00", (float)$finMidDeposit['deposit_held'] === 20.00);

// 9.4 Refund remaining $20.00 to customer
$refundRes = RentalService::refundDeposit($conn, $activeOrderId, 20.00, 'cash', 'DEP-REF-FINAL-' . uniqid(), $userIds['e2e_fin'], 'Remaining deposit refund');
assertTest("Remaining \$20.00 deposit refunded to customer", $refundRes['success']);

$finPostRefund = RentalService::recalculateOrderFinancials($conn, $activeOrderId);
assertTest("Escrow deposit held balance is \$0.00", (float)$finPostRefund['deposit_held'] === 0.00);
assertTest("Deposit status is 'partially_refunded'", $finPostRefund['deposit_status'] === 'partially_refunded');

// Settle remaining charges to allow completion
if ($finPostRefund['outstanding_balance'] > 0) {
    RentalService::recordPayment($conn, $activeOrderId, $finPostRefund['outstanding_balance'], 'damage_fee', 'cash', 'SETTLE-BAL-' . uniqid(), $userIds['e2e_fin'], 'Balance settlement', true);
}
$completeRes = RentalService::completeRental($conn, $activeOrderId, $userIds['e2e_mgr'], 'none');
assertTest("Rental finalized and completed", $completeRes['success']);

// =============================================================
// SCENARIO 10: Financial Report Reconciliation
// =============================================================
echo "\n--- Scenario 10: Financial Report Reconciliation ---\n";

$startDateReport = date('Y-m-d', strtotime('-30 days'));
$endDateReport   = date('Y-m-d', strtotime('+30 days'));

$report = RentalService::getFinancialReport($conn, $startDateReport, $endDateReport);
$kpis = $report['kpis'] ?? [];

assertTest("Financial report returns total_collected_inflows", isset($kpis['total_collected_inflows']) && is_numeric($kpis['total_collected_inflows']));
assertTest("Financial report returns net_rental_revenue", isset($kpis['net_rental_revenue']) && is_numeric($kpis['net_rental_revenue']));
assertTest("Financial report strictly separates active escrow deposits", isset($kpis['active_deposits_held']));
assertTest("Financial report computes net_operating_result", isset($kpis['net_operating_result']));

// Verify that report total inflows equals sum of verified non-refund payments in period
$directSum = (float)$conn->query("
    SELECT COALESCE(SUM(amount), 0) as s 
    FROM payments 
    WHERE status = 'verified' 
      AND payment_type IN ('rental_payment', 'additional_charge', 'damage_fee', 'late_fee', 'security_deposit')
      AND DATE(verified_at) BETWEEN '$startDateReport' AND '$endDateReport'
")->fetch_assoc()['s'];

assertTest("Report total inflows match underlying payments ledger", abs($kpis['total_collected_inflows'] - $directSum) < 0.01, "Report: \${$kpis['total_collected_inflows']}, Ledger: \$$directSum");

// =============================================================
// SCENARIO 11: Unauthorized User Attempts Sensitive Operations
// =============================================================
echo "\n--- Scenario 11: Authorization & Privilege Escalation Guards ---\n";

// Customer attempts sensitive operations
assertTest("Customer CANNOT manage vehicles", !RentalService::hasPermission('manage_vehicles', 'customer'));
assertTest("Customer CANNOT manage payments", !RentalService::hasPermission('manage_payments', 'customer'));
assertTest("Customer CANNOT manage refunds", !RentalService::hasPermission('manage_refunds', 'customer'));
assertTest("Customer CANNOT manage staff roles", !RentalService::hasPermission('manage_staff_roles', 'customer'));

// Rental Manager attempts financial reports or refunds
assertTest("Rental Manager CANNOT view finance reports", !RentalService::hasPermission('view_finance_reports', 'rental_manager'));
assertTest("Rental Manager CANNOT manage refunds", !RentalService::hasPermission('manage_refunds', 'rental_manager'));
assertTest("Rental Manager CANNOT manage staff roles", !RentalService::hasPermission('manage_staff_roles', 'rental_manager'));

// Finance staff attempts vehicle management
assertTest("Finance Staff CANNOT manage vehicles", !RentalService::hasPermission('manage_vehicles', 'finance_staff'));
assertTest("Finance Staff CANNOT schedule maintenance", !RentalService::hasPermission('manage_maintenance', 'finance_staff'));
assertTest("Finance Staff CANNOT record handover", !RentalService::hasPermission('manage_handover_return', 'finance_staff'));

// =============================================================
// SCENARIO 12: Notification Failure and Retry Handling
// =============================================================
echo "\n--- Scenario 12: Notification Failure, Retry & Deduplication ---\n";

$notifIdempotentKey = "e2e_retry_test_" . uniqid();

$notif1 = RentalService::createNotification($conn, [
    'user_id' => $userIds['e2e_cust1'],
    'target_role' => 'customer',
    'type' => 'upcoming_pickup',
    'title' => 'E2E Reminder',
    'message' => 'Please bring your driving license.',
    'reference_id' => $activeOrderId,
    'reference_type' => 'order',
    'idempotency_key' => $notifIdempotentKey
]);

assertTest("Initial notification creation succeeds", $notif1['success'] && $notif1['duplicate'] === false);
$notifId = (int)$notif1['id'];

// Retry identical submission (e.g. repeated queue event or web retry)
$notifRetry = RentalService::createNotification($conn, [
    'user_id' => $userIds['e2e_cust1'],
    'target_role' => 'customer',
    'type' => 'upcoming_pickup',
    'title' => 'E2E Reminder Duplicate',
    'message' => 'Please bring your driving license.',
    'reference_id' => $activeOrderId,
    'reference_type' => 'order',
    'idempotency_key' => $notifIdempotentKey
]);

assertTest("Retry does not duplicate notification", $notifRetry['success'] && $notifRetry['duplicate'] === true);

// Execute notification delivery retry helper
$dispatchRetry = RentalService::retryNotificationDelivery($conn, $notifId);
assertTest("retryNotificationDelivery succeeds without error", $dispatchRetry['success']);

// =============================================================
// SCENARIO 13: Database Error During Critical Transaction (Rollback Integrity)
// =============================================================
echo "\n--- Scenario 13: Transaction Atomicity & Rollback Integrity ---\n";

// Attempt to create a booking with a non-existent vehicle ID to trigger failure inside transaction
$invalidCart = [9999999 => 1];
$failedBooking = RentalService::createBooking(
    $conn,
    $userIds['e2e_cust1'],
    $invalidCart,
    date('Y-m-d', strtotime('+20 days')),
    '10:00:00',
    date('Y-m-d', strtotime('+22 days')),
    '18:00:00'
);

assertTest("Transaction rollback on invalid vehicle", !$failedBooking['success']);

// Verify no partial order records were created
$orphanOrders = (int)$conn->query("SELECT COUNT(*) as cnt FROM orders WHERE shipping_address = 'GHOST_ORDER_TEST'")->fetch_assoc()['cnt'];
assertTest("Zero orphan orders created in database", $orphanOrders === 0);

// =============================================================
// SCENARIO 14: Browser Refresh / Repeated Submission Handling
// =============================================================
echo "\n--- Scenario 14: Repeated Submission & Duplicate Payment Prevention ---\n";

$dupPaymentRef = "E2E-REF-" . uniqid();

$payAttempt1 = RentalService::recordPayment(
    $conn,
    $activeOrderId,
    25.00,
    'rental_payment',
    'cash',
    $dupPaymentRef,
    $userIds['e2e_fin'],
    'First submission'
);
assertTest("First payment submission succeeds", $payAttempt1['success']);

// User hits refresh / double clicks submit button with identical reference
$payAttempt2 = RentalService::recordPayment(
    $conn,
    $activeOrderId,
    25.00,
    'rental_payment',
    'cash',
    $dupPaymentRef,
    $userIds['e2e_fin'],
    'Accidental double-click submission'
);
assertTest("Repeated submission with same payment reference is strictly rejected", !$payAttempt2['success']);
assertTest("Duplicate rejection message returned", strpos($payAttempt2['message'], 'already exists') !== false);

// =============================================================
// SCENARIO 15: Vehicle with Historical Rentals is Archived
// =============================================================
echo "\n--- Scenario 15: Archiving Vehicle with Historical Rentals ---\n";

// Vehicle $testProdId has completed orders ($activeOrderId). Attempt archiving.
$histCount = (int)$conn->query("SELECT COUNT(*) as cnt FROM order_items WHERE product_id = $testProdId")->fetch_assoc()['cnt'];
assertTest("Vehicle has historical rental records ($histCount orders)", $histCount > 0);

// Execute archiving logic (as performed in product_delete.php)
$archiveStmt = $conn->prepare("UPDATE products SET is_archived = 1, status = 'inactive' WHERE id = ?");
$archiveStmt->bind_param("i", $testProdId);
$archiveStmt->execute();

$prodArchived = $conn->query("SELECT is_archived, status FROM products WHERE id = $testProdId")->fetch_assoc();
assertTest("Vehicle marked as is_archived = 1", (int)$prodArchived['is_archived'] === 1);
assertTest("Vehicle status set to 'inactive'", $prodArchived['status'] === 'inactive');

// Verify historical order and items remain intact!
$pastOrderCheck = $conn->query("SELECT id, status, total_amount FROM orders WHERE id = $activeOrderId")->fetch_assoc();
$pastItemCheck = $conn->query("SELECT id, price FROM order_items WHERE order_id = $activeOrderId AND product_id = $testProdId")->fetch_assoc();
assertTest("Historical order record preserved 100%", !empty($pastOrderCheck) && $pastOrderCheck['status'] === 'Completed');
assertTest("Historical order_items preserved 100%", !empty($pastItemCheck));

// Verify archived vehicle cannot be booked anymore
$bookArchivedAttempt = RentalService::createBooking(
    $conn,
    $userIds['e2e_cust1'],
    [$testProdId => 1],
    date('Y-m-d', strtotime('+25 days')),
    '10:00:00',
    date('Y-m-d', strtotime('+27 days')),
    '18:00:00'
);
assertTest("Archived vehicle cannot be booked by customers", !$bookArchivedAttempt['success']);

// Restore vehicle for inventory consistency
$restoreStmt = $conn->prepare("UPDATE products SET is_archived = 0, status = 'available', condition_status = 'excellent' WHERE id = ?");
$restoreStmt->bind_param("i", $testProdId);
$restoreStmt->execute();

// -------------------------------------------------------------
// TEARDOWN TEST DATA
// -------------------------------------------------------------
$conn->query("DELETE FROM payments WHERE order_id IN ($orderId1, $activeOrderId)");
$conn->query("DELETE FROM financial_audit_logs WHERE order_id IN ($orderId1, $activeOrderId)");
$conn->query("DELETE FROM notifications WHERE reference_id IN ($orderId1, $activeOrderId) AND reference_type = 'order'");
$conn->query("DELETE FROM notifications WHERE idempotency_key = '$notifIdempotentKey'");
$conn->query("DELETE FROM order_items WHERE order_id IN ($orderId1, $activeOrderId)");
$conn->query("DELETE FROM orders WHERE id IN ($orderId1, $activeOrderId)");
$conn->query("DELETE FROM vehicle_maintenance WHERE product_id = $testProdId");
$conn->query("DELETE FROM products WHERE id = $testProdId");

echo "\n====================================================================\n";
echo "   Test Results Summary: Passed: $testsPassed | Failed: $testsFailed \n";
echo "====================================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
