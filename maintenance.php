<?php
// maintenance.php - Vehicle Maintenance & Condition History Management
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit();
}

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
$error = '';

// Handle creating or updating maintenance record
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request.');
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
        $updateVehicleStatus = isset($_POST['update_vehicle_status']) && $_POST['update_vehicle_status'] == '1';
        $conditionStatus = $_POST['condition_status'] ?? null;

        if ($pId <= 0 || empty($type)) {
            $error = 'Vehicle and maintenance type are required.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO vehicle_maintenance (product_id, maintenance_type, description, cost, start_date, end_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issdsss", $pId, $type, $desc, $cost, $startDate, $endDate, $status);
            if ($stmt->execute()) {
                // If maintenance is in progress, mark vehicle as maintenance; if completed, mark available
                if ($updateVehicleStatus) {
                    if ($status === 'in_progress') {
                        $conn->query("UPDATE products SET status = 'maintenance' WHERE id = $pId");
                    } elseif ($status === 'completed') {
                        $conn->query("UPDATE products SET status = 'available' WHERE id = $pId");
                    }
                }
                if ($conditionStatus && in_array($conditionStatus, ['excellent', 'good', 'fair', 'needs_service'])) {
                    $cStmt = $conn->prepare("UPDATE products SET condition_status = ? WHERE id = ?");
                    $cStmt->bind_param("si", $conditionStatus, $pId);
                    $cStmt->execute();
                }

                $_SESSION['message'] = 'Maintenance record logged successfully.';
                header("Location: maintenance.php" . ($productId ? "?product_id=$productId" : ''));
                exit();
            } else {
                $error = 'Database error: ' . $stmt->error;
            }
        }
    } elseif ($action === 'mark_completed') {
        $mId = (int)$_POST['maintenance_id'];
        $now = date('Y-m-d');
        $stmt = $conn->prepare("
            UPDATE vehicle_maintenance 
            SET status = 'completed', end_date = COALESCE(end_date, ?) 
            WHERE id = ?
        ");
        $stmt->bind_param("si", $now, $mId);
        if ($stmt->execute()) {
            // Restore product status to available
            $pStmt = $conn->prepare("SELECT product_id FROM vehicle_maintenance WHERE id = ?");
            $pStmt->bind_param("i", $mId);
            $pStmt->execute();
            if ($pRow = $pStmt->get_result()->fetch_assoc()) {
                $pId = (int)$pRow['product_id'];
                $conn->query("UPDATE products SET status = 'available', condition_status = 'good' WHERE id = $pId AND status = 'maintenance'");
            }
            $_SESSION['message'] = "Maintenance #$mId marked as completed.";
            header("Location: maintenance.php" . ($productId ? "?product_id=$productId" : ''));
            exit();
        }
    }
}

