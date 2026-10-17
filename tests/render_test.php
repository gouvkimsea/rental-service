<?php
// tests/render_test.php - Comprehensive View Rendering & Presentation Integrity Test Suite
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../rental_service.php';

$_SERVER['REQUEST_METHOD'] = 'GET';

echo "====================================================================\n";
echo "   RentalKH UI Rendering & View Integration Test Suite             \n";
echo "====================================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assertRender(string $testName, bool $condition, string $detail = '') {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "[PASS] $testName" . ($detail ? " ($detail)" : "") . "\n";
        $testsPassed++;
    } else {
        echo "[FAIL] $testName" . ($detail ? " - ERROR: $detail" : "") . "\n";
        $testsFailed++;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------
// 1. Healthcheck Diagnostic Rendering
// -----------------------------------------------------------------
$phpBin = PHP_BINARY ?: 'php';
exec(escapeshellcmd($phpBin) . ' ' . escapeshellarg(__DIR__ . '/../healthcheck.php'), $hcLines, $hcCode);
$hcOutput = implode("\n", $hcLines);
assertRender(
    'Healthcheck CLI Probe',
    $hcCode === 0 && strpos($hcOutput, 'Status:   HEALTHY [200 OK]') !== false,
    'CLI probe successful'
);

// -----------------------------------------------------------------
// 2. Customer Storefront & Catalog Rendering
// -----------------------------------------------------------------
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'testcustomer';
$_SESSION['role'] = 'customer';
$_SESSION['is_admin'] = 0;
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));

ob_start();
include __DIR__ . '/../Service.php';
$serviceHtml = ob_get_clean();
assertRender(
    'Service.php (Storefront Home)',
    strlen($serviceHtml) > 1000 && strpos($serviceHtml, 'JOUL Rental') !== false && strpos($serviceHtml, 'Escrow Protected') !== false,
    strlen($serviceHtml) . ' bytes'
);

ob_start();
include __DIR__ . '/../second1.php';
$catalogHtml = ob_get_clean();
assertRender(
    'second1.php (Vehicle Catalog)',
    strlen($catalogHtml) > 1000 && strpos($catalogHtml, 'JOUL Rental Fleet') !== false,
    strlen($catalogHtml) . ' bytes'
);

ob_start();
include __DIR__ . '/../user_orders.php';
$userOrdersHtml = ob_get_clean();
assertRender(
    'user_orders.php (Customer Bookings)',
    strlen($userOrdersHtml) > 500 && strpos($userOrdersHtml, 'My Rental Bookings') !== false,
    strlen($userOrdersHtml) . ' bytes'
);

ob_start();
include __DIR__ . '/../profile.php';
$profileHtml = ob_get_clean();
assertRender(
    'profile.php (Customer Profile)',
    strlen($profileHtml) > 500 && strpos($profileHtml, 'Customer Profile') !== false,
    strlen($profileHtml) . ' bytes'
);

// -----------------------------------------------------------------
// 3. Authentication & Recovery Views
// -----------------------------------------------------------------
ob_start();
include __DIR__ . '/../login.php';
$loginHtml = ob_get_clean();
assertRender(
    'login.php (Authentication Form)',
    strpos($loginHtml, 'Log In') !== false && strpos($loginHtml, 'name="csrf_token"') !== false,
    'CSRF token field verified'
);

ob_start();
include __DIR__ . '/../signin.php';
$signinHtml = ob_get_clean();
assertRender(
    'signin.php (Registration Form)',
    strpos($signinHtml, 'Create Account') !== false && strpos($signinHtml, 'name="csrf_token"') !== false,
    'CSRF token field verified'
);

ob_start();
include __DIR__ . '/../forgot_password.php';
$forgotHtml = ob_get_clean();
assertRender(
    'forgot_password.php (Password Recovery)',
    strpos($forgotHtml, 'Password Recovery') !== false && strpos($forgotHtml, 'type="email"') !== false,
    'Recovery form rendered'
);

// -----------------------------------------------------------------
// 4. Staff & Administration Portal Rendering
// -----------------------------------------------------------------
$adminRow = $conn->query("SELECT id, name FROM users WHERE is_admin = 1 OR role = 'admin' LIMIT 1")->fetch_assoc();
$adminId = $adminRow ? (int)$adminRow['id'] : 10;
$adminUsername = $adminRow ? $adminRow['name'] : 'admin';

