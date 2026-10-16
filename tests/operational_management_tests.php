<?php
// tests/operational_management_tests.php - Comprehensive Operational Management & RBAC Test Suite
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../rental_service.php';

echo "====================================================================\n";
echo "   RentalKH Operational Management & RBAC Test Suite (Step 7)       \n";
echo "====================================================================\n\n";

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

// -------------------------------------------------------------
// SETUP: Roles, Test Users & Test Fleet
// -------------------------------------------------------------

// 1. Create/Ensure Test Users for Each Role
$rolesToCreate = [
    'test_admin' => ['role' => 'admin', 'is_admin' => 1, 'email' => 'test_admin@rental.com'],
    'test_mgr'   => ['role' => 'rental_manager', 'is_admin' => 0, 'email' => 'test_mgr@rental.com'],
    'test_fin'   => ['role' => 'finance_staff', 'is_admin' => 0, 'email' => 'test_fin_staff@rental.com'],
    'test_cust'  => ['role' => 'customer', 'is_admin' => 0, 'email' => 'test_cust_ops@rental.com']
];

$userIds = [];
foreach ($rolesToCreate as $uname => $info) {
    $conn->query("
        INSERT INTO users (name, password, email, phone, driver_license, id_card_number, role, is_admin, is_active)
        VALUES ('$uname', 'hash', '{$info['email']}', '012345678', 'DL-$uname', 'ID-$uname', '{$info['role']}', {$info['is_admin']}, 1)
        ON DUPLICATE KEY UPDATE role = '{$info['role']}', is_admin = {$info['is_admin']}, is_active = 1
    ");
    $uRow = $conn->query("SELECT id FROM users WHERE email = '{$info['email']}'")->fetch_assoc();
    $userIds[$uname] = (int)$uRow['id'];
}

// 2. Create Dedicated Operational Test Vehicle
$conn->query("
    INSERT INTO products (name, category_id, reg_number, price, hourly_price, quantity, status, condition_status, mileage, is_archived)
    VALUES ('OpsTest Toyota Prius Gen 4', 1, 'TEST-OPS-PRIUS', 45.00, 8.00, 1, 'available', 'excellent', 12000, 0)
    ON DUPLICATE KEY UPDATE status = 'available', is_archived = 0, quantity = 1, price = 45.00, condition_status = 'excellent'
");
$prod = $conn->query("SELECT id FROM products WHERE reg_number = 'TEST-OPS-PRIUS'")->fetch_assoc();
$testProdId = (int)$prod['id'];

// Clean past test data for this product
$oldOrders = $conn->query("SELECT o.id FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.product_id = $testProdId");
while ($o = $oldOrders->fetch_assoc()) {
    $oid = (int)$o['id'];
    $conn->query("DELETE FROM payments WHERE order_id = $oid");
    $conn->query("DELETE FROM financial_audit_logs WHERE order_id = $oid");
    $conn->query("DELETE FROM order_items WHERE order_id = $oid");
    $conn->query("DELETE FROM orders WHERE id = $oid");
}
$conn->query("DELETE FROM vehicle_maintenance WHERE product_id = $testProdId");
$conn->query("DELETE FROM notifications WHERE reference_id = $testProdId AND reference_type = 'product'");

// =============================================================
// TEST 1: Role-Based Access Control Matrix (Step 2)
// =============================================================
echo "\n--- Section 1: Role-Based Access Control (RBAC) ---\n";

// 1.1 Admin has full permissions
assertTest("Admin has manage_vehicles", RentalService::checkPermission($conn, $userIds['test_admin'], 'manage_vehicles'));
assertTest("Admin has view_finance_reports", RentalService::checkPermission($conn, $userIds['test_admin'], 'view_finance_reports'));
assertTest("Admin has manage_staff_roles", RentalService::checkPermission($conn, $userIds['test_admin'], 'manage_staff_roles'));

// 1.2 Rental Manager has fleet, bookings, maintenance, but NOT finance reports or refund permissions
assertTest("Rental Manager has manage_vehicles", RentalService::checkPermission($conn, $userIds['test_mgr'], 'manage_vehicles'));
assertTest("Rental Manager has manage_maintenance", RentalService::checkPermission($conn, $userIds['test_mgr'], 'manage_maintenance'));
assertTest("Rental Manager has manage_handover_return", RentalService::checkPermission($conn, $userIds['test_mgr'], 'manage_handover_return'));
assertTest("Rental Manager CANNOT view finance reports", !RentalService::checkPermission($conn, $userIds['test_mgr'], 'view_finance_reports'));
assertTest("Rental Manager CANNOT manage refunds", !RentalService::checkPermission($conn, $userIds['test_mgr'], 'manage_refunds'));
assertTest("Rental Manager CANNOT manage staff roles", !RentalService::checkPermission($conn, $userIds['test_mgr'], 'manage_staff_roles'));

// 1.3 Finance Staff has financial access, but NOT fleet management or maintenance
assertTest("Finance Staff has view_finance_reports", RentalService::checkPermission($conn, $userIds['test_fin'], 'view_finance_reports'));
assertTest("Finance Staff has manage_payments", RentalService::checkPermission($conn, $userIds['test_fin'], 'manage_payments'));
assertTest("Finance Staff has manage_refunds", RentalService::checkPermission($conn, $userIds['test_fin'], 'manage_refunds'));
assertTest("Finance Staff CANNOT manage vehicles", !RentalService::checkPermission($conn, $userIds['test_fin'], 'manage_vehicles'));
assertTest("Finance Staff CANNOT schedule maintenance", !RentalService::checkPermission($conn, $userIds['test_fin'], 'manage_maintenance'));
assertTest("Finance Staff CANNOT record vehicle handover", !RentalService::checkPermission($conn, $userIds['test_fin'], 'manage_handover_return'));

// 1.4 Customer has only customer rights
assertTest("Customer has create_booking", RentalService::checkPermission($conn, $userIds['test_cust'], 'create_booking'));
assertTest("Customer CANNOT view dashboard", !RentalService::checkPermission($conn, $userIds['test_cust'], 'view_dashboard'));
assertTest("Customer CANNOT manage bookings", !RentalService::checkPermission($conn, $userIds['test_cust'], 'manage_bookings'));
assertTest("Customer CANNOT manage vehicles", !RentalService::checkPermission($conn, $userIds['test_cust'], 'manage_vehicles'));

// =============================================================
// TEST 2: Maintenance Conflict Detection & Safe Resolution (Step 1)
// =============================================================
echo "\n--- Section 2: Vehicle Maintenance & Reservation Conflict Detection ---\n";

// 2.1 Create an active booking for next week
$bookStart = date('Y-m-d', strtotime('+5 days'));
$bookEnd   = date('Y-m-d', strtotime('+8 days'));

$bookRes = RentalService::createBooking(
    $conn,
    $userIds['test_cust'],
    [$testProdId => 1],
    $bookStart,
    '10:00:00',
    $bookEnd,
    '18:00:00',
    'Operational Test Delivery'
);
assertTest("Create booking for test vehicle", $bookRes['success'], "Order ID: " . ($bookRes['order_id'] ?? 'none'));
$orderId = (int)$bookRes['order_id'];

// Approve booking
RentalService::updateBookingStatus($conn, $orderId, 'Approved', $userIds['test_mgr']);

// 2.2 Attempt to schedule maintenance overlapping the reservation (WITHOUT override)
$maintStart = date('Y-m-d', strtotime('+6 days'));
$maintEnd   = date('Y-m-d', strtotime('+7 days'));

$conflictCheck = RentalService::detectMaintenanceConflicts($conn, $testProdId, $maintStart, $maintEnd);
assertTest("detectMaintenanceConflicts finds overlapping reservation", $conflictCheck['has_conflict'], "Count: " . count($conflictCheck['conflicting_bookings']));

$schedRes = RentalService::scheduleMaintenance($conn, [
    'product_id' => $testProdId,
    'maintenance_type' => 'brake_inspection',
    'description' => 'Standard routine brake pads check',
    'cost' => 85.00,
    'start_date' => $maintStart,
    'end_date' => $maintEnd,
    'service_center' => 'Toyota Service Center Central',
    'staff_id' => $userIds['test_mgr'],
    'allow_conflicts' => false // DO NOT ALLOW
]);

assertTest("Schedule maintenance blocks when conflicts exist", !$schedRes['success']);
assertTest("Confirmed booking is NOT cancelled silently", $schedRes['conflict_detected'] === true);

// Verify booking is still Approved
$chkOrder = $conn->query("SELECT status, maintenance_conflict FROM orders WHERE id = $orderId")->fetch_assoc();
assertTest("Booking remains Approved and safe", $chkOrder['status'] === 'Approved');

// 2.3 Schedule maintenance WITH explicit authorized staff override
$schedOverrideRes = RentalService::scheduleMaintenance($conn, [
    'product_id' => $testProdId,
    'maintenance_type' => 'emergency_engine_recall',
    'description' => 'Manufacturer critical engine recall service',
    'cost' => 220.00,
    'start_date' => $maintStart,
    'end_date' => $maintEnd,
    'service_center' => 'Authorized Toyota MegaHub',
    'staff_id' => $userIds['test_mgr'],
    'allow_conflicts' => true // EXPLICIT OVERRIDE
]);

assertTest("Schedule maintenance succeeds with staff override", $schedOverrideRes['success']);
$maintId = (int)$schedOverrideRes['maintenance_id'];

// Check that the booking was flagged for reassignment
$chkOrder2 = $conn->query("SELECT maintenance_conflict FROM orders WHERE id = $orderId")->fetch_assoc();
assertTest("Conflicting booking is flagged with maintenance_conflict = 1", (int)$chkOrder2['maintenance_conflict'] === 1);

// =============================================================
// TEST 3: Inspection Gate & Return to Service Safety (Step 1)
// =============================================================
echo "\n--- Section 3: Safety Inspection Gate & Return to Service ---\n";

// Set vehicle under maintenance right now
$conn->query("UPDATE products SET status = 'maintenance' WHERE id = $testProdId");
$conn->query("UPDATE vehicle_maintenance SET status = 'in_progress', start_date = CURDATE(), end_date = CURDATE() WHERE id = $maintId");

// 3.1 Attempt to complete maintenance with FAILED inspection outcome
$failRes = RentalService::completeMaintenanceInspection($conn, $maintId, [
    'staff_id' => $userIds['test_mgr'],
    'inspection_result' => 'failed',
    'condition_status' => 'needs_service',
    'odometer' => 12150,
    'notes' => 'Brake calipers leaking fluid. Vehicle unsafe for customer use.'
]);

assertTest("Failed inspection recorded", $failRes['success']);
assertTest("Failed inspection does NOT return vehicle to service", $failRes['returned_to_service'] === false);

$prodCheckFailed = $conn->query("SELECT status, condition_status FROM products WHERE id = $testProdId")->fetch_assoc();
assertTest("Vehicle remains in maintenance status", $prodCheckFailed['status'] === 'maintenance');
assertTest("Vehicle condition set to needs_service", $prodCheckFailed['condition_status'] === 'needs_service');

// 3.2 Now complete maintenance with PASSED inspection outcome
$passRes = RentalService::completeMaintenanceInspection($conn, $maintId, [
    'staff_id' => $userIds['test_mgr'],
    'inspection_result' => 'passed',
    'condition_status' => 'excellent',
    'odometer' => 12160,
    'notes' => 'Calipers replaced and pressure tested. Vehicle certified 100% safe.'
]);

assertTest("Passed inspection returns vehicle to service", $passRes['returned_to_service'] === true);

$prodCheckPassed = $conn->query("SELECT status, condition_status FROM products WHERE id = $testProdId")->fetch_assoc();
assertTest("Vehicle status safely restored to available", $prodCheckPassed['status'] === 'available');
assertTest("Vehicle condition updated to excellent", $prodCheckPassed['condition_status'] === 'excellent');

// =============================================================
// TEST 4: Derived Real-Time Operational Status (Step 4)
// =============================================================
echo "\n--- Section 4: Inventory & Real-Time Operational Status ---\n";

// 4.1 When vehicle is available with no today reservations
$opStatus1 = RentalService::getVehicleOperationalStatus($conn, $testProdId);
assertTest("Derived operational status is 'available'", $opStatus1['state'] === 'available');

// 4.2 When vehicle is in maintenance
$conn->query("
    INSERT INTO vehicle_maintenance (product_id, maintenance_type, start_date, end_date, status)
    VALUES ($testProdId, 'oil_change', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'in_progress')
");
$tmpMaintId = $conn->insert_id;
$opStatus2 = RentalService::getVehicleOperationalStatus($conn, $testProdId);
assertTest("Derived operational status detects active maintenance", $opStatus2['state'] === 'maintenance');
$conn->query("DELETE FROM vehicle_maintenance WHERE id = $tmpMaintId");

// 4.3 When vehicle is rented out and overdue
$conn->query("
    INSERT INTO orders (user_id, booking_ref, total_amount, subtotal, status, order_date, start_date, start_time, end_date, end_time)
    VALUES ({$userIds['test_cust']}, 'TEST-OVERDUE-01', 90.00, 90.00, 'Active', NOW(), DATE_SUB(CURDATE(), INTERVAL 2 DAY), '10:00:00', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '10:00:00')
");
$overdueOrderId = $conn->insert_id;
$conn->query("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES ($overdueOrderId, $testProdId, 1, 45.00)");

$opStatus3 = RentalService::getVehicleOperationalStatus($conn, $testProdId);
assertTest("Derived operational status detects rented vehicle", $opStatus3['state'] === 'rented');
assertTest("Derived operational status detects OVERDUE state", $opStatus3['is_overdue'] === true);

// Clean up overdue order
$conn->query("DELETE FROM order_items WHERE order_id = $overdueOrderId");
$conn->query("DELETE FROM orders WHERE id = $overdueOrderId");

// =============================================================
// TEST 5: Notifications, Idempotency & Deduplication (Step 3)
// =============================================================
echo "\n--- Section 5: Notification Deduplication & Idempotency ---\n";

$idempotentKey = "test_ops_notif_" . time() . "_" . uniqid();

$notifRes1 = RentalService::createNotification($conn, [
    'user_id' => $userIds['test_cust'],
    'target_role' => 'customer',
    'type' => 'upcoming_pickup',
    'title' => 'Test Idempotent Pickup Notification',
    'message' => 'Please arrive at 10 AM with your driving license.',
    'reference_id' => $orderId,
    'reference_type' => 'order',
    'idempotency_key' => $idempotentKey
]);

assertTest("First notification creation succeeds", $notifRes1['success'] === true && $notifRes1['duplicate'] === false);

// Attempt duplicate notification with identical idempotency key
$notifRes2 = RentalService::createNotification($conn, [
    'user_id' => $userIds['test_cust'],
    'target_role' => 'customer',
    'type' => 'upcoming_pickup',
    'title' => 'Test Idempotent Pickup Notification (DUPLICATE)',
    'message' => 'Please arrive at 10 AM with your driving license.',
    'reference_id' => $orderId,
    'reference_type' => 'order',
    'idempotency_key' => $idempotentKey
]);

assertTest("Duplicate notification prevented via idempotency key", $notifRes2['success'] === true && $notifRes2['duplicate'] === true);

$notifCount = (int)$conn->query("SELECT COUNT(*) as cnt FROM notifications WHERE idempotency_key = '$idempotentKey'")->fetch_assoc()['cnt'];
assertTest("Only single notification record stored in database", $notifCount === 1);

// Test Notification Mark Read
$notifId = (int)$notifRes1['id'];
$readRes = RentalService::markNotificationRead($conn, $notifId, $userIds['test_cust']);
assertTest("Mark notification read succeeds", $readRes === true);

$notifRow = $conn->query("SELECT status, read_at FROM notifications WHERE id = $notifId")->fetch_assoc();
assertTest("Notification status is 'read' with valid timestamp", $notifRow['status'] === 'read' && !empty($notifRow['read_at']));

// =============================================================
// TEST 6: Automated Reminders Scanner (Step 3)
// =============================================================
echo "\n--- Section 6: Automated Reminders Scanner ---\n";

$reminders = RentalService::triggerAutomatedReminders($conn);
assertTest("triggerAutomatedReminders runs without exceptions", is_array($reminders));
assertTest("Reminders return pickup, return, and overdue counts", isset($reminders['pickups'], $reminders['returns'], $reminders['overdue']));

// Running a second time must NOT create duplicate notifications due to daily idempotency keys
$reminders2 = RentalService::triggerAutomatedReminders($conn);
assertTest("Repeated reminder scan does not crash", is_array($reminders2));

// =============================================================
// TEST 7: Unified System Audit Trail with Data Sanitization (Step 5 & 6)
// =============================================================
echo "\n--- Section 7: Audit Trail & Sensitive Data Sanitization ---\n";

$rawDetails = "Customer updated profile: password=PlainSecret123, secret=API_KEY_SECRET_456, cvv=999, token=BearerTokenABC";

RentalService::logSystemAudit(
    $conn,
    $userIds['test_admin'],
    'admin',
    'auth_permission',
    'TEST_SECURITY_LOG',
    $userIds['test_cust'],
    'user',
    $rawDetails
);

$auditRow = $conn->query("
    SELECT * FROM system_audit_logs 
    WHERE action_type = 'TEST_SECURITY_LOG' 
    ORDER BY id DESC LIMIT 1
")->fetch_assoc();

assertTest("Audit log record created successfully", !empty($auditRow));
assertTest("Actor ID and role recorded correctly", (int)$auditRow['actor_id'] === $userIds['test_admin'] && $auditRow['actor_role'] === 'admin');
assertTest("Password masked in audit log", strpos($auditRow['details'], 'PlainSecret123') === false && strpos($auditRow['details'], 'password=***') !== false);
assertTest("Secret token masked in audit log", strpos($auditRow['details'], 'API_KEY_SECRET_456') === false && strpos($auditRow['details'], 'secret=***') !== false);
assertTest("CVV masked in audit log", strpos($auditRow['details'], 'cvv=999') === false && strpos($auditRow['details'], 'cvv=***') !== false);

// =============================================================
// TEST 8: Operational Dashboard Metrics Aggregation (Step 4)
// =============================================================
echo "\n--- Section 8: Operational Dashboard Metrics ---\n";

$metrics = RentalService::getOperationalDashboardMetrics($conn);
assertTest("Operational dashboard metrics returns upcoming_pickups", isset($metrics['upcoming_pickups']) && is_int($metrics['upcoming_pickups']));
assertTest("Operational dashboard metrics returns active_rentals", isset($metrics['active_rentals']) && is_int($metrics['active_rentals']));
assertTest("Operational dashboard metrics returns overdue_returns", isset($metrics['overdue_returns']) && is_int($metrics['overdue_returns']));
assertTest("Operational dashboard metrics returns maintenance_conflicts", isset($metrics['maintenance_conflicts']) && is_int($metrics['maintenance_conflicts']));
assertTest("Operational dashboard metrics returns vehicles_under_maintenance", isset($metrics['vehicles_under_maintenance']) && is_int($metrics['vehicles_under_maintenance']));

// -------------------------------------------------------------
// CLEANUP TEST DATA
// -------------------------------------------------------------
$conn->query("DELETE FROM notifications WHERE reference_id = $orderId AND reference_type = 'order'");
$conn->query("DELETE FROM notifications WHERE idempotency_key = '$idempotentKey'");
$conn->query("DELETE FROM order_items WHERE order_id = $orderId");
$conn->query("DELETE FROM orders WHERE id = $orderId");
$conn->query("DELETE FROM vehicle_maintenance WHERE id = $maintId");
$conn->query("DELETE FROM system_audit_logs WHERE action_type = 'TEST_SECURITY_LOG'");
$conn->query("DELETE FROM products WHERE id = $testProdId");

echo "\n====================================================================\n";
echo "   Test Results Summary: Passed: $testsPassed | Failed: $testsFailed \n";
echo "====================================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
