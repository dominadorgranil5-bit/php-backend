<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=drewscafe;charset=utf8mb4', 'root', '');
$columns = $pdo->query('SHOW COLUMNS FROM menu_items')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('inches', $columns, true)) {
    $pdo->exec("ALTER TABLE menu_items ADD COLUMN inches VARCHAR(30) NOT NULL DEFAULT '' AFTER price");
}
if (!in_array('addons', $columns, true)) {
    $pdo->exec('ALTER TABLE menu_items ADD COLUMN addons TEXT NULL AFTER inches');
}
echo implode(',', $pdo->query('SHOW COLUMNS FROM menu_items')->fetchAll(PDO::FETCH_COLUMN));
