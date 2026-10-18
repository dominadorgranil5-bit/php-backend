<?php
/** lib/inventory_helpers.php — recipe-based stock deduction. */

/**
 * Works out how much of every ingredient an order uses.
 * Returns [ingredient_id => qty in the ingredient's base unit].
 *
 *  1) recipe ingredients  (e.g. Salted Caramel = Cold Brew + Salted Caramel Syrup + Milk)
 *  2) per-drink items     (Cup, Lid, straw... : every DRINK sold uses per_drink_qty of them)
 *  3) pizza dough + box   (1 dough per pizza; 1 box per pizza only for pickup / delivery, not dine-in; each matched by the chosen size)
 *
 * @return array<string,float>
 */
function inventory_order_usage(PDO $pdo, string $orderId): array
{
    // 1) Recipe ingredients (skipping per-drink items, which are handled in step 2).
    $usage = $pdo->prepare(
        'SELECT r.ingredient_id, SUM(r.quantity * oi.quantity) AS used
         FROM order_items oi
         JOIN product_recipes r ON r.menu_item_id = oi.product_id
         JOIN ingredients ing ON ing.id = r.ingredient_id AND ing.per_drink_qty = 0 AND ing.dough_size IS NULL AND ing.box_size IS NULL
         WHERE oi.order_id = :id
         GROUP BY r.ingredient_id'
    );
    $usage->execute(['id' => $orderId]);
    $totals = [];
    foreach ($usage->fetchAll() as $row) {
        $totals[$row['ingredient_id']] = (float)$row['used'];
    }

    // 2) Cup / lid etc.: every DRINK sold uses per_drink_qty of them, whatever the flavor,
    //    category or whether it has a recipe. Food categories are excluded.
    $drinks = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity), 0)
         FROM order_items oi
         JOIN menu_items mi ON mi.id = oi.product_id
         WHERE oi.order_id = :id
           AND LOWER(TRIM(mi.category)) NOT IN ('pizza','burger','pasta','chicken wings','wings','waffles','waffle')"
    );
    $drinks->execute(['id' => $orderId]);
    $drinkCount = (float)$drinks->fetchColumn();
    if ($drinkCount > 0) {
        foreach ($pdo->query('SELECT id, per_drink_qty FROM ingredients WHERE per_drink_qty > 0')->fetchAll() as $pd) {
            $totals[$pd['id']] = ($totals[$pd['id']] ?? 0) + $drinkCount * (float)$pd['per_drink_qty'];
        }
    }

    // 3) Pizza dough and pizza box: 1 of each per pizza sold, taken from the stock that matches the chosen
    //    size (e.g. 14" / 16" / 20"), whatever the flavor. The size is read from the order line name,
    //    which is built as "Flavor (14\") + add-ons".
    $doughs = $pdo->query('SELECT id, dough_size FROM ingredients WHERE dough_size IS NOT NULL')->fetchAll();
    $boxes = $pdo->query('SELECT id, box_size FROM ingredients WHERE box_size IS NOT NULL')->fetchAll();
    if ($doughs || $boxes) {
        $pizzas = $pdo->prepare(
            "SELECT oi.name, oi.quantity
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.product_id
             WHERE oi.order_id = :id AND LOWER(TRIM(mi.category)) = 'pizza'"
        );
        $pizzas->execute(['id' => $orderId]);

        // The box is only used when the customer takes the pizza away: pickup or delivery.
        // Dine-in pizzas are served on a plate, so no box is deducted for them.
        $typeStmt = $pdo->prepare('SELECT order_type FROM orders WHERE id = :id');
        $typeStmt->execute(['id' => $orderId]);
        $usesBox = in_array((string)$typeStmt->fetchColumn(), ['pickup', 'delivery'], true);

        foreach ($pizzas->fetchAll() as $line) {
            $doughId = pizza_dough_for_line((string)$line['name'], $doughs);
            if ($doughId !== null) {
                $totals[$doughId] = ($totals[$doughId] ?? 0) + (float)$line['quantity'];
            }
            $boxId = $usesBox ? pizza_dough_for_line((string)$line['name'], $boxes, 'box_size') : null;
            if ($boxId !== null) {
                $totals[$boxId] = ($totals[$boxId] ?? 0) + (float)$line['quantity'];
            }
        }
    }

    return $totals;
}

