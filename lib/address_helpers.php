<?php
/** lib/address_helpers.php — shared logic for the "My Addresses" endpoints. */

const MAX_ADDRESSES = 5;

require_once __DIR__ . '/service_area_helpers.php';

/**
 * Validates + trims address fields from a request body. Sends a 400 on any problem.
 *
 * The Municipality + Barangay must be one the admin set (Admin > Customers > Delivery Addresses); the
 * province and postal code are taken from the admin's Municipality, not from the customer. The customer
 * only types the free-text "street" (building no. / street).
 *
 * $existing = the already-saved row being edited. If its Municipality/Barangay is left unchanged it is
 * accepted as-is, so an older address (or one whose area the admin later removed) doesn't block
 * the customer from saving other changes.
 */
function address_input(array $body, ?array $existing = null): array
{
    $get = fn(string $k) => trim((string)($body[$k] ?? ''));

    $data = [
        'label'       => $get('label'),        // optional (Home, Work, ...)
        'street'      => $get('street'),       // optional (building no., street, landmark)
        'barangay'    => $get('barangay'),
        'city'        => $get('city'),
        'province'    => $get('province'),
        'postal_code' => $get('postal_code'),
    ];

    if ($data['barangay'] === '') json_error('Barangay is required.', 400);
    if ($data['city'] === '')     json_error('Municipality is required.', 400);

    $unchanged = $existing !== null
        && $existing['city'] === $data['city']
        && $existing['barangay'] === $data['barangay'];

    if ($unchanged) {
        // Keep the saved province/postal code as they are.
        $data['province']    = $existing['province'];
        $data['postal_code'] = $existing['postal_code'];
    } else {
        $area = resolve_service_area($data['city'], $data['barangay']);
        $data['city']        = $area['city'];
        $data['barangay']    = $area['barangay'];
        $data['province']    = $area['province'];
        $data['postal_code'] = $area['postal_code'];
    }

    $limits = ['label' => 40, 'street' => 200];
    foreach ($limits as $key => $max) {
        if (mb_strlen($data[$key]) > $max) {
            json_error(ucfirst($key) . " is too long (max $max characters).", 400);
        }
    }

    return $data;
}

/** All of a user's addresses: default first, then oldest first. */
function addresses_for_user(string $userId): array
{
    $stmt = db()->prepare(
        'SELECT id, label, street, barangay, city, province, postal_code, is_default
         FROM customer_addresses WHERE user_id = :u
         ORDER BY is_default DESC, created_at ASC, id ASC'
    );
    $stmt->execute(['u' => $userId]);

    return array_map(function ($row) {
        $row['is_default'] = (bool)(int)$row['is_default'];
        return $row;
    }, $stmt->fetchAll());
}

/** Returns true if the address exists AND belongs to this user. */
function address_owned_by(string $addressId, string $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM customer_addresses WHERE id = :id AND user_id = :u');
    $stmt->execute(['id' => $addressId, 'u' => $userId]);
    return (bool)$stmt->fetchColumn();
}

/** Serializes concurrent address changes for one user (prevents going over the limit / two defaults). */
function lock_user_row(string $userId): void
{
    db()->prepare('SELECT id FROM users WHERE id = :u FOR UPDATE')->execute(['u' => $userId]);
}

/** One saved address row of this user (for comparing against an edit), or null. */
function address_row_for_user(string $addressId, string $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, street, barangay, city, province, postal_code
         FROM customer_addresses WHERE id = :id AND user_id = :u'
    );
    $stmt->execute(['id' => $addressId, 'u' => $userId]);
    return $stmt->fetch() ?: null;
}
