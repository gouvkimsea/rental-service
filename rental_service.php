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

        return self::cancelBookingWithFinancials($conn, $orderId, $userId, $reason, $isAdmin);
    }

    /**
     * Cancel booking with complete financial policy, deposit refund, and fee calculations.
     */
    public static function cancelBookingWithFinancials(
        mysqli $conn, 
        int $orderId, 
        int $userId, 
        string $reason, 
        bool $isAdmin = false, 
        ?float $overrideFee = null
    ): array {
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();

        if (!$order) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }

        if (!$isAdmin && (int)$order['user_id'] !== $userId) {
            return ['success' => false, 'message' => 'Unauthorized action.'];
        }

        if (in_array($order['status'], ['Cancelled', 'Declined', 'Completed', 'Expired'])) {
            return ['success' => false, 'message' => "Booking is already {$order['status']}."];
        }

        if ($order['status'] === 'Active') {
            return ['success' => false, 'message' => "Cannot cancel an Active rental. Please use Return & Settlement workflow."];
        }

        $fin = self::recalculateOrderFinancials($conn, $orderId);

        // Calculate cancellation fee
        $fee = 0.00;
        if ($overrideFee !== null && $isAdmin) {
            $fee = max(0.00, round($overrideFee, 2));
        } else {
            $cutoffHours = (int)self::getSetting($conn, 'cancellation_cutoff_hours', 24);
            $fixedFee = (float)self::getSetting($conn, 'cancellation_fee_fixed', 10.00);
            $startTs = strtotime("{$order['start_date']} {$order['start_time']}");

            if ($startTs - time() >= ($cutoffHours * 3600)) {
                // Cancelled well in advance: minimal or zero fee
                $fee = min($fin['net_rental_paid'], $fixedFee);
            } else {
                // Cancelled late (within cutoff): charge fixed fee
                $fee = min($fin['net_rental_paid'], $fixedFee);
            }
        }

        // Rental refund amount = paid_rental - fee
        $rentalRefund = max(0.00, round($fin['net_rental_paid'] - $fee, 2));

        // Process rental refund entry if refundable amount exists
        if ($rentalRefund > 0) {
            $refCode = 'REF-CAN-' . strtoupper(substr(uniqid(), -6));
            $rStmt = $conn->prepare("
                INSERT INTO payments (order_id, amount, currency, payment_type, payment_method, payment_reference, status, staff_id, notes, verified_at)
                VALUES (?, ?, 'USD', 'refund', 'other', ?, 'verified', ?, ?, NOW())
            ");
            $notes = "Automated cancellation refund ($reason)";
            $rStmt->bind_param("idsis", $orderId, $rentalRefund, $refCode, $userId, $notes);
            $rStmt->execute();
        }

        // Security deposit is 100% refundable upon cancellation
        if ($fin['deposit_held'] > 0) {
            $depRefCode = 'DEP-CAN-' . strtoupper(substr(uniqid(), -6));
            $dStmt = $conn->prepare("
                INSERT INTO payments (order_id, amount, currency, payment_type, payment_method, payment_reference, status, staff_id, notes, verified_at)
                VALUES (?, ?, 'USD', 'deposit_refund', 'other', ?, 'verified', ?, ?, NOW())
            ");
            $dNotes = "Security deposit full refund on booking cancellation";
            $dStmt->bind_param("idsis", $orderId, $fin['deposit_held'], $depRefCode, $userId, $dNotes);
            $dStmt->execute();
        }

        $now = date('Y-m-d H:i:s');
        $upd = $conn->prepare("
            UPDATE orders 
            SET status = 'Cancelled', cancellation_reason = ?, cancellation_fee = ?, 
                cancellation_refund = ?, cancelled_at = ? 
            WHERE id = ?
        ");
        $upd->bind_param("sddsi", $reason, $fee, $rentalRefund, $now, $orderId);
        $upd->execute();

        self::recalculateOrderFinancials($conn, $orderId, $userId);
        self::logFinancialAudit($conn, $orderId, $userId, 'CANCELLATION_REFUND', $rentalRefund, "Booking cancelled ($reason). Fee charged: \$$fee, Refunded: \$$rentalRefund.");

        return [
            'success' => true,
            'cancellation_fee' => $fee,
            'refund_amount' => $rentalRefund,
            'deposit_refunded' => $fin['deposit_held'],
            'message' => "Booking cancelled. Fee: \$$fee, Rental refund: \$$rentalRefund, Deposit refunded: \${$fin['deposit_held']}."
        ];
    }

    /**
     * Log structured financial and operational audit trail entry.
     */
    public static function logFinancialAudit(mysqli $conn, ?int $orderId, int $actorId, string $actionType, ?float $amount, string $reason, array $metadata = []): void {
        $metaJson = !empty($metadata) ? json_encode($metadata) : null;
        $amt = $amount !== null ? round($amount, 2) : 0.00;
        $stmt = $conn->prepare("
            INSERT INTO financial_audit_logs (order_id, actor_id, action_type, amount, reason, metadata) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iisdss", $orderId, $actorId, $actionType, $amt, $reason, $metaJson);
        $stmt->execute();
    }

    /**
     * Verify customer ID and driver license documents.
     */
    public static function verifyCustomerDocuments(mysqli $conn, int $orderId, int $staffId, string $notes = ''): array {
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            UPDATE orders 
            SET documents_verified = 1, documents_verified_by = ?, documents_verified_at = ? 
            WHERE id = ?
        ");
        $stmt->bind_param("isi", $staffId, $now, $orderId);
        if ($stmt->execute()) {
            self::logFinancialAudit($conn, $orderId, $staffId, 'DOCUMENTS_VERIFIED', 0.00, $notes ?: 'Customer driver license and identity documents verified.');
            return ['success' => true, 'message' => 'Customer documents verified successfully.'];
        }
        return ['success' => false, 'message' => 'Failed to verify documents.'];
    }

    /**
     * Record a payment transaction in the ledger.
     */
    public static function recordPayment(
        mysqli $conn, 
        int $orderId, 
        float $amount, 
        string $paymentType, 
        string $paymentMethod, 
        string $paymentRef, 
        int $staffId, 
        string $notes = '', 
        bool $autoVerify = true
    ): array {
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Payment amount must be greater than zero.'];
        }

        $validTypes = ['rental_payment', 'security_deposit', 'additional_charge', 'damage_fee', 'late_fee', 'refund', 'deposit_refund'];
        if (!in_array($paymentType, $validTypes)) {
            return ['success' => false, 'message' => 'Invalid payment type.'];
        }

        $validMethods = ['cash', 'bank_transfer', 'credit_card', 'aba_pay', 'other'];
        if (!in_array($paymentMethod, $validMethods)) {
            return ['success' => false, 'message' => 'Invalid payment method.'];
        }

        // Prevent duplicate transaction references
        $chk = $conn->prepare("SELECT id FROM payments WHERE payment_reference = ?");
        $chk->bind_param("s", $paymentRef);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => "Payment reference '$paymentRef' already exists. Duplicate transactions are prevented."];
        }

        $status = $autoVerify ? 'verified' : 'pending';
        $verifiedAt = $autoVerify ? date('Y-m-d H:i:s') : null;

        $stmt = $conn->prepare("
            INSERT INTO payments (order_id, amount, currency, payment_type, payment_method, payment_reference, status, staff_id, notes, verified_at)
            VALUES (?, ?, 'USD', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("idssssiss", $orderId, $amount, $paymentType, $paymentMethod, $paymentRef, $status, $staffId, $notes, $verifiedAt);

        if ($stmt->execute()) {
            $payId = $conn->insert_id;
            if ($autoVerify) {
                self::recalculateOrderFinancials($conn, $orderId, $staffId);
            }
            self::logFinancialAudit($conn, $orderId, $staffId, $autoVerify ? 'PAYMENT_VERIFIED' : 'PAYMENT_RECORDED', $amount, "Payment recorded ($paymentType via $paymentMethod, Ref: $paymentRef)", ['payment_id' => $payId]);
            return ['success' => true, 'payment_id' => $payId, 'message' => "Payment of \$$amount recorded ($status)."];
        }

        return ['success' => false, 'message' => 'Database error: ' . $stmt->error];
    }

    /**
     * Manually verify a pending payment transaction.
     */
    public static function verifyPayment(mysqli $conn, int $paymentId, int $staffId): array {
        $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->bind_param("i", $paymentId);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();

        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found.'];
        }
        if ($payment['status'] === 'verified') {
            return ['success' => false, 'message' => 'Payment is already verified.'];
        }

        $now = date('Y-m-d H:i:s');
        $upd = $conn->prepare("UPDATE payments SET status = 'verified', verified_at = ?, staff_id = ? WHERE id = ?");
        $upd->bind_param("sii", $now, $staffId, $paymentId);
        if ($upd->execute()) {
            self::recalculateOrderFinancials($conn, (int)$payment['order_id'], $staffId);
            self::logFinancialAudit($conn, (int)$payment['order_id'], $staffId, 'PAYMENT_VERIFIED', (float)$payment['amount'], "Payment #$paymentId manually verified by staff.", ['payment_id' => $paymentId]);
            return ['success' => true, 'message' => "Payment #{$payment['payment_reference']} verified successfully."];
        }
        return ['success' => false, 'message' => 'Failed to verify payment.'];
    }

    /**
     * Recalculate order financials, payments, deposit status, and outstanding balances.
     */
    public static function recalculateOrderFinancials(mysqli $conn, int $orderId, ?int $staffId = null): array {
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if (!$order) {
            return ['success' => false, 'message' => 'Order not found.'];
        }

        $subtotal = (float)$order['subtotal'];
        $discount = (float)$order['discount_amount'];
        $lateFee  = (float)$order['late_fee'];
        $fuelFee  = (float)$order['fuel_fee'];
        $damageFee = (float)$order['damage_fee'];
        $depositRequired = (float)$order['deposit_amount'];
        $depositDeducted = (float)$order['deposit_deducted'];

        // Total rental & operational charges
        $grossCharges = round($subtotal - $discount + $lateFee + $fuelFee + $damageFee, 2);

        // Sum verified rental & extra fee payments
        $pStmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total_paid
            FROM payments 
            WHERE order_id = ? AND status = 'verified' 
              AND payment_type IN ('rental_payment', 'additional_charge', 'damage_fee', 'late_fee')
        ");
        $pStmt->bind_param("i", $orderId);
        $pStmt->execute();
        $totalPaid = (float)$pStmt->get_result()->fetch_assoc()['total_paid'];

        // Sum verified rental refunds
        $rStmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total_refunded
            FROM payments 
            WHERE order_id = ? AND status = 'verified' AND payment_type = 'refund'
        ");
        $rStmt->bind_param("i", $orderId);
        $rStmt->execute();
        $totalRefunded = (float)$rStmt->get_result()->fetch_assoc()['total_refunded'];

        $netRentalPaid = round($totalPaid - $totalRefunded, 2);

        // Deposit collection sum
        $dStmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total_deposit
            FROM payments 
            WHERE order_id = ? AND status = 'verified' AND payment_type = 'security_deposit'
        ");
        $dStmt->bind_param("i", $orderId);
        $dStmt->execute();
        $depositCollected = (float)$dStmt->get_result()->fetch_assoc()['total_deposit'];

        // Deposit refunds sum
        $drStmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total_deposit_refunded
            FROM payments 
            WHERE order_id = ? AND status = 'verified' AND payment_type = 'deposit_refund'
        ");
        $drStmt->bind_param("i", $orderId);
        $drStmt->execute();
        $depositRefunded = (float)$drStmt->get_result()->fetch_assoc()['total_deposit_refunded'];

        // Deposit held = collected - deducted - refunded
        $depositHeld = max(0.00, round($depositCollected - $depositDeducted - $depositRefunded, 2));

        // Determine deposit status
        if ($depositRequired <= 0 && $depositCollected <= 0) {
            $depositStatus = 'not_required';
        } elseif ($depositCollected <= 0) {
            $depositStatus = 'pending';
        } elseif ($depositHeld <= 0.001) {
            if ($depositDeducted >= $depositCollected) {
                $depositStatus = 'forfeited';
            } else {
                $depositStatus = 'fully_refunded';
            }
        } elseif ($depositRefunded > 0 || $depositDeducted > 0) {
            $depositStatus = 'partially_refunded';
        } else {
            $depositStatus = 'held';
        }

        // Determine rental payment status
        $effectivePaid = $netRentalPaid + $depositDeducted;
        if ($effectivePaid >= $grossCharges && $grossCharges > 0) {
            $paymentStatus = 'paid';
        } elseif ($effectivePaid > 0) {
            $paymentStatus = 'partially_paid';
        } else {
            $paymentStatus = 'unpaid';
        }

        $outstandingBalance = max(0.00, round($grossCharges - $effectivePaid, 2));

        // Update order row
        $upd = $conn->prepare("
            UPDATE orders 
            SET total_amount = ?, paid_amount = ?, payment_status = ?, 
                deposit_status = ?, deposit_refunded = ? 
            WHERE id = ?
        ");
        $upd->bind_param("ddssdi", $grossCharges, $netRentalPaid, $paymentStatus, $depositStatus, $depositRefunded, $orderId);
        $upd->execute();

        return [
            'success' => true,
            'gross_charges' => $grossCharges,
            'net_rental_paid' => $netRentalPaid,
            'effective_paid' => $effectivePaid,
            'outstanding_balance' => $outstandingBalance,
            'deposit_collected' => $depositCollected,
            'deposit_deducted' => $depositDeducted,
            'deposit_refunded' => $depositRefunded,
            'deposit_held' => $depositHeld,
            'deposit_status' => $depositStatus,
            'payment_status' => $paymentStatus
        ];
    }

    /**
     * Authorized deduction from held security deposit (damage, late fee, unpaid charges).
     */
    public static function deductDeposit(mysqli $conn, int $orderId, float $amount, string $reason, int $staffId): array {
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Deduction amount must be greater than zero.'];
        }

        $fin = self::recalculateOrderFinancials($conn, $orderId);
        if ($amount > $fin['deposit_held']) {
            return ['success' => false, 'message' => "Cannot deduct \$$amount. Maximum available held deposit is \${$fin['deposit_held']}."];
        }

        $upd = $conn->prepare("UPDATE orders SET deposit_deducted = deposit_deducted + ? WHERE id = ?");
        $upd->bind_param("di", $amount, $orderId);
        if ($upd->execute()) {
            self::recalculateOrderFinancials($conn, $orderId, $staffId);
            self::logFinancialAudit($conn, $orderId, $staffId, 'DEPOSIT_DEDUCTION', $amount, $reason);
            return ['success' => true, 'message' => "Successfully deducted \$$amount from security deposit: $reason"];
        }

        return ['success' => false, 'message' => 'Failed to deduct deposit.'];
    }

    /**
     * Refund security deposit to customer.
     */
    public static function refundDeposit(
        mysqli $conn, 
        int $orderId, 
        float $amount, 
        string $paymentMethod, 
        string $paymentRef, 
        int $staffId, 
        string $notes = ''
    ): array {
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Refund amount must be greater than zero.'];
        }

        $fin = self::recalculateOrderFinancials($conn, $orderId);
        if ($amount > $fin['deposit_held']) {
            return ['success' => false, 'message' => "Cannot refund \$$amount. Maximum refundable held deposit is \${$fin['deposit_held']}."];
        }

        // Prevent duplicate reference
        $chk = $conn->prepare("SELECT id FROM payments WHERE payment_reference = ?");
        $chk->bind_param("s", $paymentRef);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => "Payment/Refund reference '$paymentRef' already exists."];
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            INSERT INTO payments (order_id, amount, currency, payment_type, payment_method, payment_reference, status, staff_id, notes, verified_at)
            VALUES (?, ?, 'USD', 'deposit_refund', ?, ?, 'verified', ?, ?, ?)
        ");
        $stmt->bind_param("idssiss", $orderId, $amount, $paymentMethod, $paymentRef, $staffId, $notes, $now);

        if ($stmt->execute()) {
            self::recalculateOrderFinancials($conn, $orderId, $staffId);
            self::logFinancialAudit($conn, $orderId, $staffId, 'DEPOSIT_REFUND', $amount, "Security deposit refund of \$$amount processed via $paymentMethod (Ref: $paymentRef)");
            return ['success' => true, 'message' => "Successfully refunded \$$amount of security deposit to customer."];
        }

        return ['success' => false, 'message' => 'Failed to record deposit refund: ' . $stmt->error];
    }

    /**
     * Record vehicle handover (Customer pickup).
     */
    public static function recordHandover(
        mysqli $conn, 
        int $orderId, 
        int $staffId, 
        int $odometer, 
        string $fuelLevel, 
        string $notes = ''
    ): array {
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();

        if (!$order) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }

        // Explicit state transition check
        if ($order['status'] !== 'Approved') {
            return ['success' => false, 'message' => "Cannot handover booking in '{$order['status']}' state. Booking must be Approved first."];
        }

        // Verify document requirement
        $reqDocs = (int)self::getSetting($conn, 'require_documents_for_handover', 1);
        if ($reqDocs && empty($order['documents_verified'])) {
            return ['success' => false, 'message' => 'Customer driver license and ID documents must be verified before handing over the vehicle.'];
        }

        // Verify deposit requirement
        $reqDeposit = (int)self::getSetting($conn, 'require_deposit_for_handover', 1);
        if ($reqDeposit && (float)$order['deposit_amount'] > 0 && $order['deposit_status'] !== 'held') {
            return ['success' => false, 'message' => 'Security deposit must be collected and verified before handing over the vehicle.'];
        }

        // Fetch vehicles and verify availability
        $vStmt = $conn->prepare("
            SELECT oi.product_id, p.name, p.status, p.reg_number 
            FROM order_items oi 
            JOIN products p ON oi.product_id = p.id 
            WHERE oi.order_id = ?
        ");
        $vStmt->bind_param("i", $orderId);
        $vStmt->execute();
        $vRes = $vStmt->get_result();

        $productIds = [];
        while ($v = $vRes->fetch_assoc()) {
            if ($v['status'] === 'maintenance' || $v['status'] === 'inactive') {
                return ['success' => false, 'message' => "Vehicle '{$v['name']}' ({$v['reg_number']}) is currently {$v['status']} and cannot be handed over."];
            }
            $productIds[] = (int)$v['product_id'];
        }

        $now = date('Y-m-d H:i:s');
        $upd = $conn->prepare("
            UPDATE orders 
            SET status = 'Active', handover_at = ?, handover_by = ?, 
                handover_odometer = ?, handover_fuel_level = ?, handover_notes = ? 
            WHERE id = ?
        ");
        $upd->bind_param("siissi", $now, $staffId, $odometer, $fuelLevel, $notes, $orderId);

        if ($upd->execute()) {
            // Mark vehicles as 'rented' and update mileage
            if (!empty($productIds)) {
                $inList = implode(',', $productIds);
                $conn->query("UPDATE products SET status = 'rented', mileage = $odometer WHERE id IN ($inList)");
            }
            self::logFinancialAudit($conn, $orderId, $staffId, 'VEHICLE_HANDOVER', 0.00, "Vehicle handed over to customer (Odo: $odometer km, Fuel: $fuelLevel). Rental is now Active.");
            return ['success' => true, 'message' => "Vehicle handover recorded. Rental #{$order['booking_ref']} is now Active."];
        }

        return ['success' => false, 'message' => 'Failed to record handover.'];
    }

    /**
     * Record vehicle return, condition inspection, late fees, fuel fees, and damage assessment.
     */
    public static function recordReturn(
        mysqli $conn, 
        int $orderId, 
        int $staffId, 
        int $returnOdometer, 
        string $returnFuelLevel, 
        string $inspectionNotes = '', 
        float $damageFee = 0.00, 
        string $damageDesc = ''
    ): array {
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();

        if (!$order) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }

        // Explicit state check: must be 'Active'
        if ($order['status'] !== 'Active') {
            return ['success' => false, 'message' => "Cannot return booking in '{$order['status']}' state. Booking must be Active."];
        }

        $handoverOdo = (int)($order['handover_odometer'] ?? 0);
        if ($returnOdometer < $handoverOdo) {
            return ['success' => false, 'message' => "Return odometer ($returnOdometer km) cannot be less than handover odometer ($handoverOdo km)."];
        }

        $now = date('Y-m-d H:i:s');
        $scheduledReturnTs = strtotime("{$order['end_date']} {$order['end_time']}");
        $actualReturnTs = time();

        // 1. Calculate Late Return Fee according to documented policy
        $graceMinutes = (int)self::getSetting($conn, 'late_grace_period_minutes', 30);
        $lateFeePerHour = (float)self::getSetting($conn, 'late_fee_per_hour', 10.00);
        $lateHours = 0;
        $lateFee = 0.00;

        if ($actualReturnTs > ($scheduledReturnTs + ($graceMinutes * 60))) {
            $delaySeconds = $actualReturnTs - $scheduledReturnTs;
            $lateHours = (int)ceil($delaySeconds / 3600);
            $lateFee = round($lateHours * $lateFeePerHour, 2);
        }

        // 2. Calculate Fuel Replacement Fee according to documented policy
        $fuelScale = ['Empty' => 0, '1/4' => 1, '1/2' => 2, '3/4' => 3, 'Full' => 4];
        $handoverFuel = $order['handover_fuel_level'] ?? 'Full';
        $hVal = $fuelScale[$handoverFuel] ?? 4;
        $rVal = $fuelScale[$returnFuelLevel] ?? $hVal;
        $missingQuarters = max(0, $hVal - $rVal);
        $fuelFeePerQuarter = (float)self::getSetting($conn, 'fuel_fee_per_quarter', 15.00);
        $fuelFee = round($missingQuarters * $fuelFeePerQuarter, 2);

        $damageFee = max(0.00, round($damageFee, 2));

        $combinedNotes = trim($inspectionNotes);
        if ($damageFee > 0 && $damageDesc) {
            $combinedNotes .= " [Damage Claim: \$$damageFee - $damageDesc]";
        }

        $upd = $conn->prepare("
            UPDATE orders 
            SET return_at = ?, return_by = ?, return_odometer = ?, return_fuel_level = ?, 
                return_notes = ?, late_hours = ?, late_fee = ?, fuel_fee = ?, damage_fee = ? 
            WHERE id = ?
        ");
        $upd->bind_param("siissidddi", $now, $staffId, $returnOdometer, $returnFuelLevel, $combinedNotes, $lateHours, $lateFee, $fuelFee, $damageFee, $orderId);

        if ($upd->execute()) {
            // Update vehicle status & mileage
            $vStmt = $conn->prepare("SELECT product_id FROM order_items WHERE order_id = ?");
            $vStmt->bind_param("i", $orderId);
            $vStmt->execute();
            $vRes = $vStmt->get_result();
            $pIds = [];
            while ($v = $vRes->fetch_assoc()) {
                $pIds[] = (int)$v['product_id'];
            }

            if (!empty($pIds)) {
                $inList = implode(',', $pIds);
                if ($damageFee > 0) {
                    $conn->query("UPDATE products SET status = 'maintenance', condition_status = 'needs_service', mileage = $returnOdometer WHERE id IN ($inList)");
                    foreach ($pIds as $pId) {
                        $conn->query("
                            INSERT INTO vehicle_maintenance (product_id, maintenance_type, description, cost, start_date, status)
                            VALUES ($pId, 'Post-Rental Damage Repair', 'Automated damage inspection ticket from booking #{$order['booking_ref']}: $damageDesc', $damageFee, CURDATE(), 'scheduled')
                        ");
                    }
                } else {
                    $conn->query("UPDATE products SET status = 'available', mileage = $returnOdometer WHERE id IN ($inList)");
                }
            }

            self::recalculateOrderFinancials($conn, $orderId, $staffId);
            self::logFinancialAudit($conn, $orderId, $staffId, 'VEHICLE_RETURNED', $lateFee + $fuelFee + $damageFee, "Vehicle returned (Odo: $returnOdometer km, Fuel: $returnFuelLevel, Late Fee: \$$lateFee, Fuel Fee: \$$fuelFee, Damage Fee: \$$damageFee).");

            return [
                'success' => true,
                'late_hours' => $lateHours,
                'late_fee' => $lateFee,
                'fuel_fee' => $fuelFee,
                'damage_fee' => $damageFee,
                'message' => "Vehicle return recorded. Additional charges assessed: Late: \$$lateFee, Fuel: \$$fuelFee, Damage: \$$damageFee."
            ];
        }

        return ['success' => false, 'message' => 'Failed to record return.'];
    }

    /**
     * Settle final balance, process deposit deduction/refund, and complete rental.
     */
    public static function completeRental(
        mysqli $conn, 
        int $orderId, 
        int $staffId, 
        string $depositAction = 'refund', 
        string $depositRefundMethod = 'cash', 
        string $depositRefundRef = '', 
        string $notes = ''
    ): array {
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();

        if (!$order) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }

        if (empty($order['return_at'])) {
            return ['success' => false, 'message' => 'Vehicle return inspection must be recorded before completing the rental.'];
        }

        if ($order['status'] === 'Completed') {
            return ['success' => false, 'message' => 'Rental is already completed.'];
        }

        $fin = self::recalculateOrderFinancials($conn, $orderId, $staffId);

        // Deduct extra charges from held deposit if requested
        if ($fin['outstanding_balance'] > 0 && $fin['deposit_held'] > 0) {
            $deductAmount = min($fin['outstanding_balance'], $fin['deposit_held']);
            self::deductDeposit($conn, $orderId, $deductAmount, "Applied held deposit toward outstanding charges.", $staffId);
            $fin = self::recalculateOrderFinancials($conn, $orderId, $staffId);
        }

        // Require remaining balance to be zero
        if ($fin['outstanding_balance'] > 0) {
            return [
                'success' => false, 
                'message' => "Cannot complete rental. Customer has an outstanding balance of \${$fin['outstanding_balance']}. Please collect payment first."
            ];
        }

        // Refund any remaining held deposit
        if ($fin['deposit_held'] > 0 && $depositAction === 'refund') {
            $ref = $depositRefundRef ?: 'DEP-REF-' . strtoupper(substr(uniqid(), -6));
            $refRes = self::refundDeposit($conn, $orderId, $fin['deposit_held'], $depositRefundMethod, $ref, $staffId, "Deposit refunded upon completion.");
            if (!$refRes['success']) {
                return ['success' => false, 'message' => "Failed to refund deposit: " . $refRes['message']];
            }
        }

        $upd = $conn->prepare("UPDATE orders SET status = 'Completed' WHERE id = ?");
        $upd->bind_param("i", $orderId);
        if ($upd->execute()) {
            self::recalculateOrderFinancials($conn, $orderId, $staffId);
            self::logFinancialAudit($conn, $orderId, $staffId, 'RENTAL_COMPLETED', 0.00, $notes ?: "Rental #{$order['booking_ref']} completed and finalized.");
            return ['success' => true, 'message' => "Rental #{$order['booking_ref']} completed successfully."];
        }

        return ['success' => false, 'message' => 'Failed to complete rental.'];
    }

    /**
     * Update booking status by admin.
     */
    public static function updateBookingStatus(mysqli $conn, int $orderId, string $newStatus, int $staffId = 0): array {
        $allowed = ['Pending', 'Approved', 'Active', 'Completed', 'Cancelled', 'Declined', 'Expired'];
        if (!in_array($newStatus, $allowed)) {
            return ['success' => false, 'message' => 'Invalid status.'];
        }

        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $newStatus, $orderId);
        if ($stmt->execute()) {
            self::recalculateOrderFinancials($conn, $orderId, $staffId);
            self::logFinancialAudit($conn, $orderId, $staffId, 'STATUS_TRANSITION', 0.00, "Booking status updated to $newStatus.");
            return ['success' => true, 'message' => "Booking status updated to $newStatus."];
        }
        return ['success' => false, 'message' => 'Failed to update status.'];
    }

    /**
     * Generate comprehensive financial and operational report.
     * Strictly separates deposits from earned revenue and avoids double counting.
     */
    public static function getFinancialReport(mysqli $conn, array $filters = []): array {
        self::expireStaleBookings($conn);

        // Date range filtering
        $preset = $filters['preset'] ?? '30d';
        $today = date('Y-m-d');

        if ($preset === '7d') {
            $startDate = date('Y-m-d', strtotime('-7 days'));
            $endDate = $today;
        } elseif ($preset === 'month') {
            $startDate = date('Y-m-01');
            $endDate = $today;
        } elseif ($preset === 'year') {
            $startDate = date('Y-01-01');
            $endDate = $today;
        } elseif ($preset === 'all') {
            $startDate = '2000-01-01';
            $endDate = date('Y-m-d', strtotime('+1 year'));
        } elseif (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $startDate = $filters['start_date'];
            $endDate = $filters['end_date'];
            $preset = 'custom';
        } else {
            // Default: 30 days
            $preset = '30d';
            $startDate = date('Y-m-d', strtotime('-30 days'));
            $endDate = $today;
        }

        $startDateTime = "$startDate 00:00:00";
        $endDateTime = "$endDate 23:59:59";

        // 1. PAYMENT INFLOWS & REVENUE (Verified payments within date range)
        $pStmt = $conn->prepare("
            SELECT 
                payment_type,
                payment_method,
                status,
                SUM(amount) as total_amt,
                COUNT(*) as tx_count
            FROM payments
            WHERE (created_at BETWEEN ? AND ? OR verified_at BETWEEN ? AND ?)
            GROUP BY payment_type, payment_method, status
        ");
        $pStmt->bind_param("ssss", $startDateTime, $endDateTime, $startDateTime, $endDateTime);
        $pStmt->execute();
        $pRes = $pStmt->get_result();

        $verifiedRentalCollected = 0.00;
        $verifiedDepositsCollected = 0.00;
        $rentalRefunds = 0.00;
        $depositRefunds = 0.00;
        $pendingPaymentsSum = 0.00;
        $pendingPaymentsCount = 0;
        $methodBreakdown = [];

        while ($p = $pRes->fetch_assoc()) {
            $amt = (float)$p['total_amt'];
            $cnt = (int)$p['tx_count'];
            $type = $p['payment_type'];
            $status = $p['status'];
            $method = $p['payment_method'];

            if ($status === 'pending') {
                $pendingPaymentsSum += $amt;
                $pendingPaymentsCount += $cnt;
            } elseif ($status === 'verified') {
                if (in_array($type, ['rental_payment', 'additional_charge', 'damage_fee', 'late_fee'])) {
                    $verifiedRentalCollected += $amt;
                    $methodBreakdown[$method] = ($methodBreakdown[$method] ?? 0) + $amt;
                } elseif ($type === 'security_deposit') {
                    $verifiedDepositsCollected += $amt;
                    $methodBreakdown[$method] = ($methodBreakdown[$method] ?? 0) + $amt;
                } elseif ($type === 'refund') {
                    $rentalRefunds += $amt;
                } elseif ($type === 'deposit_refund') {
                    $depositRefunds += $amt;
                }
            }
        }

        $netRentalRevenue = max(0.00, round($verifiedRentalCollected - $rentalRefunds, 2));
        $totalCollectedInflows = round($verifiedRentalCollected + $verifiedDepositsCollected, 2);
        $totalRefunds = round($rentalRefunds + $depositRefunds, 2);
        $netCashRetained = round($totalCollectedInflows - $totalRefunds, 2);

        // 2. LIFETIME & ACTIVE DEPOSITS HELD (All-time liability tracking)
        $depStmt = $conn->query("
            SELECT 
                COALESCE(SUM(CASE WHEN payment_type = 'security_deposit' AND status = 'verified' THEN amount ELSE 0 END), 0) as total_dep_collected,
                COALESCE(SUM(CASE WHEN payment_type = 'deposit_refund' AND status = 'verified' THEN amount ELSE 0 END), 0) as total_dep_refunded
            FROM payments
        ");
        $depRow = $depStmt->fetch_assoc();
        $totalDepositsEver = (float)$depRow['total_dep_collected'];
        $totalDepositsRefundedEver = (float)$depRow['total_dep_refunded'];

        $dedRow = $conn->query("SELECT COALESCE(SUM(deposit_deducted), 0) as total_deducted FROM orders")->fetch_assoc();
        $totalDepositsDeductedEver = (float)$dedRow['total_deducted'];

        $activeDepositsHeld = max(0.00, round($totalDepositsEver - $totalDepositsDeductedEver - $totalDepositsRefundedEver, 2));

        // 3. ORDERS, CHARGES, OUTSTANDING BALANCES & ADJUSTMENTS (Within period)
        $oStmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_orders,
                SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_orders,
                SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_orders,
                SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
                SUM(CASE WHEN status = 'Expired' THEN 1 ELSE 0 END) as expired_orders,
                COALESCE(SUM(CASE WHEN status IN ('Approved', 'Active', 'Completed') THEN subtotal ELSE 0 END), 0) as total_subtotal,
                COALESCE(SUM(CASE WHEN status IN ('Approved', 'Active', 'Completed') THEN discount_amount ELSE 0 END), 0) as total_discounts,
                COALESCE(SUM(CASE WHEN status IN ('Approved', 'Active', 'Completed') THEN late_fee ELSE 0 END), 0) as total_late_fees,
                COALESCE(SUM(CASE WHEN status IN ('Approved', 'Active', 'Completed') THEN fuel_fee ELSE 0 END), 0) as total_fuel_fees,
                COALESCE(SUM(CASE WHEN status IN ('Approved', 'Active', 'Completed') THEN damage_fee ELSE 0 END), 0) as total_damage_fees,
                COALESCE(SUM(CASE WHEN status = 'Cancelled' THEN cancellation_fee ELSE 0 END), 0) as total_cancellation_fees,
                COALESCE(SUM(CASE WHEN status IN ('Approved', 'Active', 'Completed') THEN total_amount ELSE 0 END), 0) as total_gross_charges,
                COALESCE(SUM(CASE WHEN status IN ('Approved', 'Active', 'Completed') THEN GREATEST(0, total_amount - (paid_amount + deposit_deducted)) ELSE 0 END), 0) as outstanding_balance
            FROM orders
            WHERE order_date BETWEEN ? AND ?
        ");
        $oStmt->bind_param("ss", $startDateTime, $endDateTime);
        $oStmt->execute();
        $oRow = $oStmt->get_result()->fetch_assoc();

        $totalBookings = (int)$oRow['total_orders'];
        $completedBookings = (int)$oRow['completed_orders'];
        $activeBookings = (int)$oRow['active_orders'];
        $approvedBookings = (int)$oRow['approved_orders'];
        $pendingBookings = (int)$oRow['pending_orders'];
        $cancelledBookings = (int)$oRow['cancelled_orders'];
        $expiredBookings = (int)$oRow['expired_orders'];

        $completionRate = $totalBookings > 0 ? round(($completedBookings / $totalBookings) * 100, 1) : 0.0;
        $cancellationRate = $totalBookings > 0 ? round(($cancelledBookings / $totalBookings) * 100, 1) : 0.0;

        $grossInvoicedRental = (float)$oRow['total_gross_charges'];
        $outstandingBalances = (float)$oRow['outstanding_balance'];
        $totalDiscounts = (float)$oRow['total_discounts'];
        $totalLateFees = (float)$oRow['total_late_fees'];
        $totalFuelFees = (float)$oRow['total_fuel_fees'];
        $totalDamageFees = (float)$oRow['total_damage_fees'];
        $totalCancellationFees = (float)$oRow['total_cancellation_fees'];

        $avgOrderValue = ($completedBookings + $activeBookings + $approvedBookings) > 0 
            ? round($grossInvoicedRental / ($completedBookings + $activeBookings + $approvedBookings), 2) 
            : 0.00;

        // 4. OPERATING / MAINTENANCE EXPENSES (Within period)
        $mStmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_maint,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN cost ELSE 0 END), 0) as completed_cost,
                COALESCE(SUM(CASE WHEN status IN ('scheduled', 'in_progress') THEN cost ELSE 0 END), 0) as pending_cost,
                COALESCE(SUM(cost), 0) as total_cost
            FROM vehicle_maintenance
            WHERE start_date BETWEEN ? AND ?
        ");
        $mStmt->bind_param("ss", $startDate, $endDate);
        $mStmt->execute();
        $mRow = $mStmt->get_result()->fetch_assoc();

        $completedMaintenanceCost = (float)$mRow['completed_cost'];
        $pendingMaintenanceCost = (float)$mRow['pending_cost'];
        $totalMaintenanceCost = (float)$mRow['total_cost'];
        $hasCostData = ((int)$mRow['total_maint'] > 0);

        // Net Operating Result = (Net Rental Revenue + Cancellation Fees) - Completed Maintenance Costs
        $netOperatingResult = null;
        if ($hasCostData || $netRentalRevenue > 0) {
            $netOperatingResult = round(($netRentalRevenue + $totalCancellationFees) - $completedMaintenanceCost, 2);
        }

        // 5. REVENUE & RENTALS BY VEHICLE CATEGORY
        $catStmt = $conn->prepare("
            SELECT 
                c.id as category_id,
                c.name as category_name,
                COUNT(DISTINCT oi.order_id) as booking_count,
                COALESCE(SUM(oi.quantity), 0) as units_rented,
                COALESCE(SUM(oi.quantity * oi.price), 0) as gross_category_revenue
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id
            LEFT JOIN order_items oi ON p.id = oi.product_id
            LEFT JOIN orders o ON oi.order_id = o.id 
                 AND o.status IN ('Approved', 'Active', 'Completed') 
                 AND o.order_date BETWEEN ? AND ?
            GROUP BY c.id, c.name
            ORDER BY gross_category_revenue DESC
        ");
        $catStmt->bind_param("ss", $startDateTime, $endDateTime);
        $catStmt->execute();
        $catRes = $catStmt->get_result();

        $categories = [];
        $totalCatRevenue = 0.0;
        while ($c = $catRes->fetch_assoc()) {
            $cRev = (float)$c['gross_category_revenue'];
            $totalCatRevenue += $cRev;
            $categories[] = [
                'id' => (int)$c['category_id'],
                'name' => $c['category_name'],
                'booking_count' => (int)$c['booking_count'],
                'units_rented' => (int)$c['units_rented'],
                'revenue' => $cRev
            ];
        }

        // Calculate revenue share %
        foreach ($categories as &$cItem) {
            $cItem['share_pct'] = $totalCatRevenue > 0 ? round(($cItem['revenue'] / $totalCatRevenue) * 100, 1) : 0.0;
        }
        unset($cItem);

        // 6. DAILY REVENUE TREND (For chart)
        $dailyStmt = $conn->prepare("
            SELECT 
                DATE(verified_at) as pay_date,
                SUM(amount) as daily_revenue
            FROM payments
            WHERE status = 'verified' 
              AND payment_type IN ('rental_payment', 'additional_charge', 'damage_fee', 'late_fee')
              AND verified_at BETWEEN ? AND ?
            GROUP BY DATE(verified_at)
            ORDER BY pay_date ASC
        ");
        $dailyStmt->bind_param("ss", $startDateTime, $endDateTime);
        $dailyStmt->execute();
        $dailyRes = $dailyStmt->get_result();

        $dailyTrend = [];
        while ($d = $dailyRes->fetch_assoc()) {
            $dailyTrend[$d['pay_date']] = (float)$d['daily_revenue'];
        }

        return [
            'period' => [
                'preset' => $preset,
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'kpis' => [
                'verified_rental_collected' => $verifiedRentalCollected,
                'verified_deposits_collected' => $verifiedDepositsCollected,
                'total_collected_inflows' => $totalCollectedInflows,
                'rental_refunds' => $rentalRefunds,
                'deposit_refunds' => $depositRefunds,
                'total_refunds' => $totalRefunds,
                'net_rental_revenue' => $netRentalRevenue,
                'net_cash_retained' => $netCashRetained,
                'gross_invoiced_rental' => $grossInvoicedRental,
                'outstanding_balances' => $outstandingBalances,
                'active_deposits_held' => $activeDepositsHeld,
                'total_deposits_ever' => $totalDepositsEver,
                'total_deposits_deducted' => $totalDepositsDeductedEver,
                'total_discounts' => $totalDiscounts,
                'total_late_fees' => $totalLateFees,
                'total_fuel_fees' => $totalFuelFees,
                'total_damage_fees' => $totalDamageFees,
                'total_cancellation_fees' => $totalCancellationFees,
                'completed_maintenance_cost' => $completedMaintenanceCost,
                'pending_maintenance_cost' => $pendingMaintenanceCost,
                'total_maintenance_cost' => $totalMaintenanceCost,
                'has_cost_data' => $hasCostData,
                'net_operating_result' => $netOperatingResult,
                'pending_payments_count' => $pendingPaymentsCount,
                'pending_payments_sum' => $pendingPaymentsSum
            ],
            'stats' => [
                'total_bookings' => $totalBookings,
                'completed_bookings' => $completedBookings,
                'active_bookings' => $activeBookings,
                'approved_bookings' => $approvedBookings,
                'pending_bookings' => $pendingBookings,
                'cancelled_bookings' => $cancelledBookings,
                'expired_bookings' => $expiredBookings,
                'completion_rate' => $completionRate,
                'cancellation_rate' => $cancellationRate,
                'avg_order_value' => $avgOrderValue
            ],
            'categories' => $categories,
            'daily_trend' => $dailyTrend,
            'payment_methods' => $methodBreakdown
        ];
    }
}

