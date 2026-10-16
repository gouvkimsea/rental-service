<?php
// tests/render_test.php - Render and HTTP integration test
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../rental_service.php';

session_start();
$_SESSION['user_id'] = 13;
$_SESSION['username'] = 'admin';
$_SESSION['is_admin'] = 1;
$_SESSION['csrf_token'] = 'testtoken';

echo "Testing admin page rendering...\n";

// 1. Test finance.php
$_GET['tab'] = 'overview';
ob_start();
include __DIR__ . '/../finance.php';
$finOut = ob_get_clean();

echo "- finance.php rendered: " . strlen($finOut) . " bytes\n";
echo "  Net Rental Revenue present: " . (strpos($finOut, 'Net Rental Revenue') !== false ? 'YES' : 'NO') . "\n";
echo "  Cash Collected present: " . (strpos($finOut, 'Cash Collected') !== false ? 'YES' : 'NO') . "\n";
echo "  Deposits Held present: " . (strpos($finOut, 'Deposits Held') !== false ? 'YES' : 'NO') . "\n";
echo "  Outstanding Balance present: " . (strpos($finOut, 'Outstanding Balance') !== false ? 'YES' : 'NO') . "\n";
echo "  Net Operating Result present: " . (strpos($finOut, 'Net Operating Result') !== false ? 'YES' : 'NO') . "\n";

// 2. Test finance.php ledger tab
$_GET['tab'] = 'ledger';
ob_start();
include __DIR__ . '/../finance.php';
$ledgerOut = ob_get_clean();
echo "- finance.php (ledger tab) rendered: " . strlen($ledgerOut) . " bytes\n";
echo "  Verified Transaction Ledger present: " . (strpos($ledgerOut, 'Verified Transaction Ledger') !== false ? 'YES' : 'NO') . "\n";

// 3. Test finance.php escrow tab
$_GET['tab'] = 'escrow';
ob_start();
include __DIR__ . '/../finance.php';
$escrowOut = ob_get_clean();
echo "- finance.php (escrow tab) rendered: " . strlen($escrowOut) . " bytes\n";
echo "  Security Deposit Escrow Tracking present: " . (strpos($escrowOut, 'Security Deposit Escrow Tracking') !== false ? 'YES' : 'NO') . "\n";

// 4. Test order_detail.php
$testOrder = $conn->query("SELECT id FROM orders ORDER BY id DESC LIMIT 1")->fetch_assoc();
if ($testOrder) {
    $_GET['id'] = $testOrder['id'];
    ob_start();
    include __DIR__ . '/../order_detail.php';
    $detailOut = ob_get_clean();
    echo "- order_detail.php (ID: {$testOrder['id']}) rendered: " . strlen($detailOut) . " bytes\n";
    echo "  Rental Lifecycle Progress present: " . (strpos($detailOut, 'Rental Lifecycle & Operations Progress') !== false ? 'YES' : 'NO') . "\n";
    echo "  Payment Ledger present: " . (strpos($detailOut, 'Financial Breakdown & Payment Ledger') !== false ? 'YES' : 'NO') . "\n";
}

echo "All page renders tested successfully without fatal errors!\n";