$_SESSION['user_id'] = $adminId;
$_SESSION['username'] = $adminUsername;
$_SESSION['role'] = 'admin';
$_SESSION['is_admin'] = 1;
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));

ob_start();
include __DIR__ . '/../dashboard.php';
$dashHtml = ob_get_clean();
assertRender(
    'dashboard.php (Admin Dashboard)',
    strpos($dashHtml, 'Operations Overview') !== false && strpos($dashHtml, 'ops-strip') !== false,
    strlen($dashHtml) . ' bytes'
);

ob_start();
include __DIR__ . '/../Product.php';
$productHtml = ob_get_clean();
assertRender(
    'Product.php (Fleet Inventory Management)',
    strpos($productHtml, 'Vehicle Inventory &amp; Fleet Status') !== false && strpos($productHtml, 'admin-table') !== false,
    'Admin table verified'
);

ob_start();
include __DIR__ . '/../orders.php';
$ordersHtml = ob_get_clean();
assertRender(
    'orders.php (Orders Management)',
    strpos($ordersHtml, 'Booking Operations & Dispatch') !== false,
    strlen($ordersHtml) . ' bytes'
);

// Order Detail
$sampleOrder = $conn->query("SELECT id FROM orders ORDER BY id DESC LIMIT 1")->fetch_assoc();
if ($sampleOrder) {
    $_GET['id'] = $sampleOrder['id'];
    ob_start();
    include __DIR__ . '/../order_detail.php';
    $orderDetailHtml = ob_get_clean();
    assertRender(
        'order_detail.php (Lifecycle & Ledger View)',
        strpos($orderDetailHtml, 'Rental Lifecycle') !== false && strpos($orderDetailHtml, 'Financial Breakdown') !== false,
        'Booking ID: ' . $sampleOrder['id']
    );
}

ob_start();
include __DIR__ . '/../maintenance.php';
$maintHtml = ob_get_clean();
assertRender(
    'maintenance.php (Fleet Maintenance)',
    strpos($maintHtml, 'Fleet Maintenance & Inspection') !== false,
    strlen($maintHtml) . ' bytes'
);

$_GET['tab'] = 'overview';
ob_start();
include __DIR__ . '/../finance.php';
$finOverview = ob_get_clean();
assertRender(
    'finance.php?tab=overview (Financial KPI Overview)',
    strpos($finOverview, 'Net Rental Revenue') !== false && strpos($finOverview, 'Deposits Held') !== false,
    'KPIs verified'
);

$_GET['tab'] = 'ledger';
ob_start();
include __DIR__ . '/../finance.php';
$finLedger = ob_get_clean();
assertRender(
    'finance.php?tab=ledger (Transaction Ledger)',
    strpos($finLedger, 'Verified Transaction Ledger') !== false && strpos($finLedger, 'table-wrap') !== false,
    'Ledger table verified'
);

$_GET['tab'] = 'escrow';
ob_start();
include __DIR__ . '/../finance.php';
$finEscrow = ob_get_clean();
assertRender(
    'finance.php?tab=escrow (Deposit Escrow Ledger)',
    strpos($finEscrow, 'Security Deposit Escrow Tracking') !== false && strpos($finEscrow, 'table-wrap') !== false,
    'Escrow table verified'
);

ob_start();
include __DIR__ . '/../categories.php';
$catHtml = ob_get_clean();
assertRender(
    'categories.php (Vehicle Category Management)',
    strpos($catHtml, 'Category Management') !== false,
    strlen($catHtml) . ' bytes'
);

ob_start();
include __DIR__ . '/../users.php';
$usersHtml = ob_get_clean();
assertRender(
    'users.php (User & Account Management)',
    strpos($usersHtml, 'Customer & Staff Role Governance') !== false,
    strlen($usersHtml) . ' bytes'
);

ob_start();
include __DIR__ . '/../notifications.php';
$notifHtml = ob_get_clean();
assertRender(
    'notifications.php (Notification Center)',
    strpos($notifHtml, 'Notification Center') !== false,
    strlen($notifHtml) . ' bytes'
);

// -----------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------
echo "\n====================================================================\n";
echo "View Rendering Summary: Passed: $testsPassed | Failed: $testsFailed\n";
echo "====================================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
