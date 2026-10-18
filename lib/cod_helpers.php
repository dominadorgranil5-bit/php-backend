<?php
/**
 * Cash on Delivery unlock: a customer can pay cash on delivery only after
 * COD_REQUIRED_ORDERS successful orders. "Successful" = the order was paid and finished
 * (completed, or delivered by the rider).
 */
const COD_REQUIRED_ORDERS = 10;

function cod_successful_orders(PDO $pdo, string $userId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE customer_id = :id
           AND payment_status = 'paid'
           AND status IN ('completed','delivered')"
    );
    $stmt->execute(['id' => $userId]);
    return (int)$stmt->fetchColumn();
}

function cod_eligibility(PDO $pdo, string $userId): array
{
    $count = cod_successful_orders($pdo, $userId);
    return [
        'eligible' => $count >= COD_REQUIRED_ORDERS,
        'successful_orders' => $count,
        'required' => COD_REQUIRED_ORDERS,
    ];
}
