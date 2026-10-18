<?php
/**
 * Saves the customer's WHOLE address list in one go (used by Profile > Save Changes).
 *
 * Body: { addresses: [ { id?, label, street, barangay, city, province, postal_code, is_default } ] }
 *  - item with an existing id  -> updated
 *  - item without an id        -> created
 *  - saved address not in list -> deleted
 * All-or-nothing (one transaction). Max 5. Exactly one default is enforced.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/address_helpers.php';
require_method('POST');

$user = require_user();
$body = json_body();
$items = $body['addresses'] ?? null;

if (!is_array($items)) {
    json_error('addresses must be a list.', 400);
}
if (count($items) > MAX_ADDRESSES) {
    json_error('You can save up to ' . MAX_ADDRESSES . ' addresses.', 409);
}

// This user's saved rows (id => row) — lets unchanged older addresses pass the Municipality/Barangay check.
$savedRows = [];
$savedStmt = db()->prepare(
    'SELECT id, street, barangay, city, province, postal_code FROM customer_addresses WHERE user_id = :u'
);
$savedStmt->execute(['u' => $user['id']]);
foreach ($savedStmt->fetchAll() as $row) {
    $savedRows[$row['id']] = $row;
}

// Validate everything first.
$clean = [];
$seenIds = [];
foreach (array_values($items) as $item) {
    if (!is_array($item)) {
        json_error('Invalid address.', 400);
    }
    $itemId = trim((string)($item['id'] ?? ''));
    $data = address_input($item, $savedRows[$itemId] ?? null);
    $data['id'] = $itemId;
    $data['is_default'] = !empty($item['is_default']);
    if ($data['id'] !== '') {
        if (isset($seenIds[$data['id']])) {
            json_error('Duplicate address in the list.', 400);
        }
        $seenIds[$data['id']] = true;
    }
    $clean[] = $data;
}

// Exactly one default (if there is any address): first one flagged wins, else the first address.
if (count($clean) > 0) {
    $defaultIndex = 0;
    foreach ($clean as $i => $c) {
        if ($c['is_default']) { $defaultIndex = $i; break; }
    }
    foreach ($clean as $i => $c) {
        $clean[$i]['is_default'] = ($i === $defaultIndex);
    }
}

$pdo = db();
$pdo->beginTransaction();
try {
    lock_user_row($user['id']);

    $stmt = $pdo->prepare('SELECT id FROM customer_addresses WHERE user_id = :u');
    $stmt->execute(['u' => $user['id']]);
    $existing = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    // Any id that isn't one of THIS user's saved addresses is rejected.
    foreach ($clean as $c) {
        if ($c['id'] !== '' && !isset($existing[$c['id']])) {
            $pdo->rollBack();
            json_error('Address not found.', 404);
        }
    }

    // 1) delete the ones removed from the list
    foreach (array_keys($existing) as $existingId) {
        if (!isset($seenIds[$existingId])) {
            $pdo->prepare('DELETE FROM customer_addresses WHERE id = :id AND user_id = :u')
                ->execute(['id' => $existingId, 'u' => $user['id']]);
        }
    }

    // 2) update existing / 3) insert new
    foreach ($clean as $c) {
        $params = [
            'label' => $c['label'], 'street' => $c['street'], 'barangay' => $c['barangay'],
            'city' => $c['city'], 'province' => $c['province'], 'postal' => $c['postal_code'],
            'def' => $c['is_default'] ? 1 : 0, 'u' => $user['id'],
        ];
        if ($c['id'] !== '') {
            $pdo->prepare(
                'UPDATE customer_addresses
                 SET label = :label, street = :street, barangay = :barangay, city = :city,
                     province = :province, postal_code = :postal, is_default = :def
                 WHERE id = :id AND user_id = :u'
            )->execute($params + ['id' => $c['id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO customer_addresses (id, user_id, label, street, barangay, city, province, postal_code, is_default)
                 VALUES (:id, :u, :label, :street, :barangay, :city, :province, :postal, :def)'
            )->execute($params + ['id' => make_uuid()]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

json_out(['addresses' => addresses_for_user($user['id']), 'max' => MAX_ADDRESSES]);
