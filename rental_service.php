<?php
// rental_service.php - Core Rental Business Logic Engine for RentalKH

require_once __DIR__ . '/config.php';

class RentalService {
    /**
     * Get system setting value by key with optional fallback.
     */
    public static function getSetting(mysqli $conn, string $key, $default = null) {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return $row['setting_value'];
        }
        return $default;
    }

    /**
     * Automatically mark pending reservations as expired if past expiration window.
     */
    public static function expireStaleBookings(mysqli $conn): int {
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = 'Expired' 
            WHERE status = 'Pending' 
              AND expires_at IS NOT NULL 
              AND expires_at < NOW()
        ");
        $stmt->execute();
        return $stmt->affected_rows;
    }

    /**
     * Generate a unique, collision-free human-readable booking reference (e.g., RNT-20261016-A1B2).
     */
    public static function generateBookingRef(mysqli $conn): string {
        $prefix = 'RNT-' . date('Ymd') . '-';
        for ($i = 0; $i < 10; $i++) {
            $random = strtoupper(bin2hex(random_bytes(2)));
            $ref = $prefix . $random;
            $stmt = $conn->prepare("SELECT id FROM orders WHERE booking_ref = ?");
            $stmt->bind_param("s", $ref);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                return $ref;
            }
        }
        // Fallback with microtime if collision occurs repeatedly
        return $prefix . strtoupper(substr(uniqid(), -4));
    }

    /**
     * Calculate rental duration in days and hours.
     */
    public static function calculateDuration(string $startDate, string $startTime, string $endDate, string $endTime): array {
        $startTs = strtotime("$startDate $startTime");
        $endTs   = strtotime("$endDate $endTime");

        if (!$startTs || !$endTs || $endTs <= $startTs) {
            return ['valid' => false, 'days' => 0, 'hours' => 0, 'error' => 'End date/time must be after start date/time.'];
        }

        $diffSeconds = $endTs - $startTs;
        $totalHours = ceil($diffSeconds / 3600);
        $days = ceil($diffSeconds / 86400);

        if ($days < 1) {
            $days = 1;
        }

        return [
            'valid' => true,
            'days' => (int)$days,
            'total_hours' => (int)$totalHours,
            'start_datetime' => date('Y-m-d H:i:s', $startTs),
            'end_datetime' => date('Y-m-d H:i:s', $endTs)
        ];
    }

    /**
     * Check vehicle availability against maintenance, active bookings, and buffers.
     * Must be called within a transaction if holding lock.
     */
    public static function checkVehiclesAvailability(
        mysqli $conn, 
        array $productIds, 
        string $startDate, 
        string $startTime, 
        string $endDate, 
        string $endTime, 
        array $quantities,
        ?int $excludeOrderId = null
    ): array {
        self::expireStaleBookings($conn);

        $defaultBuffer = (int)self::getSetting($conn, 'default_buffer_hours', 2);
        $reqStartTs = strtotime("$startDate $startTime");
        $reqEndTs   = strtotime("$endDate $endTime");

        if (!$reqStartTs || !$reqEndTs || $reqEndTs <= $reqStartTs) {
            return ['available' => false, 'message' => 'Invalid rental date range.'];
        }

        // 1. Check each product exists, is not archived, and is not permanently inactive or under maintenance
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));
        
        $stmt = $conn->prepare("
            SELECT id, name, reg_number, status, condition_status, quantity, is_archived, buffer_hours 
            FROM products 
            WHERE id IN ($placeholders)
        ");
        $stmt->bind_param($types, ...$productIds);
        $stmt->execute();
        $res = $stmt->get_result();

        $vehicles = [];
        while ($row = $res->fetch_assoc()) {
            $vehicles[$row['id']] = $row;
        }

        foreach ($productIds as $pid) {
            if (!isset($vehicles[$pid])) {
                return ['available' => false, 'message' => "Vehicle (ID: $pid) not found."];
            }
            $v = $vehicles[$pid];
            if ($v['is_archived'] == 1 || $v['status'] === 'inactive') {
                return ['available' => false, 'message' => "Vehicle '{$v['name']}' ({$v['reg_number']}) is currently inactive."];
            }
            if ($v['status'] === 'maintenance') {
                return ['available' => false, 'message' => "Vehicle '{$v['name']}' ({$v['reg_number']}) is currently undergoing maintenance."];
            }
        }

        // 2. Check scheduled/in-progress maintenance overlapping requested dates
        $maintStmt = $conn->prepare("
            SELECT product_id, maintenance_type, start_date, end_date 
            FROM vehicle_maintenance 
            WHERE product_id IN ($placeholders)
              AND status IN ('scheduled', 'in_progress')
              AND NOT (end_date < ? OR start_date > ?)
        ");
        $maintTypes = $types . 'ss';
        $maintParams = array_merge($productIds, [$startDate, $endDate]);
        $maintStmt->bind_param($maintTypes, ...$maintParams);
        $maintStmt->execute();
        $maintRes = $maintStmt->get_result();

        if ($maintRes->num_rows > 0) {
            $m = $maintRes->fetch_assoc();
            $vName = $vehicles[$m['product_id']]['name'] ?? 'Selected vehicle';
            return [
                'available' => false, 
                'message' => "$vName has maintenance scheduled ({$m['maintenance_type']}) from {$m['start_date']} to {$m['end_date']}."
            ];
        }

        // 3. Check overlapping bookings (accounting for turnaround buffer)
        // Active statuses blocking inventory: 'Pending', 'Approved', 'Active'
        $excludeSql = $excludeOrderId ? " AND o.id != ?" : "";
        $overlapSql = "
            SELECT oi.product_id, SUM(oi.quantity) as booked_qty
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE oi.product_id IN ($placeholders)
              AND o.status IN ('Pending', 'Approved', 'Active')
              $excludeSql
              AND NOT (
                  TIMESTAMP(o.end_date, o.end_time) + INTERVAL COALESCE(p.buffer_hours, ?) HOUR <= ?
                  OR 
                  TIMESTAMP(o.start_date, o.start_time) - INTERVAL COALESCE(p.buffer_hours, ?) HOUR >= ?
              )
            GROUP BY oi.product_id
        ";

        $reqStartDt = date('Y-m-d H:i:s', $reqStartTs);
        $reqEndDt   = date('Y-m-d H:i:s', $reqEndTs);

        $checkParams = $productIds;
        $checkTypes = $types;

        if ($excludeOrderId) {
            $checkParams[] = $excludeOrderId;
            $checkTypes .= 'i';
        }
        $checkParams[] = $defaultBuffer;
        $checkParams[] = $reqStartDt;
        $checkParams[] = $defaultBuffer;
        $checkParams[] = $reqEndDt;
        $checkTypes .= 'isis';

        $overlapStmt = $conn->prepare($overlapSql);
        $overlapStmt->bind_param($checkTypes, ...$checkParams);
        $overlapStmt->execute();
        $overlapRes = $overlapStmt->get_result();

        $bookedCounts = [];
        while ($row = $overlapRes->fetch_assoc()) {
            $bookedCounts[$row['product_id']] = (int)$row['booked_qty'];
        }

        foreach ($productIds as $pid) {
            $reqQty = (int)($quantities[$pid] ?? 1);
            $totalQty = (int)($vehicles[$pid]['quantity'] ?? 1);
            $booked = $bookedCounts[$pid] ?? 0;

            if ($reqQty + $booked > $totalQty) {
                $vName = $vehicles[$pid]['name'];
                $reg = $vehicles[$pid]['reg_number'] ? " ({$vehicles[$pid]['reg_number']})" : "";
                return [
                    'available' => false,
                    'message' => "Vehicle '$vName'$reg is not available for the requested period (already reserved or turnaround buffer in effect)."
                ];
            }
        }

        return ['available' => true, 'vehicles' => $vehicles];
    }

    /**
     * Calculate rental pricing strictly on the server.
     */
    public static function calculatePricing(mysqli $conn, array $cart, string $startDate, string $startTime, string $endDate, string $endTime): array {
        $duration = self::calculateDuration($startDate, $startTime, $endDate, $endTime);
        if (!$duration['valid']) {
            return ['success' => false, 'message' => $duration['error']];
        }

        $minDays = (int)self::getSetting($conn, 'min_rental_days', 1);
        $days = max($minDays, $duration['days']);

        $productIds = array_keys($cart);
        if (empty($productIds)) {
            return ['success' => false, 'message' => 'Cart is empty.'];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));

        $stmt = $conn->prepare("SELECT id, name, reg_number, price, hourly_price FROM products WHERE id IN ($placeholders)");
        $stmt->bind_param($types, ...$productIds);
        $stmt->execute();
        $res = $stmt->get_result();

        $products = [];
        while ($row = $res->fetch_assoc()) {
            $products[$row['id']] = $row;
        }

        $items = [];
        $subtotal = 0.0;

        foreach ($cart as $pid => $qty) {
            $pid = (int)$pid;
            $qty = (int)$qty;
            if ($qty <= 0 || $qty > 100) continue;
            if (!isset($products[$pid])) continue;

            $p = $products[$pid];
            $dailyRate = (float)$p['price'];
            $itemSubtotal = $dailyRate * $qty * $days;
            $subtotal += $itemSubtotal;

            $items[] = [
                'product_id' => $pid,
                'name' => $p['name'],
                'reg_number' => $p['reg_number'],
                'quantity' => $qty,
                'daily_rate' => $dailyRate,
                'days' => $days,
                'subtotal' => $itemSubtotal
            ];
        }

        if (empty($items)) {
            return ['success' => false, 'message' => 'No valid vehicles in cart.'];
        }

        $depositFixed = (float)self::getSetting($conn, 'security_deposit_fixed', 0.00);
        $discountAmount = 0.00;
        $total = $subtotal - $discountAmount + $depositFixed;

        return [
            'success' => true,
            'days' => $days,
            'total_hours' => $duration['total_hours'],
            'items' => $items,
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'deposit_amount' => round($depositFixed, 2),
            'total_amount' => round($total, 2)
        ];
    }

    /**
     * Create a booking with atomic transaction and row locks.
     */
    public static function createBooking(
        mysqli $conn,
        int $userId,
        array $cart,
        string $startDate,
        string $startTime,
        string $endDate,
        string $endTime,
        ?string $shippingAddress = null
    ): array {
        // Enforce basic user check
        $uStmt = $conn->prepare("SELECT id, is_active FROM users WHERE id = ?");
        $uStmt->bind_param("i", $userId);
        $uStmt->execute();
        $user = $uStmt->get_result()->fetch_assoc();
        if (!$user || $user['is_active'] == 0) {
            return ['success' => false, 'message' => 'User account is invalid or disabled.'];
        }

        // Validate time format
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) $startTime = '09:00:00';
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime))   $endTime   = '18:00:00';
        if (strlen($startTime) === 5) $startTime .= ':00';
        if (strlen($endTime) === 5)   $endTime   .= ':00';

        // Check start date not in past (with grace period for timezone)
        $today = date('Y-m-d');
        if ($startDate < $today) {
            return ['success' => false, 'message' => 'Rental start date cannot be in the past.'];
        }

        $pricing = self::calculatePricing($conn, $cart, $startDate, $startTime, $endDate, $endTime);
        if (!$pricing['success']) {
            return ['success' => false, 'message' => $pricing['message']];
        }

        $productIds = array_keys($cart);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));

        // Use transaction with serialized lock to prevent concurrency race conditions
        $conn->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
        $conn->begin_transaction();

        try {
            // Lock product rows for update
            $lockStmt = $conn->prepare("SELECT id, quantity FROM products WHERE id IN ($placeholders) FOR UPDATE");
            $lockStmt->bind_param($types, ...$productIds);
            $lockStmt->execute();
            $lockStmt->get_result();

            // Check availability under transaction lock
            $avail = self::checkVehiclesAvailability(
                $conn, 
                $productIds, 
                $startDate, 
                $startTime, 
                $endDate, 
                $endTime, 
                $cart
            );

            if (!$avail['available']) {
                $conn->rollback();
                return ['success' => false, 'message' => $avail['message']];
            }

            // Expiry time for reservation
            $expiryHours = (int)self::getSetting($conn, 'reservation_expiry_hours', 24);
            $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiryHours hours"));
            $bookingRef = self::generateBookingRef($conn);

            // Insert into orders
            $insOrder = $conn->prepare("
                INSERT INTO orders (
                    booking_ref, user_id, total_amount, subtotal, discount_amount, 
                    deposit_amount, status, start_date, start_time, end_date, 
                    end_time, shipping_address, expires_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?, ?, ?)
            ");
            $insOrder->bind_param(
                "siddddssssss",
                $bookingRef,
                $userId,
                $pricing['total_amount'],
                $pricing['subtotal'],
                $pricing['discount_amount'],
                $pricing['deposit_amount'],
                $startDate,
                $startTime,
                $endDate,
                $endTime,
                $shippingAddress,
                $expiresAt
            );
            $insOrder->execute();
            $orderId = $conn->insert_id;

            // Insert order items
            $insItem = $conn->prepare("
                INSERT INTO order_items (
                    order_id, product_id, quantity, rental_days, daily_rate, price, subtotal
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($pricing['items'] as $item) {
                $insItem->bind_param(
                    "iiiiddd",
                    $orderId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['days'],
                    $item['daily_rate'],
                    $item['daily_rate'],
                    $item['subtotal']
                );
                $insItem->execute();
            }

            $conn->commit();

            return [
                'success' => true,
                'order_id' => $orderId,
                'booking_ref' => $bookingRef,
                'pricing' => $pricing,
                'expires_at' => $expiresAt
            ];
        } catch (Exception $e) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Booking failed: ' . $e->getMessage()];
        }
    }

    /**
     * Cancel booking safely with reason and audit timestamp.
     */
    public static function cancelBooking(mysqli $conn, int $orderId, int $userId, string $reason, bool $isAdmin = false): array {
        $stmt = $conn->prepare("
            SELECT id, user_id, status, start_date, start_time, booking_ref 
            FROM orders 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();

        if (!$order) {
            return ['success' => false, 'message' => 'Order not found.'];
        }

        if (!$isAdmin && (int)$order['user_id'] !== $userId) {
            return ['success' => false, 'message' => 'Unauthorized action.'];
        }

        if (in_array($order['status'], ['Cancelled', 'Declined', 'Completed', 'Expired'])) {
            return ['success' => false, 'message' => "Order is already {$order['status']}."];
        }

        // Check cancellation cutoff for customers on Approved orders
        if (!$isAdmin && $order['status'] === 'Approved') {
            $cutoffHours = (int)self::getSetting($conn, 'cancellation_cutoff_hours', 24);
            $startTs = strtotime("{$order['start_date']} {$order['start_time']}");
            if ($startTs - time() < ($cutoffHours * 3600)) {
                return [
                    'success' => false, 
                    'message' => "Bookings cannot be cancelled less than $cutoffHours hours before pickup. Please contact customer support."
                ];
            }
        }

        $now = date('Y-m-d H:i:s');
        $upd = $conn->prepare("
            UPDATE orders 
            SET status = 'Cancelled', cancellation_reason = ?, cancelled_at = ? 
            WHERE id = ?
        ");
        $upd->bind_param("ssi", $reason, $now, $orderId);
        if ($upd->execute()) {
            return ['success' => true, 'message' => "Booking #{$order['booking_ref']} cancelled successfully."];
        }

        return ['success' => false, 'message' => 'Failed to cancel booking.'];
    }

    /**
     * Update booking status by admin (e.g. Approve, Active, Complete, Decline).
     */
    public static function updateBookingStatus(mysqli $conn, int $orderId, string $newStatus): array {
        $allowed = ['Pending', 'Approved', 'Active', 'Completed', 'Cancelled', 'Declined', 'Expired'];
        if (!in_array($newStatus, $allowed)) {
            return ['success' => false, 'message' => 'Invalid status.'];
        }

        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $newStatus, $orderId);
        if ($stmt->execute()) {
            return ['success' => true, 'message' => "Booking status updated to $newStatus."];
        }
        return ['success' => false, 'message' => 'Failed to update status.'];
    }
}
