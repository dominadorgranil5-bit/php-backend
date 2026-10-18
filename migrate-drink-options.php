<?php
/** Adds default sizes and add-ons to existing drink items without replacing custom options. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$items = $pdo->query('SELECT id, category, price FROM menu_items')->fetchAll();
$updated = 0;

$drinkCategories = ['coffee', 'non-coffee', 'frappe coffee based', 'frappe non-coffee', 'house blended ice tea', 'fruit ade', 'milk tea'];
$newId = static function (): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
};
$insertOptionGroup = static function (string $itemId, string $name, string $selectionType, bool $required, array $options) use ($pdo, $newId): void {
    $groupId = $newId();
    $pdo->prepare(
        'INSERT INTO menu_option_groups (id, menu_item_id, name, selection_type, required, sort_order)
         VALUES (:id, :item_id, :name, :selection_type, :required, 0)'
    )->execute([
        'id' => $groupId,
        'item_id' => $itemId,
        'name' => $name,
        'selection_type' => $selectionType,
        'required' => $required ? 1 : 0,
    ]);
    $insert = $pdo->prepare(
        'INSERT INTO menu_options (id, group_id, name, description, extra_price, available, sort_order)
         VALUES (:id, :group_id, :name, :description, :price, 1, :sort_order)'
    );
    foreach ($options as $index => $option) {
        $insert->execute([
            'id' => $newId(),
            'group_id' => $groupId,
            'name' => $option['name'],
            'description' => '',
            'price' => $option['price'],
            'sort_order' => $index,
        ]);
    }
};

$pdo->beginTransaction();
try {
    foreach ($items as $item) {
        if (!in_array(strtolower(trim((string)$item['category'])), $drinkCategories, true)) {
            continue;
        }

        $groups = $pdo->prepare('SELECT name FROM menu_option_groups WHERE menu_item_id = :id');
        $groups->execute(['id' => $item['id']]);
        $groupNames = $groups->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('Size', $groupNames, true)) {
            $basePrice = (float)$item['price'];
            $insertOptionGroup($item['id'], 'Size', 'single', true, [
                ['name' => 'Small', 'price' => $basePrice],
                ['name' => 'Medium', 'price' => $basePrice + 20],
                ['name' => 'Large', 'price' => $basePrice + 40],
            ]);
            $updated++;
        }
        if (!in_array('Add-Ons', $groupNames, true)) {
            $insertOptionGroup($item['id'], 'Add-Ons', 'multiple', false, [
                ['name' => 'Pearl', 'price' => 20],
                ['name' => 'Coffee Jelly', 'price' => 20],
                ['name' => 'Whipped Cream', 'price' => 20],
                ['name' => 'Extra Shot', 'price' => 30],
            ]);
            $updated++;
        }
    }
    $pdo->commit();
    echo "Done — added default options to $updated drink items.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}