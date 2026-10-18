<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_method('POST');
require_role(['staff', 'admin']);

$body = json_body();
$id = require_field($body, 'id');

$pdo = db();
$pdo->beginTransaction();
try {
	$exists = $pdo->prepare('SELECT 1 FROM menu_items WHERE id = :id');
	$exists->execute(['id' => $id]);
	if (!$exists->fetchColumn()) {
		$pdo->rollBack();
		json_error('Menu item not found.', 404);
	}

	// Remove option rows explicitly so deletion works even on older databases
	// where the foreign keys were created without ON DELETE CASCADE.
	$groupIds = $pdo->prepare('SELECT id FROM menu_option_groups WHERE menu_item_id = :id');
	$groupIds->execute(['id' => $id]);
	$groups = $groupIds->fetchAll(PDO::FETCH_COLUMN);
	if (!empty($groups)) {
		$placeholders = implode(',', array_fill(0, count($groups), '?'));
		$pdo->prepare("DELETE FROM menu_options WHERE group_id IN ($placeholders)")->execute($groups);
		$pdo->prepare('DELETE FROM menu_option_groups WHERE menu_item_id = :id')->execute(['id' => $id]);
	}

	$pdo->prepare('DELETE FROM menu_items WHERE id = :id')->execute(['id' => $id]);
	$pdo->commit();
} catch (Throwable $e) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	throw $e;
}

json_out(['ok' => true]);
