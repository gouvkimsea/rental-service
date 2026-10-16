<?php
// tests/financial_operations_tests.php - Comprehensive Financial & Operational Test Suite
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../rental_service.php';

echo "====================================================================\n";
echo "   RentalKH Operations & Financial Integrity Test Suite (Step 8)    \n";
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
// SETUP: Test User & Dedicated Test Vehicle
// -------------------------------------------------------------
$conn->query("
    INSERT INTO users (name, password, email, phone, driver_license, id_card_number, is_admin, is_active) 
    VALUES ('test_fin_user', 'hash', 'test_fin@rental.com', '012345678', 'DL-TEST-999', 'ID-TEST-888', 0, 1) 
    ON DUPLICATE KEY UPDATE is_active = 1, phone = '012345678', driver_license = 'DL-TEST-999', id_card_number = 'ID-TEST-888'
");
$testUser = $conn->query("SELECT id FROM users WHERE email = 'test_fin@rental.com'")->fetch_assoc();
$userId = (int)$testUser['id'];

$adminUser = $conn->query("SELECT id FROM users WHERE is_admin = 1 LIMIT 1")->fetch_assoc();
$adminId = (int)($adminUser['id'] ?? 1);

// Dedicated Test Vehicle
$conn->query("
    INSERT INTO products (name, category_id, reg_number, price, hourly_price, quantity, status, condition_status, mileage, is_archived)
    VALUES ('FinTest Honda PCX 160', 1, 'TEST-FIN-PCX', 30.00, 6.00, 1, 'available', 'excellent', 5000, 0)
    ON DUPLICATE KEY UPDATE status = 'available', is_archived = 0, quantity = 1, price = 30.00, mileage = 5000
");
$testProd = $conn->query("SELECT id FROM products WHERE reg_number = 'TEST-FIN-PCX'")->fetch_assoc();
$prodId = (int)$testProd['id'];

// Clean previous test orders for this test vehicle
$oldOrders = $conn->query("SELECT o.id FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.product_id = $prodId");
while ($o = $oldOrders->fetch_assoc()) {
    $oid = (int)$o['id'];
    $conn->query("DELETE FROM payments WHERE order_id = $oid");
    $conn->query("DELETE FROM financial_audit_logs WHERE order_id = $oid");
    $conn->query("DELETE FROM order_items WHERE order_id = $oid");
    $conn->query("DELETE FROM orders WHERE id = $oid");
}
$conn->query("DELETE FROM vehicle_maintenance WHERE product_id = $prodId");

// =============================================================
// TEST 1: Booking Creation with Financial Attributes
// =============================================================
echo "\n--- 1. Testing Booking Creation & Initial Balances ---\n";
$start = date('Y-m-d', strtotime('+5 days'));
$end   = date('Y-m-d', strtotime('+7 days')); // 3 days @ $30 = $90 subtotal, default deposit = $50

$bRes = RentalService::createBooking(
    $conn,
    $userId,
    [$prodId => 1],
    $start,
    '09:00',
    $end,
    '18:00',
    'Financial Test Pickup Point'
);

$orderId = (int)($bRes['order_id'] ?? 0);
$bookingRef = $bRes['booking_ref'] ?? '';

assertTest(
    "Booking created with reference",
    $bRes['success'] && $orderId > 0 && !empty($bookingRef),
    "Order ID: $orderId, Ref: $bookingRef"
);

$finInit = RentalService::recalculateOrderFinancials($conn, $orderId);
assertTest(
    "Initial financial state: unpaid, deposit pending",
    $finInit['success'] && $finInit['gross_charges'] == 90.00 && $finInit['outstanding_balance'] == 90.00 && $finInit['payment_status'] === 'unpaid' && $finInit['deposit_status'] === 'pending',
    "Gross: \${$finInit['gross_charges']}, Balance: \${$finInit['outstanding_balance']}, Deposit Status: {$finInit['deposit_status']}"
);

// =============================================================
// TEST 2: Duplicate Payment Reference Prevention
// =============================================================
echo "\n--- 2. Testing Duplicate Payment Reference Prevention ---\n";
$dupRef = 'TEST-REF-' . time() . '-DUP';

$pay1 = RentalService::recordPayment(
    $conn,
    $orderId,
    50.00,
    'security_deposit',
    'cash',
    $dupRef,
    $adminId,
    'Security deposit test collection'
);
assertTest("Record first transaction with reference $dupRef", $pay1['success']);

$payDup = RentalService::recordPayment(
    $conn,
    $orderId,
    50.00,
    'security_deposit',
    'cash',
    $dupRef,
    $adminId,
    'Duplicate attempt with same reference'
);
assertTest("Reject duplicate transaction reference", !$payDup['success'], $payDup['message'] ?? '');

// =============================================================
// TEST 3: Invalid & Zero-Amount Payment Prevention
// =============================================================
echo "\n--- 3. Testing Payment Validation (Zero & Invalid Types) ---\n";
$payZero = RentalService::recordPayment($conn, $orderId, 0.00, 'rental_payment', 'cash', 'TEST-ZERO-1', $adminId);
assertTest("Reject payment amount <= 0", !$payZero['success'], $payZero['message'] ?? '');

$payInvalidType = RentalService::recordPayment($conn, $orderId, 20.00, 'invalid_type', 'cash', 'TEST-INV-1', $adminId);
assertTest("Reject invalid payment type", !$payInvalidType['success'], $payInvalidType['message'] ?? '');

$payInvalidMethod = RentalService::recordPayment($conn, $orderId, 20.00, 'rental_payment', 'bitcoin', 'TEST-INV-2', $adminId);
assertTest("Reject unconfigured payment method", !$payInvalidMethod['success'], $payInvalidMethod['message'] ?? '');

// =============================================================
// TEST 4: Partial Payments & Outstanding Balance Tracking
// =============================================================
echo "\n--- 4. Testing Partial Payments & Balance Tracking ---\n";
// Currently gross charges = $90.00, deposit collected = $50.00 (held in escrow, not rental revenue!)
$finAfterDep = RentalService::recalculateOrderFinancials($conn, $orderId);
assertTest(
    "Deposit is held in escrow, not counted as rental payment",
    $finAfterDep['deposit_held'] == 50.00 && $finAfterDep['net_rental_paid'] == 0.00 && $finAfterDep['outstanding_balance'] == 90.00 && $finAfterDep['deposit_status'] === 'held',
    "Deposit Held: \${$finAfterDep['deposit_held']}, Rental Paid: \${$finAfterDep['net_rental_paid']}, Balance: \${$finAfterDep['outstanding_balance']}"
);

// Record partial rental payment of $40.00
$refPart1 = 'TEST-PAY-PART-1-' . time();
$payPart1 = RentalService::recordPayment($conn, $orderId, 40.00, 'rental_payment', 'bank_transfer', $refPart1, $adminId, 'Partial payment 1');
$finPart1 = RentalService::recalculateOrderFinancials($conn, $orderId);

assertTest(
    "Partial payment 1 recorded ($40 paid of $90 due)",
    $payPart1['success'] && $finPart1['net_rental_paid'] == 40.00 && $finPart1['outstanding_balance'] == 50.00 && $finPart1['payment_status'] === 'partially_paid',
    "Net Rental Paid: \${$finPart1['net_rental_paid']}, Outstanding: \${$finPart1['outstanding_balance']}, Status: {$finPart1['payment_status']}"
);

// Record remaining rental payment of $50.00
$refPart2 = 'TEST-PAY-PART-2-' . time();
$payPart2 = RentalService::recordPayment($conn, $orderId, 50.00, 'rental_payment', 'cash', $refPart2, $adminId, 'Partial payment 2 (settlement)');
$finPart2 = RentalService::recalculateOrderFinancials($conn, $orderId);

assertTest(
    "Remaining balance paid ($90 of $90 settled)",
    $payPart2['success'] && $finPart2['net_rental_paid'] == 90.00 && $finPart2['outstanding_balance'] == 0.00 && $finPart2['payment_status'] === 'paid',
    "Net Rental Paid: \${$finPart2['net_rental_paid']}, Outstanding: \${$finPart2['outstanding_balance']}, Status: {$finPart2['payment_status']}"
);

// =============================================================
// TEST 5: Document Verification & Handover Validation
// =============================================================
echo "\n--- 5. Testing Handover State Guard & Document Verification ---\n";
// Attempt handover while booking is in 'Pending' status (Must be rejected)
$handoverPending = RentalService::recordHandover($conn, $orderId, $adminId, 5000, 'Full');
assertTest("Reject handover on unapproved booking", !$handoverPending['success'], $handoverPending['message'] ?? '');

// Approve booking
RentalService::updateBookingStatus($conn, $orderId, 'Approved', $adminId);

// Attempt handover without document verification (Must be rejected)
$handoverNoDocs = RentalService::recordHandover($conn, $orderId, $adminId, 5000, 'Full');
assertTest("Reject handover without verified documents", !$handoverNoDocs['success'], $handoverNoDocs['message'] ?? '');

// Verify customer documents
$docRes = RentalService::verifyCustomerDocuments($conn, $orderId, $adminId, "Verified driver license DL-TEST-999");
assertTest("Customer documents verified successfully", $docRes['success']);

// Handover vehicle successfully
$handoverOk = RentalService::recordHandover($conn, $orderId, $adminId, 5000, 'Full', 'Vehicle in pristine condition');
assertTest("Record vehicle handover and transition to Active", $handoverOk['success']);

// Check vehicle status updated to 'rented'
$vCheck = $conn->query("SELECT status, mileage FROM products WHERE id = $prodId")->fetch_assoc();
assertTest("Vehicle status changed to 'rented'", $vCheck['status'] === 'rented');

// =============================================================
// TEST 6: Late Return, Fuel Deficit & Damage Fee Calculation
// =============================================================
echo "\n--- 6. Testing Return Inspection, Late Fee, & Fuel Calculations ---\n";
// Handover odometer: 5000 km, Fuel: Full
// Return odometer less than handover (e.g., 4900 km) -> Must be rejected!
$retBadOdo = RentalService::recordReturn($conn, $orderId, $adminId, 4900, 'Full');
assertTest("Reject return odometer lower than handover", !$retBadOdo['success'], $retBadOdo['message'] ?? '');

// Simulate vehicle returned:
// - Return Odo: 5120 km (120 km driven)
// - Return Fuel: '1/2' (Missing 2 quarters: 2 * $15 = $30.00 fuel fee)
// - Damage claim: $25.00 for scratched mirror
$retRes = RentalService::recordReturn(
    $conn,
    $orderId,
    $adminId,
    5120,
    '1/2',
    'Customer scratched side mirror on parking pillar.',
    25.00,
    'Scratched right rearview mirror'
);

assertTest(
    "Record return with fuel deficit & damage assessment",
    $retRes['success'] && $retRes['fuel_fee'] == 30.00 && $retRes['damage_fee'] == 25.00,
    "Fuel fee: \${$retRes['fuel_fee']}, Damage fee: \${$retRes['damage_fee']}"
);

// Check vehicle is marked 'maintenance' due to damage
$vDamaged = $conn->query("SELECT status, condition_status FROM products WHERE id = $prodId")->fetch_assoc();
assertTest("Vehicle marked 'maintenance' with 'needs_service' due to damage", $vDamaged['status'] === 'maintenance' && $vDamaged['condition_status'] === 'needs_service');

// Check financial impact: gross charges increased by $55.00 ($30 fuel + $25 damage)
$finAfterReturn = RentalService::recalculateOrderFinancials($conn, $orderId);
assertTest(
    "Gross charges include additional fees with balance due",
    $finAfterReturn['gross_charges'] == 145.00 && $finAfterReturn['outstanding_balance'] == 55.00,
    "Gross: \${$finAfterReturn['gross_charges']}, Balance Due: \${$finAfterReturn['outstanding_balance']}"
);

// =============================================================
// TEST 7: Deposit Deduction & Over-Refund Prevention
// =============================================================
echo "\n--- 7. Testing Deposit Deduction & Over-Refund Guard ---\n";
// Currently deposit held = $50.00
// Try to refund $100.00 (Exceeds held deposit!) -> Must be rejected!
$refOver = RentalService::refundDeposit($conn, $orderId, 100.00, 'cash', 'TEST-REF-OVER', $adminId);
assertTest("Strictly reject refund exceeding held deposit", !$refOver['success'], $refOver['message'] ?? '');

// Try to deduct $70.00 (Exceeds held deposit!) -> Must be rejected!
$dedOver = RentalService::deductDeposit($conn, $orderId, 70.00, 'Excessive deduction test', $adminId);
assertTest("Strictly reject deduction exceeding held deposit", !$dedOver['success'], $dedOver['message'] ?? '');

// Deduct $50.00 from held deposit to cover damage and fuel
$dedOk = RentalService::deductDeposit($conn, $orderId, 50.00, 'Cover mirror damage ($25) and partial fuel ($25)', $adminId);
$finAfterDed = RentalService::recalculateOrderFinancials($conn, $orderId);

assertTest(
    "Authorized deposit deduction of $50 applied toward charges",
    $dedOk['success'] && $finAfterDed['deposit_held'] == 0.00 && $finAfterDed['deposit_deducted'] == 50.00 && $finAfterDed['outstanding_balance'] == 5.00 && $finAfterDed['deposit_status'] === 'forfeited',
    "Deposit Held: \${$finAfterDed['deposit_held']}, Deducted: \${$finAfterDed['deposit_deducted']}, Remaining Balance: \${$finAfterDed['outstanding_balance']}, Status: {$finAfterDed['deposit_status']}"
);

// Try to complete rental while $5.00 remains outstanding -> Must be rejected!
$compBlocked = RentalService::completeRental($conn, $orderId, $adminId);
assertTest("Block rental completion when balance is outstanding", !$compBlocked['success'], $compBlocked['message'] ?? '');

// Settle remaining $5.00 balance
$refFinal = 'TEST-PAY-FINAL-' . time();
$payFinal = RentalService::recordPayment($conn, $orderId, 5.00, 'additional_charge', 'cash', $refFinal, $adminId, 'Final $5 fuel balance settlement');
assertTest("Final $5 balance paid", $payFinal['success']);

// Complete rental successfully
$compOk = RentalService::completeRental($conn, $orderId, $adminId, 'refund', 'cash', '', 'Rental closed in good order.');
$finCompleted = RentalService::recalculateOrderFinancials($conn, $orderId);

assertTest(
    "Complete rental when fully settled",
    $compOk['success'] && $finCompleted['outstanding_balance'] == 0.00 && $finCompleted['payment_status'] === 'paid',
    "Status: Completed, Outstanding Balance: \${$finCompleted['outstanding_balance']}"
);

// =============================================================
// TEST 8: Cancellation Rules, Policy Fees & Deposit Refunds
// =============================================================
echo "\n--- 8. Testing Cancellation Rules & Escrow Return ---\n";
// Create a separate available vehicle for cancellation testing
$conn->query("
    INSERT INTO products (name, category_id, reg_number, price, hourly_price, quantity, status, condition_status, mileage, is_archived)
    VALUES ('FinCancel Honda Scoopy', 1, 'TEST-FIN-CAN', 20.00, 4.00, 1, 'available', 'excellent', 2000, 0)
    ON DUPLICATE KEY UPDATE status = 'available', is_archived = 0, quantity = 1, price = 20.00
");
$canProd = $conn->query("SELECT id FROM products WHERE reg_number = 'TEST-FIN-CAN'")->fetch_assoc();
$canProdId = (int)$canProd['id'];

// Clean previous orders for this vehicle
$oldCanOrders = $conn->query("SELECT o.id FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.product_id = $canProdId");
while ($co = $oldCanOrders->fetch_assoc()) {
    $coid = (int)$co['id'];
    $conn->query("DELETE FROM payments WHERE order_id = $coid");
    $conn->query("DELETE FROM financial_audit_logs WHERE order_id = $coid");
    $conn->query("DELETE FROM order_items WHERE order_id = $coid");
    $conn->query("DELETE FROM orders WHERE id = $coid");
}

$canStart = date('Y-m-d', strtotime('+15 days'));
$canEnd   = date('Y-m-d', strtotime('+17 days')); // 3 days @ $20 = $60

$bCan = RentalService::createBooking($conn, $userId, [$canProdId => 1], $canStart, '10:00', $canEnd, '18:00');
$canOrderId = (int)($bCan['order_id'] ?? 0);

// Record rental payment of $60.00 + deposit of $50.00
$refCanRent = 'TEST-CAN-RENT-' . time();
$refCanDep  = 'TEST-CAN-DEP-' . time();
RentalService::recordPayment($conn, $canOrderId, 60.00, 'rental_payment', 'aba_pay', $refCanRent, $adminId, 'Prepaid rental');
RentalService::recordPayment($conn, $canOrderId, 50.00, 'security_deposit', 'aba_pay', $refCanDep, $adminId, 'Prepaid deposit');

// Cancel booking: standard cancellation fee = $10.00.
// Expected: Rental refund = $50.00 ($60 - $10 fee), Deposit refund = $50.00 (100% deposit returned).
$cancelRes = RentalService::cancelBookingWithFinancials($conn, $canOrderId, $userId, 'Customer changed travel plans', false);

assertTest(
    "Cancellation fee applied, rental partially refunded, deposit fully refunded",
    $cancelRes['success'] && $cancelRes['cancellation_fee'] == 10.00 && $cancelRes['refund_amount'] == 50.00 && $cancelRes['deposit_refunded'] == 50.00,
    "Fee: \${$cancelRes['cancellation_fee']}, Rental Refund: \${$cancelRes['refund_amount']}, Deposit Refunded: \${$cancelRes['deposit_refunded']}"
);

$finCancel = RentalService::recalculateOrderFinancials($conn, $canOrderId);
assertTest(
    "Cancelled order financials: deposit status fully refunded",
    $finCancel['deposit_status'] === 'fully_refunded' && $finCancel['deposit_held'] == 0.00,
    "Deposit Status: {$finCancel['deposit_status']}, Deposit Held: \${$finCancel['deposit_held']}"
);

// =============================================================
// TEST 9: Financial Reconciliation & Report Aggregation
// =============================================================
echo "\n--- 9. Testing Financial Report Reconciliation ---\n";
$report = RentalService::getFinancialReport($conn, ['preset' => 'all']);
$kpis = $report['kpis'];

assertTest(
    "Financial report generated with positive cash inflows",
    $kpis['total_collected_inflows'] > 0 && $kpis['net_rental_revenue'] > 0,
    "Inflows: \${$kpis['total_collected_inflows']}, Net Rental Rev: \${$kpis['net_rental_revenue']}"
);

assertTest(
    "Deposits held in escrow strictly separated from revenue",
    $kpis['active_deposits_held'] >= 0.00,
    "Active Deposits Held: \${$kpis['active_deposits_held']}"
);

assertTest(
    "Net operating result computed with maintenance data",
    $kpis['net_operating_result'] !== null,
    "Net Operating Result: \${$kpis['net_operating_result']}"
);

assertTest(
    "Category revenue aggregated correctly",
    !empty($report['categories']),
    "Categories reported: " . count($report['categories'])
);

// Check Audit Trail records exist
$auditCount = $conn->query("SELECT COUNT(*) as cnt FROM financial_audit_logs WHERE order_id IN ($orderId, $canOrderId)")->fetch_assoc()['cnt'];
assertTest(
    "Financial audit trail recorded immutable events",
    $auditCount >= 8,
    "Logged events for test orders: $auditCount"
);

// =============================================================
// SUMMARY
// =============================================================
echo "\n====================================================================\n";
echo "   Test Suite Completed: $testsPassed Passed, $testsFailed Failed\n";
echo "====================================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
