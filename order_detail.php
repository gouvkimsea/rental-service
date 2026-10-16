<?php
// order_detail.php - Full Rental Operations, Lifecycle, Financial Ledger & Audit Breakdown
require_once 'config.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = (int)$_SESSION['user_id'];
$is_admin = !empty($_SESSION['is_admin']);

$msg = '';
$error = '';

// Handle Admin & Staff Operations
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $op = $_POST['operation'] ?? '';

    if ($is_admin) {
        if ($op === 'verify_documents') {
            $notes = trim($_POST['doc_notes'] ?? '');
            $res = RentalService::verifyCustomerDocuments($conn, $order_id, $user_id, $notes);
            $res['success'] ? $msg = $res['message'] : $error = $res['message'];
        } elseif ($op === 'record_payment') {
            $amount = (float)($_POST['amount'] ?? 0);
            $pType = $_POST['payment_type'] ?? 'rental_payment';
            $pMethod = $_POST['payment_method'] ?? 'cash';
            $pRef = trim($_POST['payment_reference'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $autoVerify = isset($_POST['auto_verify']) && $_POST['auto_verify'] == '1';

            if (empty($pRef)) {
                $pRef = strtoupper(substr($pType, 0, 3)) . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
            }

            $res = RentalService::recordPayment($conn, $order_id, $amount, $pType, $pMethod, $pRef, $user_id, $notes, $autoVerify);
            $res['success'] ? $msg = $res['message'] : $error = $res['message'];
        } elseif ($op === 'verify_payment_item') {
            $pId = (int)$_POST['payment_id'];
            $res = RentalService::verifyPayment($conn, $pId, $user_id);
            $res['success'] ? $msg = $res['message'] : $error = $res['message'];
        } elseif ($op === 'deduct_deposit') {
            $amount = (float)($_POST['deduct_amount'] ?? 0);
            $reason = trim($_POST['deduct_reason'] ?? 'Deposit deduction for damages/fees');
            $res = RentalService::deductDeposit($conn, $order_id, $amount, $reason, $user_id);
            $res['success'] ? $msg = $res['message'] : $error = $res['message'];
        } elseif ($op === 'refund_deposit') {
            $amount = (float)($_POST['refund_amount'] ?? 0);
            $method = $_POST['refund_method'] ?? 'cash';
            $ref = trim($_POST['refund_reference'] ?? ('DEP-REF-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4))));
            $res = RentalService::refundDeposit($conn, $order_id, $amount, $method, $ref, $user_id, "Security deposit refund");
            $res['success'] ? $msg = $res['message'] : $error = $res['message'];
        } elseif ($op === 'record_handover') {
            $odometer = max(0, (int)($_POST['odometer'] ?? 0));
            $fuel = $_POST['fuel_level'] ?? 'Full';
            $notes = trim($_POST['handover_notes'] ?? '');
            $res = RentalService::recordHandover($conn, $order_id, $user_id, $odometer, $fuel, $notes);
            $res['success'] ? $msg = $res['message'] : $error = $res['message'];
        } elseif ($op === 'record_return') {
            $odometer = max(0, (int)($_POST['return_odometer'] ?? 0));
            $fuel = $_POST['return_fuel_level'] ?? 'Full';
            $damageFee = max(0.00, (float)($_POST['damage_fee'] ?? 0));
            $damageDesc = trim($_POST['damage_desc'] ?? '');
            $inspectionNotes = trim($_POST['inspection_notes'] ?? '');
            $res = RentalService::recordReturn($conn, $order_id, $user_id, $odometer, $fuel, $inspectionNotes, $damageFee, $damageDesc);
            $res['success'] ? $msg = $res['message'] : $error = $res['message'];
        } elseif ($op === 'complete_rental') {
            $depAction = $_POST['deposit_action'] ?? 'refund';
            $depMethod = $_POST['deposit_refund_method'] ?? 'cash';
            $res = RentalService::completeRental($conn, $order_id, $user_id, $depAction, $depMethod);
            $res['success'] ? $msg = $res['message'] : $error = $res['message'];
        } elseif ($op === 'change_status') {
            $newStatus = $_POST['new_status'] ?? '';
            $res = RentalService::updateBookingStatus($conn, $order_id, $newStatus, $user_id);
            $res['success'] ? $msg = $res['message'] : $error = $res['message'];
        }
    }

    // Customer or Admin Cancellation
    if ($op === 'cancel_booking') {
        $reason = trim($_POST['cancel_reason'] ?? 'Customer cancellation');
        $overrideFee = isset($_POST['override_fee']) ? (float)$_POST['override_fee'] : null;
        $res = RentalService::cancelBookingWithFinancials($conn, $order_id, $user_id, $reason, $is_admin, $overrideFee);
        $res['success'] ? $msg = $res['message'] : $error = $res['message'];
    }
}

// Recalculate and fetch up-to-date booking record
RentalService::recalculateOrderFinancials($conn, $order_id);

if ($is_admin) {
    $stmt = $conn->prepare("
        SELECT o.*, u.name as customer_name, u.email as customer_email, 
               u.phone as customer_phone, u.address as customer_address, 
               u.id_card_number, u.driver_license,
               h_staff.name as handover_staff_name,
               r_staff.name as return_staff_name,
               d_staff.name as doc_staff_name
        FROM orders o 
        JOIN users u ON o.user_id = u.id 
        LEFT JOIN users h_staff ON o.handover_by = h_staff.id
        LEFT JOIN users r_staff ON o.return_by = r_staff.id
        LEFT JOIN users d_staff ON o.documents_verified_by = d_staff.id
        WHERE o.id = ?
    ");
    $stmt->bind_param("i", $order_id);
} else {
    $stmt = $conn->prepare("
        SELECT o.*, u.name as customer_name, u.email as customer_email, 
               u.phone as customer_phone, u.address as customer_address, 
               u.id_card_number, u.driver_license,
               NULL as handover_staff_name, NULL as return_staff_name, NULL as doc_staff_name
        FROM orders o 
        JOIN users u ON o.user_id = u.id 
        WHERE o.id = ? AND o.user_id = ?
    ");
    $stmt->bind_param("ii", $order_id, $user_id);
}
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Booking not found or you do not have permission to view it.");
}

// Fetch items
$items_stmt = $conn->prepare("
    SELECT oi.*, p.name as product_name, p.reg_number, p.image_name, p.mileage as current_mileage, c.name as category_name
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE oi.order_id = ?
");
$items_stmt->bind_param("i", $order_id);
$items_stmt->execute();
$items = $items_stmt->get_result();

// Fetch payments
$payStmt = $conn->prepare("
    SELECT p.*, u.name as staff_name
    FROM payments p
    LEFT JOIN users u ON p.staff_id = u.id
    WHERE p.order_id = ?
    ORDER BY p.id DESC
");
$payStmt->bind_param("i", $order_id);
$payStmt->execute();
$payments = $payStmt->get_result();

// Fetch audit logs
$auditStmt = $conn->prepare("
    SELECT fal.*, u.name as actor_name
    FROM financial_audit_logs fal
    JOIN users u ON fal.actor_id = u.id
    WHERE fal.order_id = ?
    ORDER BY fal.id DESC
");
$auditStmt->bind_param("i", $order_id);
$auditStmt->execute();
$auditLogs = $auditStmt->get_result();

$fin = RentalService::recalculateOrderFinancials($conn, $order_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking Operations #<?= htmlspecialchars($order['booking_ref'] ?: $order['id']) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .detail-container { max-width: 1000px; margin: 30px auto; padding: 25px; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
        .info-box { background: #f8fafc; padding: 18px; border-radius: 6px; border: 1px solid #e2e8f0; }
        .lifecycle-step { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 6px; background: #f1f5f9; margin-bottom: 10px; }
        .lifecycle-step.completed { background: #dcfce7; color: #166534; }
        .lifecycle-step.active { background: #e0f2fe; color: #0284c7; font-weight: bold; border-left: 4px solid #0284c7; }
        .table-custom { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .table-custom th, .table-custom td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #eee; font-size: 13px; }
        .table-custom th { background: #f1f5f9; font-size: 12px; text-transform: uppercase; color: #475569; }
        .badge { padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-verified { background: #dcfce7; color: #166534; }
        .badge-pending { background: #fef08a; color: #854d0e; }
        .badge-refunded { background: #e0e7ff; color: #4338ca; }
        .btn-op { padding: 7px 14px; border-radius: 4px; border: none; font-size: 12px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-success { background: #16a34a; color: #fff; }
        .btn-danger { background: #dc2626; color: #fff; }
    </style>
</head>
<body>
<div class="detail-container">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
        <div>
            <h2 style="margin:0 0 4px 0;">Booking <?= htmlspecialchars($order['booking_ref'] ?: '#' . $order['id']) ?></h2>
            <div style="font-size: 13px; color: #64748b;">
                Placed on <?= date('F j, Y, g:i A', strtotime($order['order_date'])) ?> | Customer: <strong><?= htmlspecialchars($order['customer_name']) ?></strong>
            </div>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <span class="status-pill status-pill--<?= strtolower($order['status']) ?>" style="font-size:14px; padding:6px 14px;">
                <?= htmlspecialchars($order['status']) ?>
            </span>
            <span class="badge badge-<?= $fin['payment_status'] === 'paid' ? 'verified' : ($fin['payment_status'] === 'partially_paid' ? 'pending' : 'disabled') ?>">
                Payment: <?= ucfirst(str_replace('_', ' ', $fin['payment_status'])) ?>
            </span>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success" style="margin-bottom: 20px; padding: 12px; background: #dfe7d8; border-radius: 6px; color:#2d5a27;">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px; padding: 12px; background: #fee2e2; border-radius: 6px; color: #b91c1c;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- OPERATIONAL LIFECYCLE BAR -->
    <section class="panel" style="margin-bottom: 25px; padding: 18px; border-left: 4px solid #0284c7;">
        <h3 style="margin-top:0; font-size:16px;">Rental Lifecycle & Operations Progress</h3>
        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; font-size: 12px;">
            <div class="lifecycle-step completed">
                <strong>1. Booking Placed</strong><br>Ref: <?= htmlspecialchars($order['booking_ref']) ?>
            </div>
            <div class="lifecycle-step <?= $order['status'] !== 'Pending' ? 'completed' : 'active' ?>">
                <strong>2. Review & Confirm</strong><br><?= $order['status'] === 'Pending' ? 'Awaiting Approval' : 'Confirmed' ?>
            </div>
            <div class="lifecycle-step <?= !empty($order['documents_verified']) ? 'completed' : 'active' ?>">
                <strong>3. Documents</strong><br><?= !empty($order['documents_verified']) ? 'Verified' : 'Pending Verification' ?>
            </div>
            <div class="lifecycle-step <?= in_array($order['status'], ['Active', 'Completed']) ? 'completed' : ($order['status'] === 'Approved' ? 'active' : '') ?>">
                <strong>4. Handover & Active</strong><br><?= $order['handover_at'] ? date('M j, g:i A', strtotime($order['handover_at'])) : 'Pending Handover' ?>
            </div>
            <div class="lifecycle-step <?= $order['status'] === 'Completed' ? 'completed' : ($order['status'] === 'Active' ? 'active' : '') ?>">
                <strong>5. Return & Complete</strong><br><?= $order['return_at'] ? 'Returned & Inspected' : 'Pending Return' ?>
            </div>
        </div>
    </section>

    <div class="info-grid">
        <!-- Customer Details Box -->
        <div class="info-box">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin-bottom:10px;">
                <h3 style="margin:0; font-size:15px;">Customer & Verification</h3>
                <?php if ($order['documents_verified']): ?>
                    <span class="badge badge-verified">&check; Documents Verified</span>
                <?php else: ?>
                    <span class="badge badge-pending">&excl; Documents Unverified</span>
                <?php endif; ?>
            </div>
            <p><strong>Customer Name:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($order['customer_email']) ?></p>
            <p><strong>Phone:</strong> <?= htmlspecialchars($order['customer_phone'] ?: 'Not provided') ?></p>
            <p><strong>Driver License:</strong> <?= htmlspecialchars($order['driver_license'] ?: 'Not provided') ?></p>
            <p><strong>National ID / Passport:</strong> <?= htmlspecialchars($order['id_card_number'] ?: 'Not provided') ?></p>
            <?php if ($order['documents_verified_at']): ?>
                <div style="font-size:11px; color:#166534; margin-top:5px;">
                    Verified by <?= htmlspecialchars($order['doc_staff_name'] ?? 'Staff') ?> on <?= date('M j, Y, g:i A', strtotime($order['documents_verified_at'])) ?>
                </div>
            <?php elseif ($is_admin): ?>
                <form method="post" style="margin-top:10px;">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="operation" value="verify_documents">
                    <button type="submit" class="btn-op btn-primary" onclick="return confirm('Verify that you have inspected and confirmed the customer\'s driver license and ID?');">
                        &check; Verify Customer Documents
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Rental Schedule Box -->
        <div class="info-box">
            <h3 style="margin-top:0; font-size:15px; border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin-bottom:10px;">Rental Schedule</h3>
            <p><strong>Pickup Date:</strong> <?= date('l, M j, Y', strtotime($order['start_date'])) ?> at <?= date('g:i A', strtotime($order['start_time'])) ?></p>
            <p><strong>Return Date:</strong> <?= date('l, M j, Y', strtotime($order['end_date'])) ?> at <?= date('g:i A', strtotime($order['end_time'])) ?></p>
            <p><strong>Reserved Vehicles:</strong></p>
            <ul style="margin:5px 0 10px 20px; font-size:13px;">
                <?php while ($item = $items->fetch_assoc()): ?>
                    <li>
                        <strong><?= htmlspecialchars($item['product_name']) ?></strong> (Plate: <?= htmlspecialchars($item['reg_number']) ?>) - $<?= number_format($item['price'], 2) ?>/day x <?= $item['quantity'] ?>
                    </li>
                <?php endwhile; ?>
            </ul>
        </div>
    </div>

    <!-- HANDOVER & RETURN INSPECTION AUDIT -->
    <section class="panel" style="margin-bottom: 25px; padding: 20px;">
        <h3 style="margin-top:0;">Handover & Return Inspections</h3>
        <div class="info-grid">
            <!-- Handover Record -->
            <div style="background:#f1f5f9; padding:15px; border-radius:6px;">
                <h4 style="margin-top:0; color:#0f172a;">Vehicle Handover (Pickup)</h4>
                <?php if ($order['handover_at']): ?>
                    <p><strong>Timestamp:</strong> <?= date('M j, Y, g:i A', strtotime($order['handover_at'])) ?></p>
                    <p><strong>Handed Over By:</strong> <?= htmlspecialchars($order['handover_staff_name'] ?? 'Staff') ?></p>
                    <p><strong>Departure Odometer:</strong> <?= number_format($order['handover_odometer']) ?> km</p>
                    <p><strong>Departure Fuel Level:</strong> <?= htmlspecialchars($order['handover_fuel_level']) ?></p>
                    <?php if ($order['handover_notes']): ?>
                        <p><strong>Notes:</strong> <?= htmlspecialchars($order['handover_notes']) ?></p>
                    <?php endif; ?>
                <?php elseif ($is_admin && $order['status'] === 'Approved'): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="operation" value="record_handover">
                        <div style="margin-bottom:8px;">
                            <label style="font-size:12px; font-weight:600;">Odometer Reading (km):</label>
                            <input type="number" name="odometer" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="font-size:12px; font-weight:600;">Fuel / Charge Level:</label>
                            <select name="fuel_level" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                                <option value="Full" selected>Full (4/4)</option>
                                <option value="3/4">3/4 Tank</option>
                                <option value="1/2">1/2 Tank</option>
                                <option value="1/4">1/4 Tank</option>
                            </select>
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="font-size:12px; font-weight:600;">Handover Inspection Notes:</label>
                            <textarea name="handover_notes" rows="2" placeholder="Pre-existing scratches, customer signed agreement..." style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></textarea>
                        </div>
                        <button type="submit" class="btn-op btn-primary" style="width:100%;" onclick="return confirm('Hand over vehicle and activate this rental?');">
                            Record Handover & Activate Rental
                        </button>
                    </form>
                <?php else: ?>
                    <p style="color:#666; font-size:13px;">Handover can be recorded once booking is Approved and verified.</p>
                <?php endif; ?>
            </div>

            <!-- Return Record -->
            <div style="background:#f1f5f9; padding:15px; border-radius:6px;">
                <h4 style="margin-top:0; color:#0f172a;">Vehicle Return (Check-In)</h4>
                <?php if ($order['return_at']): ?>
                    <p><strong>Timestamp:</strong> <?= date('M j, Y, g:i A', strtotime($order['return_at'])) ?></p>
                    <p><strong>Inspected By:</strong> <?= htmlspecialchars($order['return_staff_name'] ?? 'Staff') ?></p>
                    <p><strong>Return Odometer:</strong> <?= number_format($order['return_odometer']) ?> km (Driven: <?= number_format($order['return_odometer'] - $order['handover_odometer']) ?> km)</p>
                    <p><strong>Return Fuel:</strong> <?= htmlspecialchars($order['return_fuel_level']) ?></p>
                    <?php if ($order['late_hours'] > 0): ?>
                        <p style="color:#b91c1c;"><strong>Late Delay:</strong> <?= $order['late_hours'] ?> hr(s) (Fee: $<?= number_format($order['late_fee'], 2) ?>)</p>
                    <?php endif; ?>
                    <?php if ($order['fuel_fee'] > 0): ?>
                        <p style="color:#b91c1c;"><strong>Fuel Difference Fee:</strong> $<?= number_format($order['fuel_fee'], 2) ?></p>
                    <?php endif; ?>
                    <?php if ($order['damage_fee'] > 0): ?>
                        <p style="color:#b91c1c;"><strong>Damage Assessment:</strong> $<?= number_format($order['damage_fee'], 2) ?></p>
                    <?php endif; ?>
                    <?php if ($order['return_notes']): ?>
                        <p><strong>Notes:</strong> <?= htmlspecialchars($order['return_notes']) ?></p>
                    <?php endif; ?>
                <?php elseif ($is_admin && $order['status'] === 'Active'): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="operation" value="record_return">
                        <div style="margin-bottom:8px;">
                            <label style="font-size:12px; font-weight:600;">Return Odometer (km):</label>
                            <input type="number" name="return_odometer" min="<?= (int)$order['handover_odometer'] ?>" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="font-size:12px; font-weight:600;">Return Fuel / Charge Level:</label>
                            <select name="return_fuel_level" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                                <option value="Full" selected>Full (4/4)</option>
                                <option value="3/4">3/4 Tank</option>
                                <option value="1/2">1/2 Tank</option>
                                <option value="1/4">1/4 Tank</option>
                                <option value="Empty">Empty (0/4)</option>
                            </select>
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="font-size:12px; font-weight:600;">Damage Assessment Fee ($ if applicable):</label>
                            <input type="number" step="0.01" name="damage_fee" value="0.00" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="font-size:12px; font-weight:600;">Damage Details / Description:</label>
                            <input type="text" name="damage_desc" placeholder="e.g. Scratched front bumper, cracked mirror" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="font-size:12px; font-weight:600;">Return Inspection Notes:</label>
                            <textarea name="inspection_notes" rows="2" placeholder="Vehicle condition, cleanliness, belongings checked..." style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></textarea>
                        </div>
                        <button type="submit" class="btn-op btn-success" style="width:100%;" onclick="return confirm('Record vehicle return and inspect condition?');">
                            Record Return & Inspect Vehicle
                        </button>
                    </form>
                <?php else: ?>
                    <p style="color:#666; font-size:13px;">Return inspection can be recorded once rental is Active.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- FINANCIAL LEDGER & BALANCES -->
    <section class="panel" style="margin-bottom: 25px; padding: 20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0;">Financial Breakdown & Payment Ledger</h3>
            <div style="font-size:13px;">
                Deposit Status: <strong style="text-transform:uppercase;"><?= str_replace('_', ' ', $fin['deposit_status']) ?></strong>
            </div>
        </div>

        <!-- Financial Summary Cards -->
        <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; margin-bottom:20px;">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px; border-radius:6px;">
                <div style="font-size:11px; color:#64748b; text-transform:uppercase;">Gross Charges</div>
                <div style="font-size:18px; font-weight:bold; color:#0f172a;">$<?= number_format($fin['gross_charges'], 2) ?></div>
                <div style="font-size:11px; color:#888;">Rental + Fees</div>
            </div>
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:12px; border-radius:6px;">
                <div style="font-size:11px; color:#166534; text-transform:uppercase;">Verified Payments</div>
                <div style="font-size:18px; font-weight:bold; color:#166534;">$<?= number_format($fin['net_rental_paid'], 2) ?></div>
                <div style="font-size:11px; color:#166534;">Collected rental</div>
            </div>
            <div style="background:#eff6ff; border:1px solid #bfdbfe; padding:12px; border-radius:6px;">
                <div style="font-size:11px; color:#1e40af; text-transform:uppercase;">Security Deposit Held</div>
                <div style="font-size:18px; font-weight:bold; color:#1e40af;">$<?= number_format($fin['deposit_held'], 2) ?></div>
                <div style="font-size:11px; color:#1e40af;">Refundable portion</div>
            </div>
            <div style="background:<?= $fin['outstanding_balance'] > 0 ? '#fef2f2' : '#f8fafc' ?>; border:1px solid <?= $fin['outstanding_balance'] > 0 ? '#fecaca' : '#e2e8f0' ?>; padding:12px; border-radius:6px;">
                <div style="font-size:11px; color:<?= $fin['outstanding_balance'] > 0 ? '#991b1b' : '#64748b' ?>; text-transform:uppercase;">Outstanding Balance</div>
                <div style="font-size:18px; font-weight:bold; color:<?= $fin['outstanding_balance'] > 0 ? '#991b1b' : '#0f172a' ?>;">$<?= number_format($fin['outstanding_balance'], 2) ?></div>
                <div style="font-size:11px; color:#888;">Unpaid charges</div>
            </div>
        </div>

        <!-- Payments Table -->
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Staff</th>
                    <?php if ($is_admin): ?><th>Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ($payments && $payments->num_rows > 0): ?>
                <?php while ($p = $payments->fetch_assoc()): ?>
                    <tr>
                        <td><?= date('M j, Y g:i A', strtotime($p['created_at'])) ?></td>
                        <td><strong><?= ucwords(str_replace('_', ' ', $p['payment_type'])) ?></strong></td>
                        <td><?= ucfirst(str_replace('_', ' ', $p['payment_method'])) ?></td>
                        <td style="font-family:monospace;"><?= htmlspecialchars($p['payment_reference']) ?></td>
                        <td><strong>$<?= number_format($p['amount'], 2) ?></strong></td>
                        <td>
                            <span class="badge badge-<?= $p['status'] ?>">
                                <?= ucfirst($p['status']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($p['staff_name'] ?? 'System') ?></td>
                        <?php if ($is_admin): ?>
                            <td>
                                <?php if ($p['status'] === 'pending'): ?>
                                    <form method="post" style="display:inline-block;">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="operation" value="verify_payment_item">
                                        <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn-op btn-success" style="padding:3px 8px; font-size:11px;" onclick="return confirm('Verify that payment has been received in bank or cash?');">
                                            Verify
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#666; font-size:11px;">Verified</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="<?= $is_admin ? 8 : 7 ?>" style="text-align:center; color:#666; padding:15px;">No payments recorded for this booking yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- Admin Financial Controls -->
        <?php if ($is_admin && !in_array($order['status'], ['Completed', 'Cancelled'])): ?>
            <div style="background:#f8fafc; padding:15px; border-radius:6px; border:1px solid #e2e8f0; margin-top:15px;">
                <h4 style="margin-top:0;">Record New Payment / Deposit</h4>
                <form method="post" style="display:grid; grid-template-columns: repeat(5, 1fr) auto; gap:10px; align-items:flex-end;">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="operation" value="record_payment">
                    <div>
                        <label style="font-size:11px; font-weight:600;">Payment Type:</label>
                        <select name="payment_type" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                            <option value="rental_payment">Rental Payment</option>
                            <option value="security_deposit">Security Deposit</option>
                            <option value="additional_charge">Additional / Extra Fee</option>
                            <option value="damage_fee">Damage Settlement</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:600;">Method:</label>
                        <select name="payment_method" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                            <option value="cash">Cash</option>
                            <option value="aba_pay">ABA Pay / QR</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="credit_card">Credit Card</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:600;">Amount ($):</label>
                        <input type="number" step="0.01" name="amount" value="<?= $fin['outstanding_balance'] > 0 ? $fin['outstanding_balance'] : $order['deposit_amount'] ?>" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:600;">Receipt / Ref #:</label>
                        <input type="text" name="payment_reference" placeholder="Auto-generated if blank" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:600;">Auto-Verify:</label><br>
                        <label style="font-size:12px;"><input type="checkbox" name="auto_verify" value="1" checked> Funds Received</label>
                    </div>
                    <div>
                        <button type="submit" class="btn-op btn-primary" style="padding:8px 16px;">Record Payment</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Deposit Refund / Completion Actions for Admin -->
        <?php if ($is_admin && $order['return_at'] && $order['status'] !== 'Completed'): ?>
            <div style="background:#eff6ff; padding:15px; border-radius:6px; border:1px solid #bfdbfe; margin-top:15px;">
                <h4 style="margin-top:0; color:#1e40af;">Final Settlement & Rental Completion</h4>
                <p style="font-size:13px; color:#333; margin-bottom:12px;">
                    Vehicle return inspection has been completed. Settle any remaining deposit or outstanding balance and mark rental as Completed.
                </p>
                <form method="post" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="operation" value="complete_rental">
                    <label style="font-size:13px; font-weight:600;">
                        Deposit Settlement Action:
                        <select name="deposit_action" style="padding:6px; border:1px solid #ccc; border-radius:4px;">
                            <option value="refund">Deduct charges and refund remaining deposit ($<?= number_format($fin['deposit_held'], 2) ?>)</option>
                            <option value="hold">Keep deposit held (pending disputes)</option>
                        </select>
                    </label>
                    <label style="font-size:13px; font-weight:600;">
                        Refund Method:
                        <select name="deposit_refund_method" style="padding:6px; border:1px solid #ccc; border-radius:4px;">
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer / ABA</option>
                        </select>
                    </label>
                    <button type="submit" class="btn-op btn-success" style="padding:8px 18px;" onclick="return confirm('Complete this rental and finalize all balances?');">
                        &check; Settle Balances & Complete Rental
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </section>

    <!-- FINANCIAL & OPERATIONAL AUDIT TRAIL -->
    <?php if ($is_admin && $auditLogs && $auditLogs->num_rows > 0): ?>
        <section class="panel" style="margin-bottom: 25px; padding: 20px;">
            <h3 style="margin-top:0;">Audit Trail & Security Logs</h3>
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Action</th>
                        <th>Amount</th>
                        <th>Actor</th>
                        <th>Reason / Description</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($log = $auditLogs->fetch_assoc()): ?>
                    <tr>
                        <td><?= date('M j, Y g:i:s A', strtotime($log['created_at'])) ?></td>
                        <td><span style="font-family:monospace; font-weight:bold;"><?= htmlspecialchars($log['action_type']) ?></span></td>
                        <td><?= $log['amount'] > 0 ? '$' . number_format($log['amount'], 2) : '-' ?></td>
                        <td><?= htmlspecialchars($log['actor_name']) ?></td>
                        <td><?= htmlspecialchars($log['reason']) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <div style="margin-top: 30px; text-align: center; display: flex; justify-content: center; gap: 12px;">
        <?php if ($is_admin): ?>
            <a href="orders.php" class="submit-btn" style="text-decoration:none; display:inline-block;">&larr; Back to Bookings</a>
            <a href="finance.php" class="submit-btn" style="text-decoration:none; display:inline-block; background: #234234;">Financial Dashboard & Ledger</a>
        <?php else: ?>
            <a href="user_orders.php" class="submit-btn" style="text-decoration:none; display:inline-block;">&larr; Back to My Bookings</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
