<?php
/** lib/cart_helpers.php — an account's saved cart ("customer_carts"), shared by every device the account signs in on. */

/** Keep in sync with MAX_CART_ITEMS in the app's src/context/CartContext.tsx. Total of all quantities. */
const MAX_CART_ITEMS = 20;
const MAX_CART_JSON_BYTES = 60000;

/** The saved cart lines for a user (empty list if none). */
function cart_items_for_user(string $userId): array
{
    $stmt = db()->prepare('SELECT items FROM customer_carts WHERE user_id = :u');
    $stmt->execute(['u' => $userId]);
    $json = $stmt->fetchColumn();
    if (!is_string($json) || $json === '') {
        return [];
    }
    $items = json_decode($json, true);
    return is_array($items) ? $items : [];
}

/** Validates what the app sends and keeps only the known cart-line fields. 400s if the cart is over the limit. */
function sanitize_cart_items($raw): array
{
    if (!is_array($raw)) {
        json_error('items must be a list', 400);
    }

    $out = [];
    $count = 0;
    foreach ($raw as $e) {
        if (!is_array($e)) {
            continue;
        }
        $cartItemId = trim((string)($e['cartItemId'] ?? ''));
        $id = trim((string)($e['id'] ?? ''));
        $qty = (int)($e['quantity'] ?? 0);
        if ($cartItemId === '' || $id === '' || $qty < 1 || !is_numeric($e['price'] ?? null)) {
            continue;
        }

        $count += $qty;
        if ($count > MAX_CART_ITEMS) {
            json_error('Your cart can hold up to ' . MAX_CART_ITEMS . ' items.', 400);
        }

        $out[] = [
            'cartItemId'   => mb_substr($cartItemId, 0, 200),
            'id'           => mb_substr($id, 0, 64),
            'name'         => mb_substr((string)($e['name'] ?? ''), 0, 200),
            'description'  => mb_substr((string)($e['description'] ?? ''), 0, 1000),
            'emoji'        => mb_substr((string)($e['emoji'] ?? ''), 0, 16),
            'image_url'    => isset($e['image_url']) && is_string($e['image_url']) ? mb_substr($e['image_url'], 0, 2000) : null,
            'category'     => mb_substr((string)($e['category'] ?? ''), 0, 100),
            'size'         => is_array($e['size'] ?? null) ? $e['size'] : null,
            'addOns'       => is_array($e['addOns'] ?? null) ? array_values($e['addOns']) : [],
            'optionsLabel' => mb_substr((string)($e['optionsLabel'] ?? ''), 0, 300),
            'price'        => (float)$e['price'],
            'quantity'     => $qty,
        ];
    }

    return $out;
}

/** Replaces the user's saved cart. An empty cart removes the row. */
function save_cart_items(string $userId, array $items): void
{
    $pdo = db();
    if (count($items) === 0) {
        $pdo->prepare('DELETE FROM customer_carts WHERE user_id = :u')->execute(['u' => $userId]);
        return;
    }

    $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > MAX_CART_JSON_BYTES) {
        json_error('Cart is too large to save.', 400);
    }

    $pdo->prepare(
        'INSERT INTO customer_carts (user_id, items) VALUES (:u, :i)
         ON DUPLICATE KEY UPDATE items = VALUES(items)'
    )->execute(['u' => $userId, 'i' => $json]);
}
