<?php
// maintenance.php - Vehicle Maintenance, Conflict Detection, Inspection & Condition History
require_once 'config.php';
require_once 'rental_service.php';

RentalService::requirePermission($conn, 'manage_maintenance');

$staffId = (int)$_SESSION['user_id'];
$currentUserRole = $_SESSION['role'] ?? RentalService::getUserRole($conn, $staffId);
$unread_sidebar_count = RentalService::getUnreadNotificationCount($conn, $staffId, $currentUserRole);
$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
$conflictWarning = null;

// Handle creating or updating maintenance record
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $pId = (int)$_POST['product_id'];
        $type = substr(trim($_POST['maintenance_type'] ?? ''), 0, 100);
        $desc = substr(trim($_POST['description'] ?? ''), 0, 1000);
        $cost = max(0.00, (float)($_POST['cost'] ?? 0));
        $startDate = $_POST['start_date'] ?? date('Y-m-d');
        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $status = in_array($_POST['status'] ?? '', ['scheduled', 'in_progress', 'completed']) ? $_POST['status'] : 'scheduled';
        $serviceCenter = substr(trim($_POST['service_center'] ?? ''), 0, 150);
        $odometer = !empty($_POST['odometer_at_service']) ? (int)$_POST['odometer_at_service'] : null;
        $allowConflicts = isset($_POST['allow_conflicts']) && $_POST['allow_conflicts'] === '1';

        if ($pId <= 0 || empty($type)) {
            $error = 'Vehicle and maintenance type are required.';
        } else {
            $res = RentalService::scheduleMaintenance(
                $conn, 
                $pId, 
                $type, 
                $desc, 
                $cost, 
                $startDate, 
                $endDate, 
                $status, 
                $staffId, 
                $serviceCenter, 
                $odometer, 
                $allowConflicts
            );

            if ($res['success']) {
                $_SESSION['message'] = $res['message'];
                header("Location: maintenance.php" . ($productId ? "?product_id=$productId" : ''));
                exit();
            } else {
                if (!empty($res['has_conflict'])) {
                    $conflictWarning = [
                        'message' => $res['message'],
                        'conflicts' => $res['conflicts'] ?? [],
                        'pId' => $pId,
                        'type' => $type,
                        'desc' => $desc,
                        'cost' => $cost,
                        'startDate' => $startDate,
                        'endDate' => $endDate,
                        'status' => $status,
                        'serviceCenter' => $serviceCenter,
                        'odometer' => $odometer
                    ];
                } else {
                    $error = $res['message'];
                }
            }
        }
    } elseif ($action === 'complete_inspection') {
        $mId = (int)$_POST['maintenance_id'];
        $inspectionResult = $_POST['inspection_result'] ?? 'passed';
        $inspectionNotes = trim($_POST['inspection_notes'] ?? '');
        $conditionStatus = $_POST['condition_status'] ?? 'good';
        $returnOdo = !empty($_POST['return_odometer']) ? (int)$_POST['return_odometer'] : null;

        $res = RentalService::completeMaintenanceInspection(
            $conn, 
            $mId, 
            $staffId, 
            $inspectionResult, 
            $inspectionNotes, 
            $conditionStatus, 
            $returnOdo
        );

        if ($res['success']) {
            $_SESSION['message'] = $res['message'];
        } else {
            $_SESSION['error'] = $res['message'];
        }
        header("Location: maintenance.php" . ($productId ? "?product_id=$productId" : ''));
        exit();
    }
}

