<?php
// product_delete.php - Safe Vehicle Archiving & Deletion
require_once 'config.php';
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? 'archive';

if ($id > 0) {
    // Check if vehicle has any order items (rental history)
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM order_items WHERE product_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $historyCount = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;

    if ($action === 'unarchive') {
        $upd = $conn->prepare("UPDATE products SET is_archived = 0, status = 'available' WHERE id = ?");
        $upd->bind_param("i", $id);
        $upd->execute();
        $_SESSION['message'] = "Vehicle #$id has been restored to active inventory.";
    } elseif ($historyCount > 0 || $action === 'archive') {
        // Safe archiving to preserve historical bookings and revenue analytics
        $upd = $conn->prepare("UPDATE products SET is_archived = 1, status = 'inactive' WHERE id = ?");
        $upd->bind_param("i", $id);
        $upd->execute();
        $_SESSION['message'] = "Vehicle #$id has been safely archived (all rental history preserved).";
    } else {
        // Only hard delete if absolutely no historical records exist
        $del = $conn->prepare("DELETE FROM products WHERE id = ?");
        $del->bind_param("i", $id);
        $del->execute();
        $_SESSION['message'] = "Vehicle #$id has been permanently deleted.";
    }
}

header("Location: Product.php");
exit();