// Fetch maintenance history
$whereSql = $productId > 0 ? "WHERE vm.product_id = $productId" : "";
$history = $conn->query("
    SELECT vm.*, p.name AS vehicle_name, p.reg_number, p.condition_status AS current_condition
    FROM vehicle_maintenance vm
    JOIN products p ON vm.product_id = p.id
    $whereSql
    ORDER BY vm.status != 'completed' DESC, vm.start_date DESC, vm.id DESC
");

// Fetch vehicles for dropdown
$vehicles = $conn->query("SELECT id, name, reg_number, status, condition_status FROM products WHERE is_archived = 0 ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vehicle Maintenance History</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-scheduled { background: #e0f2fe; color: #0369a1; }
        .badge-in_progress { background: #fef08a; color: #854d0e; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-full { grid-column: span 2; }
    </style>
</head>
<body>
<div class="app-shell" style="grid-template-columns:240px minmax(0,1fr);">
    <aside class="app-sidebar">
        <a class="logo" href="Service.php">Joul Rental</a>
        <nav class="side-nav">
            <a href="dashboard.php"><span class="dot"></span>Overview</a>
            <a href="Product.php"><span class="dot"></span>Products</a>
            <a href="product_edit.php"><span class="dot"></span>Product Management</a>
            <a class="active" href="maintenance.php"><span class="dot"></span>Maintenance</a>
            <a href="categories.php"><span class="dot"></span>Categories</a>
            <a href="orders.php"><span class="dot"></span>Orders</a>
            <a href="finance.php"><span class="dot"></span>Finance</a>
            <a href="users.php"><span class="dot"></span>Users</a>
        </nav>
        <div class="sidebar-footer">
            <div class="profile-avatar"><?= strtoupper(substr($_SESSION['username'],0,2)) ?></div>
            <div>
                <p class="name"><?= htmlspecialchars($_SESSION['username']) ?></p>
                <p class="role"><a href="logout.php" style="color: #a72d25; text-decoration: none; font-weight: bold;">Logout</a></p>
            </div>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div>
                <a href="maintenance.php" class="logo"><h1>Vehicle Maintenance & Condition</h1></a>
                <p class="subtitle"><?= date('l, F j, Y') ?></p>
            </div>
            <div class="topbar-actions">
                <a href="Product.php" class="submit-btn" style="text-decoration:none; padding:10px 16px; background:#666;">&larr; Back to Inventory</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-success" style="margin: 20px; padding: 12px; background: #dfe7d8; border-radius: 6px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin: 20px; padding: 12px; background: #fee2e2; border-radius: 6px; color: #b91c1c;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 360px 1fr; gap: 24px; margin: 20px;">
            <!-- Log Service Form -->
            <section class="panel" style="padding: 20px;">
                <h3 style="margin-top:0;">Log Maintenance Event</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:13px;">Vehicle</label>
                        <select name="product_id" required style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                            <option value="">-- Select Vehicle --</option>
                            <?php while ($v = $vehicles->fetch_assoc()): ?>
                                <option value="<?= $v['id'] ?>" <?= $productId == $v['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['name']) ?> (<?= htmlspecialchars($v['reg_number']) ?>) - <?= ucfirst($v['status']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:13px;">Maintenance Type</label>
                        <select name="maintenance_type" required style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                            <option value="Routine Inspection">Routine Inspection</option>
                            <option value="Oil & Filter Change">Oil & Filter Change</option>
                            <option value="Brake System Service">Brake System Service</option>
                            <option value="Tire Replacement / Alignment">Tire Replacement / Alignment</option>
                            <option value="Engine / Transmission Repair">Engine / Transmission Repair</option>
                            <option value="Battery / Electrical">Battery / Electrical</option>
                            <option value="Bodywork & Detailing">Bodywork & Detailing</option>
                            <option value="General Safety Check">General Safety Check</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:13px;">Start Date</label>
                        <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:13px;">Estimated Completion Date</label>
                        <input type="date" name="end_date" style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:13px;">Service Cost ($)</label>
                        <input type="number" step="0.01" name="cost" value="0.00" style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:13px;">Current Maintenance Status</label>
                        <select name="status" style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                            <option value="scheduled">Scheduled</option>
                            <option value="in_progress" selected>In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:13px;">Update Condition Status</label>
                        <select name="condition_status" style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;">
                            <option value="good">Good</option>
                            <option value="excellent">Excellent</option>
                            <option value="fair">Fair</option>
                            <option value="needs_service">Needs Service</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-size:13px;">
                            <input type="checkbox" name="update_vehicle_status" value="1" checked>
                            Automatically sync vehicle status (Set to 'Maintenance' if in progress)
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label style="font-weight:600; font-size:13px;">Notes / Service Details</label>
                        <textarea name="description" rows="3" placeholder="Parts replaced, technician notes..." style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;"></textarea>
                    </div>

                    <button type="submit" class="submit-btn" style="width:100%;">Record Maintenance</button>
                </form>
            </section>

            <!-- Maintenance History Table -->
            <section class="panel" style="padding: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3 style="margin:0;">Maintenance Records <?= $productId ? "for Selected Vehicle" : "" ?></h3>
                    <?php if ($productId > 0): ?>
                        <a href="maintenance.php" style="font-size:13px; color:#a72d25;">View All Vehicles</a>
                    <?php endif; ?>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Type</th>
                            <th>Dates</th>
                            <th>Cost</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($history && $history->num_rows > 0): ?>
                        <?php while ($row = $history->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($row['vehicle_name']) ?></strong>
                                <div style="font-size:11px; color:#666; font-family:monospace;">
                                    <?= htmlspecialchars($row['reg_number']) ?>
                                </div>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($row['maintenance_type']) ?></strong>
                                <?php if ($row['description']): ?>
                                    <div style="font-size:12px; color:#555;"><?= htmlspecialchars($row['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;">
                                <?= date('M j, Y', strtotime($row['start_date'])) ?>
                                <?= $row['end_date'] ? ' &rarr; ' . date('M j, Y', strtotime($row['end_date'])) : '' ?>
                            </td>
                            <td>$<?= number_format($row['cost'], 2) ?></td>
                            <td>
                                <span class="badge badge-<?= htmlspecialchars($row['status']) ?>">
                                    <?= str_replace('_', ' ', ucfirst($row['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['status'] !== 'completed'): ?>
                                    <form method="post" style="display:inline-block;">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="mark_completed">
                                        <input type="hidden" name="maintenance_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn-approve" style="font-size:11px; padding:4px 8px;" onclick="return confirm('Mark this maintenance as completed and restore vehicle to available?');">
                                            Mark Done
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#666; font-size:12px;">Completed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:30px; color:#666;">No maintenance records found.</td>
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
