<?php
/** lib/orders_helpers.php — shared helpers used by multiple orders/*.php endpoints. */

/** Batches order_items for a list of orders (avoids N+1 queries) and nests them under each order. */
function attach_order_items(array $orders): array
{
    if (empty($orders)) {
        return [];
    }
    $ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare(
        "SELECT id, order_id, product_id, name, price, quantity
         FROM order_items WHERE order_id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $itemsByOrder = [];
    $allItemIds = [];
    foreach ($stmt->fetchAll() as $row) {
        $itemsByOrder[$row['order_id']][] = [
            'id' => (int)$row['id'],
            'product_id' => $row['product_id'],
            'name' => $row['name'],
            'price' => $row['price'],
            'quantity' => (int)$row['quantity'],
            // Filled in below once we've batch-fetched the picked options.
            'options' => [],
        ];
        $allItemIds[] = (int)$row['id'];
    }

    if (!empty($allItemIds)) {
        $itemPlaceholders = implode(',', array_fill(0, count($allItemIds), '?'));
        $optStmt = db()->prepare(
            "SELECT order_item_id, group_name, option_name, extra_price
             FROM order_item_options WHERE order_item_id IN ($itemPlaceholders)"
        );
        $optStmt->execute($allItemIds);
        $optionsByItemId = [];
        foreach ($optStmt->fetchAll() as $row) {
            $optionsByItemId[$row['order_item_id']][] = [
                'group_name' => $row['group_name'],
                'option_name' => $row['option_name'],
                'extra_price' => (float)$row['extra_price'],
            ];
        }

        foreach ($itemsByOrder as &$lineItems) {
            foreach ($lineItems as &$line) {
                $line['options'] = $optionsByItemId[$line['id']] ?? [];
            }
            unset($line);
        }
        unset($lineItems);
    }

    foreach ($orders as &$order) {
        $order['order_items'] = $itemsByOrder[$order['id']] ?? [];

        // Safety net for old rows saved with total = 0: show the line-items sum instead.
        if ((float)($order['total'] ?? 0) <= 0) {
            $sum = 0.0;
            foreach ($order['order_items'] as $line) {
                $sum += (float)$line['price'] * (int)$line['quantity'];
            }
            if ($sum > 0) {
                $order['total'] = number_format($sum, 2, '.', '');
            }
        }
    }
    unset($order);
    return $orders;
}

/**
 * Builds sales analytics for orders created within [$start, $end] (inclusive
 * DateTime bounds).
 *
 * Counted: every order that is not cancelled / payment_failed, and — for GCash
 * — only orders PayMongo has marked paid (same gate as orders/all.php, so the
 * report agrees with what staff see). Cash orders count from the moment they
 * are placed.
 *
 * Returns:
 *   total, orderCount, unitsSold, aov  — headline metrics
 *   topItems  — best sellers by units (limited to $topLimit), with revenue
 *   daily     — one row per calendar day in the range (zero-filled)
 *   orders    — one row per order, oldest first (for the CSV / PDF detail)
 *
 * Used by orders/sales-report.php (this month's best sellers) and
 * orders/sales-report-range.php (admin report builder + exports).
 */
function build_sales_breakdown(DateTime $start, DateTime $end, int $topLimit = 5): array
{
    $stmt = db()->prepare(
        'SELECT o.id, o.total, o.status, o.payment_method, o.payment_reference, o.created_at,
                u.name AS customer_name,
                oi.name, oi.quantity, oi.price
         FROM orders o
         LEFT JOIN users u ON u.id = o.customer_id
         LEFT JOIN order_items oi ON oi.order_id = o.id
         WHERE o.status NOT IN ("cancelled", "payment_failed")
           AND (o.payment_method != "gcash" OR o.payment_status = "paid")
           AND o.created_at BETWEEN :start AND :end
         ORDER BY o.created_at ASC, o.id ASC'
    );
    $stmt->execute([
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
    ]);
    $rows = $stmt->fetchAll();

    $orders = [];     // order_id => order row (with running unit count)
    $itemStats = [];  // item name => ['quantity' => int, 'revenue' => float]

    foreach ($rows as $row) {
        $id = $row['id'];
        if (!isset($orders[$id])) {
            $orders[$id] = [
                'id' => $id,
                'code' => strtoupper(substr($id, -6)),
                'created_at' => $row['created_at'],
                'customer' => $row['customer_name'] ?? '',
                'payment_method' => $row['payment_method'],
                'payment_reference' => $row['payment_reference'],
                'status' => $row['status'],
                'units' => 0,
                'total' => (float)$row['total'],
            ];
        }
        if ($row['name'] !== null) {
            $qty = (int)$row['quantity'];
            $orders[$id]['units'] += $qty;
            if (!isset($itemStats[$row['name']])) {
                $itemStats[$row['name']] = ['quantity' => 0, 'revenue' => 0.0];
            }
            $itemStats[$row['name']]['quantity'] += $qty;
            $itemStats[$row['name']]['revenue'] += (float)$row['price'] * $qty;
        }
    }

    // Zero-filled per-day rows so the report has no gaps.
    $daily = [];
    for ($d = (clone $start)->setTime(0, 0, 0); $d <= $end; $d->modify('+1 day')) {
        $daily[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'orders' => 0, 'units' => 0, 'revenue' => 0.0];
    }

    $total = 0.0;
    $unitsSold = 0;
    foreach ($orders as $o) {
        $total += $o['total'];
        $unitsSold += $o['units'];
        $day = substr($o['created_at'], 0, 10);
        if (isset($daily[$day])) {
            $daily[$day]['orders'] += 1;
            $daily[$day]['units'] += $o['units'];
            $daily[$day]['revenue'] += $o['total'];
        }
    }

    uasort($itemStats, fn($a, $b) => [$b['quantity'], $b['revenue']] <=> [$a['quantity'], $a['revenue']]);
    $topItems = [];
    foreach (array_slice($itemStats, 0, $topLimit, true) as $name => $stat) {
        $topItems[] = ['name' => (string)$name, 'quantity' => $stat['quantity'], 'revenue' => $stat['revenue']];
    }

    $orderCount = count($orders);

    return [
        'total' => $total,
        'orderCount' => $orderCount,
        'unitsSold' => $unitsSold,
        'aov' => $orderCount > 0 ? $total / $orderCount : 0.0,
        'topItems' => $topItems,
        'daily' => array_values($daily),
        'orders' => array_values($orders),
    ];
}
