<?php
/**
 * lib/menu_helpers.php — shared helpers used by multiple menu/*.php endpoints.
 *
 * `sizes` and `add_ons` on a menu item are not their own tables — they're
 * stored as two fixed `menu_option_groups` rows (name = 'Size' / 'Add-Ons'),
 * each with its `menu_options` rows underneath (see schema.sql). This file
 * translates between that generic group/option shape and the simple
 * `sizes: [...]` / `add_ons: [...]` arrays the admin app sends and expects.
 */

const MENU_SIZE_GROUP_NAME = 'Size';
const MENU_ADDON_GROUP_NAME = 'Add-Ons';

function is_drink_category(string $category): bool
{
    return in_array(strtolower(trim($category)), [
        'coffee',
        'non-coffee',
        'frappe coffee based',
        'frappe non-coffee',
        'house blended ice tea',
        'fruit ade',
        'milk tea',
    ], true);
}

function is_no_option_category(string $category): bool
{
    return in_array(strtolower(trim($category)), ['burger', 'wings', 'waffle', 'pasta'], true);
}

function default_drink_sizes(float $basePrice): array
{
    return [
        ['name' => 'Small', 'description' => '', 'price' => $basePrice],
        ['name' => 'Medium', 'description' => '', 'price' => $basePrice + 20],
        ['name' => 'Large', 'description' => '', 'price' => $basePrice + 40],
    ];
}

function default_drink_add_ons(): array
{
    return [
        ['name' => 'Pearl', 'description' => '', 'price' => 20],
        ['name' => 'Coffee Jelly', 'description' => '', 'price' => 20],
        ['name' => 'Whipped Cream', 'description' => '', 'price' => 20],
        ['name' => 'Extra Shot', 'description' => '', 'price' => 30],
    ];
}

/**
 * Attaches `sizes` and `add_ons` arrays to each menu item row (mutates and
 * returns $items). Runs at most 2 queries total, regardless of item count.
 */
function attach_menu_options(array $items): array
{
    if (empty($items)) {
        return $items;
    }

    $pdo = db();
    $itemIds = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

    $groupsStmt = $pdo->prepare(
        "SELECT id, menu_item_id, name FROM menu_option_groups WHERE menu_item_id IN ($placeholders)"
    );
    $groupsStmt->execute($itemIds);
    $groups = $groupsStmt->fetchAll();

    $optionsByGroup = [];
    $groupIds = array_column($groups, 'id');
    if (!empty($groupIds)) {
        $optPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));
        $optionsStmt = $pdo->prepare(
            "SELECT id, group_id, name, description, extra_price, available
             FROM menu_options
             WHERE group_id IN ($optPlaceholders)
             ORDER BY sort_order ASC, id ASC"
        );
        $optionsStmt->execute($groupIds);
        foreach ($optionsStmt->fetchAll() as $opt) {
            $optionsByGroup[$opt['group_id']][] = [
                'id' => $opt['id'],
                'name' => $opt['name'],
                'description' => $opt['description'] ?? '',
                'price' => (float)$opt['extra_price'],
                'available' => (bool)$opt['available'],
            ];
        }
    }

    $sizesByItem = [];
    $addOnsByItem = [];
    foreach ($groups as $g) {
        $opts = $optionsByGroup[$g['id']] ?? [];
        if ($g['name'] === MENU_SIZE_GROUP_NAME) {
            $sizesByItem[$g['menu_item_id']] = $opts;
        } elseif ($g['name'] === MENU_ADDON_GROUP_NAME) {
            $addOnsByItem[$g['menu_item_id']] = $opts;
        }
    }

    foreach ($items as &$item) {
        $item['sizes'] = $sizesByItem[$item['id']] ?? [];
        $item['add_ons'] = $addOnsByItem[$item['id']] ?? [];
    }
    unset($item);

    return $items;
}

/**
 * Replaces the Size / Add-Ons option group for a menu item with the given
 * rows. Pass null for either to leave that group untouched (mirrors
 * update.php's "only touch fields that were sent" behavior); pass [] to
 * clear a group out entirely.
 */
function save_menu_options(string $menuItemId, ?array $sizes, ?array $addOns): void
{
    $itemStmt = db()->prepare('SELECT category, price FROM menu_items WHERE id = :id');
    $itemStmt->execute(['id' => $menuItemId]);
    $item = $itemStmt->fetch();

    if ($item && is_drink_category((string)$item['category'])) {
        if ($sizes !== null && empty($sizes)) {
            $sizes = default_drink_sizes((float)$item['price']);
        }
        if ($addOns !== null && empty($addOns)) {
            $addOns = default_drink_add_ons();
        }
    }

    if ($sizes !== null) {
        replace_menu_option_group($menuItemId, MENU_SIZE_GROUP_NAME, 'single', true, $sizes);
    }
    if ($addOns !== null) {
        replace_menu_option_group($menuItemId, MENU_ADDON_GROUP_NAME, 'multiple', false, $addOns);
    }
}

function replace_menu_option_group(
    string $menuItemId,
    string $groupName,
    string $selectionType,
    bool $required,
    array $rows
): void {
    $pdo = db();

    // Only keep rows that actually have a name — mirrors the admin form,
    // which never sends its blank/in-progress rows.
    $rows = array_values(array_filter($rows, fn($r) => trim((string)($r['name'] ?? '')) !== ''));

    $stmt = $pdo->prepare('SELECT id FROM menu_option_groups WHERE menu_item_id = :item_id AND name = :name');
    $stmt->execute(['item_id' => $menuItemId, 'name' => $groupName]);
    $existingGroupId = $stmt->fetchColumn();

    if (empty($rows)) {
        if ($existingGroupId) {
            // menu_options.group_id has ON DELETE CASCADE, so this takes the options with it.
            $pdo->prepare('DELETE FROM menu_option_groups WHERE id = :id')->execute(['id' => $existingGroupId]);
        }
        return;
    }

    if ($existingGroupId) {
        $groupId = $existingGroupId;
        // A full replace is simpler and safer than diffing individual rows —
        // the admin form always submits its whole current list on save.
        $pdo->prepare('DELETE FROM menu_options WHERE group_id = :group_id')->execute(['group_id' => $groupId]);
    } else {
        $groupId = make_uuid();
        $pdo->prepare(
            'INSERT INTO menu_option_groups (id, menu_item_id, name, selection_type, required, sort_order)
             VALUES (:id, :menu_item_id, :name, :selection_type, :required, 0)'
        )->execute([
            'id' => $groupId,
            'menu_item_id' => $menuItemId,
            'name' => $groupName,
            'selection_type' => $selectionType,
            'required' => $required ? 1 : 0,
        ]);
    }

    $insert = $pdo->prepare(
        'INSERT INTO menu_options (id, group_id, name, description, extra_price, available, sort_order)
         VALUES (:id, :group_id, :name, :description, :extra_price, :available, :sort_order)'
    );
    foreach (array_values($rows) as $index => $row) {
        $insert->execute([
            'id' => make_uuid(),
            'group_id' => $groupId,
            'name' => trim((string)$row['name']),
            'description' => trim((string)($row['description'] ?? '')),
            'extra_price' => (float)($row['price'] ?? 0),
            'available' => array_key_exists('available', $row) && !$row['available'] ? 0 : 1,
            'sort_order' => $index,
        ]);
    }
}
