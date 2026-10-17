<?php
require_once 'config.php';
require_once 'rental_service.php';

RentalService::requirePermission($conn, 'manage_customers');

$currentUserId = (int)$_SESSION['user_id'];
$currentUserRole = $_SESSION['role'] ?? 'customer';
$canManageRoles = RentalService::checkPermission($conn, $currentUserId, 'manage_staff_roles');
$unread_sidebar_count = RentalService::getUnreadNotificationCount($conn, $currentUserId, $currentUserRole);

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

$selectedCustomer = null;
$customerOrders = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['user_id'], $_POST['action'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
    
    $target_id = (int)$_POST['user_id'];
    $action = $_POST['action'];

    if ($action === 'enable' || $action === 'disable') {
        if ($target_id === $currentUserId) {
            $_SESSION['error'] = "You cannot disable your own active account.";
        } else {
            $new_status = ($action === 'enable') ? 1 : 0;
            $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_status, $target_id);
            if ($stmt->execute()) {
                RentalService::logSystemAudit($conn, $currentUserId, $currentUserRole, 'auth_permission', 'USER_STATUS_CHANGE', $target_id, 'user', "User #$target_id status changed to " . ($new_status ? 'Active' : 'Disabled'));
                $_SESSION['message'] = "User status updated successfully.";
            }
        }
    } elseif ($action === 'change_role') {
        if (!$canManageRoles) {
            $_SESSION['error'] = "Unauthorized: Only super administrators can reassign staff roles.";
        } elseif ($target_id === $currentUserId) {
            $_SESSION['error'] = "You cannot modify your own administrative role.";
        } else {
            $newRole = $_POST['role'] ?? 'customer';
            $validRoles = ['admin', 'rental_manager', 'finance_staff', 'customer'];
            if (in_array($newRole, $validRoles, true)) {
                $isAdmin = ($newRole === 'admin') ? 1 : 0;
                $stmt = $conn->prepare("UPDATE users SET role = ?, is_admin = ? WHERE id = ?");
                $stmt->bind_param("sii", $newRole, $isAdmin, $target_id);
                if ($stmt->execute()) {
                    RentalService::logSystemAudit($conn, $currentUserId, $currentUserRole, 'auth_permission', 'ROLE_CHANGED', $target_id, 'user', "User #$target_id role updated to $newRole");
                    $_SESSION['message'] = "User #$target_id role updated to " . ucfirst(str_replace('_', ' ', $newRole)) . ".";
                }
            } else {
                $_SESSION['error'] = "Invalid role specified.";
            }
        }
    }
    header("Location: users.php" . (isset($_GET['view']) ? "?view=" . (int)$_GET['view'] : ''));
    exit();
}

