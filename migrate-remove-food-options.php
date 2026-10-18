<?php
/** Removes sizes and add-ons from burger, wings, waffle, and pasta items. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$pdo = db();
$categories = ['burger', 'wings', 'waffle', 'pasta'];
$placeholders = implode(',', array_fill(0, count($categories), '?'));
$items = $pdo->prepare("SELECT id FROM menu_items WHERE LOWER(TRIM(category)) IN ($placeholders)");
$items->execute($categories);
$itemIds = $items->fetchAll(PDO::FETCH_COLUMN);

$pdo->beginTransaction();
try {
    foreach ($itemIds as $itemId) {
        $groups = $pdo->prepare('SELECT id FROM menu_option_groups WHERE menu_item_id = :item_id');
        $groups->execute(['item_id' => $itemId]);
        $groupIds = $groups->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($groupIds)) {
            $groupPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));
            $pdo->prepare("DELETE FROM menu_options WHERE group_id IN ($groupPlaceholders)")->execute($groupIds);
            $pdo->prepare('DELETE FROM menu_option_groups WHERE menu_item_id = :item_id')->execute(['item_id' => $itemId]);
        }
    }
    $pdo->commit();
    echo 'Done — removed options from ' . count($itemIds) . " food items.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}