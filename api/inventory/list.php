<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('GET');
require_role(['staff', 'admin']);

$rows = db()->query(
    'SELECT * FROM ingredients ORDER BY name'
)->fetchAll();

// Packs on hand per ingredient id (used to derive pack-linked stocks such as Cold Brew).
$packStock = [];
foreach ($rows as $pr) {
    $packStock[$pr['id']] = ['name' => $pr['name'], 'stock' => (float)$pr['stock']];
}

foreach ($rows as &$r) {
    $r['stock'] = (float)$r['stock'];
    $r['low_stock_level'] = (float)$r['low_stock_level'];
    $r['pump_ml'] = $r['pump_ml'] !== null ? (float)$r['pump_ml'] : null;
    $r['per_drink_qty'] = (float)$r['per_drink_qty'];
    $r['dough_size'] = ($r['dough_size'] ?? null) !== null ? (int)$r['dough_size'] : null;
    $r['box_size'] = ($r['box_size'] ?? null) !== null ? (int)$r['box_size'] : null;
    $r['pack_ingredient_id'] = $r['pack_ingredient_id'] ?? null;
    $r['pack_size'] = ($r['pack_size'] ?? null) !== null ? (float)$r['pack_size'] : null;
    $r['pack_used'] = (float)($r['pack_used'] ?? 0);
    unset($r['created_at']);

    // Pack-linked stock (Cold Brew <- Coffee Pack): the liquid is not counted by hand. What is left is
    // simply  packs on hand x pack size - what was already used from the open pack.
    // e.g. 10 packs x 15,000 ml - 3,150 ml used = 146,850 ml (= 979 coffee orders at 150 ml).
    $r['is_derived'] = false;
    $r['pack_stock'] = null;
    $r['pack_name'] = null;
    if ($r['pack_ingredient_id'] !== null && $r['pack_size'] !== null && $r['pack_size'] > 0
        && isset($packStock[$r['pack_ingredient_id']])) {
        $packs = $packStock[$r['pack_ingredient_id']];
        $r['is_derived'] = true;
        $r['pack_stock'] = $packs['stock'];
        $r['pack_name'] = $packs['name'];
        $r['stock'] = round($packs['stock'] * $r['pack_size'] - $r['pack_used'], 2);
    }
    $r['is_low'] = $r['stock'] <= $r['low_stock_level'];
}
unset($r);

json_out($rows);