/**
 * Packs: a stock can be linked to another stock "per pack" (ingredients.pack_ingredient_id + pack_size),
 * e.g. Cold Brew (ml) -> Coffee Pack (pc), pack_size 25000. $delta is how much of the stock was just
 * used (positive) or given back (negative). A running counter (pack_used) remembers how much has been
 * used since the last pack went; every time it reaches pack_size, 1 pack is taken out of the pack stock
 * (and logged in the History). Returning stock turns the counter back and returns packs the same way.
 *
 * Runs inside the caller's transaction. Never throws - a pack problem must not block a sale.
 */
function apply_pack_usage(PDO $pdo, string $ingredientId, float $delta): void
{
    if ($delta == 0.0) {
        return;
    }
    try {
        $st = $pdo->prepare('SELECT name, unit, pack_ingredient_id, pack_size, pack_used FROM ingredients WHERE id = :id FOR UPDATE');
        $st->execute(['id' => $ingredientId]);
        $ing = $st->fetch();
        if (!$ing || $ing['pack_ingredient_id'] === null || (float)$ing['pack_size'] <= 0) {
            return;
        }
        $packStmt = $pdo->prepare('SELECT name FROM ingredients WHERE id = :id');
        $packStmt->execute(['id' => $ing['pack_ingredient_id']]);
        $packName = $packStmt->fetchColumn();
        if ($packName === false) {
            return;
        }

        $size = (float)$ing['pack_size'];
        $used = round((float)$ing['pack_used'] + $delta, 2);
        $packs = 0;
        if ($used >= $size) {
            $packs = (int)floor($used / $size);
        } elseif ($used < 0) {
            $packs = -(int)ceil(-$used / $size);
        }
        $used = round($used - $packs * $size, 2);

        $pdo->prepare('UPDATE ingredients SET pack_used = :u WHERE id = :id')->execute(['u' => $used, 'id' => $ingredientId]);
        if ($packs !== 0) {
            $pdo->prepare('UPDATE ingredients SET stock = stock - :p WHERE id = :id')
                ->execute(['p' => $packs, 'id' => $ing['pack_ingredient_id']]);
            $pdo->prepare(
                'INSERT INTO inventory_movements (ingredient_id, change_qty, reason, note)
                 VALUES (:i, :c, :r, :n)'
            )->execute([
                'i' => $ing['pack_ingredient_id'],
                'c' => -$packs,
                'r' => $packs > 0 ? 'sale' : 'restock',
                'n' => 'Auto: ' . $ing['name'] . ($packs > 0 ? ' used up (' : ' returned (') . rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.') . ' ' . $ing['unit'] . ' = 1 pack)',
            ]);
        }
    } catch (Throwable $e) {
        error_log('[inventory] pack usage failed for ' . $ingredientId . ': ' . $e->getMessage());
    }
}

/** Takes $totals out of stock, logs a 'sale' movement per ingredient and sets the order's lock. */
function inventory_write_deduction(PDO $pdo, string $orderId, array $totals): void
{
    $deduct = $pdo->prepare('UPDATE ingredients SET stock = stock - :q WHERE id = :id');
    $log = $pdo->prepare(
        'INSERT INTO inventory_movements (ingredient_id, order_id, change_qty, reason)
         VALUES (:i, :o, :c, "sale")'
    );
    foreach ($totals as $ingredientId => $used) {
        if ($used <= 0) continue;
        $deduct->execute(['q' => $used, 'id' => $ingredientId]);
        $log->execute(['i' => $ingredientId, 'o' => $orderId, 'c' => -$used]);
        apply_pack_usage($pdo, (string)$ingredientId, (float)$used); // e.g. Cold Brew used -> Coffee Pack
    }
    // updated_at = updated_at keeps this bookkeeping write from looking like an order change to the live stream.
    $pdo->prepare('UPDATE orders SET inventory_deducted_at = NOW(), updated_at = updated_at WHERE id = :id')
        ->execute(['id' => $orderId]);
}

