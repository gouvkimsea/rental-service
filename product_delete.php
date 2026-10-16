<?php
// product_delete.php - Safe Vehicle Archiving & Deletion with RBAC & Audit Trail
require_once 'config.php';
require_once 'rental_service.php';

RentalService::requirePermission($conn, 'manage_vehicles');

$actorId = (int)$_SESSION['user_id'];
$actorRole = $_SESSION['role'] ?? RentalService::getUserRole($conn, $actorId);

// CSRF validation
$token = $_GET['token'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    die('Invalid or missing security token.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? 'archive';

if ($id > 0) {
    // Check vehicle name
    $nameStmt = $conn->prepare("SELECT name, reg_number FROM products WHERE id = ?");
    $nameStmt->bind_param("i", $id);
    $nameStmt->execute();
    $vInfo = $nameStmt->get_result()->fetch_assoc();
    $vName = $vInfo ? "{$vInfo['name']} ({$vInfo['reg_number']})" : "ID #$id";

    // Check if vehicle has any order items (rental history)
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM order_items WHERE product_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $historyCount = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;

    if ($action === 'unarchive') {
        $upd = $conn->prepare("UPDATE products SET is_archived = 0, status = 'available' WHERE id = ?");
        $upd->bind_param("i", $id);
        $upd->execute();
        RentalService::logSystemAudit($conn, $actorId, $actorRole, 'operational', 'VEHICLE_RESTORED', $id, 'vehicle', "Restored vehicle $vName to active inventory");
        $_SESSION['message'] = "Vehicle #$id has been restored to active inventory.";
    } elseif ($historyCount > 0 || $action === 'archive') {
        // Safe archiving to preserve historical bookings and revenue analytics
        $upd = $conn->prepare("UPDATE products SET is_archived = 1, status = 'inactive' WHERE id = ?");
        $upd->bind_param("i", $id);
        $upd->execute();
        RentalService::logSystemAudit($conn, $actorId, $actorRole, 'operational', 'VEHICLE_ARCHIVED', $id, 'vehicle', "Archived vehicle $vName ($historyCount historical bookings preserved)");
        $_SESSION['message'] = "Vehicle #$id has been safely archived (all rental history preserved).";
    } else {
        // Only hard delete if absolutely no historical records exist
        $del = $conn->prepare("DELETE FROM products WHERE id = ?");
        $del->bind_param("i", $id);
        $del->execute();
        RentalService::logSystemAudit($conn, $actorId, $actorRole, 'operational', 'VEHICLE_DELETED', $id, 'vehicle', "Permanently deleted vehicle $vName (no rental history)");
        $_SESSION['message'] = "Vehicle #$id has been permanently deleted.";
    }
}

header("Location: Product.php");
exit();