// Fetch maintenance history
$whereSql = $productId > 0 ? "WHERE vm.product_id = $productId" : "";
$history = $conn->query("
    SELECT vm.*, p.name AS vehicle_name, p.reg_number, p.condition_status AS current_condition,
           u.name as inspector_name
    FROM vehicle_maintenance vm
    JOIN products p ON vm.product_id = p.id
    LEFT JOIN users u ON vm.inspected_by = u.id
    $whereSql
    ORDER BY vm.status != 'completed' DESC, vm.start_date DESC, vm.id DESC
");

// Fetch vehicles for dropdown
$vehicles = $conn->query("SELECT id, name, reg_number, status, mileage, condition_status FROM products WHERE is_archived = 0 ORDER BY name ASC");

// Flagged conflicts count
$flaggedCount = (int)$conn->query("SELECT COUNT(*) as cnt FROM vehicle_maintenance WHERE conflict_detected = 1 AND status != 'completed'")->fetch_assoc()['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Maintenance & Fleet Health — RentalKH</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .badge { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge-scheduled { background: #fef3c7; color: #92400e; }
        .badge-in_progress { background: #fee2e2; color: #991b1b; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-passed { background: #dcfce7; color: #166534; }
        .badge-passed_with_notes { background: #fef3c7; color: #b45309; }
        .badge-failed { background: #fee2e2; color: #b91c1c; }
        .badge-conflict { background: #fee2e2; color: #dc2626; border: 1px solid #f87171; }
        .conflict-box { background: #fff5f5; border: 1.5px solid #f87171; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
        .modal-card { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; margin-top: 10px; }
    </style>
</head>
<body>
<div class="app-shell" style="grid-template-columns:240px minmax(0,1fr);">
    <!-- Sidebar -->
    <aside class="app-sidebar">
        <a class="logo" href="Service.php">Joul Rental</a>
        <nav class="side-nav">
            <a href="dashboard.php"><span class="dot"></span>Overview</a>
            <?php if (RentalService::checkPermission($conn, $staffId, 'manage_vehicles')): ?>
                <a href="Product.php"><span class="dot"></span>Products</a>
                <a href="product_edit.php"><span class="dot"></span>Product Management</a>
            <?php endif; ?>
            <a class="active" href="maintenance.php"><span class="dot"></span>Maintenance</a>
            <?php if (RentalService::checkPermission($conn, $staffId, 'manage_categories')): ?>
                <a href="categories.php"><span class="dot"></span>Categories</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $staffId, 'manage_bookings')): ?>
                <a href="orders.php"><span class="dot"></span>Orders</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $staffId, 'view_finance_reports')): ?>
                <a href="finance.php"><span class="dot"></span>Finance</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $staffId, 'manage_customers') || RentalService::checkPermission($conn, $staffId, 'manage_staff_roles')): ?>
                <a href="users.php"><span class="dot"></span>Users</a>
            <?php endif; ?>
            <a href="notifications.php">
                <span class="dot"></span>Notifications
                <?php if ($unread_sidebar_count > 0): ?>
                    <span style="background:#e53e3e; color:#fff; font-size:11px; font-weight:700; border-radius:10px; padding:1px 6px; margin-left:auto;"><?= $unread_sidebar_count ?></span>
                <?php endif; ?>
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="profile-avatar"><?= strtoupper(substr($_SESSION['username'],0,2)) ?></div>
            <div>
                <p class="name"><?= htmlspecialchars($_SESSION['username']) ?></p>
                <p class="role"><span style="text-transform: capitalize; font-size:11px; color:#64748b;"><?= htmlspecialchars($currentUserRole) ?></span> | <a href="logout.php" style="color: #a72d25; text-decoration: none; font-weight: bold;">Logout</a></p>
            </div>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div>
                <a href="maintenance.php" class="logo"><h1>Fleet Maintenance & Inspection Operations</h1></a>
                <p class="subtitle"><?= date('l, F j, Y') ?> &bull; Operational Integrity & Safety Control &bull; Role: <strong><?= htmlspecialchars(strtoupper($currentUserRole)) ?></strong></p>
            </div>
            <div class="topbar-actions" style="display:flex; gap:10px; align-items:center;">
                <a href="notifications.php" style="text-decoration:none; background:#f1f5f9; padding:8px 14px; border-radius:6px; font-size:13px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:6px;">
                    🔔 Notifications <?php if ($unread_sidebar_count > 0): ?><span style="background:#e53e3e; color:#fff; border-radius:10px; padding:1px 6px; font-size:11px; font-weight:700;"><?= $unread_sidebar_count ?></span><?php endif; ?>
                </a>
                <a href="Product.php" class="submit-btn" style="text-decoration:none; padding:8px 14px; background:#64748b;">&larr; Back to Fleet</a>
            </div>
        </header>

        <!-- Flash Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success" style="margin: 20px 20px 0; padding: 12px; background: #dfe7d8; border-radius: 6px; color:#166534;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin: 20px 20px 0; padding: 12px; background: #fee2e2; border-radius: 6px; color: #b91c1c;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Active Conflict Banner -->
        <?php if ($flaggedCount > 0): ?>
            <div style="margin: 20px 20px 0; background: #fef2f2; border: 1px solid #ef4444; padding: 14px 20px; border-radius: 8px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <strong style="color: #991b1b;">Maintenance Conflicts Detected:</strong>
                    <span style="color: #7f1d1d;"> <?= $flaggedCount ?> vehicle maintenance record(s) overlap with confirmed customer reservations. Please reassign vehicles or contact customers.</span>
                </div>
                <a href="orders.php?filter=conflicts" style="background:#b91c1c; color:#fff; text-decoration:none; padding:6px 12px; border-radius:4px; font-size:12px; font-weight:600;">View Conflicts</a>
            </div>
        <?php endif; ?>

        <!-- Conflict Resolution Confirmation Prompt (If triggered) -->
        <?php if ($conflictWarning): ?>
            <div style="margin: 20px;" class="conflict-box">
                <h3 style="color:#b91c1c; margin-top:0;">&excl; Booking Conflict Warning: Customer Reservations Detected</h3>
                <p style="font-size:13px; color:#7f1d1d; line-height:1.5;">
                    The vehicle you selected has <strong><?= count($conflictWarning['conflicts']) ?> confirmed/active booking(s)</strong> overlapping the requested maintenance period (<?= htmlspecialchars($conflictWarning['startDate']) ?> to <?= htmlspecialchars($conflictWarning['endDate'] ?: $conflictWarning['startDate']) ?>). 
                    To maintain customer trust, <strong>RentalKH never silently cancels confirmed bookings</strong>.
                </p>

                <div style="background:#fff; border:1px solid #fca5a5; border-radius:6px; padding:12px; margin-bottom:14px;">
                    <strong style="font-size:12px; text-transform:uppercase; color:#991b1b;">Conflicting Reservations:</strong>
                    <ul style="margin:6px 0 0; padding-left:20px; font-size:13px;">
                        <?php foreach ($conflictWarning['conflicts'] as $c): ?>
                            <li>
                                <strong><a href="order_detail.php?id=<?= $c['id'] ?>" target="_blank" style="color:#b91c1c;"><?= htmlspecialchars($c['booking_ref']) ?></a></strong> &mdash; 
                                Customer: <?= htmlspecialchars($c['customer_name']) ?> (<?= htmlspecialchars($c['customer_phone'] ?: 'No phone') ?>) &bull;
                                Period: <?= htmlspecialchars($c['start_date']) ?> to <?= htmlspecialchars($c['end_date']) ?> &bull; Status: <strong><?= htmlspecialchars($c['status']) ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <form method="post" style="display:flex; gap:10px; align-items:center;">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="product_id" value="<?= $conflictWarning['pId'] ?>">
                    <input type="hidden" name="maintenance_type" value="<?= htmlspecialchars($conflictWarning['type']) ?>">
                    <input type="hidden" name="description" value="<?= htmlspecialchars($conflictWarning['desc']) ?>">
                    <input type="hidden" name="cost" value="<?= htmlspecialchars($conflictWarning['cost']) ?>">
                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($conflictWarning['startDate']) ?>">
                    <input type="hidden" name="end_date" value="<?= htmlspecialchars($conflictWarning['endDate']) ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($conflictWarning['status']) ?>">
                    <input type="hidden" name="service_center" value="<?= htmlspecialchars($conflictWarning['serviceCenter']) ?>">
                    <input type="hidden" name="odometer_at_service" value="<?= htmlspecialchars($conflictWarning['odometer'] ?? '') ?>">
                    <input type="hidden" name="allow_conflicts" value="1">

                    <button type="submit" style="background:#b91c1c; color:#fff; border:none; padding:8px 16px; border-radius:4px; font-weight:700; cursor:pointer;">
                        Confirm Staff Override & Flag Conflicts
                    </button>
                    <a href="maintenance.php" style="color:#64748b; font-size:13px; text-decoration:none;">Cancel</a>
                </form>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 380px 1fr; gap: 24px; margin: 20px;">
            <!-- Schedule Maintenance Form -->
            <section class="panel" style="padding: 20px;">
                <h3 style="margin-top:0; font-size:16px;">Schedule Maintenance Event</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:13px;">Vehicle *</label>
                        <select name="product_id" required style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                            <option value="">-- Select Vehicle --</option>
                            <?php while ($v = $vehicles->fetch_assoc()): ?>
                                <option value="<?= $v['id'] ?>" <?= $productId == $v['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['name']) ?> (<?= htmlspecialchars($v['reg_number'] ?: 'No Reg') ?>) &mdash; <?= ucfirst($v['status']) ?> &bull; <?= number_format($v['mileage']) ?> km
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:13px;">Maintenance Category *</label>
                        <select name="maintenance_type" required style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                            <option value="Routine Inspection">Routine Inspection & Safety Check</option>
                            <option value="Oil & Filter Change">Oil & Filter Change</option>
                            <option value="Brake System Service">Brake System Service</option>
                            <option value="Tire Replacement / Alignment">Tire Replacement & Wheel Alignment</option>
                            <option value="Engine / Transmission Repair">Engine & Transmission Overhaul</option>
                            <option value="Battery / Electrical">Battery & Electrical Diagnostics</option>
                            <option value="Post-Rental Damage Repair">Post-Rental Damage Restoration</option>
                            <option value="Bodywork & Detailing">Bodywork, Paint & Sanitization</option>
                        </select>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:12px;">
                        <div>
                            <label style="font-weight:600; font-size:13px;">Start Date *</label>
                            <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:13px;">Estimated End Date</label>
                            <input type="date" name="end_date" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:12px;">
                        <div>
                            <label style="font-weight:600; font-size:13px;">Initial Status</label>
                            <select name="status" style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                                <option value="scheduled">Scheduled</option>
                                <option value="in_progress" selected>In Progress (Locks Vehicle)</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:13px;">Estimated Cost ($)</label>
                            <input type="number" name="cost" step="0.01" min="0" placeholder="0.00" style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:13px;">Service Center / Workshop</label>
                        <input type="text" name="service_center" placeholder="e.g. Honda Central Garage, Phnom Penh" style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:13px;">Current Odometer (km)</label>
                        <input type="number" name="odometer_at_service" placeholder="e.g. 15400" style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                    </div>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label style="font-weight:600; font-size:13px;">Service Reason & Work Scope</label>
                        <textarea name="description" rows="3" placeholder="Specific issues reported, parts required..." style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;"></textarea>
                    </div>

                    <button type="submit" class="submit-btn" style="width:100%; padding:10px; font-weight:700;">
                        Schedule & Verify Availability
                    </button>
                </form>
            </section>

            <!-- Maintenance History Table -->
            <section class="panel" style="padding: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h3 style="margin:0; font-size:16px;">Fleet Maintenance & Inspection History</h3>
                        <span style="font-size:12px; color:#64748b;">Vehicles return to service only after authorized inspection</span>
                    </div>
                    <?php if ($productId > 0): ?>
                        <a href="maintenance.php" style="font-size:13px; color:var(--color-wine); font-weight:600;">View All Vehicles</a>
                    <?php endif; ?>
                </div>

                <table class="admin-table" style="width:100%; font-size:13px;">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Service Type & Workshop</th>
                            <th>Dates</th>
                            <th style="text-align:right;">Cost</th>
                            <th>Status / Inspection</th>
                            <th>Action / Authorization</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($history && $history->num_rows > 0): ?>
                        <?php while ($row = $history->fetch_assoc()): ?>
                        <tr style="<?= !empty($row['conflict_detected']) ? 'background: #fff8f8;' : '' ?>">
                            <td>
                                <strong><?= htmlspecialchars($row['vehicle_name']) ?></strong>
                                <div style="font-size:11px; color:#64748b; font-family:monospace;">
                                    <?= htmlspecialchars($row['reg_number'] ?: 'N/A') ?>
                                </div>
                                <?php if (!empty($row['conflict_detected'])): ?>
                                    <span class="badge badge-conflict" title="Reservation overlap flagged">&excl; Conflict Flagged</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($row['maintenance_type']) ?></strong>
                                <?php if ($row['service_center']): ?>
                                    <div style="font-size:11px; color:#0369a1;"><?= htmlspecialchars($row['service_center']) ?></div>
                                <?php endif; ?>
                                <?php if ($row['description']): ?>
                                    <div style="font-size:12px; color:#64748b; max-width:260px;"><?= htmlspecialchars($row['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px; white-space:nowrap;">
                                <?= date('M j, Y', strtotime($row['start_date'])) ?>
                                <?= $row['end_date'] ? ' &rarr; ' . date('M j, Y', strtotime($row['end_date'])) : '' ?>
                            </td>
                            <td style="text-align:right; font-weight:700;">$<?= number_format($row['cost'], 2) ?></td>
                            <td>
                                <span class="badge badge-<?= htmlspecialchars($row['status']) ?>">
                                    <?= str_replace('_', ' ', htmlspecialchars($row['status'])) ?>
                                </span>
                                <?php if ($row['status'] === 'completed'): ?>
                                    <div style="margin-top:4px;">
                                        <span class="badge badge-<?= htmlspecialchars($row['inspection_result']) ?>">
                                            <?= str_replace('_', ' ', htmlspecialchars($row['inspection_result'])) ?>
                                        </span>
                                    </div>
                                    <?php if ($row['inspector_name']): ?>
                                        <div style="font-size:10px; color:#64748b;">By <?= htmlspecialchars($row['inspector_name']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['status'] !== 'completed'): ?>
                                    <!-- Authorized Inspection Completion Form -->
                                    <details style="font-size:12px;">
                                        <summary style="cursor:pointer; color:#0284c7; font-weight:600; padding:4px 0;">
                                            Inspect & Authorize &rarr;
                                        </summary>
                                        <div class="modal-card">
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="action" value="complete_inspection">
                                                <input type="hidden" name="maintenance_id" value="<?= $row['id'] ?>">

                                                <label style="font-weight:600; font-size:11px; display:block; margin-bottom:4px;">Inspection Outcome:</label>
                                                <select name="inspection_result" required style="width:100%; font-size:12px; padding:4px; margin-bottom:8px;">
                                                    <option value="passed">PASSED (Return to Active Service)</option>
                                                    <option value="passed_with_notes">PASSED WITH NOTES</option>
                                                    <option value="failed">FAILED (Keep in Maintenance!)</option>
                                                </select>

                                                <label style="font-weight:600; font-size:11px; display:block; margin-bottom:4px;">Vehicle Condition Rating:</label>
                                                <select name="condition_status" style="width:100%; font-size:12px; padding:4px; margin-bottom:8px;">
                                                    <option value="excellent">Excellent</option>
                                                    <option value="good" selected>Good</option>
                                                    <option value="fair">Fair</option>
                                                </select>

                                                <label style="font-weight:600; font-size:11px; display:block; margin-bottom:4px;">Odometer (km):</label>
                                                <input type="number" name="return_odometer" placeholder="Current km" style="width:100%; font-size:12px; padding:4px; margin-bottom:8px;">

                                                <label style="font-weight:600; font-size:11px; display:block; margin-bottom:4px;">Inspection Notes / Cert:</label>
                                                <textarea name="inspection_notes" rows="2" placeholder="Road test results, parts verified..." style="width:100%; font-size:12px; padding:4px; margin-bottom:8px;"></textarea>

                                                <button type="submit" class="submit-btn" style="width:100%; padding:6px; font-size:12px; background:#166534;">
                                                    Authorize Inspection
                                                </button>
                                            </form>
                                        </div>
                                    </details>
                                <?php else: ?>
                                    <span style="color:#166534; font-size:12px; font-weight:600;">
                                        &check; Authorized
                                    </span>
                                    <?php if ($row['inspection_notes']): ?>
                                        <div style="font-size:11px; color:#64748b;"><?= htmlspecialchars($row['inspection_notes']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:40px; color:#64748b;">No maintenance records found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</div>
</body>
</html>