/**
 * CHECKOUT deduction — called the moment an order is placed (customer checkout or staff walk-in POS).
 * Takes the recipe ingredients + cup/lid out of stock right away. Stock is shared, so the admin
 * and staff Inventory screens both see the lower numbers.
 *
 * Safe to call any number of times (orders.inventory_deducted_at is the lock). Does nothing for
 * cancelled / payment_failed orders. Never throws — a stock problem must not block a sale.
 * Items without a recipe simply deduct nothing; stock may go negative (= recount needed).
 */
function deduct_inventory_at_checkout(string $orderId): bool
{
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT status, inventory_deducted_at FROM orders WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        if (
            !$order
            || $order['inventory_deducted_at'] !== null
            || in_array($order['status'], ['cancelled', 'payment_failed'], true)
        ) {
            $pdo->rollBack();
            return false;
        }

        inventory_write_deduction($pdo, $orderId, inventory_order_usage($pdo, $orderId));
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[inventory] checkout deduction failed for order ' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Puts an order's stock back (order cancelled, or its GCash payment failed).
 * Reverses exactly what was logged for the order (net of earlier returns), then clears the lock
 * so the stock is taken again if the order is revived (e.g. a late GCash payment). Idempotent.
 */
function restore_inventory_for_order(string $orderId): bool
{
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT inventory_deducted_at FROM orders WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!$order || $order['inventory_deducted_at'] === null) {
            $pdo->rollBack();
            return false; // nothing deducted (or already returned)
        }

        $net = $pdo->prepare(
            'SELECT ingredient_id, SUM(change_qty) AS net FROM inventory_movements
             WHERE order_id = :id GROUP BY ingredient_id'
        );
        $net->execute(['id' => $orderId]);
        $add = $pdo->prepare('UPDATE ingredients SET stock = stock + :q WHERE id = :id');
        $log = $pdo->prepare(
            'INSERT INTO inventory_movements (ingredient_id, order_id, change_qty, reason, note)
             VALUES (:i, :o, :c, "restock", "Order cancelled / payment failed - stock returned")'
        );
        foreach ($net->fetchAll() as $row) {
            $back = -(float)$row['net'];
            if ($back <= 0) continue;
            $add->execute(['q' => $back, 'id' => $row['ingredient_id']]);
            $log->execute(['i' => $row['ingredient_id'], 'o' => $orderId, 'c' => $back]);
            apply_pack_usage($pdo, (string)$row['ingredient_id'], -$back); // give packs back the same way
        }

        $pdo->prepare('UPDATE orders SET inventory_deducted_at = NULL, updated_at = updated_at WHERE id = :id')
            ->execute(['id' => $orderId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[inventory] restore failed for order ' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * LEGACY deduction on completion — kept for orders that were placed before checkout deduction
 * existed and are still open. It acts only when the order is PAID and finished (completed /
 * delivered), only once, and never for an order that already has stock movements (those were
 * handled at checkout, or returned after a cancel). Never throws.
 */
function deduct_inventory_for_order(string $orderId): bool
{
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT status, payment_status, inventory_deducted_at FROM orders WHERE id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        if (
            !$order
            || $order['inventory_deducted_at'] !== null
            || $order['payment_status'] !== 'paid'
            || !in_array($order['status'], ['completed', 'delivered'], true)
        ) {
            $pdo->rollBack();
            return false;
        }

        $seen = $pdo->prepare('SELECT 1 FROM inventory_movements WHERE order_id = :id LIMIT 1');
        $seen->execute(['id' => $orderId]);
        if ($seen->fetchColumn()) {
            $pdo->rollBack();
            return false;
        }

        inventory_write_deduction($pdo, $orderId, inventory_order_usage($pdo, $orderId));
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[inventory] deduction failed for order ' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Finds which dough or box (by size) an order line uses. $sizeKey is the column holding the size
 * ('dough_size' or 'box_size'). The size sits in the "(...)" part of the line name,
 * e.g. 'Hawaiian (14")' or 'Pepperoni (16 inches) + Extra Cheese'. Returns the ingredient id or null.
 *
 * @param array<int,array<string,mixed>> $doughs
 */
function pizza_dough_for_line(string $lineName, array $doughs, string $sizeKey = 'dough_size'): ?string
{
    if (!preg_match_all('/\(([^()]*)\)/', $lineName, $groups)) {
        return null;
    }
    // The size group is added right after the item name, so check the groups from the end first.
    foreach (array_reverse($groups[1]) as $group) {
        foreach ($doughs as $d) {
            $size = (int)$d[$sizeKey];
            if ($size > 0 && preg_match('/(?<!\d)' . $size . '(?!\d)/', $group)) {
                return $d['id'];
            }
        }
    }
    return null;
}


/**
 * Menu items that cannot be made right now because an ingredient ran out.
 * Returns [menu_item_id => name of the ingredient that is short].
 *
 *  - a recipe ingredient has NO stock left (stock <= 0 = "No Stock" in Inventory), or
 *  - a per-drink item (cup, lid...) has no stock left (drink categories only).
 * "Low stock" (at/below the alert level but still > 0) does NOT make an item unavailable.
 * Items without a recipe are never marked out of stock (nothing tracks them).
 *
 * @return array<string,string>
 */
function menu_out_of_stock_map(PDO $pdo): array
{
    $out = [];
    $recipe = $pdo->query(
        'SELECT r.menu_item_id, i.name
         FROM product_recipes r
         JOIN ingredients i ON i.id = r.ingredient_id
         WHERE i.stock <= 0
         ORDER BY i.name'
    )->fetchAll();
    foreach ($recipe as $row) {
        $out[$row['menu_item_id']] ??= (string)$row['name'];
    }

    $perDrink = $pdo->query('SELECT name FROM ingredients WHERE per_drink_qty > 0 AND stock <= 0 ORDER BY name')->fetchAll();
    if ($perDrink) {
        $drinks = $pdo->query(
            "SELECT id FROM menu_items
             WHERE LOWER(TRIM(category)) NOT IN ('pizza','burger','pasta','chicken wings','wings','waffles','waffle')"
        )->fetchAll();
        foreach ($drinks as $d) {
            $out[$d['id']] ??= (string)$perDrink[0]['name'];
        }
    }
    return $out;
}

/**
 * Pure calculation: how many servings can be made from the stock, and which ingredient runs out first.
 *
 *   max servings = MIN( floor( stock / amount needed per serving ) )   over every ingredient line
 *
 * @param array<int,array{name:string,unit:string,stock:float,per_unit:float,low_stock_level:float}> $lines
 * @return array{max_qty:int,limiting_ingredient:string,limiting_stock:float,limiting_unit:string,needed_per_serving:float,stock_status:string}|null
 *         null when the item has no tracked ingredient lines (nothing to calculate).
 */
function compute_menu_yield(array $lines): ?array
{
    $best = null;
    foreach ($lines as $l) {
        if ($l['per_unit'] <= 0) continue;
        // Negative stock (= needs a recount) counts as 0. The epsilon guards against float noise like 0.29999.
        $yield = (int)floor(max($l['stock'], 0) / $l['per_unit'] + 1e-9);
        if ($best === null || $yield < $best['yield'] || ($yield === $best['yield'] && strcmp($l['name'], $best['line']['name']) < 0)) {
            $best = ['yield' => $yield, 'line' => $l];
        }
    }
    if ($best === null) return null;

    $l = $best['line'];
    $status = 'ok';
    if ($best['yield'] === 0) {
        $status = 'out';
    } elseif ($best['yield'] <= MENU_LOW_YIELD || $l['stock'] <= $l['low_stock_level']) {
        $status = 'low';
    }
    return [
        'max_qty'             => $best['yield'],
        'limiting_ingredient' => $l['name'],
        'limiting_stock'      => $l['stock'],
        'limiting_unit'       => $l['unit'],
        'needed_per_serving'  => $l['per_unit'],
        'stock_status'        => $status,
    ];
}

/** A menu item is flagged LOW when it can be made this many times or fewer. */
const MENU_LOW_YIELD = 10;

/**
 * Max servings per menu item from the current Inventory stock. Mirrors inventory_order_usage():
 * recipe lines + per-drink items (cup, lid...) for non-food categories.
 * Pizza dough / box are deducted by the SIZE picked at order time, so they are not part of this number.
 *
 * @return array<string,array<string,mixed>>  [menu_item_id => compute_menu_yield() result]
 */
function menu_yield_map(PDO $pdo): array
{
    $lines = [];

    $recipe = $pdo->query(
        'SELECT r.menu_item_id, i.name, i.unit, i.stock, i.low_stock_level, r.quantity
         FROM product_recipes r
         JOIN ingredients i ON i.id = r.ingredient_id
         WHERE i.per_drink_qty = 0 AND i.dough_size IS NULL AND i.box_size IS NULL'
    )->fetchAll();
    foreach ($recipe as $row) {
        $lines[$row['menu_item_id']][] = [
            'name' => (string)$row['name'], 'unit' => (string)$row['unit'],
            'stock' => (float)$row['stock'], 'low_stock_level' => (float)$row['low_stock_level'],
            'per_unit' => (float)$row['quantity'],
        ];
    }

    $perDrink = $pdo->query(
        'SELECT name, unit, stock, low_stock_level, per_drink_qty FROM ingredients WHERE per_drink_qty > 0'
    )->fetchAll();
    if ($perDrink) {
        $drinks = $pdo->query(
            "SELECT id FROM menu_items
             WHERE LOWER(TRIM(category)) NOT IN ('pizza','burger','pasta','chicken wings','wings','waffles','waffle')"
        )->fetchAll();
        foreach ($drinks as $d) {
            foreach ($perDrink as $p) {
                $lines[$d['id']][] = [
                    'name' => (string)$p['name'], 'unit' => (string)$p['unit'],
                    'stock' => (float)$p['stock'], 'low_stock_level' => (float)$p['low_stock_level'],
                    'per_unit' => (float)$p['per_drink_qty'],
                ];
            }
        }
    }

    $map = [];
    foreach ($lines as $itemId => $itemLines) {
        $res = compute_menu_yield($itemLines);
        if ($res !== null) $map[$itemId] = $res;
    }
    return $map;
}

/**
 * Adds `max_qty` (int|null), `limiting_ingredient`, `limiting_stock`, `limiting_unit`,
 * `needed_per_serving` and `stock_status` ('ok'|'low'|'out'|null) to each menu item row.
 * null = the item has no recipe / tracked ingredient, so nothing can be calculated.
 * Never throws: if inventory isn't set up the fields are null.
 */
function attach_stock_yield(array $items): array
{
    try {
        $map = menu_yield_map(db());
    } catch (Throwable $e) {
        error_log('[inventory] stock yield failed: ' . $e->getMessage());
        $map = [];
    }
    foreach ($items as &$it) {
        $y = $map[$it['id']] ?? null;
        $it['max_qty']             = $y['max_qty'] ?? null;
        $it['limiting_ingredient'] = $y['limiting_ingredient'] ?? null;
        $it['limiting_stock']      = $y['limiting_stock'] ?? null;
        $it['limiting_unit']       = $y['limiting_unit'] ?? null;
        $it['needed_per_serving']  = $y['needed_per_serving'] ?? null;
        $it['stock_status']        = $y['stock_status'] ?? null;
    }
    unset($it);
    return $items;
}

/** Adds `out_of_stock` (bool) to each menu item row. Never throws: if inventory isn't set up the flag is false. */
function attach_out_of_stock(array $items): array
{
    try {
        $map = menu_out_of_stock_map(db());
    } catch (Throwable $e) {
        error_log('[inventory] out-of-stock check failed: ' . $e->getMessage());
        $map = [];
    }
    foreach ($items as &$it) {
        $it['out_of_stock'] = isset($map[$it['id']]);
    }
    unset($it);
    return $items;
}