// Check if viewing specific customer history
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId > 0) {
    $cStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $cStmt->bind_param("i", $viewId);
    $cStmt->execute();
    $selectedCustomer = $cStmt->get_result()->fetch_assoc();

    if ($selectedCustomer) {
        $oStmt = $conn->prepare("
            SELECT o.*, 
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
            FROM orders o 
            WHERE o.user_id = ? 
            ORDER BY o.order_date DESC
        ");
        $oStmt->bind_param("i", $viewId);
        $oStmt->execute();
        $customerOrders = $oStmt->get_result();
    }
}

// Fetch all users
$query = "
    SELECT u.id, u.name, u.email, u.phone, u.driver_license, u.id_card_number, u.is_admin, u.role, u.is_active, u.created_at,
           COUNT(o.id) as total_orders,
           COALESCE(SUM(CASE WHEN o.status NOT IN ('Cancelled', 'Declined', 'Expired') THEN o.total_amount ELSE 0 END), 0) as total_spent
    FROM users u
    LEFT JOIN orders o ON u.id = o.user_id
    GROUP BY u.id
    ORDER BY u.role = 'admin' DESC, u.role = 'rental_manager' DESC, u.role = 'finance_staff' DESC, u.id DESC
";
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer & Staff Role Governance — JOUL Rental</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <aside class="app-sidebar">
        <a class="logo" href="Service.php">JOUL RENTAL</a>
        <nav class="side-nav">
            <a href="dashboard.php"><span class="dot"></span>Overview</a>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_vehicles')): ?>
                <a href="Product.php"><span class="dot"></span>Products</a>
                <a href="product_edit.php"><span class="dot"></span>Product Management</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_maintenance')): ?>
                <a href="maintenance.php"><span class="dot"></span>Maintenance</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_categories')): ?>
                <a href="categories.php"><span class="dot"></span>Categories</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'manage_bookings')): ?>
                <a href="orders.php"><span class="dot"></span>Orders</a>
            <?php endif; ?>
            <?php if (RentalService::checkPermission($conn, $currentUserId, 'view_finance_reports')): ?>
                <a href="finance.php"><span class="dot"></span>Finance</a>
            <?php endif; ?>
            <a class="active" href="users.php"><span class="dot"></span>Users</a>
            <a href="notifications.php">
                <span class="dot"></span>Notifications
                <?php if ($unread_sidebar_count > 0): ?>
                    <span class="badge-count" style="margin-left:auto;"><?= $unread_sidebar_count ?></span>
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
                <h1>Customer & Staff Role Governance</h1>
                <p class="subtitle"><?= date('l, F j, Y') ?> &bull; Logged in as: <strong><?= ucfirst(str_replace('_', ' ', $currentUserRole)) ?></strong></p>
            </div>
            <div class="topbar-actions">
                <a href="notifications.php" style="text-decoration:none; background:#f1f5f9; padding:8px 14px; border-radius:6px; font-size:13px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:6px;">
                    🔔 Notifications <?php if ($unread_sidebar_count > 0): ?><span class="badge-count"><?= $unread_sidebar_count ?></span><?php endif; ?>
                </a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-success" style="margin: 20px 20px 0; padding: 12px 16px; border-radius: 6px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin: 20px 20px 0; padding: 12px 16px; border-radius: 6px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($selectedCustomer): ?>
            <section class="panel" style="margin: 20px; padding: 20px; border-left: 4px solid var(--color-wine);">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div>
                        <h2 style="margin:0 0 5px 0; font-size:18px;"><?= htmlspecialchars($selectedCustomer['name']) ?></h2>
                        <div style="font-size:13px; color:#666;">
                            Email: <strong><?= htmlspecialchars($selectedCustomer['email'] ?: 'None') ?></strong> &bull; 
                            Phone: <strong><?= htmlspecialchars($selectedCustomer['phone'] ?: 'None') ?></strong> &bull;
                            ID Card: <strong><?= htmlspecialchars($selectedCustomer['id_card_number'] ?: 'None') ?></strong> &bull;
                            License: <strong><?= htmlspecialchars($selectedCustomer['driver_license'] ?: 'None') ?></strong>
                        </div>
                        <?php if ($selectedCustomer['address']): ?>
                            <div style="font-size:13px; color:#555; margin-top:4px;">Address: <?= htmlspecialchars($selectedCustomer['address']) ?></div>
                        <?php endif; ?>
                    </div>
                    <a href="users.php" style="color:#666; font-size:13px; text-decoration:underline;">Close Details</a>
                </div>

                <h3 style="margin-top:20px; font-size:16px;">Rental History for <?= htmlspecialchars($selectedCustomer['name']) ?></h3>
                <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Booking Ref</th>
                            <th>Date</th>
                            <th>Rental Window</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($customerOrders && $customerOrders->num_rows > 0): ?>
                        <?php while ($co = $customerOrders->fetch_assoc()): ?>
                            <tr>
                                <td><a href="order_detail.php?id=<?= $co['id'] ?>" style="font-family:monospace; font-weight:bold; color:var(--color-wine);"><?= htmlspecialchars($co['booking_ref'] ?: '#' . $co['id']) ?></a></td>
                                <td><?= date('M j, Y', strtotime($co['order_date'])) ?></td>
                                <td style="font-size:12px;">
                                    <?= date('M j', strtotime($co['start_date'])) ?> &rarr; <?= date('M j, Y', strtotime($co['end_date'])) ?>
                                </td>
                                <td>$<?= number_format($co['total_amount'], 2) ?></td>
                                <td>
                                    <span class="status-pill status-pill--<?= strtolower($co['status']) ?>">
                                        <?= htmlspecialchars($co['status']) ?>
                                    </span>
                                </td>
                                <td><a href="order_detail.php?id=<?= $co['id'] ?>">View</a></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center; padding:15px; color:#666;">No bookings found for this customer.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel" style="margin: 20px;">
            <div class="panel-head" style="display:flex; justify-content:space-between; align-items:center;">
                <h2>All Registered Users & Staff Roles</h2>
                <span style="font-size:12px; color:#64748b;">Roles: Super Admin, Rental Manager, Finance Staff, Customer</span>
            </div>
            <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Contact / Phone</th>
                        <th>Driver License</th>
                        <th>Assigned Role</th>
                        <th>Bookings</th>
                        <th>Total Spent</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $result->fetch_assoc()): 
                    $uRole = $row['role'] ?: ($row['is_admin'] ? 'admin' : 'customer');
                ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($row['name']) ?></strong>
                            <div style="font-size:12px; color:#666;"><?= htmlspecialchars($row['email'] ?: 'No email') ?></div>
                        </td>
                        <td><?= htmlspecialchars($row['phone'] ?: 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['driver_license'] ?: 'N/A') ?></td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($uRole) ?>">
                                <?= str_replace('_', ' ', htmlspecialchars($uRole)) ?>
                            </span>

                            <?php if ($canManageRoles && $row['id'] !== $currentUserId): ?>
                                <form method="post" style="display:block; margin-top:4px;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="action" value="change_role">
                                    <select name="role" onchange="this.form.submit()" style="font-size:11px; padding:2px 4px; border-radius:3px;">
                                        <option value="customer" <?= $uRole === 'customer' ? 'selected' : '' ?>>Customer</option>
                                        <option value="rental_manager" <?= $uRole === 'rental_manager' ? 'selected' : '' ?>>Rental Manager</option>
                                        <option value="finance_staff" <?= $uRole === 'finance_staff' ? 'selected' : '' ?>>Finance Staff</option>
                                        <option value="admin" <?= $uRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    </select>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="users.php?view=<?= $row['id'] ?>" style="font-weight:bold;">
                                <?= $row['total_orders'] ?> bookings
                            </a>
                        </td>
                        <td>$<?= number_format($row['total_spent'], 2) ?></td>
                        <td>
                            <span class="badge badge-<?= $row['is_active'] ? 'active' : 'disabled' ?>">
                                <?= $row['is_active'] ? 'Active' : 'Disabled' ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="users.php?view=<?= $row['id'] ?>" style="margin-right:8px; font-size:12px;">History</a>
                            <?php if ($canManageRoles && $row['id'] !== $currentUserId): ?>
                                <form method="post" style="display:inline-block;">
                                    <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="<?= $row['is_active'] ? 'disable' : 'enable' ?>">
                                    <button type="submit" 
                                            class="<?= $row['is_active'] ? 'btn-decline' : 'btn-approve' ?>"
                                            style="padding:4px 8px; font-size:11px;"
                                            onclick="return confirm('<?= $row['is_active'] ? 'Disable' : 'Enable' ?> this user?')">
                                        <?= $row['is_active'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                            <?php elseif ($row['id'] === $currentUserId): ?>
                                <span style="color:#aaa; font-size:11px;">(You)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            </div>
        </section>
    </div>
</div>
</body>
</html>
